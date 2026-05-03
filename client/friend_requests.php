<?php
// friend_requests.php - Complete fixed version
require_once 'db.php';
requireLogin();

$user_id = $_SESSION['user_id'];

// Get sent requests
$sent_requests = $conn->prepare("
    SELECT fr.*, u.username, u.email, u.avatar, u.last_active
    FROM friend_requests fr
    JOIN users u ON u.user_id = fr.receiver_id
    WHERE fr.sender_id = ?
    ORDER BY fr.created_at DESC
");
$sent_requests->bind_param("i", $user_id);
$sent_requests->execute();
$sent_result = $sent_requests->get_result();

// Get received requests
$received_requests = $conn->prepare("
    SELECT fr.*, u.username, u.email, u.avatar, u.last_active
    FROM friend_requests fr
    JOIN users u ON u.user_id = fr.sender_id
    WHERE fr.receiver_id = ? AND fr.status = 'pending'
    ORDER BY fr.created_at DESC
");
$received_requests->bind_param("i", $user_id);
$received_requests->execute();
$received_result = $received_requests->get_result();

// Get user data for profile
$user_data = $conn->query("SELECT * FROM users WHERE user_id = $user_id")->fetch_assoc();

// Get counts for badges
$unread_result = $conn->query("
    SELECT COUNT(*) as count FROM messages 
    WHERE receiver_id = $user_id AND is_read = 0
");
$total_unread = $unread_result->fetch_assoc()['count'] ?? 0;

$missed_calls = $conn->query("
    SELECT COUNT(*) as count FROM calls 
    WHERE receiver_id = $user_id AND status = 'missed'
")->fetch_assoc()['count'] ?? 0;

// Helper function
function getAvatarLetter($username) {
    return strtoupper(substr($username, 0, 1));
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php includeResponsive(); ?>
    <title>FRIEND REQUESTS · CONNECTXION GAMING</title>
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

        .back-btn {
            padding: 12px 24px;
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .back-btn:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: var(--glow-effect);
        }

        .requests-container {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
        }

        .requests-container::-webkit-scrollbar {
            width: 4px;
        }

        .requests-container::-webkit-scrollbar-track {
            background: var(--bg-tertiary);
        }

        .requests-container::-webkit-scrollbar-thumb {
            background: var(--accent);
            border-radius: 4px;
        }

        .requests-section {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
        }

        .requests-section h2 {
            color: var(--text-primary);
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .requests-section h2 span {
            color: var(--accent);
            font-size: 24px;
        }

        .request-card {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: var(--bg-tertiary);
            border-radius: 16px;
            margin-bottom: 10px;
            border: 1px solid var(--border);
            transition: all 0.3s;
        }

        .request-card:hover {
            border-color: var(--accent);
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        .request-avatar {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            font-weight: bold;
            overflow: hidden;
            flex-shrink: 0;
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
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .request-email {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .request-id {
            font-size: 11px;
            color: var(--accent);
            font-family: monospace;
        }

        .request-status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 4px;
        }

        .status-pending {
            background: rgba(250, 166, 26, 0.2);
            color: #faa61a;
        }

        .status-accepted {
            background: rgba(67, 181, 129, 0.2);
            color: var(--success);
        }

        .status-rejected {
            background: rgba(240, 71, 71, 0.2);
            color: var(--danger);
        }

        .request-actions {
            display: flex;
            gap: 8px;
        }

        .request-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
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

        .request-time {
            color: var(--text-muted);
            font-size: 11px;
            font-family: monospace;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }

        .empty-state-icon {
            font-size: 60px;
            margin-bottom: 15px;
            filter: drop-shadow(0 0 20px var(--accent-glow));
        }

        .empty-state h3 {
            color: var(--text-primary);
            margin-bottom: 10px;
            font-size: 18px;
            text-transform: uppercase;
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

        .modal-btn.secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }

        .modal-btn.danger {
            background: var(--danger);
            color: white;
        }

        @media (max-width: 768px) {
            .nav-sidebar {
                width: 70px;
            }
            
            .requests-container {
                padding: 15px;
            }
            
            .request-card {
                flex-wrap: wrap;
            }
            
            .request-actions {
                width: 100%;
                justify-content: flex-end;
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
                <?php if ($received_result->num_rows > 0): ?>
                <span class="nav-badge"><?php echo $received_result->num_rows; ?></span>
                <?php endif; ?>
            </div>

            <div class="nav-item active" title="REQUESTS">
                📨
            </div>
            
            <div class="nav-item" title="SETTINGS" onclick="window.location.href='settings.php'">
                ⚙️
            </div>
            
            <div class="nav-footer">
                <div class="avatar" onclick="window.location.href='profile.php'">
                    <?php if (!empty($user_data['avatar']) && file_exists($user_data['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($user_data['avatar']); ?>" alt="Avatar">
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
                        <img src="<?php echo htmlspecialchars($user_data['avatar']); ?>" alt="Avatar">
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
                <h1>FRIEND REQUESTS</h1>
                <a href="friends.php" class="back-btn">← BACK TO SQUAD</a>
            </div>
            
            <div class="requests-container">
                <!-- Received Requests -->
                <div class="requests-section">
                    <h2>
                        <span>📨</span> RECEIVED REQUESTS
                        <?php if ($received_result->num_rows > 0): ?>
                        <span style="background: var(--accent); color: white; padding: 2px 10px; border-radius: 20px; font-size: 12px; margin-left: 10px;">
                            <?php echo $received_result->num_rows; ?>
                        </span>
                        <?php endif; ?>
                    </h2>
                    
                    <?php if ($received_result && $received_result->num_rows > 0): ?>
                        <?php while($req = $received_result->fetch_assoc()): ?>
                        <div class="request-card">
                            <div class="request-avatar">
                                <?php if (!empty($req['avatar']) && file_exists($req['avatar'])): ?>
                                    <img src="<?php echo htmlspecialchars($req['avatar']); ?>" alt="<?php echo htmlspecialchars($req['username']); ?>">
                                <?php else: ?>
                                    <?php echo getAvatarLetter($req['username']); ?>
                                <?php endif; ?>
                            </div>
                            <div class="request-info">
                                <div class="request-name"><?php echo htmlspecialchars($req['username']); ?></div>
                                <div class="request-email"><?php echo htmlspecialchars($req['email']); ?></div>
                                <div class="request-id">ID: #<?php echo $req['sender_id']; ?></div>
                                <div class="request-time"><?php echo timeAgo($req['created_at']); ?></div>
                            </div>
                            <div class="request-actions">
                                <button class="request-btn accept" onclick="acceptRequest(<?php echo $req['id']; ?>)" title="ACCEPT">✓</button>
                                <button class="request-btn reject" onclick="rejectRequest(<?php echo $req['id']; ?>)" title="REJECT">✗</button>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📭</div>
                            <h3>NO PENDING REQUESTS</h3>
                            <p>When someone sends you a friend request, it will appear here</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Sent Requests -->
                <div class="requests-section">
                    <h2>
                        <span>📤</span> SENT REQUESTS
                    </h2>
                    
                    <?php if ($sent_result && $sent_result->num_rows > 0): ?>
                        <?php while($req = $sent_result->fetch_assoc()): ?>
                        <div class="request-card">
                            <div class="request-avatar">
                                <?php if (!empty($req['avatar']) && file_exists($req['avatar'])): ?>
                                    <img src="<?php echo htmlspecialchars($req['avatar']); ?>" alt="<?php echo htmlspecialchars($req['username']); ?>">
                                <?php else: ?>
                                    <?php echo getAvatarLetter($req['username']); ?>
                                <?php endif; ?>
                            </div>
                            <div class="request-info">
                                <div class="request-name"><?php echo htmlspecialchars($req['username']); ?></div>
                                <div class="request-email"><?php echo htmlspecialchars($req['email']); ?></div>
                                <div class="request-id">ID: #<?php echo $req['receiver_id']; ?></div>
                                <div>
                                    <span class="request-status status-<?php echo $req['status']; ?>">
                                        <?php echo strtoupper($req['status']); ?>
                                    </span>
                                </div>
                                <div class="request-time"><?php echo timeAgo($req['created_at']); ?></div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📪</div>
                            <h3>NO SENT REQUESTS</h3>
                            <p>You haven't sent any friend requests yet</p>
                        </div>
                    <?php endif; ?>
                </div>
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
    
    // Close modals when clicking outside
    window.addEventListener('click', function(e) {
        const logoutModal = document.getElementById('logoutModal');
        if (e.target === logoutModal) {
            logoutModal.classList.remove('show');
        }
    });
    
    // Handle escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.getElementById('logoutModal').classList.remove('show');
        }
    });
    
    // Auto-refresh every 10 seconds to check for new requests
    setInterval(function() {
        fetch('check_friend_requests.php')
        .then(response => response.json())
        .then(data => {
            if (data.has_new) {
                // Show notification
                const toast = document.createElement('div');
                toast.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: var(--accent);
                    color: white;
                    padding: 15px 25px;
                    border-radius: 12px;
                    z-index: 2000;
                    animation: slideIn 0.3s;
                    box-shadow: var(--glow-effect);
                `;
                toast.innerHTML = '📨 New friend request received!';
                document.body.appendChild(toast);
                
                setTimeout(() => {
                    toast.remove();
                    location.reload();
                }, 2000);
            }
        })
        .catch(err => console.error('Error checking requests:', err));
    }, 10000);
    </script>
    
</body>
</html>

<?php
$sent_requests->close();
$received_requests->close();
?>