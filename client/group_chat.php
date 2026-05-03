<?php
require_once 'db.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$error = '';

// First, ensure tables exist with proper indexes for performance
$conn->query("
    CREATE TABLE IF NOT EXISTS chat_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_name VARCHAR(255) NOT NULL,
        room_description TEXT,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_private TINYINT DEFAULT 0,
        max_members INT DEFAULT 50,
        INDEX idx_created_by (created_by)
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS chat_room_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_id INT NOT NULL,
        user_id INT NOT NULL,
        role VARCHAR(50) DEFAULT 'member',
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_room_user (room_id, user_id),
        INDEX idx_room_id (room_id),
        INDEX idx_user_id (user_id),
        INDEX idx_joined_at (joined_at)
    )
");

// Add reactions table if not exists
$conn->query("
    CREATE TABLE IF NOT EXISTS message_reactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        user_id INT NOT NULL,
        reaction VARCHAR(10) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_reaction (message_id, user_id, reaction),
        INDEX idx_message_id (message_id),
        INDEX idx_user_id (user_id)
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS group_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_id INT NOT NULL,
        user_id INT NOT NULL,
        message TEXT,
        message_type VARCHAR(20) DEFAULT 'text',
        file_path VARCHAR(255),
        file_name VARCHAR(255),
        file_size INT,
        reply_to_id INT DEFAULT NULL,
        is_edited TINYINT DEFAULT 0,
        is_deleted TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_room_id (room_id),
        INDEX idx_created_at (created_at),
        INDEX idx_reply_to (reply_to_id),
        FOREIGN KEY (reply_to_id) REFERENCES group_messages(id) ON DELETE SET NULL
    )
");

if (!$room_id) {
    // This means user wants to create a NEW group chat
    $room_name = "SQUAD_" . date('Ymd_His');
    
    $room_data = [
        'room_name' => $room_name,
        'created_by' => $user_id,
        'max_members' => 50
    ];
    
    $room_id = dbInsert($conn, 'chat_rooms', $room_data);
    
    if ($room_id) {
        // Add creator to room as admin
        $member_data = [
            'room_id' => $room_id,
            'user_id' => $user_id,
            'role' => 'admin'
        ];
        dbInsert($conn, 'chat_room_members', $member_data);
        
        // Redirect to the new room with clean URL
        header("Location: group_chat.php?room_id=$room_id");
        exit();
    } else {
        $error = "Failed to create group";
    }
}

// Handle adding member to room
if (isset($_POST['add_member'])) {
    $member_id = (int)$_POST['member_id'];
    
    // Check current member count
    $count_row = dbGetRow($conn, "SELECT COUNT(*) as total FROM chat_room_members WHERE room_id = ?", "i", $room_id);
    $current_count = $count_row['total'] ?? 0;
    
    // Get room max members
    $room_info = dbGetRow($conn, "SELECT max_members FROM chat_rooms WHERE id = ?", "i", $room_id);
    $max_members = $room_info['max_members'] ?? 50;
    
    if ($current_count >= $max_members) {
        $error = "Cannot add more members. Maximum limit of $max_members members reached.";
    } else {
        // Check if user is already a member
        $check = dbGetRow($conn, "SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ?", "ii", $room_id, $member_id);
        
        if (!$check) {
            $member_data = [
                'room_id' => $room_id,
                'user_id' => $member_id,
                'role' => 'member'
            ];
            
            if (dbInsert($conn, 'chat_room_members', $member_data)) {
                // Get member info for response
                $member_info = dbGetRow($conn, "SELECT username FROM users WHERE user_id = ?", "i", $member_id);
                $success = htmlspecialchars($member_info['username'] ?? 'User') . " has been added to the group";
            } else {
                $error = "User is already a member of this group";
            }
        } else {
            $error = "User is already a member of this group";
        }
    }
    
    // Redirect to prevent form resubmission
    header("Location: group_chat.php?room_id=$room_id&success=" . urlencode($success ?? '') . "&error=" . urlencode($error ?? ''));
    exit();
}

// Handle removing member (for admins)
if (isset($_POST['remove_member']) && isset($_POST['member_id'])) {
    $member_id = (int)$_POST['member_id'];
    
    // Check if current user is admin
    $admin_check = dbGetRow($conn, "SELECT role FROM chat_room_members WHERE room_id = ? AND user_id = ?", "ii", $room_id, $user_id);
    
    if ($admin_check && $admin_check['role'] == 'admin' && $member_id != $user_id) {
        if (prepareAndExecute($conn, "DELETE FROM chat_room_members WHERE room_id = ? AND user_id = ?", "ii", $room_id, $member_id)) {
            $success = "Member removed from the group";
        }
    }
    
    // Redirect to prevent form resubmission
    header("Location: group_chat.php?room_id=$room_id&success=" . urlencode($success ?? '') . "&error=" . urlencode($error ?? ''));
    exit();
}

// Check for success/error messages from redirect
if (isset($_GET['success']) && !empty($_GET['success'])) {
    $success = $_GET['success'];
}
if (isset($_GET['error']) && !empty($_GET['error'])) {
    $error = $_GET['error'];
}

// Get room info
$room = dbGetRow($conn, "
    SELECT cr.*, u.username as creator_name,
           (SELECT COUNT(*) FROM chat_room_members WHERE room_id = cr.id) as member_count
    FROM chat_rooms cr
    JOIN users u ON u.user_id = cr.created_by
    WHERE cr.id = ?
", "i", $room_id);

if (!$room) {
    $error = "Room not found";
}

// IMPORTANT CHANGE: Don't auto-add users to existing rooms
// Instead, check if user is a member and show appropriate message
$member_check = dbGetRow($conn, "SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ?", "ii", $room_id, $user_id);

$is_member = ($member_check !== null);

// If not a member, show message but don't auto-add
if (!$is_member && !$error) {
    $error = "You are not a member of this group. Ask the host to add you or use the invite link.";
}

// Get current user's role (only if member)
$user_role = 'member';
if ($is_member) {
    $role_row = dbGetRow($conn, "SELECT role FROM chat_room_members WHERE room_id = ? AND user_id = ?", "ii", $room_id, $user_id);
    $user_role = $role_row['role'] ?? 'member';
}

// Get room members - only show if user is member
$members_result = null;
$total_members = 0;
$friends_result = null;
$total_friends = 0;

if ($is_member) {
    $members_result = prepareAndExecute($conn, "
        SELECT u.user_id, u.username, u.last_active, crm.role, crm.joined_at
        FROM chat_room_members crm
        JOIN users u ON u.user_id = crm.user_id
        WHERE crm.room_id = ?
        ORDER BY crm.role = 'admin' DESC, u.username ASC
    ", "i", $room_id)->get_result();
    $total_members = $members_result ? $members_result->num_rows : 0;

    // Get user's friends (for adding to group) - exclude members already in room
    $friends_result = prepareAndExecute($conn, "
        SELECT u.user_id, u.username, u.last_active
        FROM friends f
        JOIN users u ON u.user_id = f.friend_id
        WHERE f.user_id = ?
        AND u.user_id NOT IN (
            SELECT user_id FROM chat_room_members WHERE room_id = ?
        )
        ORDER BY u.username ASC
    ", "ii", $user_id, $room_id)->get_result();
    $total_friends = $friends_result ? $friends_result->num_rows : 0;
}

// Get user data for profile
$user_data = dbGetRow($conn, "SELECT * FROM users WHERE user_id = ?", "i", $user_id);

// Get unread messages for badge
$unread_row = dbGetRow($conn, "SELECT COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0", "i", $user_id);
$total_unread = $unread_row['count'] ?? 0;

// Get friend requests count for badge
$requests_row = dbGetRow($conn, "SELECT COUNT(*) as count FROM friend_requests WHERE receiver_id = ? AND status = 'pending'", "i", $user_id);
$requests_count = $requests_row['count'] ?? 0;

// Get missed calls count for badge
$missed_row = dbGetRow($conn, "SELECT COUNT(*) as count FROM calls WHERE receiver_id = ? AND status = 'missed'", "i", $user_id);
$missed_calls = $missed_row['count'] ?? 0;

// Helper function for avatar letter
function getAvatarLetter($username) {
    return strtoupper(substr($username, 0, 1));
}

// Helper function for time ago
function timeAgo($timestamp) {
    if (!$timestamp) return '';
    
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return 'now';
    if ($diff < 3600) return floor($diff / 60) . 'm';
    if ($diff < 86400) return floor($diff / 3600) . 'h';
    if ($diff < 604800) return floor($diff / 86400) . 'd';
    return date('M d', $time);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php includeResponsive(); ?>
    <title><?php echo htmlspecialchars($room['room_name'] ?? 'Squad Chat'); ?> · CONNECTXION GAMING</title>
    <!-- PWA Support -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#ff4655">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <script src="pwa_manager.js"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', 'Poppins', system-ui, sans-serif;
            background: #0a0c0f;
            height: 100vh;
            overflow: hidden;
            color: #e0e0e0;
        }

        /* Gaming Theme Variables */
        :root {
            --bg-primary: #0a0c0f;
            --bg-secondary: #14181c;
            --bg-tertiary: #1e2329;
            --bg-card: #1a1f25;
            --bg-hover: #252b33;
            --text-primary: #ffffff;
            --text-secondary: #b0b7c2;
            --text-muted: #6e7a8a;
            --accent: #ff4655; /* Valorant red */
            --accent-secondary: #0ed3c7; /* Cyber teal */
            --accent-glow: rgba(255, 70, 85, 0.3);
            --accent-glow-secondary: rgba(14, 211, 199, 0.3);
            --border: #2a313c;
            --success: #43b581;
            --warning: #faa61a;
            --danger: #f04747;
            --gradient-primary: linear-gradient(135deg, #ff4655, #ff7b72);
            --gradient-secondary: linear-gradient(135deg, #0ed3c7, #10b3aa);
            --glow-effect: 0 0 15px var(--accent-glow);
            
            /* Message colors */
            --message-own: #1e2a3a;
            --message-other: #1a1f25;
            --message-own-text: #ffffff;
            --message-other-text: #e0e0e0;
            
            /* Scrollbar colors */
            --scrollbar-track: #1a1f25;
            --scrollbar-thumb: #ff4655;
            --scrollbar-thumb-hover: #ff5e6b;
        }

        @keyframes pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.05); }
            100% { opacity: 1; transform: scale(1); }
        }

        @keyframes scanline {
            0% { transform: translateY(-100%); }
            100% { transform: translateY(100%); }
        }

        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-5px); }
            100% { transform: translateY(0px); }
        }

        /* Custom Scrollbar Styles */
        .scroll-panel {
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--scrollbar-thumb) var(--scrollbar-track);
        }

        .scroll-panel::-webkit-scrollbar {
            width: 6px;
        }

        .scroll-panel::-webkit-scrollbar-track {
            background: var(--scrollbar-track);
            border-radius: 10px;
        }

        .scroll-panel::-webkit-scrollbar-thumb {
            background: var(--scrollbar-thumb);
            border-radius: 10px;
            transition: all 0.3s;
        }

        .scroll-panel::-webkit-scrollbar-thumb:hover {
            background: var(--scrollbar-thumb-hover);
            box-shadow: 0 0 10px var(--accent-glow);
        }

        /* For Firefox */
        .scroll-panel {
            scrollbar-width: thin;
            scrollbar-color: var(--scrollbar-thumb) var(--scrollbar-track);
        }

        .app-container {
            display: flex;
            height: 100vh;
            background: var(--bg-primary);
            position: relative;
            overflow: hidden;
        }

        /* Gaming Overlay Effect */
        .app-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: repeating-linear-gradient(
                0deg,
                rgba(0, 0, 0, 0.15) 0px,
                rgba(0, 0, 0, 0.15) 1px,
                transparent 1px,
                transparent 2px
            );
            pointer-events: none;
            z-index: 5;
        }

        /* Left Sidebar - Gaming Navigation */
        .nav-sidebar {
            width: 80px;
            background: linear-gradient(180deg, var(--bg-secondary) 0%, #0f1317 100%);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 0;
            position: relative;
            z-index: 10;
            box-shadow: 5px 0 20px rgba(0, 0, 0, 0.5);
        }

        .nav-sidebar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--gradient-primary);
            box-shadow: var(--glow-effect);
        }

        .logo {
            width: 52px;
            height: 52px;
            background: var(--gradient-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            font-weight: 900;
            margin-bottom: 32px;
            position: relative;
            box-shadow: var(--glow-effect);
            animation: float 3s ease-in-out infinite;
            overflow: hidden;
        }

        .logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .logo span {
            transform: rotate(-45deg);
        }

        .nav-item {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            font-size: 24px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            background: transparent;
            border: 1px solid transparent;
        }

        .nav-item:hover {
            background: var(--bg-hover);
            color: var(--accent);
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 70, 85, 0.2);
        }

        .nav-item.active {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--glow-effect);
            animation: pulse 2s infinite;
        }

        .nav-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: var(--danger);
            color: white;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 20px;
            min-width: 20px;
            text-align: center;
            border: 2px solid var(--bg-secondary);
            box-shadow: 0 0 10px rgba(240, 71, 71, 0.5);
        }

        .nav-footer {
            margin-top: auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 20px 0;
        }

        .avatar {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: var(--gradient-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 22px;
            cursor: pointer;
            position: relative;
            transition: all 0.3s;
            overflow: hidden;
            border: 2px solid transparent;
        }

        .avatar:hover {
            transform: scale(1.1);
            border-color: var(--accent-secondary);
            box-shadow: 0 0 20px var(--accent-glow-secondary);
        }

        .avatar.online::before {
            content: '';
            position: absolute;
            bottom: 3px;
            right: 3px;
            width: 12px;
            height: 12px;
            background: var(--success);
            border: 2px solid var(--bg-secondary);
            border-radius: 50%;
            z-index: 2;
        }

        .avatar.online::after {
            content: '';
            position: absolute;
            bottom: 3px;
            right: 3px;
            width: 12px;
            height: 12px;
            background: var(--success);
            border-radius: 50%;
            animation: pulse 1.5s infinite;
            opacity: 0.7;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .logout-btn {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: rgba(240, 71, 71, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--danger);
            font-size: 24px;
            cursor: pointer;
            transition: all 0.3s;
            border: 1px solid transparent;
            margin-top: 8px;
        }

        .logout-btn:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(240, 71, 71, 0.4);
            border-color: var(--danger);
        }

        /* Alert Messages */
        .alert {
            padding: 16px 24px;
            border-radius: 14px;
            margin: 20px 30px 0;
            animation: slideIn 0.3s;
            display: flex;
            align-items: center;
            gap: 12px;
            border-left: 4px solid transparent;
            background: var(--bg-card);
            border: 1px solid var(--border);
        }

        .alert.success {
            border-left-color: var(--success);
            color: var(--success);
        }

        .alert.error {
            border-left-color: var(--danger);
            color: var(--danger);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--bg-primary);
            overflow: hidden;
        }

        /* Chat Container */
        .chat-container {
            flex: 1;
            display: flex;
            background: var(--bg-primary);
            position: relative;
            overflow: hidden;
        }

        /* Messages Area */
        .messages-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--bg-primary);
            overflow: hidden;
            height: 100%;
        }

        /* Chat Header */
        .chat-header {
            padding: 16px 24px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .chat-header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-btn {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            color: var(--text-secondary);
            font-size: 22px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .back-btn:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
            transform: translateX(-3px);
        }

        .room-info {
            display: flex;
            flex-direction: column;
        }

        .room-name {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .room-meta {
            font-size: 13px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .member-limit {
            background: var(--bg-tertiary);
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            color: var(--accent);
        }

        .chat-actions {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            color: var(--text-secondary);
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.5s;
        }

        .action-btn:hover::before {
            left: 100%;
        }

        .action-btn:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: var(--glow-effect);
        }

        /* Messages Container - Scroll Panel */
        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            background: var(--bg-primary);
            min-height: 0;
            height: 100%;
        }

        /* Message Styles */
        .message-wrapper {
            max-width: 70%;
            position: relative;
            animation: fadeIn 0.2s;
        }

        .message-own {
            align-self: flex-end;
        }

        .message-other {
            align-self: flex-start;
        }

        .message {
            display: flex;
            flex-direction: column;
        }

        .message-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
            padding: 0 8px;
        }

        .message-sender {
            font-size: 12px;
            font-weight: 700;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .message-own .message-sender {
            color: var(--accent-secondary);
        }

        .message-time {
            font-size: 10px;
            color: var(--text-muted);
        }

        .message-bubble {
            padding: 12px 16px;
            border-radius: 18px;
            position: relative;
            word-wrap: break-word;
            font-size: 14px;
            line-height: 1.5;
            border: 1px solid transparent;
        }

        .message-own .message-bubble {
            background: var(--message-own);
            color: var(--message-own-text);
            border-bottom-right-radius: 4px;
            border-color: rgba(255, 70, 85, 0.2);
        }

        .message-other .message-bubble {
            background: var(--message-other);
            color: var(--message-other-text);
            border-bottom-left-radius: 4px;
            border-color: var(--border);
        }

        /* Reply Preview in Message */
        .reply-preview-message {
            background: rgba(255, 255, 255, 0.05);
            border-left: 3px solid var(--accent);
            padding: 8px 12px;
            margin-bottom: 8px;
            border-radius: 8px;
            font-size: 12px;
        }

        .reply-sender {
            color: var(--accent);
            font-weight: 600;
            margin-bottom: 2px;
        }

        .reply-text {
            color: var(--text-muted);
            font-style: italic;
        }

        /* Message Actions */
        .message-actions {
            display: flex;
            align-items: center;
            gap: 4px;
            margin-top: 4px;
            padding: 0 8px;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .message-wrapper:hover .message-actions {
            opacity: 1;
        }

        .msg-action {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: none;
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .msg-action:hover {
            background: var(--accent);
            color: white;
            transform: scale(1.1);
        }

        /* Reactions */
        .message-reactions {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 4px;
            padding: 0 8px;
        }

        .reaction {
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 2px 8px;
            font-size: 12px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .reaction:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .reaction.active {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .reaction-count {
            font-weight: 600;
        }

        /* Reaction Picker */
        .reaction-picker {
            position: fixed;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 30px;
            padding: 8px;
            display: flex;
            gap: 4px;
            z-index: 1000;
            box-shadow: 0 8px 24px rgba(0,0,0,0.5);
            animation: fadeIn 0.2s;
        }

        .reaction-emoji {
            width: 36px;
            height: 36px;
            border-radius: 18px;
            border: none;
            background: var(--bg-tertiary);
            color: white;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .reaction-emoji:hover {
            background: var(--accent);
            transform: scale(1.2);
        }

        /* Edited Indicator */
        .edited-indicator {
            font-size: 10px;
            color: var(--text-muted);
            font-style: italic;
            margin-left: 4px;
        }

        /* Deleted Message */
        .message-deleted {
            padding: 12px 16px;
            border-radius: 18px;
            background: var(--bg-tertiary);
            color: var(--text-muted);
            font-style: italic;
            border: 1px dashed var(--border);
        }

        /* Reply Preview */
        .reply-preview {
            background: var(--bg-secondary);
            border-left: 4px solid var(--accent);
            padding: 12px 20px;
            margin: 0 24px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: slideIn 0.2s;
            flex-shrink: 0;
        }

        .reply-preview-content {
            flex: 1;
        }

        .reply-preview-header {
            font-size: 11px;
            color: var(--accent);
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .reply-preview-text {
            font-size: 13px;
            color: var(--text-muted);
        }

        .cancel-reply {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: var(--danger);
            color: white;
            cursor: pointer;
            transition: all 0.2s;
        }

        .cancel-reply:hover {
            transform: scale(1.1);
            background: #d32f2f;
        }

        /* Sidebar */
        .chat-sidebar {
            width: 320px;
            background: var(--bg-secondary);
            border-left: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            padding: 20px;
            gap: 20px;
            overflow-y: auto;
            height: 100%;
        }

        .room-details {
            padding: 16px;
            background: var(--bg-card);
            border-radius: 16px;
            border: 1px solid var(--border);
            flex-shrink: 0;
        }

        .room-description {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 8px;
            line-height: 1.5;
        }

        .members-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .section-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-transform: uppercase;
            letter-spacing: 1px;
            flex-shrink: 0;
        }

        .member-count {
            background: var(--bg-tertiary);
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            color: var(--accent);
        }

        .members-list {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding-right: 5px;
            min-height: 0;
        }

        .member-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            background: var(--bg-tertiary);
            border-radius: 12px;
            border: 1px solid var(--border);
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .member-item:hover {
            border-color: var(--accent-secondary);
            transform: translateX(5px);
        }

        .member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--gradient-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
            position: relative;
            flex-shrink: 0;
        }

        .member-avatar.online::after {
            content: '';
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 10px;
            height: 10px;
            background: var(--success);
            border: 2px solid var(--bg-tertiary);
            border-radius: 50%;
        }

        .member-info {
            flex: 1;
            min-width: 0;
        }

        .member-name {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 2px;
            display: flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .member-role {
            font-size: 10px;
            color: var(--accent);
            background: var(--bg-card);
            padding: 2px 6px;
            border-radius: 10px;
            text-transform: uppercase;
            flex-shrink: 0;
        }

        .member-status {
            font-size: 10px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .remove-member {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: none;
            background: var(--danger);
            color: white;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            flex-shrink: 0;
        }

        .member-item:hover .remove-member {
            opacity: 1;
        }

        .remove-member:hover {
            transform: scale(1.1);
            background: #d32f2f;
        }

        /* Add Member Section */
        .add-member-section {
            padding: 16px;
            background: var(--bg-card);
            border-radius: 16px;
            border: 1px solid var(--border);
            flex-shrink: 0;
        }

        .friends-list {
            max-height: 200px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 10px 0;
            padding-right: 5px;
        }

        .friend-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            background: var(--bg-tertiary);
            border-radius: 12px;
            border: 1px solid var(--border);
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .friend-item:hover {
            border-color: var(--accent);
        }

        .friend-avatar {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
            flex-shrink: 0;
        }

        .friend-info {
            flex: 1;
            min-width: 0;
        }

        .friend-name {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .friend-status {
            font-size: 10px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .add-friend-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: var(--accent);
            color: white;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .add-friend-btn:hover {
            background: var(--accent-hover);
            transform: scale(1.1);
        }

        .no-friends {
            text-align: center;
            padding: 20px;
            color: var(--text-muted);
            font-size: 12px;
        }

        .invite-section {
            padding: 16px;
            background: var(--bg-card);
            border-radius: 16px;
            border: 1px solid var(--border);
            flex-shrink: 0;
        }

        .invite-link {
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px;
            margin: 10px 0;
            font-size: 11px;
            color: var(--text-secondary);
            word-break: break-all;
            font-family: monospace;
        }

        .copy-btn {
            width: 100%;
            padding: 10px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .copy-btn:hover {
            background: var(--accent-hover);
            transform: translateY(-2px);
            box-shadow: var(--glow-effect);
        }

        /* Voice message styles */
        .message-voice {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 220px;
            max-width: 280px;
            padding: 5px 0;
        }

        .voice-play {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--accent);
            border: none;
            color: white;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            flex-shrink: 0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .voice-play:hover {
            transform: scale(1.1);
            box-shadow: 0 3px 8px rgba(0,0,0,0.3);
        }

        .voice-play.playing {
            background: #ff4444;
        }

        .voice-wave-container {
            flex: 1;
            height: 40px;
            display: flex;
            align-items: center;
            gap: 2px;
            cursor: pointer;
            padding: 0 2px;
            background: var(--bg-secondary);
            border-radius: 20px;
            overflow: hidden;
        }

        .voice-wave-bar {
            flex: 1;
            background: var(--accent);
            opacity: 0.5;
            border-radius: 2px;
            min-width: 2px;
            transition: opacity 0.2s, background 0.2s;
        }

        .voice-wave-bar.active {
            opacity: 1;
            background: var(--accent);
        }

        .message-own .voice-wave-bar {
            background: #00ff88;
        }

        .voice-duration {
            font-size: 12px;
            color: var(--text-muted);
            min-width: 70px;
            text-align: right;
            font-family: monospace;
        }

        .voice-recording-indicator {
            padding: 10px 20px;
            background: rgba(255, 70, 85, 0.1);
            border-top: 1px solid var(--accent);
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: slideUp 0.3s;
        }

        .voice-recording-left {
            display: flex;
            align-items: center;
            gap: 15px;
            color: var(--accent);
            font-weight: 700;
            font-size: 13px;
        }

        .voice-timer {
            font-family: monospace;
            font-size: 16px;
        }

        .voice-stop-btn {
            background: var(--danger);
            color: white;
            border: none;
            padding: 6px 15px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            font-size: 12px;
        }

        .input-btn.recording {
            color: var(--danger);
            animation: pulse 1s infinite;
        }

        /* Chat Input */
        .chat-input-area {
            padding: 20px 24px;
            background: var(--bg-secondary);
            border-top: 1px solid var(--border);
            flex-shrink: 0;
        }

        .chat-form {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 30px;
            padding: 4px 4px 4px 20px;
            transition: all 0.3s;
        }

        .chat-form:focus-within {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        .chat-form input[type="text"] {
            flex: 1;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 14px;
            padding: 12px 0;
            outline: none;
            font-family: 'Poppins', sans-serif;
        }

        .chat-form input[type="text"]::placeholder {
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 12px;
        }

        .input-actions {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .input-btn {
            width: 44px;
            height: 44px;
            border-radius: 22px;
            border: none;
            background: transparent;
            color: var(--text-secondary);
            font-size: 20px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .input-btn:hover {
            background: var(--accent-light);
            color: var(--accent);
            transform: scale(1.1);
        }

        .send-btn {
            width: 44px;
            height: 44px;
            border-radius: 22px;
            border: none;
            background: var(--gradient-primary);
            color: white;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--glow-effect);
        }

        .send-btn:hover:not(:disabled) {
            transform: scale(1.1);
            box-shadow: 0 0 20px var(--accent-glow);
        }

        .send-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Error Message */
        .error-message {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-card);
            border-radius: 24px;
            border: 1px solid var(--border);
            margin: 30px;
        }

        .error-icon {
            font-size: 80px;
            margin-bottom: 20px;
            filter: drop-shadow(0 0 20px var(--accent-glow));
        }

        .error-title {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .error-text {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 30px;
        }

        .back-btn {
            display: inline-block;
            padding: 12px 30px;
            background: var(--gradient-primary);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s;
            box-shadow: var(--glow-effect);
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255, 70, 85, 0.4);
        }

        /* Modal */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: linear-gradient(145deg, var(--bg-secondary), var(--bg-tertiary));
            border-radius: 30px;
            padding: 35px;
            max-width: 450px;
            width: 90%;
            transform: translateY(20px) scale(0.95);
            transition: all 0.3s;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
            box-shadow: 0 30px 60px rgba(0,0,0,0.7);
        }

        .modal.show .modal-content {
            transform: translateY(0) scale(1);
        }

        .modal-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--gradient-primary);
        }

        .modal-content h3 {
            font-size: 24px;
            color: var(--text-primary);
            margin-bottom: 20px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .edit-message-input {
            width: 100%;
            padding: 15px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            border-radius: 14px;
            color: var(--text-primary);
            font-size: 14px;
            margin-bottom: 20px;
            resize: vertical;
            min-height: 100px;
        }

        .edit-message-input:focus {
            border-color: var(--accent);
            outline: none;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .modal-btn {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .modal-btn.primary {
            background: var(--accent);
            color: white;
        }

        .modal-btn.primary:hover {
            background: var(--accent-hover);
            transform: translateY(-2px);
        }

        .modal-btn.secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .modal-btn.secondary:hover {
            background: var(--bg-hover);
        }

        .modal-btn.danger:hover {
            background: #d32f2f;
            transform: translateY(-2px);
        }

        /* Profile Modal Specific Styles */
        .profile-modal-content {
            padding: 0;
            overflow: hidden;
            background: #121317;
        }

        .profile-header-banner {
            height: 100px;
            background: var(--gradient-primary);
            width: 100%;
        }

        .profile-main-info {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-top: -50px;
            padding: 0 20px 20px;
        }

        .profile-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 20px;
            background: #1e1f24;
            border: 4px solid #121317;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            font-weight: 800;
            color: var(--accent);
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            overflow: hidden;
        }

        .profile-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-main-info h2 {
            margin-top: 15px;
            font-size: 24px;
            color: white;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .profile-status-badge {
            margin-top: 5px;
            font-size: 11px;
            font-weight: 800;
            padding: 4px 12px;
            border-radius: 20px;
            background: rgba(255,255,255,0.05);
            color: var(--text-muted);
        }

        .profile-status-badge.online {
            color: #43b581;
            background: rgba(67, 181, 129, 0.1);
        }

        .profile-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            padding: 20px;
            border-top: 1px solid rgba(255,255,255,0.05);
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .profile-stat-box {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .stat-num {
            font-size: 20px;
            font-weight: 800;
            color: var(--accent);
        }

        .stat-name {
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 2px;
        }

        .profile-details {
            padding: 20px;
        }

        .detail-item label {
            display: block;
            font-size: 10px;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 8px;
        }

        .detail-item p {
            font-size: 14px;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        /* Not Member Message */
        .not-member {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-card);
            border-radius: 24px;
            border: 1px solid var(--border);
            margin: 30px;
        }

        .not-member-icon {
            font-size: 80px;
            margin-bottom: 20px;
            filter: drop-shadow(0 0 20px var(--accent-glow));
        }

        .not-member-title {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .not-member-text {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 30px;
        }

        /* Toast */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 14px;
            z-index: 2000;
            animation: slideInRight 0.3s;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            text-transform: uppercase;
            color: var(--text-primary);
            box-shadow: 0 8px 24px rgba(0,0,0,0.5);
            border-left: 4px solid transparent;
        }

        .toast.success {
            background: var(--success);
            border-left-color: var(--success-dark);
        }

        .toast.error {
            background: var(--danger);
            border-left-color: var(--danger-dark);
        }

        .toast.info {
            background: var(--bg-secondary);
            border-left-color: var(--accent);
        }

        .toast.warning {
            background: var(--warning);
            border-left-color: var(--warning-dark);
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100%);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Scroll to bottom button */
        .scroll-bottom {
            position: fixed;
            bottom: 140px;
            right: 340px;
            width: 44px;
            height: 44px;
            border-radius: 22px;
            background: var(--accent);
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: bold;
            transition: all 0.3s;
            opacity: 0;
            visibility: hidden;
            box-shadow: var(--glow-effect);
            z-index: 100;
        }

        .scroll-bottom.visible {
            opacity: 1;
            visibility: visible;
        }

        .scroll-bottom:hover {
            transform: scale(1.1);
        }

        /* Typing Indicator */
        .typing-indicator-msg {
            padding: 10px 20px;
            color: var(--text-muted);
            font-size: 12px;
            font-style: italic;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: fadeIn 0.3s;
        }

        .typing-dots span {
            animation: blink 1.4s infinite both;
            font-weight: bold;
        }

        .typing-dots span:nth-child(2) { animation-delay: 0.2s; }
        .typing-dots span:nth-child(3) { animation-delay: 0.4s; }

        @keyframes blink {
            0% { opacity: 0.2; }
            20% { opacity: 1; }
            100% { opacity: 0.2; }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .nav-sidebar {
                width: 70px;
            }
            
            .chat-container {
                flex-direction: column;
            }
            
            .chat-sidebar {
                width: 100%;
                max-height: 400px;
            }
            
            .members-list {
                max-height: 250px;
            }

            .scroll-bottom {
                right: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Left Navigation Sidebar -->
        <div class="nav-sidebar">
            <div class="logo">
                <img src="photos/logo.png" alt="CONNECTXION">
            </div>
            
            <div class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'home.php' ? 'active' : ''; ?>" title="CHAT HUB" onclick="window.location.href='home.php'">
                💬
                <?php if ($total_unread > 0): ?>
                <span class="nav-badge"><?php echo min($total_unread, 99); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="nav-item" title="SQUAD" onclick="window.location.href='friends.php'">
                👥
                <?php if ($requests_count > 0): ?>
                <span class="nav-badge"><?php echo $requests_count; ?></span>
                <?php endif; ?>
            </div>

            <div class="nav-item" title="GROUPS" onclick="window.location.href='groups.php'">
                👪
            </div>
            
            <div class="nav-item" title="SETTINGS" onclick="window.location.href='settings.php'">
                ⚙️
            </div>
            
            <div class="nav-footer">
                <div class="avatar <?php echo (isset($user_data['last_active']) && $user_data['last_active'] && (time() - strtotime($user_data['last_active']) < 300)) ? 'online' : ''; ?>" onclick="window.location.href='profile.php'">
                    <?php if (!empty($user_data['avatar']) && file_exists($user_data['avatar'])): ?>
                        <img src="<?php echo $user_data['avatar']; ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo getAvatarLetter($user_data['username']); ?>
                    <?php endif; ?>
                </div>
                
                <div class="logout-btn" title="LOGOUT" onclick="showLogoutModal()">
                    ⏻
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <?php if ($error && !$is_member): ?>
            <div class="not-member">
                <div class="not-member-icon">🔒</div>
                <div class="not-member-title">PRIVATE SQUAD</div>
                <div class="not-member-text"><?php echo $error; ?></div>
                <a href="home.php" class="back-btn">BACK TO HUB</a>
            </div>
            <?php elseif ($error): ?>
            <div class="alert error">
                <span>⚠️</span>
                <?php echo $error; ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($success) && !empty($success) && $is_member): ?>
            <div class="alert success">
                <span>✓</span>
                <?php echo $success; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($is_member): ?>
            <!-- Chat Container -->
            <div class="chat-container">
                <!-- Messages Area -->
                <div class="messages-area">
                    <!-- Chat Header -->
                    <div class="chat-header">
                        <div class="chat-header-left">
                            <button class="back-btn" onclick="window.location.href='home.php'" title="BACK TO HUB">←</button>
                            <div class="room-info">
                                <div class="room-name"><?php echo htmlspecialchars($room['room_name']); ?></div>
                                <div class="room-meta">
                                    <span>👥 <?php echo $total_members; ?> / <?php echo $room['max_members'] ?? 50; ?> members</span>
                                    <span>•</span>
                                    <span>Host: <?php echo htmlspecialchars($room['creator_name']); ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="chat-actions">
                            <button class="action-btn" onclick="toggleMemberList()" title="TOGGLE MEMBERS">👥</button>
                        </div>
                    </div>
                    
                    <!-- Reply Preview -->
                    <div class="reply-preview" id="replyPreview" style="display: none;">
                        <div class="reply-preview-content">
                            <div class="reply-preview-header" id="replyPreviewHeader">REPLYING TO</div>
                            <div class="reply-preview-text" id="replyPreviewText">Message preview</div>
                        </div>
                        <button class="cancel-reply" onclick="cancelReply()">✕</button>
                    </div>
                    
                    <!-- Messages Container - Scroll Panel -->
                    <div class="messages-container scroll-panel" id="messagesContainer">
                        <!-- Messages will be loaded here -->
                        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <div style="font-size: 60px; margin-bottom: 20px;">💬</div>
                            <h3>WELCOME TO THE SQUAD!</h3>
                            <p>Start the conversation</p>
                        </div>
                    </div>
                    
                    <!-- Chat Input -->
                    <div class="chat-input-area">
                        <form class="chat-form" id="sendForm">
                            <input type="hidden" name="room_id" value="<?php echo $room_id; ?>">
                            <input type="hidden" name="reply_to" id="replyToInput">
                            <input type="text" name="message" id="messageInput" placeholder="TYPE YOUR MESSAGE..." autocomplete="off">
                            
                            <div class="input-actions">
                                <button type="button" class="input-btn" onclick="showEmojiPicker()" title="EMOJI">😊</button>
                                <button type="button" class="input-btn" onclick="showAttachMenu()" title="ATTACH">📎</button>
                                <button type="button" class="input-btn" id="voiceBtn" onclick="toggleVoiceRecording()" title="VOICE MESSAGE">🎤</button>
                                <button type="submit" class="send-btn" id="sendBtn" disabled>➤</button>
                            </div>
                        </form>
                        
                        <div id="voiceRecordingIndicator" class="voice-recording-indicator" style="display: none;">
                            <div class="voice-recording-left">
                                <span>🔴 RECORDING...</span>
                                <span class="voice-timer" id="voiceTimer">00:00</span>
                            </div>
                            <button type="button" class="voice-stop-btn" onclick="stopVoiceRecording()">STOP</button>
                        </div>
                    </div>
                </div>
                
                <!-- Sidebar -->
                <div class="chat-sidebar scroll-panel" id="memberSidebar">
                    <div class="room-details">
                        <div class="section-title">
                            <span>📋</span> ABOUT ROOM
                        </div>
                        <div class="room-description">
                            <?php echo htmlspecialchars($room['room_description'] ?? 'No description yet'); ?>
                        </div>
                    </div>
                    
                    <div class="members-section">
                        <div class="section-title">
                            <span>👥</span> MEMBERS
                            <span class="member-count"><?php echo $total_members; ?>/<?php echo $room['max_members'] ?? 50; ?></span>
                        </div>
                        
                        <!-- Members List - Scroll Panel -->
                        <div class="members-list scroll-panel" id="membersList">
                            <?php if ($members_result && $members_result->num_rows > 0): ?>
                                <?php 
                                $members_result->data_seek(0);
                                while($member = $members_result->fetch_assoc()): 
                                    $is_online = isset($member['last_active']) && $member['last_active'] && (time() - strtotime($member['last_active']) < 300);
                                ?>
                                <div class="member-item" onclick="showProfile(<?php echo $member['user_id']; ?>)">
                                    <div class="member-avatar <?php echo $is_online ? 'online' : ''; ?>">
                                        <?php echo getAvatarLetter($member['username']); ?>
                                    </div>
                                    <div class="member-info">
                                        <div class="member-name">
                                            <?php echo htmlspecialchars($member['username']); ?>
                                            <?php if ($member['role'] == 'admin'): ?>
                                            <span class="member-role">HOST</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="member-status">
                                            <span class="status-dot" style="background: <?php echo $is_online ? 'var(--success)' : 'var(--text-muted)'; ?>;"></span>
                                            <?php echo $is_online ? 'ONLINE' : 'OFFLINE'; ?>
                                        </div>
                                    </div>
                                    <?php if ($user_role == 'admin' && $member['user_id'] != $user_id): ?>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Remove <?php echo htmlspecialchars($member['username']); ?> from the group?')">
                                        <input type="hidden" name="member_id" value="<?php echo $member['user_id']; ?>">
                                        <button type="submit" name="remove_member" class="remove-member" title="REMOVE">✕</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Add Member Section (only for admins) -->
                    <?php if ($user_role == 'admin' && $total_members < ($room['max_members'] ?? 50)): ?>
                    <div class="add-member-section">
                        <div class="section-title">
                            <span>➕</span> ADD MEMBERS
                            <?php if ($total_friends > 0): ?>
                            <span class="member-count"><?php echo $total_friends; ?> available</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($friends_result && $friends_result->num_rows > 0): ?>
                        <!-- Friends List - Scroll Panel -->
                        <div class="friends-list scroll-panel">
                            <?php 
                            $friends_result->data_seek(0);
                            while($friend = $friends_result->fetch_assoc()): 
                                $is_online = isset($friend['last_active']) && $friend['last_active'] && (time() - strtotime($friend['last_active']) < 300);
                            ?>
                            <div class="friend-item">
                                <div class="friend-avatar">
                                    <?php echo getAvatarLetter($friend['username']); ?>
                                </div>
                                <div class="friend-info">
                                    <div class="friend-name"><?php echo htmlspecialchars($friend['username']); ?></div>
                                    <div class="friend-status">
                                        <span class="status-dot" style="background: <?php echo $is_online ? 'var(--success)' : 'var(--text-muted)'; ?>; display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px;"></span>
                                        <?php echo $is_online ? 'ONLINE' : 'OFFLINE'; ?>
                                    </div>
                                </div>
                                <form method="POST" style="margin: 0;" onsubmit="return confirm('Add <?php echo htmlspecialchars($friend['username']); ?> to this group?')">
                                    <input type="hidden" name="member_id" value="<?php echo $friend['user_id']; ?>">
                                    <button type="submit" name="add_member" class="add-friend-btn" title="ADD TO GROUP">+</button>
                                </form>
                            </div>
                            <?php endwhile; ?>
                        </div>
                        <?php else: ?>
                        <div class="no-friends">
                            No friends available to add.<br>
                            <a href="friends.php" style="color: var(--accent); text-decoration: none;">Add friends first</a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($user_role == 'admin' && $total_members >= ($room['max_members'] ?? 50)): ?>
                    <div class="add-member-section">
                        <div class="no-friends" style="color: var(--warning);">
                            ⚠️ Maximum member limit reached (<?php echo $room['max_members'] ?? 50; ?>)
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="invite-section">
                        <div class="section-title">
                            <span>🔗</span> INVITE LINK
                        </div>
                        <div class="invite-link" id="inviteLink">
                            <?php echo "http://" . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . "/join_room.php?room_id=$room_id"; ?>
                        </div>
                        <button class="copy-btn" onclick="copyInviteLink()">COPY LINK</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Emoji Picker Modal -->
    <div class="modal" id="emojiModal">
        <div class="modal-content" style="max-width: 400px;">
            <h3>CHOOSE EMOJI</h3>
            <div style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin: 25px 0;">
                <?php
                $emojis = ['😊', '😂', '❤️', '👍', '😢', '🎉', '😍', '🔥', '✨', '⭐', '🍕', '🎮', '😎', '🥺', '😡', '💀', '✅', '❌'];
                foreach ($emojis as $emoji) {
                    echo "<button class=\"reaction-emoji\" onclick=\"insertEmoji('$emoji')\">$emoji</button>";
                }
                ?>
            </div>
            <div class="modal-actions">
                <button class="modal-btn secondary" onclick="hideEmojiPicker()">CLOSE</button>
            </div>
        </div>
    </div>
    
    <!-- Edit Message Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <h3>EDIT MESSAGE</h3>
            <textarea class="edit-message-input" id="editMessageInput" placeholder="Edit your message..."></textarea>
            <div class="modal-actions">
                <button class="modal-btn secondary" onclick="hideEditModal()">CANCEL</button>
                <button class="modal-btn primary" onclick="saveEdit()">SAVE</button>
            </div>
        </div>
    </div>
    
    <!-- Delete Message Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <h3>DELETE MESSAGE</h3>
            <p>Are you sure you want to delete this message? This action cannot be undone.</p>
            <div class="modal-actions">
                <button class="modal-btn secondary" onclick="hideDeleteModal()">CANCEL</button>
                <button class="modal-btn danger" onclick="confirmDelete()">DELETE</button>
            </div>
        </div>
    </div>
    
    <div class="modal" id="profileModal">
        <div class="modal-content profile-modal-content">
            <div class="profile-header-banner"></div>
            <div class="profile-main-info">
                <div class="profile-avatar-large" id="profileAvatar"></div>
                <h2 id="profileUsername">USERNAME</h2>
                <div class="profile-status-badge" id="profileStatus">OFFLINE</div>
            </div>
            
            <div class="profile-stats-grid">
                <div class="profile-stat-box">
                    <span class="stat-num" id="statFriends">0</span>
                    <span class="stat-name">FRIENDS</span>
                </div>
                <div class="profile-stat-box">
                    <span class="stat-num" id="statMessages">0</span>
                    <span class="stat-name">MESSAGES</span>
                </div>
                <div class="profile-stat-box">
                    <span class="stat-num" id="statMemberSince">2026</span>
                    <span class="stat-name">JOINED</span>
                </div>
            </div>
            
            <div class="profile-details">
                <div class="detail-item">
                    <label>BIO</label>
                    <p id="profileBio">No bio yet.</p>
                </div>
            </div>
            
            <div class="modal-actions">
                <button class="modal-btn secondary" onclick="hideProfile()">CLOSE</button>
                <a href="profile.php" class="modal-btn primary" id="editProfileBtn" style="display: none; text-decoration: none; text-align: center;">EDIT PROFILE</a>
            </div>
        </div>
    </div>

    <!-- Logout Confirmation Modal -->
    <div class="modal" id="logoutModal">
        <div class="modal-content">
            <h3>EXIT GAME?</h3>
            <p>Are you sure you want to logout?</p>
            <div class="modal-actions">
                <button type="button" class="modal-btn secondary" onclick="hideLogoutModal()">CANCEL</button>
                <button type="button" class="modal-btn danger" onclick="confirmLogout()">LOGOUT</button>
            </div>
        </div>
    </div>
    
    <!-- Hidden Logout Form -->
    <form method="POST" action="home.php" id="logoutForm" style="display: none;">
        <input type="hidden" name="logout" value="1">
    </form>
    
    <!-- File Upload Form -->
    <form id="fileUploadForm" style="display: none;">
        <input type="file" id="fileInput" name="file" onchange="uploadFile(this)">
    </form>
    
    <!-- Scroll to bottom button -->
    <button class="scroll-bottom" id="scrollBottomBtn" onclick="scrollToBottom()">↓</button>
    
    <script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>
    <script>
        const CONFIG = {
            userId: <?php echo $user_id; ?>,
            roomId: <?php echo $room_id; ?>,
            username: "<?php echo addslashes($user_data['username']); ?>"
        };
    </script>
    <script src="group_chat_script.js?v=<?php echo time(); ?>"></script>
       
</body>
</html>