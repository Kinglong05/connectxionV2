<?php
require_once 'db.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$friend_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$friend_id) {
    header("Location: home.php");
    exit();
}

// Get friend data
$result = $conn->query("SELECT * FROM users WHERE user_id = $friend_id");

if (!$result || $result->num_rows === 0) {
    header("Location: home.php?error=user_not_found");
    exit();
}

$friend = $result->fetch_assoc();

// Get user data for profile
$user_data = $conn->query("SELECT * FROM users WHERE user_id = $user_id")->fetch_assoc();

// Get messages count
$messages_count = 0;
$count_result = $conn->query("
    SELECT COUNT(*) as total 
    FROM messages 
    WHERE (sender_id = $user_id AND receiver_id = $friend_id)
       OR (sender_id = $friend_id AND receiver_id = $user_id)
");
if ($count_result && $count_result->num_rows > 0) {
    $messages_count = $count_result->fetch_assoc()['total'];
}

// Check if user is online
$is_online = false;
if (isset($friend['last_active']) && !empty($friend['last_active'])) {
    $last_active_time = strtotime($friend['last_active']);
    if ($last_active_time !== false) {
        $is_online = (time() - $last_active_time) < 300;
    }
}

// Get total unread count for badge
$unread_result = $conn->query("
    SELECT COUNT(*) as count FROM messages 
    WHERE receiver_id = $user_id AND is_read = 0
");
$total_unread = $unread_result->fetch_assoc()['count'] ?? 0;

// Get friend requests count
$requests_count = 0;
$requests_result = $conn->query("
    SELECT COUNT(*) as count FROM friend_requests 
    WHERE receiver_id = $user_id AND status = 'pending'
");
if ($requests_result) {
    $requests_count = $requests_result->fetch_assoc()['count'];
}

// Helper function for avatar letter
function getAvatarLetter($username) {
    return strtoupper(substr($username, 0, 1));
}

// Get pinned message
$pinned_message = null;
$pinned_result = $conn->query("
    SELECT * FROM messages 
    WHERE ((sender_id = $user_id AND receiver_id = $friend_id) OR (sender_id = $friend_id AND receiver_id = $user_id))
    AND is_pinned = 1 
    ORDER BY created_at DESC LIMIT 1
");
if ($pinned_result && $pinned_result->num_rows > 0) {
    $pinned_message = $pinned_result->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php includeResponsive(); ?>
    <title><?php echo htmlspecialchars($friend['username']); ?> · CONNECTXION GAMING</title>
    <!-- PWA Support -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#ff4655">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <script src="pwa_manager.js"></script>
    <!-- Socket.IO Client -->
    <script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>
    <style>
        /* ========== YOUR EXISTING CSS STYLES GO HERE ========== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            max-width: 100%;
            overflow-x: hidden;
            margin: 0;
            padding: 0;
            height: 100vh;
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
            --accent-glow-secondary: rgba(14, 211, 199, 0.3);
            --border: #2a313c;
            --success: #43b581;
            --warning: #faa61a;
            --danger: #f04747;
            --gradient-primary: linear-gradient(135deg, #ff4655, #ff7b72);
            --gradient-secondary: linear-gradient(135deg, #0ed3c7, #10b3aa);
            --glow-effect: 0 0 15px var(--accent-glow);
            
            --message-own: #1e2a3a;
            --message-other: #1a1f25;
            --message-own-text: #ffffff;
            --message-other-text: #e0e0e0;
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

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-10px); }
        }

        .app-container {
            display: flex;
            height: 100vh;
            background: var(--bg-primary);
            position: relative;
            overflow: hidden;
            width: 100%;
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
            flex-shrink: 0;
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
            width: 50px;
            height: 50px;
            background: var(--gradient-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 30px;
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

        .chat-container {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            background: var(--bg-primary);
            position: relative;
            width: calc(100% - 80px);
        }

        .chat-header {
            padding: 16px 24px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            z-index: 10;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
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

        .chat-user {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .chat-avatar {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
            position: relative;
            text-transform: uppercase;
            border: 2px solid transparent;
            transition: all 0.3s;
        }

        .chat-avatar.online::after {
            content: '';
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 12px;
            height: 12px;
            background: var(--success);
            border: 2px solid var(--bg-secondary);
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }

        .chat-user-info {
            display: flex;
            flex-direction: column;
        }

        .chat-user-name {
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

        .chat-user-status {
            font-size: 13px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: <?php echo $is_online ? 'var(--success)' : 'var(--text-muted)'; ?>;
            animation: <?php echo $is_online ? 'pulse 1.5s infinite' : 'none'; ?>;
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

        /* Search UI styles moved to bottom */

        .search-result-info {
            flex: 1;
        }

        .search-result-name {
            color: var(--text-primary);
            font-weight: 600;
            margin-bottom: 3px;
        }

        .search-result-preview {
            color: var(--text-muted);
            font-size: 12px;
        }

        .search-result-date {
            color: var(--text-muted);
            font-size: 11px;
        }

        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            background: var(--bg-primary);
            scroll-behavior: smooth;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .messages-container::-webkit-scrollbar {
            width: 4px;
        }

        .messages-container::-webkit-scrollbar-track {
            background: transparent;
        }

        .messages-container::-webkit-scrollbar-thumb {
            background: var(--accent);
            border-radius: 4px;
        }

        .date-separator {
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 20px 0 10px;
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

        .message {
            max-width: 99%;
            position: relative;
            animation: fadeIn 0.2s;
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .message-own {
            align-self: flex-end;
        }

        .message-other {
            align-self: flex-start;
        }

        .message-sender {
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0 8px;
        }

        .message-other .message-sender {
            text-align: left;
            color: var(--accent);
        }

        .message-own .message-sender {
            text-align: right;
            color: var(--accent-secondary);
        }

        .message-wrapper {
            position: relative;
            cursor: pointer;
            width: 100%;
        }

        .message-wrapper:hover .message-actions {
            opacity: 1;
            transform: translateY(0);
        }

        .message-bubble {
            padding: 12px 16px;
            border-radius: 18px;
            position: relative;
            word-wrap: break-word;
            overflow-wrap: break-word;
            font-size: 15px;
            line-height: 1.5;
            border: 1px solid transparent;
            transition: all 0.2s;
            width: fit-content;
            max-width: 100%;
            box-sizing: border-box;
        }

        .message-own .message-bubble {
            background: var(--message-own);
            color: var(--message-own-text);
            border-bottom-right-radius: 6px;
            border-color: rgba(255, 70, 85, 0.3);
            margin-left: auto;
        }

        .message-other .message-bubble {
            background: var(--message-other);
            color: var(--message-other-text);
            border-bottom-left-radius: 6px;
            border-color: var(--border);
            margin-right: auto;
        }

        .message-own .message-bubble:hover {
            border-color: var(--accent);
            box-shadow: -3px 0 0 var(--accent);
        }

        .message-other .message-bubble:hover {
            border-color: var(--accent-secondary);
            box-shadow: 3px 0 0 var(--accent-secondary);
        }

        .message-text {
            margin-right: 10px;
        }

        .message-image {
            max-width: 250px;
            max-height: 200px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
            object-fit: cover;
        }

        .message-own .message-image {
            border-color: rgba(255, 70, 85, 0.3);
        }

        .message-other .message-image {
            border-color: var(--border);
        }

        .message-image:hover {
            transform: scale(1.05);
            border-color: var(--accent);
            box-shadow: var(--glow-effect);
        }

        .image-preview-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }

        .image-preview-modal.show {
            opacity: 1;
            visibility: visible;
        }

        .preview-content {
            position: relative;
            max-width: 90%;
            max-height: 90%;
        }

        .preview-content img {
            max-width: 100%;
            max-height: 90vh;
            border-radius: 16px;
            border: 3px solid var(--accent);
            box-shadow: 0 0 50px rgba(255, 70, 85, 0.3);
        }

        .close-preview {
            position: absolute;
            top: -40px;
            right: 0;
            background: none;
            border: none;
            color: white;
            font-size: 30px;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 70, 85, 0.2);
            transition: all 0.3s;
        }

        .close-preview:hover {
            background: var(--accent);
            transform: scale(1.1);
        }

        .message-file {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--bg-tertiary);
            padding: 10px 16px;
            border-radius: 12px;
            border: 1px solid var(--border);
            transition: all 0.3s;
        }

        .message-file:hover {
            border-color: var(--accent);
            transform: translateX(5px);
        }

        .file-icon {
            font-size: 28px;
            color: var(--accent-secondary);
        }

        .file-info {
            flex: 1;
        }

        .file-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 2px;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .file-size {
            font-size: 11px;
            color: var(--text-muted);
        }

        .file-download {
            color: var(--accent);
            font-size: 20px;
            text-decoration: none;
            padding: 5px;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .file-download:hover {
            background: var(--accent);
            color: white;
            transform: scale(1.1);
        }

        /* Enhanced Voice Message Styles */
        .message-voice {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--bg-tertiary);
            padding: 8px 16px;
            border-radius: 30px;
            border: 1px solid var(--border);
            min-width: 280px;
            max-width: 100%;
            transition: all 0.3s;
        }

        .message-voice:hover {
            border-color: var(--accent);
            box-shadow: var(--glow-effect);
            transform: scale(1.02);
        }

        .voice-play {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--accent);
            border: none;
            color: white;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .voice-play:hover {
            transform: scale(1.1);
            box-shadow: var(--glow-effect);
        }

        .voice-play.playing {
            background: var(--danger);
            animation: pulse 1s infinite;
        }

        .voice-wave-container {
            flex: 1;
            height: 40px;
            display: flex;
            align-items: center;
            gap: 2px;
            cursor: pointer;
            padding: 0 5px;
        }

        .voice-wave-bar {
            flex: 1;
            background: linear-gradient(to top, var(--accent), var(--accent-secondary));
            border-radius: 2px;
            min-height: 4px;
            transition: all 0.2s;
            opacity: 0.5;
        }

        .voice-wave-bar.active {
            opacity: 1;
            background: var(--accent);
            transform: scaleY(1.2);
        }

        .voice-duration {
            font-family: monospace;
            font-size: 12px;
            color: var(--text-muted);
            min-width: 45px;
            text-align: right;
        }

        .message-own .message-voice {
            background: var(--message-own);
        }

        .message-own .voice-wave-bar {
            background: linear-gradient(to top, var(--accent-secondary), var(--accent));
        }

        /* Voice Preview Styles */
        .voice-preview {
            margin-top: 15px;
            padding: 15px;
            background: var(--bg-secondary);
            border-radius: 16px;
            border: 1px solid var(--border);
            animation: slideUp 0.3s ease;
        }

        .voice-preview-content {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .voice-preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--text-secondary);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .voice-preview-header .close-preview {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 18px;
            cursor: pointer;
            padding: 4px 8px;
            transition: all 0.2s;
        }

        .voice-preview-header .close-preview:hover {
            color: var(--danger);
            transform: scale(1.2);
        }

        .voice-preview-waveform {
            padding: 10px 0;
        }

        .waveform-preview {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 2px;
            height: 40px;
            background: var(--bg-tertiary);
            border-radius: 20px;
            padding: 0 10px;
        }

        .waveform-bar {
            flex: 1;
            background: linear-gradient(to top, var(--accent), var(--accent-secondary));
            border-radius: 2px;
            min-height: 4px;
            transition: height 0.2s;
        }

        .voice-preview-controls {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .voice-play-preview {
            width: 44px;
            height: 44px;
            border-radius: 22px;
            background: var(--accent);
            border: none;
            color: white;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .voice-play-preview:hover {
            transform: scale(1.1);
            box-shadow: var(--glow-effect);
        }

        .preview-duration {
            font-family: monospace;
            font-size: 16px;
            color: var(--text-primary);
        }

        .voice-send {
            margin-left: auto;
            padding: 10px 24px;
            background: var(--gradient-primary);
            border: none;
            border-radius: 22px;
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .voice-send:hover {
            transform: translateY(-2px);
            box-shadow: var(--glow-effect);
        }

        .voice-re-record {
            padding: 10px 24px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            border-radius: 22px;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .voice-re-record:hover {
            border-color: var(--warning);
            color: var(--warning);
            transform: translateY(-2px);
        }

        .message-actions {
            position: absolute;
            bottom: -25px;
            display: flex;
            gap: 6px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 25px;
            padding: 6px;
            opacity: 0;
            transform: translateY(5px);
            transition: all 0.2s;
            z-index: 100;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
        }

        .message-own .message-actions {
            right: 0;
        }

        .message-other .message-actions {
            left: 0;
        }

        .msg-action {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.05);
            background: #25262c;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
        }

        .msg-action:hover {
            transform: translateY(-3px) scale(1.1);
            color: white;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.5);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .msg-action.react { background: linear-gradient(135deg, #00f2fe 0%, #4facfe 100%); color: white; border: none; }
        .msg-action.pin { background: #3c3f41; color: #ffeb3b; }
        .msg-action.reply { background: #1e88e5; color: white; }
        .msg-action.edit { background: #fb8c00; color: white; }
        .msg-action.delete { background: #2c2f33; color: #ff5252; }
        .msg-action.delete:hover { background: #ff5252; color: white; }

        /* Premium Modal Styles */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: linear-gradient(145deg, #1a1b1f, #121317);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 35px;
            width: 90%;
            max-width: 450px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 70, 85, 0.1);
            transform: scale(0.9);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .modal.show .modal-content {
            transform: scale(1);
        }

        .modal-content h3 {
            color: var(--accent);
            font-size: 24px;
            margin-bottom: 20px;
            text-transform: uppercase;
            letter-spacing: 2px;
            text-align: center;
        }

        .modal-content p {
            color: var(--text-secondary);
            margin-bottom: 30px;
            text-align: center;
            line-height: 1.6;
        }

        .edit-message-input {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 15px;
            color: white;
            font-family: inherit;
            font-size: 16px;
            resize: none;
            height: 120px;
            margin-bottom: 25px;
            transition: border-color 0.3s;
        }

        .edit-message-input:focus {
            outline: none;
            border-color: var(--accent);
            background: rgba(255, 255, 255, 0.08);
        }

        .message-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 4px;
            font-size: 11px;
            color: var(--text-muted);
            width: 100%;
            padding: 0 8px;
        }

        .message-own .message-meta {
            justify-content: flex-end;
        }

        .message-other .message-meta {
            justify-content: flex-start;
        }

        .message-time {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .message-status {
            color: var(--accent);
        }

        .message-status.read {
            color: var(--success);
        }

        .edited-indicator {
            font-size: 10px;
            color: var(--text-muted);
            margin-left: 4px;
            font-style: italic;
        }

        .message-reactions {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 4px;
            width: 100%;
            padding: 0 8px;
        }

        .message-own .message-reactions {
            justify-content: flex-end;
        }

        .message-other .message-reactions {
            justify-content: flex-start;
        }

        .reaction {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 4px 10px;
            font-size: 13px;
            color: var(--text-primary);
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 5px;
        }

        .reaction:hover {
            background: rgba(255, 70, 85, 0.1);
            border-color: var(--accent);
            transform: scale(1.1) translateY(-2px);
        }

        .reaction-count {
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 800;
        }

        .reaction-pop {
            animation: pop 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes pop {
            0% { transform: scale(0.5); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }

        /* Improved Reaction Picker Positioning */
        .reaction-picker-mini {
            position: fixed;
            background: rgba(18, 18, 24, 0.85);
            border: 1px solid var(--accent);
            border-radius: 40px;
            padding: 8px 12px;
            display: flex;
            gap: 8px;
            z-index: 10000;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.8), 0 0 20px var(--accent-glow);
            backdrop-filter: blur(15px);
            animation: slideUp 0.3s cubic-bezier(0.18, 0.89, 0.32, 1.28);
        }

        .reaction-picker-mini button {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            transition: all 0.2s;
            padding: 5px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .reaction-picker-mini button:hover {
            transform: scale(1.3) translateY(-5px);
            background: rgba(255, 70, 85, 0.15);
            box-shadow: 0 5px 15px rgba(255, 70, 85, 0.3);
        }

        .reaction-emoji {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: var(--bg-tertiary);
            color: white;
            font-size: 24px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .reaction-emoji:hover {
            background: var(--accent);
            transform: scale(1.2) translateY(-5px);
            border-color: white;
            box-shadow: 0 5px 15px var(--accent-glow);
        }

        .reply-indicator {
            background: var(--bg-tertiary);
            border-left: 3px solid var(--accent);
            padding: 8px 12px;
            border-radius: 12px;
            margin-bottom: 6px;
            font-size: 12px;
        }

        .reply-sender {
            color: var(--accent);
            font-weight: 600;
            margin-bottom: 2px;
            font-size: 11px;
            text-transform: uppercase;
        }

        .reply-content {
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
            font-size: 12px;
        }

        /* Reply Preview Styling */
        .reply-preview {
            background: rgba(30, 31, 38, 0.9);
            border-left: 4px solid var(--accent);
            padding: 12px 20px;
            margin: 10px 24px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: slideUp 0.3s ease;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-left-width: 4px;
        }

        .reply-preview-content {
            flex: 1;
            overflow: hidden;
        }

        .reply-preview-header {
            color: var(--accent);
            font-size: 11px;
            font-weight: 800;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .reply-preview-text {
            color: var(--text-secondary);
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 80%;
        }

        .modal-btn {
            padding: 12px 24px;
            border-radius: 12px;
            border: none;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 12px;
            font-family: 'Poppins', sans-serif;
        }

        .modal-btn.primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 4px 15px rgba(255, 70, 85, 0.3);
        }

        .modal-btn.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 70, 85, 0.5);
        }

        .modal-btn.secondary {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-secondary);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .modal-btn.secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .modal-btn.danger {
            background: #ff5252;
            color: white;
        }

        .modal-btn.danger:hover {
            background: #ff1744;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 23, 68, 0.4);
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

        /* Final Unified Search Styles */
        .search-container {
            padding: 0 24px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            max-height: 0;
            overflow: hidden;
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            z-index: 101;
        }

        .search-container.show {
            max-height: 80px;
            opacity: 1;
            padding: 15px 24px;
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px 15px;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.3);
        }

        .search-box input {
            flex: 1;
            background: none;
            border: none;
            color: white;
            outline: none;
            font-size: 14px;
            font-family: inherit;
        }

        .search-results {
            position: fixed;
            top: 130px;
            left: 50%;
            transform: translateX(-50%);
            width: 90%;
            max-width: 600px;
            max-height: 450px;
            overflow-y: auto;
            background: rgba(15, 16, 20, 0.98);
            border: 1px solid var(--accent);
            border-radius: 20px;
            z-index: 3000;
            box-shadow: 0 25px 60px rgba(0,0,0,0.9), 0 0 30px rgba(255, 70, 85, 0.2);
            display: none;
            padding: 10px;
            backdrop-filter: blur(25px);
            margin-top: 10px;
        }

        .search-results.show {
            display: block;
            animation: slideInDown 0.3s cubic-bezier(0.18, 0.89, 0.32, 1.28);
        }

        @keyframes slideInDown {
            from { transform: translate(-50%, -20px); opacity: 0; }
            to { transform: translate(-50%, 0); opacity: 1; }
        }

        .search-result-item {
            padding: 15px 20px;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid transparent;
            border-radius: 14px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .search-result-item:hover {
            background: rgba(255, 70, 85, 0.08);
            border-color: rgba(255, 70, 85, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }

        .search-result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .search-result-header strong {
            font-size: 12px;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 800;
        }

        .search-result-header span {
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .search-result-text {
            font-size: 14px;
            color: var(--text-primary);
            line-height: 1.5;
            white-space: normal;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .search-no-results {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .cancel-reply {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 20px;
            cursor: pointer;
            padding: 4px 8px;
            transition: all 0.2s;
        }

        .cancel-reply:hover {
            color: var(--danger);
            transform: scale(1.2);
        }

        .chat-input-area {
            padding: 16px 24px;
            background: var(--bg-secondary);
            border-top: 1px solid var(--border);
            width: 100%;
            box-sizing: border-box;
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
            width: 100%;
            box-sizing: border-box;
        }

        .chat-form:focus-within {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        .chat-form input[type="text"] {
            flex: 1;
            min-width: 0;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 15px;
            padding: 14px 0;
            outline: none;
            font-family: 'Poppins', sans-serif;
        }

        .chat-form input[type="text"]:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
            flex-shrink: 0;
        }

        .input-btn {
            width: 46px;
            height: 46px;
            border-radius: 23px;
            border: none;
            background: transparent;
            color: var(--text-secondary);
            font-size: 22px;
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

        .input-btn.recording {
            background: var(--danger);
            color: white;
            animation: pulse 1s infinite;
        }

        .send-btn {
            width: 46px;
            height: 46px;
            border-radius: 23px;
            border: none;
            background: var(--gradient-primary);
            color: white;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--glow-effect);
            flex-shrink: 0;
        }

        .send-btn:hover:not(:disabled) {
            transform: scale(1.1) rotate(90deg);
            box-shadow: 0 0 20px var(--accent-glow);
        }

        .send-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            box-shadow: none;
        }

        .voice-recording-indicator {
            margin-top: 10px;
            padding: 15px 20px;
            background: var(--bg-tertiary);
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 1px solid var(--danger);
            animation: pulse 2s infinite;
        }

        .voice-recording-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .voice-recording-left span:first-child {
            color: var(--danger);
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .voice-timer {
            font-family: monospace;
            font-size: 20px;
            color: var(--text-primary);
            background: var(--bg-card);
            padding: 5px 15px;
            border-radius: 20px;
            border: 1px solid var(--border);
        }

        .voice-stop-btn {
            background: var(--danger);
            border: none;
            color: white;
            padding: 10px 25px;
            border-radius: 25px;
            cursor: pointer;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s;
            border: 1px solid transparent;
        }

        .voice-stop-btn:hover {
            background: transparent;
            border-color: var(--danger);
            color: var(--danger);
            transform: scale(1.05);
        }

        .typing-indicator {
            display: flex;
            gap: 6px;
            padding: 12px 20px;
            background: var(--message-other);
            border-radius: 20px;
            border-bottom-left-radius: 6px;
            width: fit-content;
            margin: 0 24px 12px 24px;
            border: 1px solid var(--border);
        }

        .typing-dot {
            width: 8px;
            height: 8px;
            background: var(--accent);
            border-radius: 50%;
            animation: typing 1.4s infinite;
        }

        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }

        .pinned-bar {
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            padding: 10px 24px;
            display: flex;
            align-items: center;
            gap: 15px;
            z-index: 9;
            transition: all 0.3s ease;
            max-height: 0;
            overflow: hidden;
            opacity: 0;
        }

        .pinned-bar.show {
            max-height: 60px;
            opacity: 1;
        }

        .pinned-icon {
            color: var(--accent);
            font-size: 18px;
            animation: pulse 2s infinite;
        }

        .pinned-content {
            flex: 1;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--text-secondary);
            cursor: pointer;
        }

        .pinned-content strong {
            color: var(--accent);
            margin-right: 5px;
        }

        .unpin-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 18px;
            padding: 5px;
            border-radius: 50%;
            transition: all 0.2s;
        }

        .unpin-btn:hover {
            background: rgba(240, 71, 71, 0.1);
            color: var(--danger);
            transform: scale(1.1);
        }

        .scroll-bottom {
            position: fixed;
            bottom: 100px;
            right: 30px;
            width: 48px;
            height: 48px;
            border-radius: 24px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            box-shadow: var(--glow-effect);
            transition: all 0.3s;
            z-index: 100;
            animation: float 2s infinite;
        }

        .scroll-bottom:hover {
            transform: scale(1.1);
        }

        .scroll-bottom.visible {
            display: flex;
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
        }

        .edit-message-input {
            width: 100%;
            padding: 16px;
            background: var(--bg-card);
            border: 2px solid var(--border);
            border-radius: 16px;
            color: var(--text-primary);
            font-size: 15px;
            margin-bottom: 20px;
            resize: vertical;
            min-height: 120px;
            font-family: inherit;
        }

        .edit-message-input:focus {
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
    </style>
</head>
<body>
    <div class="app-container">
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
                <div class="avatar <?php echo (isset($user_data['last_active']) && !empty($user_data['last_active']) && (time() - strtotime($user_data['last_active']) < 300)) ? 'online' : ''; ?>" onclick="window.location.href='profile.php'">
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
        
        <div class="chat-container">
            <div class="chat-header">
                <div class="chat-header-left">
                    <button class="back-btn" onclick="window.location.href='home.php'" title="BACK TO HUB">←</button>
                    <div class="chat-user">
                        <div class="chat-avatar <?php echo $is_online ? 'online' : ''; ?>">
                            <?php echo getAvatarLetter($friend['username']); ?>
                        </div>
                        <div class="chat-user-info">
                            <span class="chat-user-name"><?php echo htmlspecialchars($friend['username']); ?></span>
                            <span class="chat-user-status">
                                <span class="status-dot"></span>
                                <?php echo $is_online ? 'ONLINE' : 'OFFLINE'; ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="chat-actions">
                    <span id="connectionStatus" class="connection-status" title="Connecting...">⚪</span>
                    <button class="action-btn" onclick="toggleSearch()" title="SEARCH MESSAGES">🔍</button>
                    <button class="action-btn" onclick="showUserInfo()" title="PLAYER INFO">ℹ️</button>
                </div>
            </div>
            
            <div class="search-container" id="searchContainer">
                <div class="search-box">
                    <span>🔍</span>
                    <input type="text" id="searchInput" placeholder="SEARCH MESSAGES..." onkeyup="searchMessages(this.value)">
                </div>
            </div>
            
            <div class="search-results" id="searchResults"></div>
            
            <!-- Pinned Message Bar -->
        <div id="pinnedBar" class="pinned-bar <?php echo $pinned_message ? 'show' : ''; ?>">
            <div class="pinned-icon">📌</div>
            <div class="pinned-content" id="pinnedContent" onclick="scrollToMessage(<?php echo $pinned_message['message_id'] ?? 0; ?>)">
                <strong>Pinned Message:</strong>
                <span id="pinnedText"><?php 
                    if ($pinned_message) {
                        if ($pinned_message['message_type'] === 'text') echo htmlspecialchars($pinned_message['message']);
                        else echo '[' . ucfirst($pinned_message['message_type']) . ']';
                    }
                ?></span>
            </div>
            <button class="unpin-btn" onclick="togglePin(<?php echo $pinned_message['message_id'] ?? 0; ?>, false)" title="Unpin message">✕</button>
        </div>

        <div class="messages-container" id="messagesContainer">
                <!-- Messages will be loaded here -->
            </div>
            
            <div class="typing-indicator" id="typingIndicator" style="display: none;">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
            </div>
            
            <div class="reply-preview" id="replyPreview" style="display: none;">
                <div class="reply-preview-content">
                    <div class="reply-preview-header" id="replyPreviewHeader">REPLYING TO</div>
                    <div class="reply-preview-text" id="replyPreviewText">Message preview</div>
                </div>
                <button class="cancel-reply" onclick="cancelReply()">✕</button>
            </div>
            
            <div class="chat-input-area">
                <form class="chat-form" id="sendForm">
                    <input type="hidden" name="receiver_id" value="<?php echo $friend_id; ?>">
                    <input type="hidden" name="reply_to" id="replyToInput">
                    <input type="text" name="message" id="messageInput" placeholder="TYPE YOUR MESSAGE..." autocomplete="off">
                    
                    <div class="input-actions">
                        <button type="button" class="input-btn" onclick="showAttachMenu()" title="ATTACH">📎</button>
                        <button type="button" class="input-btn" id="voiceBtn" onclick="toggleVoiceRecording()" title="VOICE MESSAGE">🎤</button>
                        <button type="submit" class="send-btn" id="sendBtn">➤</button>
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
    </div>
    
    <div class="modal" id="emojiModal">
        <div class="modal-content" style="max-width: 400px;">
            <h3>CHOOSE EMOJI</h3>
            <div style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin: 25px 0;">
                <button class="reaction-emoji" onclick="insertEmoji('😊')">😊</button>
                <button class="reaction-emoji" onclick="insertEmoji('😂')">😂</button>
                <button class="reaction-emoji" onclick="insertEmoji('❤️')">❤️</button>
                <button class="reaction-emoji" onclick="insertEmoji('👍')">👍</button>
                <button class="reaction-emoji" onclick="insertEmoji('😢')">😢</button>
                <button class="reaction-emoji" onclick="insertEmoji('🎉')">🎉</button>
                <button class="reaction-emoji" onclick="insertEmoji('😍')">😍</button>
                <button class="reaction-emoji" onclick="insertEmoji('🔥')">🔥</button>
                <button class="reaction-emoji" onclick="insertEmoji('✨')">✨</button>
                <button class="reaction-emoji" onclick="insertEmoji('⭐')">⭐</button>
                <button class="reaction-emoji" onclick="insertEmoji('🍕')">🍕</button>
                <button class="reaction-emoji" onclick="insertEmoji('🎮')">🎮</button>
                <button class="reaction-emoji" onclick="insertEmoji('😎')">😎</button>
                <button class="reaction-emoji" onclick="insertEmoji('🥺')">🥺</button>
                <button class="reaction-emoji" onclick="insertEmoji('😡')">😡</button>
                <button class="reaction-emoji" onclick="insertEmoji('💀')">💀</button>
                <button class="reaction-emoji" onclick="insertEmoji('✅')">✅</button>
                <button class="reaction-emoji" onclick="insertEmoji('❌')">❌</button>
            </div>
            <div class="modal-actions">
                <button class="modal-btn secondary" onclick="hideEmojiPicker()">CLOSE</button>
            </div>
        </div>
    </div>
    
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
    
    <div class="image-preview-modal" id="imagePreviewModal">
        <div class="preview-content">
            <button class="close-preview" onclick="hideImagePreview()">✕</button>
            <img id="previewImage" src="" alt="Preview">
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

    <button class="scroll-bottom" id="scrollBottomBtn" onclick="scrollToBottom()">↓</button>
    
    <audio id="voicePlayer" style="display: none;"></audio>
    
    <script>
        const CONFIG = {
            userId: <?php echo $_SESSION['user_id']; ?>,
            friendId: <?php echo $friend_id; ?>,
            senderName: "<?php echo addslashes($user_data['username']); ?>"
        };
    </script>
    <script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>
    <script src="chat_script.js"></script>
</body>
</html>