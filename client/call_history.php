<?php
require_once 'db.php';
requireLogin();

$user_id = $_SESSION['user_id'];

// Get user data for profile
$user_data = $conn->query("SELECT * FROM users WHERE user_id = $user_id")->fetch_assoc();

$calls = $conn->prepare("
    SELECT c.*, 
           caller.username as caller_name,
           receiver.username as receiver_name
    FROM calls c
    JOIN users caller ON caller.user_id = c.caller_id
    JOIN users receiver ON receiver.user_id = c.receiver_id
    WHERE c.caller_id = ? OR c.receiver_id = ?
    ORDER BY c.created_at DESC
");
$calls->bind_param("ii", $user_id, $user_id);
$calls->execute();
$calls_result = $calls->get_result();

$missed_calls_count = $conn->prepare("
    SELECT COUNT(*) as count FROM calls 
    WHERE receiver_id = ? AND status = 'missed'
");
$missed_calls_count->bind_param("i", $user_id);
$missed_calls_count->execute();
$missed_result = $missed_calls_count->get_result();
$missed_calls = $missed_result->fetch_assoc()['count'] ?? 0;
$missed_calls_count->close();

$unread_result = $conn->prepare("
    SELECT COUNT(*) as count FROM messages 
    WHERE receiver_id = ? AND is_read = 0
");
$unread_result->bind_param("i", $user_id);
$unread_result->execute();
$unread_data = $unread_result->get_result();
$total_unread = $unread_data->fetch_assoc()['count'] ?? 0;
$unread_result->close();

$requests_count = $conn->prepare("
    SELECT COUNT(*) as count FROM friend_requests 
    WHERE receiver_id = ? AND status = 'pending'
");
$requests_count->bind_param("i", $user_id);
$requests_count->execute();
$requests_data = $requests_count->get_result();
$friend_requests = $requests_data->fetch_assoc()['count'] ?? 0;
$requests_count->close();

function formatDuration($start, $end) {
    if (!$start || !$end) return '';
    
    $start_ts = strtotime($start);
    $end_ts = strtotime($end);
    $diff = $end_ts - $start_ts;
    
    if ($diff < 60) {
        return $diff . ' sec';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        $secs = $diff % 60;
        return $mins . ':' . str_pad($secs, 2, '0', STR_PAD_LEFT);
    } else {
        $hours = floor($diff / 3600);
        $mins = floor(($diff % 3600) / 60);
        return $hours . 'h ' . $mins . 'm';
    }
}

function timeAgo($timestamp) {
    if (!$timestamp) return '';
    
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d, Y', $time);
}

function getAvatarLetter($username) {
    return strtoupper(substr($username, 0, 1));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Call History · CONNECTXION GAMING</title>
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
            --warning: #faa61a;
            --danger: #f04747;
            --gradient-primary: linear-gradient(135deg, #ff4655, #ff7b72);
            --gradient-secondary: linear-gradient(135deg, #0ed3c7, #10b3aa);
            --glow-effect: 0 0 15px var(--accent-glow);
            
            --call-answered: #43b581;
            --call-missed: #f04747;
            --call-outgoing: #ff4655;
            --call-incoming: #0ed3c7;
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

        .filter-buttons {
            display: flex;
            gap: 8px;
            background: var(--bg-tertiary);
            padding: 4px;
            border-radius: 12px;
        }

        .filter-btn {
            padding: 8px 16px;
            background: transparent;
            border: none;
            border-radius: 10px;
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-transform: uppercase;
        }

        .filter-btn:hover {
            color: var(--text-primary);
        }

        .filter-btn.active {
            background: var(--accent);
            color: white;
        }

        .action-btn {
            padding: 10px 20px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 13px;
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

        .calls-container {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
        }

        .calls-container::-webkit-scrollbar {
            width: 4px;
        }

        .calls-container::-webkit-scrollbar-track {
            background: var(--bg-tertiary);
        }

        .calls-container::-webkit-scrollbar-thumb {
            background: var(--accent);
            border-radius: 4px;
        }

        .date-separator {
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 25px 0 15px;
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .date-separator::before,
        .date-separator::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .calls-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .call-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px 20px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .call-item::before {
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

        .call-item:hover::before {
            transform: scaleY(1);
        }

        .call-item:hover {
            border-color: var(--accent);
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        .call-item.missed {
            border-left: 4px solid var(--call-missed);
        }

        .call-item.answered {
            border-left: 4px solid var(--call-answered);
        }

        .call-avatar {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
            position: relative;
            flex-shrink: 0;
        }

        .call-icon-badge {
            position: absolute;
            bottom: -4px;
            right: -4px;
            width: 24px;
            height: 24px;
            border-radius: 8px;
            background: var(--bg-tertiary);
            border: 2px solid var(--bg-card);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }

        .call-icon-badge.outgoing {
            background: var(--call-outgoing);
            color: white;
        }

        .call-icon-badge.incoming {
            background: var(--call-incoming);
            color: white;
        }

        .call-icon-badge.missed {
            background: var(--call-missed);
            color: white;
        }

        .call-info {
            flex: 1;
        }

        .call-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }

        .call-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            text-transform: uppercase;
        }

        .call-time {
            font-size: 11px;
            color: var(--text-muted);
        }

        .call-details {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .call-type {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .call-direction {
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 20px;
            background: var(--bg-tertiary);
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 600;
        }

        .call-direction.outgoing {
            background: rgba(255, 70, 85, 0.2);
            color: var(--accent);
        }

        .call-direction.incoming {
            background: rgba(14, 211, 199, 0.2);
            color: var(--accent-secondary);
        }

        .call-direction.missed {
            background: rgba(240, 71, 71, 0.2);
            color: var(--danger);
        }

        .call-duration {
            font-size: 12px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .call-actions {
            display: flex;
            gap: 8px;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .call-item:hover .call-actions {
            opacity: 1;
        }

        .call-action-btn {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: none;
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .call-action-btn:hover {
            background: var(--accent);
            color: white;
            transform: scale(1.1);
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
            letter-spacing: 2px;
        }

        .empty-text {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 30px;
            text-transform: uppercase;
        }

        @media (max-width: 768px) {
            .nav-sidebar {
                width: 70px;
            }
            
            .content-header {
                flex-direction: column;
                gap: 15px;
                padding: 15px;
            }
            
            .filter-buttons {
                width: 100%;
                overflow-x: auto;
            }
            
            .calls-container {
                padding: 15px;
            }
            
            .call-actions {
                opacity: 1;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
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
                <?php if ($friend_requests > 0): ?>
                <span class="nav-badge"><?php echo min($friend_requests, 99); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="nav-item active" title="CALLS">
                📞
                <?php if ($missed_calls > 0): ?>
                <span class="nav-badge"><?php echo min($missed_calls, 99); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="nav-item" title="SETTINGS" onclick="window.location.href='settings.php'">
                ⚙️
            </div>
            
            <div class="nav-footer">
                <div class="avatar <?php echo (isset($user_data['last_active']) && $user_data['last_active'] && (time() - strtotime($user_data['last_active']) < 300)) ? 'online' : ''; ?>" onclick="window.location.href='profile.php'">
                    <?php echo getAvatarLetter($user_data['username']); ?>
                </div>
                
                <div class="logout-btn" title="LOGOUT" onclick="showLogoutModal()">
                    ⏻
                </div>
            </div>
        </div>
        
        <div class="main-content">
            <div class="profile-card" onclick="window.location.href='profile.php'">
                <div class="profile-avatar">
                    <?php echo getAvatarLetter($user_data['username']); ?>
                </div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($user_data['username']); ?></div>
                    <div class="profile-status">
                        <span class="profile-status-dot"></span>
                        <?php echo (isset($user_data['last_active']) && $user_data['last_active'] && (time() - strtotime($user_data['last_active']) < 300)) ? 'ONLINE' : 'OFFLINE'; ?>
                    </div>
                </div>
                <div style="color: var(--accent-secondary); font-size: 22px;">▶</div>
            </div>
            
            <div class="content-header">
                <h1>CALL HISTORY</h1>
                <div class="header-actions">
                    <div class="filter-buttons">
                        <button class="filter-btn active" onclick="filterCalls('all')">ALL</button>
                        <button class="filter-btn" onclick="filterCalls('missed')">MISSED</button>
                        <button class="filter-btn" onclick="filterCalls('outgoing')">OUTGOING</button>
                        <button class="filter-btn" onclick="filterCalls('incoming')">INCOMING</button>
                    </div>
                    <a href="friends.php" class="action-btn">
                        <span>📞</span> NEW CALL
                    </a>
                </div>
            </div>
            
            <div class="calls-container" id="callsContainer">
                <?php if ($calls_result && $calls_result->num_rows > 0): ?>
                    <?php 
                    $current_date = '';
                    while($call = $calls_result->fetch_assoc()): 
                        $is_outgoing = ($call['caller_id'] == $user_id);
                        $other_user = $is_outgoing ? $call['receiver_name'] : $call['caller_name'];
                        $call_icon = $call['call_type'] == 'video' ? '📹' : '📞';
                        $call_date = date('Y-m-d', strtotime($call['created_at']));
                        $direction = $is_outgoing ? 'outgoing' : 'incoming';
                        
                        $display_date = '';
                        if ($call_date != $current_date) {
                            $current_date = $call_date;
                            $today = date('Y-m-d');
                            $yesterday = date('Y-m-d', strtotime('-1 day'));
                            
                            if ($call_date == $today) {
                                $display_date = 'TODAY';
                            } elseif ($call_date == $yesterday) {
                                $display_date = 'YESTERDAY';
                            } else {
                                $display_date = strtoupper(date('F j, Y', strtotime($call['created_at'])));
                            }
                        }
                        
                        $duration = formatDuration($call['started_at'], $call['ended_at']);
                    ?>
                    
                    <?php if ($display_date): ?>
                    <div class="date-separator">
                        <?php echo $display_date; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="call-item <?php echo $call['status']; ?>" data-direction="<?php echo $direction; ?>" data-status="<?php echo $call['status']; ?>">
                        <div class="call-avatar">
                            <?php echo getAvatarLetter($other_user); ?>
                            <div class="call-icon-badge <?php echo $call['status'] == 'missed' ? 'missed' : $direction; ?>">
                                <?php 
                                if ($call['status'] == 'missed') echo '❌';
                                else echo $is_outgoing ? '↗️' : '↙️';
                                ?>
                            </div>
                        </div>
                        
                        <div class="call-info">
                            <div class="call-row">
                                <span class="call-name"><?php echo htmlspecialchars($other_user); ?></span>
                                <span class="call-time"><?php echo timeAgo($call['created_at']); ?></span>
                            </div>
                            
                            <div class="call-details">
                                <span class="call-type">
                                    <span><?php echo $call_icon; ?></span>
                                    <?php echo ucfirst($call['call_type']); ?>
                                </span>
                                
                                <span class="call-direction <?php echo $call['status'] == 'missed' ? 'missed' : $direction; ?>">
                                    <?php 
                                    if ($call['status'] == 'missed') {
                                        echo 'MISSED';
                                    } else {
                                        echo $is_outgoing ? 'OUTGOING' : 'INCOMING';
                                    }
                                    ?>
                                </span>
                                
                                <?php if ($duration): ?>
                                <span class="call-duration">
                                    <span>⏱️</span> <?php echo $duration; ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="call-actions">
                            <button class="call-action-btn" onclick="event.stopPropagation(); callAgain(<?php echo $call['caller_id'] == $user_id ? $call['receiver_id'] : $call['caller_id']; ?>, '<?php echo $call['call_type']; ?>')" title="CALL AGAIN">
                                📞
                            </button>
                            <button class="call-action-btn" onclick="event.stopPropagation(); openChat(<?php echo $call['caller_id'] == $user_id ? $call['receiver_id'] : $call['caller_id']; ?>)" title="SEND MESSAGE">
                                💬
                            </button>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">📞</div>
                    <div class="empty-title">NO CALLS YET</div>
                    <div class="empty-text">Start a call with your squad members</div>
                    <a href="friends.php" class="action-btn" style="display: inline-flex;">📞 START A CALL</a>
                </div>
                <?php endif; ?>
                <?php $calls_result->close(); ?>
            </div>
        </div>
    </div>
    
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
    
    <form method="POST" action="home.php" id="logoutForm" style="display: none;">
        <input type="hidden" name="logout" value="1">
    </form>
    
    <style>
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

    .modal-btn.secondary {
        background: var(--bg-tertiary);
        color: var(--text-primary);
    }

    .modal-btn.danger {
        background: var(--danger);
        color: white;
    }

    .modal-btn.danger:hover {
        background: #d32f2f;
    }
    </style>
    
    <script>
    function filterCalls(filter) {
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        event.target.classList.add('active');
        
        const callItems = document.querySelectorAll('.call-item');
        const dateSeparators = document.querySelectorAll('.date-separator');
        
        callItems.forEach(item => item.style.display = 'none');
        dateSeparators.forEach(sep => sep.style.display = 'none');
        
        let visibleCount = 0;
        
        callItems.forEach(item => {
            let show = false;
            
            if (filter === 'all') {
                show = true;
            } else if (filter === 'missed') {
                show = item.classList.contains('missed');
            } else if (filter === 'outgoing') {
                show = item.dataset.direction === 'outgoing' && !item.classList.contains('missed');
            } else if (filter === 'incoming') {
                show = item.dataset.direction === 'incoming' && !item.classList.contains('missed');
            }
            
            if (show) {
                item.style.display = 'flex';
                visibleCount++;
                
                const prevSep = item.previousElementSibling;
                if (prevSep && prevSep.classList.contains('date-separator')) {
                    prevSep.style.display = 'flex';
                }
            }
        });
    }
    
    function callAgain(userId, type) {
        if (confirm(`START ${type.toUpperCase()} CALL?`)) {
            fetch('create_call.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'receiver_id=' + userId + '&type=' + type
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = 'video_call.php?call_id=' + data.call_id;
                } else {
                    alert('Failed to start call: ' + data.error);
                }
            })
            .catch(err => {
                console.error('Error:', err);
                alert('Failed to start call');
            });
        }
    }
    
    function openChat(userId) {
        window.location.href = 'chat.php?id=' + userId;
    }
    
    function showLogoutModal() {
        document.getElementById('logoutModal').classList.add('show');
    }
    
    function hideLogoutModal() {
        document.getElementById('logoutModal').classList.remove('show');
    }
    
    function confirmLogout() {
        document.getElementById('logoutForm').submit();
    }
    
    window.addEventListener('click', function(e) {
        const logoutModal = document.getElementById('logoutModal');
        if (e.target === logoutModal) {
            logoutModal.classList.remove('show');
        }
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.getElementById('logoutModal').classList.remove('show');
        }
    });
    </script>

</body>
</html>

<?php
$calls->close();
?>