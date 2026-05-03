<?php
// home.php - COMPLETE FIXED VERSION (ADD FRIEND REMOVED)
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Handle logout request
if (isset($_POST['logout']) && $_POST['logout'] == '1') {
    header("Location: logout.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if is_read column exists
$is_read_exists = false;
$column_check = $conn->query("SHOW COLUMNS FROM messages LIKE 'is_read'");
if ($column_check && $column_check->num_rows > 0) {
    $is_read_exists = true;
}

// Get unread messages count
$unread_counts = [];
if ($is_read_exists) {
    $unread_result = prepareAndExecute($conn, "
        SELECT sender_id, COUNT(*) as count 
        FROM messages 
        WHERE receiver_id = ? AND is_read = 0 
        GROUP BY sender_id
    ", "i", $user_id)->get_result();
    if ($unread_result) {
        while($row = $unread_result->fetch_assoc()) {
            $unread_counts[$row['sender_id']] = $row['count'];
        }
    }
}

// Get online friends
$last_active_exists = false;
$column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'last_active'");
if ($column_check && $column_check->num_rows > 0) {
    $last_active_exists = true;
}

$online_friends = [];
if ($last_active_exists) {
    $online_result = prepareAndExecute($conn, "
        SELECT friend_id 
        FROM friends f
        JOIN users u ON u.user_id = f.friend_id
        WHERE f.user_id = ? AND u.last_active > NOW() - INTERVAL 5 MINUTE
    ", "i", $user_id)->get_result();
    if ($online_result) {
        while($row = $online_result->fetch_assoc()) {
            $online_friends[] = $row['friend_id'];
        }
    }
}

// Get user data
$user_data = dbGetRow($conn, "SELECT * FROM users WHERE user_id = ?", "i", $user_id);

// Get all friends
$friends_result = prepareAndExecute($conn, "
    SELECT u.* 
    FROM friends f
    JOIN users u ON u.user_id = f.friend_id
    WHERE f.user_id = ?
    ORDER BY u.username ASC
", "i", $user_id)->get_result();

$friends_list = [];
if ($friends_result && $friends_result->num_rows > 0) {
    while($row = $friends_result->fetch_assoc()) {
        $friends_list[$row['user_id']] = $row;
    }
}

// Get last message for each friend
$last_messages = [];
$last_times = [];

if (!empty($friends_list)) {
    $friend_ids = array_keys($friends_list);
    $friend_ids_str = implode(',', $friend_ids);
    
    $messages_result = $conn->query("
        SELECT m.*, 
               CASE 
                   WHEN m.sender_id = $user_id THEN m.receiver_id 
                   ELSE m.sender_id 
               END as contact_id
        FROM messages m
        WHERE (m.sender_id = $user_id AND m.receiver_id IN ($friend_ids_str))
           OR (m.receiver_id = $user_id AND m.sender_id IN ($friend_ids_str))
        ORDER BY m.created_at DESC
    ");
    
    if ($messages_result) {
        $processed_contacts = [];
        while($row = $messages_result->fetch_assoc()) {
            $contact_id = $row['contact_id'];
            if (!isset($processed_contacts[$contact_id])) {
                $last_messages[$contact_id] = $row;
                $last_times[$contact_id] = $row['created_at'];
                $processed_contacts[$contact_id] = true;
            }
        }
    }
}

// Get counts for badges
$total_unread = array_sum($unread_counts);

$requests_row = dbGetRow($conn, "
    SELECT COUNT(*) as count FROM friend_requests 
    WHERE receiver_id = ? AND status = 'pending'
", "i", $user_id);
$requests_count = $requests_row['count'] ?? 0;

$missed_row = dbGetRow($conn, "
    SELECT COUNT(*) as count FROM calls 
    WHERE receiver_id = ? AND status = 'missed'
", "i", $user_id);
$missed_calls = $missed_row['count'] ?? 0;

// Helper functions
function getAvatarHtml($user, $size = 'medium', $online = false) {
    $avatarClass = 'avatar';
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

function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $time_ago = $timestamp;
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    $seconds = $time_difference;
    
    $minutes = round($seconds / 60);
    $hours = round($seconds / 3600);
    $days = round($seconds / 86400);
    $weeks = round($seconds / 604800);
    $months = round($seconds / 2629440);
    $years = round($seconds / 31553280);
    
    if ($seconds <= 60) {
        return "Just now";
    } else if ($minutes <= 60) {
        return $minutes . "m";
    } else if ($hours <= 24) {
        return $hours . "h";
    } else if ($days <= 7) {
        return $days . "d";
    } else if ($weeks <= 4.3) {
        return $weeks . "w";
    } else if ($months <= 12) {
        return $months . "mo";
    } else {
        return $years . "y";
    }
}

function getAvatarLetter($username) {
    return strtoupper(substr($username, 0, 1));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php includeResponsive(); ?>
    <title>CONNECTXION · GAMING HUB</title>
    <!-- PWA Support -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#ff4655">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <script src="pwa_manager.js"></script>
    <!-- Socket.IO Client -->
    <script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>
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

        :root {
            --bg-primary: #0a0c0f;
            --bg-secondary: #14181c;
            --bg-tertiary: #1e2329;
            --bg-card: #1a1f25;
            --bg-hover: #252b33;
            --text-primary: #ffffff;
            --text-secondary: #b0b7c2;
            --text-muted: #6e7a8a;
            --accent: #ff4655;
            --accent-secondary: #0ed3c7;
            --accent-glow: rgba(255, 70, 85, 0.3);
            --border: #2a313c;
            --success: #43b581;
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

        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-5px); }
            100% { transform: translateY(0px); }
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

        .app-container {
            display: flex;
            height: 100vh;
            background: var(--bg-primary);
            position: relative;
            overflow: hidden;
        }

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

        .chats-sidebar {
            width: 380px;
            background: var(--bg-secondary);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 10;
            box-shadow: 5px 0 30px rgba(0, 0, 0, 0.7);
        }

        .chats-header {
            padding: 24px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, var(--bg-tertiary) 0%, transparent 100%);
        }

        .chats-header h2 {
            font-size: 28px;
            color: var(--text-primary);
            margin-bottom: 20px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 2px;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
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
        }

        .search-box:focus-within {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
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
        }

        .search-box input::placeholder {
            color: var(--text-muted);
            text-transform: uppercase;
            font-size: 12px;
        }

        .quick-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .quick-btn {
            flex: 1;
            padding: 10px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-transform: uppercase;
            position: relative;
            overflow: hidden;
        }

        .quick-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.5s;
        }

        .quick-btn:hover::before {
            left: 100%;
        }

        .quick-btn:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: var(--glow-effect);
        }

        .requests-section {
            padding: 16px 20px;
            background: linear-gradient(145deg, var(--bg-card), var(--bg-tertiary));
            margin: 12px 16px;
            border-radius: 16px;
            border-left: 4px solid var(--accent);
            position: relative;
            overflow: hidden;
        }

        .requests-section::after {
            content: 'NEW';
            position: absolute;
            top: 5px;
            right: -20px;
            background: var(--accent);
            color: white;
            font-size: 10px;
            font-weight: bold;
            padding: 2px 20px;
            transform: rotate(45deg);
        }

        .requests-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .requests-header h4 {
            color: var(--text-primary);
            font-size: 14px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
        }

        .requests-header span {
            background: var(--accent);
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .request-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: var(--bg-secondary);
            border-radius: 12px;
            margin-bottom: 10px;
            border: 1px solid var(--border);
            transition: all 0.3s;
        }

        .request-item:hover {
            border-color: var(--accent);
            transform: translateX(5px);
            box-shadow: -5px 0 0 var(--accent);
        }

        .request-avatar {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 18px;
            overflow: hidden;
        }

        .request-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .request-info {
            flex: 1;
        }

        .request-name {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 15px;
            margin-bottom: 3px;
            text-transform: uppercase;
        }

        .request-meta {
            color: var(--text-muted);
            font-size: 11px;
        }

        .request-actions {
            display: flex;
            gap: 8px;
        }

        .request-btn {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
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

        .chats-list {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            scrollbar-width: thin;
            scrollbar-color: var(--accent) var(--bg-tertiary);
        }

        .chats-list::-webkit-scrollbar {
            width: 4px;
        }

        .chats-list::-webkit-scrollbar-track {
            background: var(--bg-tertiary);
        }

        .chats-list::-webkit-scrollbar-thumb {
            background: var(--accent);
            border-radius: 4px;
        }

        .chat-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px;
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 6px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .chat-item::before {
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

        .chat-item:hover::before,
        .chat-item.active::before {
            transform: scaleY(1);
        }

        .chat-item:hover {
            background: var(--bg-hover);
            border-color: var(--accent);
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
        }

        .chat-item.active {
            background: linear-gradient(90deg, rgba(255, 70, 85, 0.1), transparent);
            border-color: var(--accent);
        }

        .chat-avatar {
            width: 60px;
            height: 60px;
            border-radius: 14px;
            background: var(--gradient-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 24px;
            position: relative;
            flex-shrink: 0;
            overflow: hidden;
        }

        .chat-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .chat-avatar.online::after {
            content: '';
            position: absolute;
            bottom: 3px;
            right: 3px;
            width: 14px;
            height: 14px;
            background: var(--success);
            border: 2px solid var(--bg-secondary);
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }

        .chat-info {
            flex: 1;
            min-width: 0;
        }

        .chat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .chat-name {
            font-weight: 700;
            color: var(--text-primary);
            font-size: 16px;
            text-transform: uppercase;
        }

        .chat-time {
            font-size: 11px;
            color: var(--text-muted);
            background: var(--bg-tertiary);
            padding: 3px 8px;
            border-radius: 20px;
            border: 1px solid var(--border);
        }

        .chat-message {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .message-preview {
            font-size: 13px;
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 180px;
        }

        .message-status {
            color: var(--accent-secondary);
            font-size: 12px;
            margin-right: 5px;
        }

        .unread-badge {
            background: var(--accent);
            color: white;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            min-width: 22px;
            text-align: center;
            box-shadow: var(--glow-effect);
            animation: pulse 1.5s infinite;
        }

        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-primary);
            padding: 30px;
            position: relative;
            z-index: 1;
        }

        .main-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 50% 50%, rgba(255, 70, 85, 0.05) 0%, transparent 50%);
            pointer-events: none;
        }

        .welcome-screen {
            text-align: center;
            max-width: 600px;
            position: relative;
            z-index: 2;
        }

        .welcome-icon {
            width: 150px;
            height: 150px;
            background: var(--gradient-primary);
            border-radius: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            color: white;
            font-size: 70px;
            box-shadow: 0 20px 40px rgba(255, 70, 85, 0.3);
            position: relative;
            animation: float 3s ease-in-out infinite;
            overflow: hidden;
        }

        .welcome-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .welcome-screen h1 {
            font-size: 42px;
            color: var(--text-primary);
            margin-bottom: 15px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 3px;
            background: linear-gradient(135deg, #fff, var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .welcome-screen p {
            color: var(--text-secondary);
            margin-bottom: 40px;
            line-height: 1.8;
            font-size: 16px;
            text-transform: uppercase;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
            margin-top: 50px;
        }

        .feature-item {
            text-align: center;
            padding: 20px;
            background: var(--bg-card);
            border-radius: 20px;
            border: 1px solid var(--border);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .feature-item:hover {
            transform: translateY(-5px);
            border-color: var(--accent);
            box-shadow: 0 10px 30px rgba(255, 70, 85, 0.2);
        }

        .feature-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--gradient-primary);
            transform: scaleX(0);
            transition: transform 0.3s;
        }

        .feature-item:hover::before {
            transform: scaleX(1);
        }

        .feature-icon {
            width: 60px;
            height: 60px;
            background: var(--bg-tertiary);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: var(--accent);
            font-size: 28px;
            border: 1px solid var(--border);
        }

        .feature-title {
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
            font-size: 15px;
            text-transform: uppercase;
        }

        .feature-desc {
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .user-id-card {
            margin-top: 50px;
            padding: 20px;
            background: linear-gradient(145deg, var(--bg-card), var(--bg-tertiary));
            border-radius: 20px;
            border: 1px solid var(--border);
            display: inline-block;
            position: relative;
            overflow: hidden;
        }

        .user-id-card::before {
            content: 'PLAYER ID';
            position: absolute;
            top: 5px;
            right: 10px;
            color: var(--accent);
            font-size: 10px;
            font-weight: bold;
            opacity: 0.5;
        }

        .user-id-label {
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .user-id-value {
            color: var(--accent);
            font-size: 28px;
            font-weight: 900;
            font-family: monospace;
            text-shadow: 0 0 10px var(--accent-glow);
        }

        .profile-card {
            padding: 18px;
            background: linear-gradient(145deg, var(--bg-card), var(--bg-tertiary));
            border-radius: 16px;
            margin: 0 16px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            cursor: pointer;
            transition: all 0.3s;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .profile-card:hover {
            border-color: var(--accent-secondary);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.5);
        }

        .profile-avatar {
            width: 55px;
            height: 55px;
            border-radius: 12px;
            background: var(--gradient-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 22px;
            overflow: hidden;
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
            margin-bottom: 5px;
            font-size: 16px;
            text-transform: uppercase;
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
        }

        .modal-content input {
            width: 100%;
            padding: 15px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 16px;
            margin-bottom: 25px;
            outline: none;
            transition: all 0.3s;
        }

        .modal-content input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        .modal-actions {
            display: flex;
            gap: 15px;
        }

        .modal-btn {
            flex: 1;
            padding: 16px;
            border: none;
            border-radius: 16px;
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

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            padding: 16px 24px;
            border-radius: 14px;
            z-index: 9999;
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

        .empty-state {
            text-align: center;
            padding: 50px 20px;
        }

        .empty-state-icon {
            font-size: 80px;
            margin-bottom: 20px;
            filter: drop-shadow(0 0 20px var(--accent-glow));
            animation: float 3s ease-in-out infinite;
        }

        .empty-state h3 {
            color: var(--text-primary);
            margin-bottom: 10px;
            font-size: 20px;
            text-transform: uppercase;
        }

        .empty-state p {
            color: var(--text-muted);
            margin-bottom: 25px;
            font-size: 14px;
        }

        .empty-state-btn {
            display: inline-flex;
            padding: 12px 30px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: var(--glow-effect);
        }

        .empty-state-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255, 70, 85, 0.4);
        }

        /* Removed conflicting internal media query */
    </style>
</head>
<body>
    <div class="app-container is-hub">
        <!-- Left Navigation Sidebar -->
        <div class="nav-sidebar">
            <div class="logo">
                <img src="photos/logo.png" alt="CONNECTXION">
            </div>
            
            <div class="nav-item active" title="CHAT HUB" onclick="window.location.href='home.php'">
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
                <?php 
                $is_online = $last_active_exists && $user_data['last_active'] && (time() - strtotime($user_data['last_active']) < 300);
                echo getAvatarHtml($user_data, 'medium', $is_online);
                ?>
                
                <div class="logout-btn" title="LOGOUT" onclick="showLogoutModal()">
                    ⏻
                </div>
            </div>
        </div>
        
        <!-- Chats Sidebar -->
        <div class="chats-sidebar">
            <div class="chats-header">
                <h2>CHAT HUB</h2>
                <div class="search-box">
                    <span>🔍</span>
                    <input type="text" id="searchInput" placeholder="SEARCH PLAYERS...">
                </div>
                
                <div class="quick-actions">
                    <!-- REMOVED ADD FRIEND BUTTON -->
                    
                </div>
            </div>
          
            <!-- Friend Requests Section -->
            <?php
            $requests = $conn->query("
                SELECT fr.*, u.username, u.avatar 
                FROM friend_requests fr
                JOIN users u ON u.user_id = fr.sender_id
                WHERE fr.receiver_id = $user_id AND fr.status = 'pending'
                LIMIT 3
            ");
            
            if ($requests && $requests->num_rows > 0): ?>
            <div class="requests-section">
                <div class="requests-header">
                    <h4>
                        <span>🎮</span> SQUAD REQUESTS
                    </h4>
                    <span><?php echo $requests->num_rows; ?></span>
                </div>
                <?php while($req = $requests->fetch_assoc()): ?>
                <div class="request-item">
                    <div class="request-avatar">
                        <?php if (!empty($req['avatar']) && file_exists($req['avatar'])): ?>
                            <img src="<?php echo htmlspecialchars($req['avatar']); ?>" alt="<?php echo htmlspecialchars($req['username']); ?>">
                        <?php else: ?>
                            <?php echo strtoupper(substr($req['username'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="request-info">
                        <div class="request-name"><?php echo htmlspecialchars($req['username']); ?></div>
                        <div class="request-meta">ID: <?php echo $req['sender_id']; ?></div>
                    </div>
                    <div class="request-actions">
                        <button class="request-btn accept" onclick="acceptRequest(<?php echo $req['id']; ?>)" title="ACCEPT">✓</button>
                        <button class="request-btn reject" onclick="rejectRequest(<?php echo $req['id']; ?>)" title="REJECT">✗</button>
                    </div>
                </div>
                <?php endwhile; ?>
                
                <?php if ($requests_count > 3): ?>
                <div style="text-align: center; margin-top: 15px;">
                    <a href="friends.php" style="color: var(--accent); text-decoration: none; font-size: 12px; text-transform: uppercase;">VIEW ALL →</a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Chats List -->
            <div class="chats-list">
                <?php if (!empty($friends_list)): ?>
                    <?php 
                    $sorted_friends = $friends_list;
                    uasort($sorted_friends, function($a, $b) use ($last_times) {
                        $time_a = isset($last_times[$a['user_id']]) ? strtotime($last_times[$a['user_id']]) : 0;
                        $time_b = isset($last_times[$b['user_id']]) ? strtotime($last_times[$b['user_id']]) : 0;
                        return $time_b - $time_a;
                    });
                    
                    foreach($sorted_friends as $friend_id => $friend): 
                        $is_online = in_array($friend_id, $online_friends);
                        $unread = isset($unread_counts[$friend_id]) ? $unread_counts[$friend_id] : 0;
                        $last_time = isset($last_times[$friend_id]) ? $last_times[$friend_id] : '';
                        $formatted_time = $last_time ? timeAgo($last_time) : '';
                        
                        $last_message_preview = 'NO MESSAGES YET';
                        $last_message_type = 'text';
                        $is_outgoing = false;
                        
                        if (isset($last_messages[$friend_id])) {
                            $last_msg = $last_messages[$friend_id];
                            $last_message_type = isset($last_msg['message_type']) ? $last_msg['message_type'] : 'text';
                            $is_outgoing = ($last_msg['sender_id'] == $user_id);
                            
                            if ($last_message_type == 'image') {
                                $last_message_preview = '📷 PHOTO';
                            } elseif ($last_message_type == 'file') {
                                $last_message_preview = '📎 FILE';
                            } elseif ($last_message_type == 'voice') {
                                $last_message_preview = '🎤 VOICE';
                            } elseif (isset($last_msg['message']) && !empty($last_msg['message'])) {
                                $last_message_preview = substr($last_msg['message'], 0, 25) . (strlen($last_msg['message']) > 25 ? '...' : '');
                            }
                        }
                    ?>
                    <div class="chat-item <?php echo $unread > 0 ? 'active' : ''; ?>" onclick="openChat(<?php echo $friend_id; ?>)">
                        <div class="chat-avatar <?php echo $is_online ? 'online' : ''; ?>">
                            <?php if (!empty($friend['avatar']) && file_exists($friend['avatar'])): ?>
                                <img src="<?php echo htmlspecialchars($friend['avatar']); ?>" alt="<?php echo htmlspecialchars($friend['username']); ?>">
                            <?php else: ?>
                                <?php echo strtoupper(substr($friend['username'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="chat-info">
                            <div class="chat-row">
                                <span class="chat-name"><?php echo htmlspecialchars($friend['username']); ?></span>
                                <span class="chat-time"><?php echo $formatted_time; ?></span>
                            </div>
                            <div class="chat-message">
                                <span class="message-preview">
                                    <?php if ($is_outgoing): ?>
                                    <span class="message-status">✓✓</span>
                                    <?php endif; ?>
                                    <?php echo strtoupper($last_message_preview); ?>
                                </span>
                                <?php if ($unread > 0): ?>
                                <span class="unread-badge"><?php echo $unread; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">🎮</div>
                        <h3>NO SQUAD YET</h3>
                        <p>Add players using their ID in the SQUAD page!</p>
                        <button class="empty-state-btn" onclick="window.location.href='friends.php'">
                            GO TO SQUAD
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="welcome-screen">
                <div class="welcome-icon">
                    <img src="photos/logo.png" alt="CONNECTXION">
                </div>
                <h1>CONNECTXION</h1>
                <p>GAMING EDITION · WHERE PLAYERS CONNECT</p>
                
                <div class="features-grid">
                    <div class="feature-item">
                        <div class="feature-icon">🔒</div>
                        <div class="feature-title">SECURE</div>
                        <div class="feature-desc">End-to-end encrypted</div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">⚡</div>
                        <div class="feature-title">LIGHTNING</div>
                        <div class="feature-desc">Real-time gaming chat</div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">🌐</div>
                        <div class="feature-title">CROSS-PLATFORM</div>
                        <div class="feature-desc">Connect anywhere</div>
                    </div>
                </div>
                
                <div class="user-id-card">
                    <div class="user-id-label">YOUR PLAYER ID</div>
                    <div class="user-id-value">#<?php echo $user_id; ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- REMOVED ADD FRIEND MODAL -->
    
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
    
    function confirmLogout() {
        document.getElementById('logoutForm').submit();
    }
    
    // Request handling
    function acceptRequest(requestId) {
        if (confirm('Accept this friend request?')) {
            window.location.href = 'accept.php?id=' + requestId;
        }
    }
    
    function rejectRequest(requestId) {
        if (confirm('Reject this friend request?')) {
            window.location.href = 'reject.php?id=' + requestId;
        }
    }
    
    // Open chat
    function openChat(userId) {
        window.location.href = 'chat.php?id=' + userId;
    }
    
    // Search functionality
    document.getElementById('searchInput').addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const chatItems = document.querySelectorAll('.chat-item');
        
        chatItems.forEach(item => {
            const name = item.querySelector('.chat-name').textContent.toLowerCase();
            if (name.includes(searchTerm)) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    });
    
    // Close modals when clicking outside
    window.addEventListener('click', function(e) {
        const logoutModal = document.getElementById('logoutModal');
        if (e.target === logoutModal) {
            logoutModal.classList.remove('show');
        }
    });
    
    // REMOVED ADD FRIEND FORM HANDLER
    
    // Check for new messages (fallback if API is not available)
    setInterval(() => {
        fetch('check_new_messages.php')
        .then(response => response.json())
        .then(data => {
            if (data.has_new) {
                location.reload();
            }
        })
        .catch(err => console.error('Error checking messages:', err));
    }, 5000);
    
    // Gaming hover effect
    const buttons = document.querySelectorAll('.nav-item, .quick-btn, .chat-item, .request-btn');
    buttons.forEach(btn => {
        btn.addEventListener('mouseenter', () => {
            btn.style.transform = 'scale(1.02)';
        });
        btn.addEventListener('mouseleave', () => {
            btn.style.transform = 'scale(1)';
        });
    });

    // Handle escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show').forEach(modal => {
                modal.classList.remove('show');
            });
        }
    });
    </script>

    <!-- Real-time Hub -->
    <script>
        const HUB_CONFIG = {
            userId: <?php echo $user_id; ?>
        };
    </script>
    <script src="hub_script.js"></script>
</body>
</html>