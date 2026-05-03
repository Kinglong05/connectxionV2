<?php
require_once 'db.php';
requireLogin();

$user_id = $_SESSION['user_id'];

// First, check if is_read column exists
$is_read_exists = false;
$column_check = $conn->query("SHOW COLUMNS FROM messages LIKE 'is_read'");
if ($column_check && $column_check->num_rows > 0) {
    $is_read_exists = true;
}

// Get all friends with their details
$friends_query = "
    SELECT u.*, 
           (SELECT created_at FROM messages 
            WHERE (sender_id = $user_id AND receiver_id = u.user_id)
               OR (sender_id = u.user_id AND receiver_id = $user_id)
            ORDER BY created_at DESC LIMIT 1) as last_message_time
    FROM friends f
    JOIN users u ON u.user_id = f.friend_id
    WHERE f.user_id = $user_id
    ORDER BY u.username ASC
";

$friends = $conn->query($friends_query);

// Get pending friend requests
$pending_requests = $conn->query("
    SELECT fr.*, u.username, u.email, u.last_active, u.avatar
    FROM friend_requests fr
    JOIN users u ON u.user_id = fr.sender_id
    WHERE fr.receiver_id = $user_id AND fr.status = 'pending'
    ORDER BY fr.created_at DESC
");

// Get user data for profile
$user_data = $conn->query("SELECT * FROM users WHERE user_id = $user_id")->fetch_assoc();

// Get counts
$requests_count = $pending_requests ? $pending_requests->num_rows : 0;
$friends_count = $friends ? $friends->num_rows : 0;

// Get missed calls count for badge
$missed_calls = $conn->query("
    SELECT COUNT(*) as count FROM calls 
    WHERE receiver_id = $user_id AND status = 'missed'
")->fetch_assoc()['count'] ?? 0;

// Get total unread messages for badge
$unread_result = $conn->query("
    SELECT COUNT(*) as count FROM messages 
    WHERE receiver_id = $user_id AND is_read = 0
");
$total_unread = $unread_result->fetch_assoc()['count'] ?? 0;

// Helper function to format time ago
function timeAgo($timestamp) {
    if (!$timestamp) return 'Never';
    
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . 'm ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . 'h ago';
    } elseif ($diff < 2592000) {
        $days = floor($diff / 86400);
        return $days . 'd ago';
    } else {
        return date('M d', $time);
    }
}

// Helper function to get avatar HTML
function getAvatarHtml($user, $size = 'medium', $online = false) {
    $avatarClass = 'avatar';
    $avatarContent = '';
    
    if ($size == 'small') {
        $avatarClass .= ' small';
    } elseif ($size == 'large') {
        $avatarClass .= ' large';
    }
    
    if ($online) {
        $avatarClass .= ' online';
    }
    
    if (!empty($user['avatar']) && file_exists($user['avatar'])) {
        $avatarContent = '<img src="' . htmlspecialchars($user['avatar']) . '" alt="' . htmlspecialchars($user['username']) . '">';
    } else {
        $avatarContent = strtoupper(substr($user['username'], 0, 1));
    }
    
    return '<div class="' . $avatarClass . '">' . $avatarContent . '</div>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php includeResponsive(); ?>
    <title>SQUAD · CONNECTXION GAMING</title>
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

        .avatar.small {
            width: 45px;
            height: 45px;
            font-size: 18px;
        }

        .avatar.large {
            width: 60px;
            height: 60px;
            font-size: 26px;
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

        /* Logout Button - Gaming Style */
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

        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--bg-primary);
            overflow: hidden;
        }

        /* Profile Card in Sidebar */
        .profile-card {
            padding: 16px 24px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 14px;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .profile-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.05), transparent);
            transform: translateX(-100%);
            transition: transform 0.5s;
        }

        .profile-card:hover::after {
            transform: translateX(100%);
        }

        .profile-card:hover {
            background: var(--bg-tertiary);
        }

        .profile-avatar {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: var(--gradient-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 22px;
            overflow: hidden;
            border: 2px solid transparent;
            transition: all 0.3s;
        }

        .profile-card:hover .profile-avatar {
            border-color: var(--accent-secondary);
            transform: scale(1.05);
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-info {
            flex: 1;
        }

        .profile-name {
            color: var(--text-primary);
            font-weight: 700;
            margin-bottom: 4px;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .profile-status {
            color: var(--text-muted);
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .profile-status-dot {
            width: 8px;
            height: 8px;
            background: var(--success);
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }

        .content-header {
            padding: 20px 30px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-secondary);
        }

        .content-header h1 {
            font-size: 28px;
            color: var(--text-primary);
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 2px;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Alert Messages for Friend Requests */
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 500;
            border-left: 4px solid;
            animation: slideIn 0.3s;
            background: var(--bg-card);
            border: 1px solid var(--border);
            margin: 10px 30px;
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
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .header-actions {
            display: flex;
            gap: 15px;
        }

        .search-box {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid var(--border);
            transition: all 0.3s;
            box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.5);
            width: 300px;
        }

        .search-box:focus-within {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow), inset 0 2px 5px rgba(0, 0, 0, 0.5);
        }

        .search-box span {
            color: var(--accent);
            font-size: 20px;
        }

        .search-box input {
            flex: 1;
            background: none;
            border: none;
            font-size: 14px;
            color: var(--text-primary);
            outline: none;
            font-family: 'Poppins', sans-serif;
        }

        .search-box input::placeholder {
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 12px;
        }

        .action-btn {
            padding: 12px 24px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
            box-shadow: var(--glow-effect);
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .action-btn:hover::before {
            left: 100%;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255, 70, 85, 0.4);
        }

        /* Contacts Container */
        .contacts-container {
            flex: 1;
            overflow-y: auto;
            padding: 24px 30px;
            scrollbar-width: thin;
            scrollbar-color: var(--accent) var(--bg-tertiary);
        }

        .contacts-container::-webkit-scrollbar {
            width: 4px;
        }

        .contacts-container::-webkit-scrollbar-track {
            background: var(--bg-tertiary);
        }

        .contacts-container::-webkit-scrollbar-thumb {
            background: var(--accent);
            border-radius: 4px;
        }

        .section-title {
            font-size: 14px;
            color: var(--text-muted);
            margin: 20px 0 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .section-title span {
            font-size: 18px;
        }

        /* Friend Requests Section */
        .requests-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .request-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .request-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 3px;
            height: 100%;
            background: var(--gradient-primary);
            transform: scaleY(0);
            transition: transform 0.3s;
        }

        .request-card:hover::before {
            transform: scaleY(1);
        }

        .request-card:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        }

        .request-avatar {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
            flex-shrink: 0;
            text-transform: uppercase;
            border: 2px solid transparent;
            transition: all 0.3s;
            overflow: hidden;
        }

        .request-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .request-card:hover .request-avatar {
            border-color: var(--accent);
            transform: scale(1.05);
        }

        .request-info {
            flex: 1;
        }

        .request-name {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .request-email {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .request-time {
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 4px;
            font-family: monospace;
        }

        .request-actions {
            display: flex;
            gap: 8px;
        }

        .request-btn {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
        }

        .request-btn.accept {
            background: var(--success);
            color: white;
        }

        .request-btn.accept:hover {
            transform: scale(1.1);
            box-shadow: 0 0 15px rgba(67, 181, 129, 0.5);
        }

        .request-btn.reject {
            background: var(--danger);
            color: white;
        }

        .request-btn.reject:hover {
            transform: scale(1.1);
            box-shadow: 0 0 15px rgba(240, 71, 71, 0.5);
        }

        /* Contacts Grid */
        .contacts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 15px;
        }

        .contact-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .contact-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 3px;
            height: 100%;
            background: var(--gradient-secondary);
            transform: scaleY(0);
            transition: transform 0.3s;
        }

        .contact-card:hover::before {
            transform: scaleY(1);
        }

        .contact-card:hover {
            border-color: var(--accent-secondary);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        }

        .contact-avatar {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            background: var(--gradient-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
            position: relative;
            flex-shrink: 0;
            text-transform: uppercase;
            border: 2px solid transparent;
            transition: all 0.3s;
            overflow: hidden;
        }

        .contact-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .contact-card:hover .contact-avatar {
            border-color: var(--accent-secondary);
            transform: scale(1.05);
        }

        .contact-avatar.online::after {
            content: '';
            position: absolute;
            bottom: 3px;
            right: 3px;
            width: 14px;
            height: 14px;
            background: var(--success);
            border: 2px solid var(--bg-card);
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }

        .contact-info {
            flex: 1;
            min-width: 0;
        }

        .contact-name-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }

        .contact-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .contact-id {
            font-size: 11px;
            color: var(--accent);
            background: var(--bg-tertiary);
            padding: 2px 8px;
            border-radius: 20px;
            font-family: monospace;
            border: 1px solid var(--border);
        }

        .contact-email {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .contact-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .status-dot.online {
            background: var(--success);
            box-shadow: 0 0 0 2px rgba(67, 181, 129, 0.2);
            animation: pulse 1.5s infinite;
        }

        .status-dot.offline {
            background: var(--text-muted);
        }

        .status-text {
            color: var(--text-muted);
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
        }

        .contact-actions {
            display: flex;
            gap: 6px;
        }

        .contact-btn {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            border: none;
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            border: 1px solid var(--border);
        }

        .contact-btn:hover {
            background: var(--accent);
            color: white;
            transform: scale(1.1);
            border-color: var(--accent);
            box-shadow: var(--glow-effect);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-card);
            border-radius: 24px;
            border: 1px solid var(--border);
        }

        .empty-icon {
            font-size: 80px;
            margin-bottom: 20px;
            filter: drop-shadow(0 0 20px var(--accent-glow));
            animation: float 3s ease-in-out infinite;
        }

        .empty-title {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .empty-text {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 30px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Modals */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.9);
            backdrop-filter: blur(5px);
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
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.7);
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
            font-size: 28px;
            color: var(--text-primary);
            margin-bottom: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .modal-content p {
            color: var(--text-secondary);
            margin-bottom: 25px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .invite-link-box {
            background: var(--bg-card);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid var(--border);
        }

        .invite-link {
            flex: 1;
            font-size: 13px;
            color: var(--text-primary);
            word-break: break-all;
            font-family: monospace;
        }

        .copy-btn {
            background: none;
            border: none;
            font-size: 22px;
            cursor: pointer;
            color: var(--accent);
            padding: 4px;
            transition: all 0.3s;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .copy-btn:hover {
            background: var(--accent);
            color: white;
            transform: scale(1.1);
        }

        .user-id-box {
            margin: 20px 0;
            padding: 16px;
            background: var(--bg-card);
            border-radius: 14px;
            text-align: center;
            border: 1px solid var(--border);
        }

        .user-id-label {
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .user-id-value {
            color: var(--accent);
            font-size: 32px;
            font-weight: 900;
            font-family: monospace;
            text-shadow: 0 0 10px var(--accent-glow);
        }

        .modal-content input {
            width: 100%;
            padding: 16px;
            background: var(--bg-card);
            border: 2px solid var(--border);
            border-radius: 16px;
            font-size: 16px;
            color: var(--text-primary);
            margin-bottom: 25px;
            transition: all 0.3s;
            font-family: monospace;
        }

        /* Search Result Styles */
        .search-results {
            margin-top: 20px;
            max-height: 300px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding-right: 5px;
        }
        .search-results::-webkit-scrollbar { width: 4px; }
        .search-results::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 4px; }
        
        .result-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s;
        }
        .result-card:hover { border-color: var(--accent); transform: translateX(5px); }
        .result-avatar {
            width: 50px; height: 50px;
            border-radius: 12px;
            background: var(--gradient-primary);
            display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 20px;
            overflow: hidden;
        }
        .result-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .result-info { flex: 1; text-align: left; }
        .result-name { color: var(--text-primary); font-weight: 700; margin-bottom: 2px; }
        .result-bio { color: var(--text-muted); font-size: 12px; line-height: 1.3; }
        .result-action .modal-btn { padding: 8px 15px; font-size: 11px; }
        
        #addFriendSearchInput {
            width: 100%;
            padding: 15px;
            background: var(--bg-card);
            border: 2px solid var(--border);
            border-radius: 12px;
            color: var(--text-primary);
            outline: none;
            transition: all 0.3s;
        }
        #addFriendSearchInput:focus { border-color: var(--accent); box-shadow: 0 0 10px var(--accent-glow); }
        .modal-content input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-glow);
        }

        .modal-actions {
            display: flex;
            gap: 12px;
        }

        .modal-btn {
            flex: 1;
            padding: 16px;
            border: none;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
        }

        .modal-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .modal-btn:hover::before {
            left: 100%;
        }

        .modal-btn.primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--glow-effect);
        }

        .modal-btn.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255, 70, 85, 0.4);
        }

        .modal-btn.secondary {
            background: var(--bg-card);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }

        .modal-btn.secondary:hover {
            background: var(--bg-hover);
            border-color: var(--accent);
        }

        .modal-btn.danger {
            background: var(--danger);
            color: white;
        }

        .modal-btn.danger:hover {
            background: #d32f2f;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(240, 71, 71, 0.4);
        }

        /* Toast Notifications */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            padding: 16px 24px;
            border-radius: 14px;
            z-index: 2000;
            animation: slideInRight 0.3s;
            border-left: 4px solid var(--accent);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        .toast.success {
            border-left-color: var(--success);
        }

        .toast.error {
            border-left-color: var(--danger);
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .nav-sidebar {
                width: 70px;
            }
            
            .content-header {
                flex-direction: column;
                gap: 15px;
                padding: 15px;
            }
            
            .search-box {
                width: 100%;
            }
            
            .contacts-container {
                padding: 15px;
            }
            
            .contacts-grid {
                grid-template-columns: 1fr;
            }
            
            .requests-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Left Navigation Sidebar - Gaming Style -->
        <div class="nav-sidebar">
            <div class="logo">
                <img src="photos/logo.png" alt="CONNECTXION">
            </div>
            
            <div class="nav-item" title="CHAT HUB" onclick="window.location.href='home.php'">
                💬
                <?php if ($total_unread > 0): ?>
                <span class="nav-badge"><?php echo min($total_unread, 99); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="nav-item active" title="SQUAD">
                👥
                <?php if ($requests_count > 0): ?>
                <span class="nav-badge"><?php echo min($requests_count, 99); ?></span>
                <?php endif; ?>
            </div>
            <div class="nav-item" title="GROUPS" onclick="window.location.href='groups.php'">
    👪
</div>
            <div class="nav-item" title="SETTINGS" onclick="window.location.href='settings.php'">
                ⚙️
            </div>
            
            <div class="nav-footer">
                <?php 
                $is_online = isset($user_data['last_active']) && $user_data['last_active'] && (time() - strtotime($user_data['last_active']) < 300);
                ?>
                <div class="avatar <?php echo $is_online ? 'online' : ''; ?>" onclick="window.location.href='profile.php'">
                    <?php if (!empty($user_data['avatar']) && file_exists($user_data['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($user_data['avatar']); ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo strtoupper(substr($user_data['username'], 0, 1)); ?>
                    <?php endif; ?>
                </div>
                
                <!-- Logout Button - Opens Modal -->
                <div class="logout-btn" title="LOGOUT" onclick="showLogoutModal()">
                    ⏻
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Profile Card at Top -->
            <div class="profile-card" onclick="window.location.href='profile.php'">
                <div class="profile-avatar">
                    <?php if (!empty($user_data['avatar']) && file_exists($user_data['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($user_data['avatar']); ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo strtoupper(substr($user_data['username'], 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($user_data['username']); ?></div>
                    <div class="profile-status">
                        <span class="profile-status-dot"></span>
                        <?php echo $is_online ? 'ONLINE' : 'OFFLINE'; ?>
                    </div>
                </div>
                <div style="color: var(--accent-secondary); font-size: 22px;">▶</div>
            </div>
            
            <div class="content-header">
                <h1>SQUAD</h1>
                <div class="header-actions">
                    <div class="search-box">
                        <span>🔍</span>
                        <input type="text" id="searchInput" placeholder="SEARCH PLAYERS...">
                    </div>
                    <button class="action-btn" onclick="showAddFriendModal()">
                        <span>➕</span> ADD
                    </button>
                    <button class="action-btn secondary" onclick="showInviteModal()" style="background: var(--bg-tertiary); box-shadow: none;">
                        <span>🔗</span> INVITE
                    </button>
                </div>
            </div>
            
            <!-- Show friend request messages -->
            <?php if (isset($_SESSION['friend_success'])): ?>
                <div class="alert success" style="margin: 10px 30px;">
                    <span>✓</span>
                    <?php 
                    echo $_SESSION['friend_success']; 
                    unset($_SESSION['friend_success']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['friend_error'])): ?>
                <div class="alert error" style="margin: 10px 30px;">
                    <span>⚠️</span>
                    <?php 
                    echo $_SESSION['friend_error']; 
                    unset($_SESSION['friend_error']);
                    ?>
                </div>
            <?php endif; ?>
            
            <div class="contacts-container">
                <!-- Friend Requests Section -->
                <?php if ($pending_requests && $pending_requests->num_rows > 0): ?>
                <div class="section-title">
                    <span>📨</span> PENDING REQUESTS (<?php echo $pending_requests->num_rows; ?>)
                </div>
                <div class="requests-grid">
                    <?php while($req = $pending_requests->fetch_assoc()): ?>
                    <div class="request-card">
                        <div class="request-avatar">
                            <?php if (!empty($req['avatar']) && file_exists($req['avatar'])): ?>
                                <img src="<?php echo htmlspecialchars($req['avatar']); ?>" alt="<?php echo htmlspecialchars($req['username']); ?>">
                            <?php else: ?>
                                <?php echo strtoupper(substr($req['username'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="request-info">
                            <div class="request-name"><?php echo htmlspecialchars($req['username']); ?></div>
                            <div class="request-email"><?php echo htmlspecialchars($req['email']); ?></div>
                            <div class="request-time">
                                <span>⏱️</span> <?php echo timeAgo($req['created_at']); ?>
                            </div>
                        </div>
                        <div class="request-actions">
                            <button class="request-btn accept" onclick="acceptRequest(<?php echo $req['id']; ?>)" title="ACCEPT">✓</button>
                            <button class="request-btn reject" onclick="rejectRequest(<?php echo $req['id']; ?>)" title="REJECT">✗</button>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php endif; ?>
                
                <!-- All Contacts Section -->
                <div class="section-title">
                    <span>👥</span> ALL PLAYERS (<?php echo $friends_count; ?>)
                </div>
                
                <?php if ($friends && $friends->num_rows > 0): ?>
                <div class="contacts-grid" id="contactsGrid">
                    <?php 
                    $friends->data_seek(0); // Reset pointer
                    while($friend = $friends->fetch_assoc()): 
                        $is_online = isset($friend['last_active']) && $friend['last_active'] && (time() - strtotime($friend['last_active']) < 300);
                        $last_seen = isset($friend['last_active']) && $friend['last_active'] ? timeAgo($friend['last_active']) : 'Never';
                    ?>
                    <div class="contact-card" onclick="openChat(<?php echo $friend['user_id']; ?>)">
                        <div class="contact-avatar <?php echo $is_online ? 'online' : ''; ?>">
                            <?php if (!empty($friend['avatar']) && file_exists($friend['avatar'])): ?>
                                <img src="<?php echo htmlspecialchars($friend['avatar']); ?>" alt="<?php echo htmlspecialchars($friend['username']); ?>">
                            <?php else: ?>
                                <?php echo strtoupper(substr($friend['username'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="contact-info">
                            <div class="contact-name-row">
                                <span class="contact-name"><?php echo htmlspecialchars($friend['username']); ?></span>
                                <span class="contact-id">#<?php echo $friend['user_id']; ?></span>
                            </div>
                            <div class="contact-email"><?php echo htmlspecialchars($friend['email']); ?></div>
                            <div class="contact-status">
                                <span class="status-dot <?php echo $is_online ? 'online' : 'offline'; ?>"></span>
                                <span class="status-text">
                                    <?php 
                                    if ($is_online) {
                                        echo 'ONLINE';
                                    } else {
                                        echo 'LAST SEEN ' . $last_seen;
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                        <div class="contact-actions">
                            <button class="contact-btn" onclick="event.stopPropagation(); openChat(<?php echo $friend['user_id']; ?>)" title="CHAT">💬</button>
                            
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">🎮</div>
                    <div class="empty-title">NO SQUAD YET</div>
                    <div class="empty-text">Add players using their ID to start gaming!</div>
                    <button class="action-btn" onclick="showAddFriendModal()">➕ ADD PLAYER</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Invite Modal -->
    <div class="modal" id="inviteModal">
        <div class="modal-content">
            <h3>INVITE PLAYER</h3>
            <p>Share your invite link or player ID</p>
            
            <div class="invite-link-box">
                <span class="invite-link" id="inviteLink">
                    <?php echo "http://" . $_SERVER['HTTP_HOST'] . "/connectxion/register.php?ref=" . $user_id; ?>
                </span>
                <button class="copy-btn" onclick="copyInviteLink()" title="COPY">📋</button>
            </div>
            
            <div class="user-id-box">
                <div class="user-id-label">YOUR PLAYER ID</div>
                <div class="user-id-value">#<?php echo $user_id; ?></div>
            </div>
            
            <div class="modal-actions">
                <button class="modal-btn secondary" onclick="hideInviteModal()">CLOSE</button>
            </div>
        </div>
    </div>
    
    <!-- Add Friend Modal -->
    <div class="modal" id="addFriendModal">
        <div class="modal-content" style="width: 500px;">
            <h3>FIND PLAYERS</h3>
            <p>Search for new comrades by name</p>
            
            <div class="search-input-wrapper">
                <input type="text" id="addFriendSearchInput" placeholder="TYPE PLAYER NAME..." autocomplete="off">
            </div>

            <div id="searchResults" class="search-results">
                <!-- Results will appear here -->
                <div style="padding: 40px; text-align: center; color: var(--text-muted);">
                    <div style="font-size: 40px; margin-bottom: 10px;">🔍</div>
                    TYPE AT LEAST 2 CHARACTERS TO SEARCH
                </div>
            </div>

            <div class="modal-actions" style="margin-top: 20px;">
                <button type="button" class="modal-btn secondary" onclick="hideAddFriendModal()">CLOSE</button>
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
    <form method="GET" action="logout.php" id="logoutForm" style="display: none;">
        <input type="hidden" name="logout" value="1">
    </form>
    
   <script>
    // Logout modal functions
    function showLogoutModal() {
        document.getElementById('logoutModal').classList.add('show');
    }
    
    function hideLogoutModal() {
        document.getElementById('logoutModal').classList.remove('show');
    }
    
    // Confirm logout function - submits the hidden form
    function confirmLogout() {
        document.getElementById('logoutForm').submit();
    }
    
    // Modal functions
    function showInviteModal() {
        document.getElementById('inviteModal').classList.add('show');
    }
    
    function hideInviteModal() {
        document.getElementById('inviteModal').classList.remove('show');
    }
    
    function showAddFriendModal() {
        document.getElementById('addFriendModal').classList.add('show');
    }
    
    function hideAddFriendModal() {
        document.getElementById('addFriendModal').classList.remove('show');
    }
    
    function copyInviteLink() {
        const link = document.getElementById('inviteLink').textContent;
        navigator.clipboard.writeText(link).then(() => {
            showToast('LINK COPIED!', 'success');
        });
    }
    
    // Request handling
    function acceptRequest(requestId) {
        window.location.href = 'accept.php?id=' + requestId;
    }
    
    function rejectRequest(requestId) {
        window.location.href = 'reject.php?id=' + requestId;
    }
    
    // Navigation
    function openChat(userId) {
        window.location.href = 'chat.php?id=' + userId;
    }
    
    // Search functionality
    document.getElementById('searchInput').addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const contactCards = document.querySelectorAll('.contact-card');
        
        contactCards.forEach(card => {
            const name = card.querySelector('.contact-name').textContent.toLowerCase();
            const email = card.querySelector('.contact-email').textContent.toLowerCase();
            const id = card.querySelector('.contact-id').textContent.toLowerCase();
            
            if (name.includes(searchTerm) || email.includes(searchTerm) || id.includes(searchTerm)) {
                card.style.display = 'flex';
            } else {
                card.style.display = 'none';
            }
        });
    });
    
    // Close modals when clicking outside
    window.addEventListener('click', function(e) {
        const inviteModal = document.getElementById('inviteModal');
        const addModal = document.getElementById('addFriendModal');
        const logoutModal = document.getElementById('logoutModal');
        
        if (e.target === inviteModal) {
            inviteModal.classList.remove('show');
        }
        if (e.target === addModal) {
            addModal.classList.remove('show');
        }
        if (e.target === logoutModal) {
            logoutModal.classList.remove('show');
        }
    });
    
    // --- ADVANCED PLAYER SEARCH LOGIC ---
    let searchTimeout;
    const searchInput = document.getElementById('addFriendSearchInput');
    const resultsContainer = document.getElementById('searchResults');

    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();

        if (query.length < 2) {
            resultsContainer.innerHTML = `
                <div style="padding: 40px; text-align: center; color: var(--text-muted);">
                    <div style="font-size: 40px; margin-bottom: 10px;">🔍</div>
                    TYPE AT LEAST 2 CHARACTERS TO SEARCH
                </div>
            `;
            return;
        }

        resultsContainer.innerHTML = '<div style="padding: 40px; text-align: center;"><div class="loader-progress" style="width: 100%; height: 2px;"></div><p style="margin-top:10px; font-size:12px;">SCANNING DATABASE...</p></div>';

        searchTimeout = setTimeout(() => {
            fetch(`search_players.php?query=${encodeURIComponent(query)}`)
            .then(res => res.json())
            .then(data => {
                console.log("SEARCH DATA:", data);
                if (data.success) {
                    renderSearchResults(data.results, query);
                } else {
                    resultsContainer.innerHTML = `<div style="padding: 20px; color: var(--danger); text-align: center;">ERROR: ${data.error}</div>`;
                }
            })
            .catch(err => {
                console.error("SEARCH ERROR:", err);
                resultsContainer.innerHTML = `<div style="padding: 20px; color: var(--danger); text-align: center;">CONNECTION ERROR</div>`;
            });
        }, 300);
    });

    function renderSearchResults(results, query) {
        if (results.length === 0) {
            resultsContainer.innerHTML = `
                <div style="padding: 40px; text-align: center; color: var(--text-muted);">
                    NO PLAYERS FOUND MATCHING "${query.toUpperCase()}"
                </div>
            `;
            return;
        }

        resultsContainer.innerHTML = '';
        results.forEach(player => {
            const card = document.createElement('div');
            card.className = 'result-card';
            
            let actionHtml = '';
            // Match exactly with backend 'friend', 'pending', 'none'
            if (player.status === 'friend' || player.is_friend) {
                actionHtml = `<button class="modal-btn secondary" disabled>FRIEND</button>`;
            } else if (player.status === 'pending') {
                actionHtml = `<button class="modal-btn secondary" disabled>PENDING</button>`;
            } else {
                actionHtml = `<button class="modal-btn primary" onclick="sendFriendRequest(${player.user_id}, '${player.username}', this)">ADD FRIEND</button>`;
            }

            const avatarHtml = player.avatar 
                ? `<img src="${player.avatar}" alt="${player.username}">`
                : player.username.charAt(0).toUpperCase();

            card.innerHTML = `
                <div class="result-avatar">${avatarHtml}</div>
                <div class="result-info">
                    <div class="result-name">${player.username}</div>
                    <div class="result-bio">${player.bio}</div>
                </div>
                <div class="result-action">${actionHtml}</div>
            `;
            resultsContainer.appendChild(card);
        });
    }

    window.sendFriendRequest = function(userId, username, btn) {
        const originalText = btn.innerText;
        btn.innerText = 'SENDING...';
        btn.disabled = true;

        const formData = new FormData();
        formData.append('friend_username', username);

        fetch('add_friend.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                btn.innerText = 'SENT!';
                btn.className = 'modal-btn secondary';
                showToast('🎮 FRIEND REQUEST SENT!', 'success');
            } else {
                btn.innerText = originalText;
                btn.disabled = false;
                showToast('❌ ' + data.error, 'error');
            }
        });
    }

    // Handle add friend form - (REMOVED LEGACY HANDLER)
    
    // Toast notification
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <span style="flex:1;">${message}</span>
            <button onclick="this.parentElement.remove()" style="background:none; border:none; color:white; cursor:pointer; font-size:18px;">✕</button>
        `;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 3000);
    }
    
    // Check for new friend requests periodically
    setInterval(() => {
        fetch('check_friend_requests.php')
        .then(response => response.json())
        .then(data => {
            if (data.has_new) {
                showToast('📨 NEW FRIEND REQUEST!', 'success');
                setTimeout(() => location.reload(), 2000);
            }
        });
    }, 10000);
    
    // Handle escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show').forEach(modal => {
                modal.classList.remove('show');
            });
        }
    });

    // Search functionality
    document.getElementById('searchInput')?.addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const contactCards = document.querySelectorAll('.contact-card');
        
        contactCards.forEach(card => {
            const name = card.querySelector('.contact-name').textContent.toLowerCase();
            if (name.includes(searchTerm)) {
                card.style.display = 'flex';
            } else {
                card.style.display = 'none';
            }
        });
    });

    // Real-time integration
    const HUB_CONFIG = {
        userId: <?php echo $user_id; ?>
    };
    </script>
    <script src="hub_script.js"></script>
    <script>
    // Override hub_script status update to handle contact-cards
    if (typeof socket !== 'undefined' && socket) {
        socket.on('user_status', (data) => {
            const contactCard = document.querySelector(`.contact-card[onclick="openChat(${data.userId})"]`);
            if (contactCard) {
                const dot = contactCard.querySelector('.status-dot');
                const text = contactCard.querySelector('.status-text');
                const avatar = contactCard.querySelector('.contact-avatar');
                
                if (data.status === 'online') {
                    if (dot) dot.className = 'status-dot online';
                    if (text) text.innerText = 'ONLINE';
                    if (avatar) avatar.classList.add('online');
                } else {
                    if (dot) dot.className = 'status-dot offline';
                    if (text) text.innerText = 'OFFLINE';
                    if (avatar) avatar.classList.remove('online');
                }
            }
        });
        
        socket.on('online_users_list', (onlineUserIds) => {
            onlineUserIds.forEach(id => {
                const contactCard = document.querySelector(`.contact-card[onclick="openChat(${id})"]`);
                if (contactCard) {
                    const dot = contactCard.querySelector('.status-dot');
                    const text = contactCard.querySelector('.status-text');
                    const avatar = contactCard.querySelector('.contact-avatar');
                    if (dot) dot.className = 'status-dot online';
                    if (text) text.innerText = 'ONLINE';
                    if (avatar) avatar.classList.add('online');
                }
            });
        });
    }
    </script>
</body>
</html>