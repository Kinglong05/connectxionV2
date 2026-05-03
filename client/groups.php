<?php
require_once 'db.php';
requireLogin();

$user_id = $_SESSION['user_id'];

// Get user data for profile with avatar
$user_data = $conn->query("SELECT * FROM users WHERE user_id = $user_id")->fetch_assoc();

// Get all groups user is a member of
$groups_query = "
    SELECT cr.*, 
           u.username as creator_name,
           u.avatar as creator_avatar,
           (SELECT COUNT(*) FROM chat_room_members WHERE room_id = cr.id) as member_count,
           (SELECT message FROM group_messages 
            WHERE room_id = cr.id 
            ORDER BY created_at DESC LIMIT 1) as last_message,
           (SELECT created_at FROM group_messages 
            WHERE room_id = cr.id 
            ORDER BY created_at DESC LIMIT 1) as last_message_time,
           (SELECT role FROM chat_room_members 
            WHERE room_id = cr.id AND user_id = $user_id) as user_role
    FROM chat_rooms cr
    JOIN chat_room_members crm ON crm.room_id = cr.id
    JOIN users u ON u.user_id = cr.created_by
    WHERE crm.user_id = $user_id
    ORDER BY 
        CASE 
            WHEN (SELECT created_at FROM group_messages WHERE room_id = cr.id ORDER BY created_at DESC LIMIT 1) IS NULL 
            THEN cr.created_at 
            ELSE (SELECT created_at FROM group_messages WHERE room_id = cr.id ORDER BY created_at DESC LIMIT 1)
        END DESC
";

$groups_result = $conn->query($groups_query);

// Get total unread count for badge
$unread_result = $conn->query("
    SELECT COUNT(*) as count FROM messages 
    WHERE receiver_id = $user_id AND is_read = 0
");
$total_unread = $unread_result->fetch_assoc()['count'] ?? 0;

// Get friend requests count
$requests_count = $conn->query("
    SELECT COUNT(*) as count FROM friend_requests 
    WHERE receiver_id = $user_id AND status = 'pending'
")->fetch_assoc()['count'] ?? 0;

// Helper function for avatar display
function getAvatarHtml($user, $size = 'medium')
{
    $avatarClass = 'avatar';
    if ($size == 'small') {
        $avatarClass .= ' small';
    } elseif ($size == 'large') {
        $avatarClass .= ' large';
    }

    if (!empty($user['avatar']) && file_exists($user['avatar'])) {
        return '<div class="' . $avatarClass . '"><img src="' . htmlspecialchars($user['avatar']) . '" alt="' . htmlspecialchars($user['username']) . '"></div>';
    } else {
        return '<div class="' . $avatarClass . '">' . strtoupper(substr($user['username'], 0, 1)) . '</div>';
    }
}

function getGroupAvatarHtml($groupName, $avatarPath = null)
{
    if (!empty($avatarPath) && file_exists($avatarPath)) {
        return '<img src="' . htmlspecialchars($avatarPath) . '" alt="' . htmlspecialchars($groupName) . '" style="width:100%; height:100%; object-fit:cover;">';
    } else {
        return strtoupper(substr($groupName, 0, 1));
    }
}

function getAvatarLetter($username)
{
    return strtoupper(substr($username, 0, 1));
}

function timeAgo($timestamp)
{
    if (!$timestamp)
        return '';

    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60)
        return 'now';
    if ($diff < 3600)
        return floor($diff / 60) . 'm';
    if ($diff < 86400)
        return floor($diff / 3600) . 'h';
    if ($diff < 604800)
        return floor($diff / 86400) . 'd';
    return date('M d', $time);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <?php includeResponsive(); ?>
    <title>My Groups · CONNECTXION GAMING</title>
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
            0% {
                opacity: 1;
                transform: scale(1);
            }

            50% {
                opacity: 0.8;
                transform: scale(1.05);
            }

            100% {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes float {
            0% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-5px);
            }

            100% {
                transform: translateY(0px);
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
            background: repeating-linear-gradient(0deg,
                    rgba(0, 0, 0, 0.15) 0px,
                    rgba(0, 0, 0, 0.15) 1px,
                    transparent 1px,
                    transparent 2px);
            pointer-events: none;
            z-index: 5;
        }

        /* Left Sidebar */
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
            transform: rotate(45deg);
            box-shadow: var(--glow-effect);
            animation: float 3s ease-in-out infinite;
            overflow: hidden;
            padding: 0;
        }

        .logo span {
            transform: rotate(-45deg);
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

        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--bg-primary);
            overflow: hidden;
        }

        .profile-card {
            padding: 16px 20px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 14px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .profile-card:hover {
            background: var(--bg-tertiary);
        }

        .profile-avatar {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--gradient-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 20px;
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
            margin-bottom: 4px;
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

        .header-actions {
            display: flex;
            gap: 15px;
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
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: var(--glow-effect);
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px var(--accent-glow);
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
            width: 300px;
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

        .groups-container {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
        }

        .groups-container::-webkit-scrollbar {
            width: 4px;
        }

        .groups-container::-webkit-scrollbar-track {
            background: var(--bg-tertiary);
        }

        .groups-container::-webkit-scrollbar-thumb {
            background: var(--accent);
            border-radius: 4px;
        }

        .groups-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .group-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .group-card::before {
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

        .group-card:hover::before {
            transform: scaleY(1);
        }

        .group-card:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        }

        .group-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .group-avatar {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            font-weight: bold;
            overflow: hidden;
        }

        .group-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .group-info {
            flex: 1;
        }

        .group-name {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 5px;
            text-transform: uppercase;
        }

        .group-meta {
            display: flex;
            gap: 10px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .group-meta span {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .group-badge {
            background: var(--bg-tertiary);
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            color: var(--accent-secondary);
        }

        .group-description {
            color: var(--text-secondary);
            font-size: 13px;
            margin-bottom: 15px;
            line-height: 1.5;
            max-height: 40px;
            overflow: hidden;
        }

        .group-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border);
        }

        .group-last-message {
            flex: 1;
            font-size: 12px;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .group-time {
            font-size: 11px;
            color: var(--text-muted);
        }

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
        }

        .empty-text {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 30px;
        }

        /* Modal */
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
            max-width: 500px;
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
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 14px 18px;
            background: var(--bg-card);
            border: 2px solid var(--border);
            border-radius: 14px;
            font-size: 15px;
            color: var(--text-primary);
            transition: all 0.3s;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-glow);
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: var(--accent);
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 25px;
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
        }

        .modal-btn.primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--glow-effect);
        }

        .modal-btn.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px var(--accent-glow);
        }

        .modal-btn.secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }

        .modal-btn.secondary:hover {
            background: var(--bg-hover);
            border-color: var(--accent);
        }

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

        @media (max-width: 768px) {
            .nav-sidebar {
                width: 70px;
            }

            .content-header {
                flex-direction: column;
                gap: 15px;
            }

            .search-box {
                width: 100%;
            }

            .groups-grid {
                grid-template-columns: 1fr;
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

            <div class="nav-item" title="CHAT HUB" onclick="window.location.href='home.php'">
                💬
                <?php if ($total_unread > 0): ?>
                    <span class="nav-badge"><?php echo min($total_unread, 99); ?></span>
                <?php endif; ?>
            </div>

            <div class="nav-item" title="SQUAD" onclick="window.location.href='friends.php'">
                👥
                <?php if ($requests_count > 0): ?>
                    <span class="nav-badge"><?php echo min($requests_count, 99); ?></span>
                <?php endif; ?>
            </div>

            <div class="nav-item active" title="GROUPS">
                👪
            </div>

            <div class="nav-item" title="SETTINGS" onclick="window.location.href='settings.php'">
                ⚙️
            </div>

            <div class="nav-footer">
                <div class="avatar" onclick="window.location.href='profile.php'">
                    <?php if (!empty($user_data['avatar']) && file_exists($user_data['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($user_data['avatar']); ?>"
                            alt="<?php echo htmlspecialchars($user_data['username']); ?>">
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
            <div class="profile-card" onclick="window.location.href='profile.php'">
                <div class="profile-avatar">
                    <?php if (!empty($user_data['avatar']) && file_exists($user_data['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($user_data['avatar']); ?>"
                            alt="<?php echo htmlspecialchars($user_data['username']); ?>">
                    <?php else: ?>
                        <?php echo getAvatarLetter($user_data['username']); ?>
                    <?php endif; ?>
                </div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($user_data['username']); ?></div>
                    <div class="profile-status">
                        <span class="profile-status-dot"></span>
                        ONLINE
                    </div>
                </div>
                <div style="color: var(--accent-secondary); font-size: 22px;">▶</div>
            </div>

            <div class="content-header">
                <h1>MY GROUPS</h1>
                <div class="header-actions">
                    <div class="search-box">
                        <span>🔍</span>
                        <input type="text" id="searchInput" placeholder="SEARCH GROUPS...">
                    </div>
                    <button class="action-btn" onclick="showCreateGroupModal()">
                        <span>➕</span> CREATE GROUP
                    </button>
                </div>
            </div>

            <div class="groups-container">
                <?php if ($groups_result && $groups_result->num_rows > 0): ?>
                    <div class="groups-grid" id="groupsGrid">
                        <?php while ($group = $groups_result->fetch_assoc()):
                            $last_message = $group['last_message'] ?? 'No messages yet';
                            $last_message = strlen($last_message) > 30 ? substr($last_message, 0, 30) . '...' : $last_message;
                            $last_time = $group['last_message_time'] ? timeAgo($group['last_message_time']) : '';
                            $is_admin = ($group['user_role'] == 'admin');
                            ?>
                            <div class="group-card" onclick="openGroup(<?php echo $group['id']; ?>)">
                                <div class="group-header">
                                    <div class="group-avatar">
                                        <?php if (!empty($group['group_avatar']) && file_exists($group['group_avatar'])): ?>
                                            <img src="<?php echo htmlspecialchars($group['group_avatar']); ?>"
                                                alt="<?php echo htmlspecialchars($group['room_name']); ?>">
                                        <?php else: ?>
                                            <?php echo getAvatarLetter($group['room_name']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="group-info">
                                        <div class="group-name"><?php echo htmlspecialchars($group['room_name']); ?></div>
                                        <div class="group-meta">
                                            <span>👥 <?php echo $group['member_count']; ?> members</span>
                                            <?php if ($is_admin): ?>
                                                <span class="group-badge">HOST</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!empty($group['room_description'])): ?>
                                    <div class="group-description">
                                        <?php echo htmlspecialchars($group['room_description']); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="group-footer">
                                    <div class="group-last-message">
                                        💬 <?php echo htmlspecialchars($last_message); ?>
                                    </div>
                                    <?php if ($last_time): ?>
                                        <div class="group-time"><?php echo $last_time; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">👪</div>
                        <div class="empty-title">NO GROUPS YET</div>
                        <div class="empty-text">Create a group to chat with multiple friends at once!</div>
                        <button class="action-btn" onclick="showCreateGroupModal()">CREATE GROUP</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Create Group Modal -->
    <div class="modal" id="createGroupModal">
        <div class="modal-content">
            <h3>CREATE GROUP</h3>
            <p>Start a new squad chat</p>

            <form id="createGroupForm">
                <div class="form-group">
                    <label>GROUP NAME</label>
                    <input type="text" name="room_name" placeholder="Enter group name" required maxlength="100">
                </div>

                <div class="form-group">
                    <label>DESCRIPTION (OPTIONAL)</label>
                    <textarea name="room_description" placeholder="What's this group about?"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>MAX MEMBERS</label>
                        <select name="max_members">
                            <option value="10">10 members</option>
                            <option value="25">25 members</option>
                            <option value="50" selected>50 members</option>
                            <option value="100">100 members</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>GROUP TYPE</label>
                        <select name="is_private">
                            <option value="0">Public</option>
                            <option value="1">Private</option>
                        </select>
                    </div>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="private_group" name="is_private" value="1">
                    <label for="private_group">Make this group private (only invited members can join)</label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="modal-btn secondary" onclick="hideCreateGroupModal()">CANCEL</button>
                    <button type="submit" class="modal-btn primary">CREATE GROUP</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Logout Modal -->
    <div class="modal" id="logoutModal">
        <div class="modal-content">
            <h3>EXIT GAME?</h3>
            <p>Are you sure you want to logout?</p>
            <div class="modal-actions">
                <button class="modal-btn secondary" onclick="hideLogoutModal()">CANCEL</button>
                <button class="modal-btn danger" onclick="confirmLogout()">LOGOUT</button>
            </div>
        </div>
    </div>

    <!-- Hidden Logout Form -->
    <form method="GET" action="logout.php" id="logoutForm" style="display: none;">
        <input type="hidden" name="logout" value="1">
    </form>

    <script>
        // Group functions
        function showCreateGroupModal() {
            document.getElementById('createGroupModal').classList.add('show');
        }

        function hideCreateGroupModal() {
            document.getElementById('createGroupModal').classList.remove('show');
        }

        function openGroup(roomId) {
            window.location.href = 'group_chat.php?room_id=' + roomId;
        }

        // Create group form submission
        document.getElementById('createGroupForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const formData = new FormData(this);

            fetch('create_group.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        hideCreateGroupModal();
                        showToast('✅ GROUP CREATED SUCCESSFULLY!', 'success');
                        setTimeout(() => {
                            window.location.href = 'group_chat.php?room_id=' + data.room_id;
                        }, 1500);
                    } else {
                        showToast('❌ ' + data.error, 'error');
                    }
                })
                .catch(err => {
                    console.error('Error:', err);
                    showToast('❌ Failed to create group', 'error');
                });
        });

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function (e) {
            const searchTerm = e.target.value.toLowerCase();
            const groupCards = document.querySelectorAll('.group-card');

            groupCards.forEach(card => {
                const name = card.querySelector('.group-name').textContent.toLowerCase();
                const desc = card.querySelector('.group-description')?.textContent.toLowerCase() || '';

                if (name.includes(searchTerm) || desc.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Logout functions
        function showLogoutModal() {
            document.getElementById('logoutModal').classList.add('show');
        }

        function hideLogoutModal() {
            document.getElementById('logoutModal').classList.remove('show');
        }

        function confirmLogout() {
            document.getElementById('logoutForm').submit();
        }

        // Toast notification
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
            <span style="flex:1;">${message}</span>
            <button onclick="this.parentElement.remove()" style="background:none; border:none; color:white; cursor:pointer; font-size:18px;">✕</button>
        `;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        // Close modals when clicking outside
        window.addEventListener('click', function (e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('show');
            }
        });

        // Handle escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.show').forEach(modal => {
                    modal.classList.remove('show');
                });
            }
        });

        // NEW ADDITIONS: Real-time group message updates

        // Helper function to update group preview with latest message
        function updateGroupPreview(roomId, message, username) {
            const groupCard = document.querySelector(`.group-card[data-room-id="${roomId}"]`);
            if (groupCard) {
                const previewElement = groupCard.querySelector('.group-preview');
                if (previewElement) {
                    const truncatedMsg = message.length > 40 ? message.substring(0, 37) + '...' : message;
                    previewElement.innerHTML = `<strong>${username}:</strong> ${truncatedMsg}`;
                }

                // Add animation to indicate new message
                groupCard.classList.add('new-message-flash');
                setTimeout(() => {
                    groupCard.classList.remove('new-message-flash');
                }, 1000);
            }
        }

        // Listen for new group messages if API exists
        if (typeof api !== 'undefined' && api.on) {
            // Listen for new group messages
            api.on('new_group_messages', (messages) => {
                messages.forEach(msg => {
                    // Update group preview in list
                    updateGroupPreview(msg.room_id, msg.message, msg.username);

                    // Show notification if not in that group
                    if (!window.location.href.includes('group_chat.php') ||
                        window.currentRoomId !== msg.room_id) {
                        showToast(`💬 New message in group from ${msg.username}`, 'info');
                    }
                });
            });

            // Keep online status updated
            setInterval(() => {
                api.ping();
            }, 30000);

            // Optional: Listen for group member updates
            api.on('group_members_updated', (data) => {
                const groupCard = document.querySelector(`.group-card[data-room-id="${data.room_id}"]`);
                if (groupCard) {
                    const memberCountElement = groupCard.querySelector('.member-count');
                    if (memberCountElement) {
                        memberCountElement.textContent = `${data.member_count} members`;
                    }
                }
            });
        }

        // Initialize group cards with data attributes and set current room ID if on group chat page
        function initializeGroupCards() {
            const groupCards = document.querySelectorAll('.group-card');
            groupCards.forEach(card => {
                // Add data attribute if not present
                const openBtn = card.querySelector('[onclick*="openGroup"]');
                if (openBtn) {
                    const onclickAttr = openBtn.getAttribute('onclick');
                    const match = onclickAttr.match(/openGroup\(['"]?(\d+)['"]?\)/);
                    if (match && match[1]) {
                        card.setAttribute('data-room-id', match[1]);
                    }
                }
            });

            // Set current room ID if on group chat page
            if (window.location.href.includes('group_chat.php')) {
                const urlParams = new URLSearchParams(window.location.search);
                const roomId = urlParams.get('room_id');
                if (roomId) {
                    window.currentRoomId = roomId;
                }
            }
        }

        // Add CSS animation for new messages
        const style = document.createElement('style');
        style.textContent = `
        .new-message-flash {
            animation: flashMessage 0.5s ease-in-out;
        }
        
        @keyframes flashMessage {
            0%, 100% { background-color: transparent; }
            50% { background-color: rgba(0, 123, 255, 0.2); }
        }
        
        .group-preview {
            font-size: 0.9em;
            color: #666;
            margin-top: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .group-card {
            transition: all 0.3s ease;
        }
    `;
        document.head.appendChild(style);

        // Run initialization when DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeGroupCards);
        } else {
            initializeGroupCards();
        }
    </script>

</body>

</html>