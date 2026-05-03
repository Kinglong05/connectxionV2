<?php
require_once 'db.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $bio = trim($_POST['bio']);
        $phone = trim($_POST['phone']);
        
        // Check if username/email already exists (excluding current user)
        $check = dbGetRow($conn, "SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?", "ssi", $username, $email, $user_id);
        
        if ($check) {
            $error = "Username or email already taken";
        } else {
            // Update user info
            $update_data = [
                'username' => $username,
                'email' => $email,
                'bio' => $bio,
                'phone' => $phone
            ];
            
            if (dbUpdate($conn, 'users', $update_data, "user_id = ?", "i", [$user_id])) {
                // Update session username
                $_SESSION['username'] = $username;
                $success = "Profile updated successfully";
            } else {
                $error = "Failed to update profile";
            }
        }
    }
    
    // Handle avatar upload
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $filename = $_FILES['avatar']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            if ($_FILES['avatar']['size'] <= 5 * 1024 * 1024) { // 5MB max
                $uploadDir = 'uploads/avatars/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $newFilename = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
                $uploadPath = $uploadDir . $newFilename;
                
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadPath)) {
                    // Delete old avatar if exists
                    $oldAvatarRow = dbGetRow($conn, "SELECT avatar FROM users WHERE user_id = ?", "i", $user_id);
                    $oldAvatar = $oldAvatarRow['avatar'] ?? null;
                    if ($oldAvatar && file_exists($oldAvatar)) {
                        unlink($oldAvatar);
                    }
                    
                    // Update database
                    if (prepareAndExecute($conn, "UPDATE users SET avatar = ? WHERE user_id = ?", "si", $uploadPath, $user_id)) {
                        $success = "Avatar updated successfully";
                    } else {
                        $error = "Failed to update avatar in database";
                    }
                } else {
                    $error = "Failed to upload avatar";
                }
            } else {
                $error = "Avatar size must be less than 5MB";
            }
        } else {
            $error = "Only JPG, PNG, GIF, and WEBP images are allowed";
        }
    }
}

// Get user data
$user = dbGetRow($conn, "SELECT * FROM users WHERE user_id = ?", "i", $user_id);

// Get user statistics
$stats = [];

// Friends count
$friends_count_row = dbGetRow($conn, "SELECT COUNT(*) as count FROM friends WHERE user_id = ?", "i", $user_id);
$friends_count = $friends_count_row['count'] ?? 0;

// Messages sent
$messages_sent_row = dbGetRow($conn, "SELECT COUNT(*) as count FROM messages WHERE sender_id = ?", "i", $user_id);
$messages_sent = $messages_sent_row['count'] ?? 0;

// Messages received
$messages_received_row = dbGetRow($conn, "SELECT COUNT(*) as count FROM messages WHERE receiver_id = ?", "i", $user_id);
$messages_received = $messages_received_row['count'] ?? 0;

// Get missed calls count
$missed_calls_row = dbGetRow($conn, "SELECT COUNT(*) as count FROM calls WHERE receiver_id = ? AND status = 'missed'", "i", $user_id);
$missed_calls = $missed_calls_row['count'] ?? 0;

// Get unread messages
$unread_row = dbGetRow($conn, "SELECT COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0", "i", $user_id);
$total_unread = $unread_row['count'] ?? 0;

// Get friend requests count
$requests_row = dbGetRow($conn, "SELECT COUNT(*) as count FROM friend_requests WHERE receiver_id = ? AND status = 'pending'", "i", $user_id);
$requests_count = $requests_row['count'] ?? 0;

// Total storage used
$storage_used = 0;
$column_check = $conn->query("SHOW COLUMNS FROM messages LIKE 'file_size'");
if ($column_check && $column_check->num_rows > 0) {
    $storage_row = dbGetRow($conn, "SELECT SUM(file_size) as total FROM messages WHERE sender_id = ? AND file_size IS NOT NULL", "i", $user_id);
    $storage_used = $storage_row['total'] ?? 0;
}

// Member since
$member_since = date('F Y', strtotime($user['created_at'] ?? 'now'));

// Last active
$last_active = $user['last_active'] ? timeAgo($user['last_active']) : 'Never';

// Helper function for time ago
function timeAgo($timestamp) {
    if (!$timestamp) return 'Never';
    
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
    return date('M d, Y', $time);
}

// Helper for file size formatting
function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 1) . ' GB';
}

// Helper function for avatar letter
function getAvatarLetter($username) {
    return strtoupper(substr($username, 0, 1));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php includeResponsive(); ?>
    <title>PLAYER PROFILE · CONNECTXION GAMING</title>
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

        .action-btn:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: var(--glow-effect);
        }

        /* Profile Container */
        .profile-container {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
            scrollbar-width: thin;
            scrollbar-color: var(--accent) var(--bg-tertiary);
        }

        .profile-container::-webkit-scrollbar {
            width: 4px;
        }

        .profile-container::-webkit-scrollbar-track {
            background: var(--bg-tertiary);
        }

        .profile-container::-webkit-scrollbar-thumb {
            background: var(--accent);
            border-radius: 4px;
        }

        /* Profile Header - Gaming Style */
        .profile-header {
            background: var(--gradient-primary);
            border-radius: 24px;
            padding: 40px;
            margin-bottom: 30px;
            color: white;
            position: relative;
            overflow: hidden;
            border: 1px solid var(--border);
            box-shadow: var(--glow-effect);
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            transform: rotate(30deg);
        }

        .profile-header-content {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 40px;
        }

        .profile-avatar-wrapper {
            position: relative;
        }

        .profile-avatar {
            width: 140px;
            height: 140px;
            border-radius: 30px;
            background: var(--bg-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 56px;
            font-weight: bold;
            color: var(--accent);
            border: 4px solid rgba(255,255,255,0.3);
            cursor: pointer;
            overflow: hidden;
            transition: all 0.3s;
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
        }

        .profile-avatar:hover {
            transform: scale(1.05);
            border-color: white;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-upload-btn {
            position: absolute;
            bottom: 10px;
            right: 10px;
            width: 42px;
            height: 42px;
            background: var(--accent-secondary);
            border: 2px solid white;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }

        .avatar-upload-btn:hover {
            background: var(--accent);
            transform: scale(1.1) rotate(90deg);
        }

        .profile-title {
            flex: 1;
        }

        .profile-name {
            font-size: 42px;
            font-weight: 900;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
            text-shadow: 0 0 20px rgba(255,255,255,0.3);
        }

        .profile-id {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .profile-badge {
            background: rgba(0,0,0,0.3);
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.1);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .profile-stats {
            display: flex;
            gap: 20px;
        }

        .stat-item {
            text-align: center;
            background: rgba(0,0,0,0.2);
            padding: 12px 24px;
            border-radius: 16px;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.1);
            transition: all 0.3s;
        }

        .stat-item:hover {
            transform: translateY(-2px);
            background: rgba(0,0,0,0.3);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 900;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 12px;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Profile Content Grid */
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .profile-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 25px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .profile-card::before {
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

        .profile-card:hover::before {
            transform: scaleY(1);
        }

        .profile-card:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
        }

        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .card-title span {
            font-size: 24px;
            color: var(--accent);
        }

        /* Form Styles - Gaming */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 14px 18px;
            background: var(--bg-tertiary);
            border: 2px solid var(--border);
            border-radius: 14px;
            font-size: 15px;
            color: var(--text-primary);
            transition: all 0.3s;
            font-family: 'Poppins', sans-serif;
        }

        .form-group input:focus,
        .form-group textarea:focus {
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

        /* Gaming Button */
        .btn {
            padding: 14px 24px;
            border: none;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            width: 100%;
            box-shadow: var(--glow-effect);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255, 70, 85, 0.4);
        }

        /* Alert Messages */
        .alert {
            padding: 16px 24px;
            border-radius: 14px;
            margin-bottom: 25px;
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 10px;
        }

        .stat-card {
            background: var(--bg-tertiary);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
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

        .stat-card:hover::before {
            transform: scaleX(1);
        }

        .stat-card:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .stat-card .value {
            font-size: 32px;
            font-weight: 900;
            color: var(--accent);
            margin-bottom: 5px;
            font-family: monospace;
        }

        .stat-card .label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Info List */
        .info-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .info-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            background: var(--bg-tertiary);
            border-radius: 16px;
            transition: all 0.3s;
            border: 1px solid var(--border);
        }

        .info-item:hover {
            transform: translateX(4px);
            border-color: var(--accent-secondary);
        }

        .info-icon {
            width: 44px;
            height: 44px;
            background: var(--bg-card);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: var(--accent-secondary);
            font-size: 22px;
            border: 1px solid var(--border);
        }

        .info-content {
            flex: 1;
        }

        .info-label {
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }

        /* Modal - Gaming Style */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.9);
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
            font-size: 28px;
            color: var(--text-primary);
            margin-bottom: 20px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .upload-area {
            border: 2px dashed var(--border);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: var(--bg-card);
            margin-bottom: 20px;
        }

        .upload-area:hover {
            border-color: var(--accent);
            background: var(--accent-light);
            transform: scale(1.02);
        }

        .upload-icon {
            font-size: 56px;
            color: var(--accent);
            margin-bottom: 15px;
        }

        .upload-text {
            font-size: 16px;
            color: var(--text-primary);
            margin-bottom: 5px;
            font-weight: 600;
        }

        .upload-hint {
            font-size: 12px;
            color: var(--text-muted);
        }

        #avatarPreview {
            text-align: center;
            margin: 20px 0;
        }

        #avatarPreview img {
            max-width: 150px;
            max-height: 150px;
            border-radius: 20px;
            border: 3px solid var(--accent);
            box-shadow: var(--glow-effect);
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
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
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
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

        .modal-btn.primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255,70,85,0.4);
        }

        .modal-btn.primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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

        /* Responsive */
        @media (max-width: 768px) {
            .nav-sidebar {
                width: 70px;
            }
            
            .profile-header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-stats {
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .profile-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .profile-id {
                justify-content: center;
            }
            
            .profile-name {
                font-size: 32px;
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
            
            <div class="nav-item" title="SQUAD" onclick="window.location.href='friends.php'">
                👥
                <?php if ($requests_count > 0): ?>
                <span class="nav-badge"><?php echo min($requests_count, 99); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="nav-item" title="SETTINGS" onclick="window.location.href='settings.php'">
                ⚙️
            </div>
            <div class="nav-item" title="GROUPS" onclick="window.location.href='groups.php'">
    👪
</div>
            <div class="nav-item active" title="PROFILE">
                👤
            </div>
            
            <div class="nav-footer">
                <div class="avatar <?php echo $user['last_active'] && (time() - strtotime($user['last_active']) < 300) ? 'online' : ''; ?>" onclick="window.location.href='profile.php'">
                    <?php if (!empty($user['avatar']) && file_exists($user['avatar'])): ?>
                        <img src="<?php echo $user['avatar']; ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo getAvatarLetter($user['username']); ?>
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
            <div class="content-header">
                <h1>PLAYER PROFILE</h1>
                <div class="header-actions">
                    <a href="settings.php" class="action-btn">
                        <span>⚙️</span> SETTINGS
                    </a>
                </div>
            </div>
            
            <div class="profile-container">
                <?php if ($success): ?>
                <div class="alert success">
                    <span>✓</span>
                    <?php echo $success; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert error">
                    <span>⚠️</span>
                    <?php echo $error; ?>
                </div>
                <?php endif; ?>
                
                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="profile-header-content">
                        <div class="profile-avatar-wrapper">
                            <div class="profile-avatar" onclick="showAvatarUpload()">
                                <?php if (!empty($user['avatar']) && file_exists($user['avatar'])): ?>
                                    <img src="<?php echo $user['avatar']; ?>" alt="Avatar">
                                <?php else: ?>
                                    <?php echo getAvatarLetter($user['username']); ?>
                                <?php endif; ?>
                            </div>
                            <div class="avatar-upload-btn" onclick="showAvatarUpload()" title="CHANGE AVATAR">
                                📷
                            </div>
                        </div>
                        
                        <div class="profile-title">
                            <div class="profile-name"><?php echo htmlspecialchars($user['username']); ?></div>
                            <div class="profile-id">
                                <span>PLAYER ID: <strong style="color: white;">#<?php echo $user_id; ?></strong></span>
                                <span class="profile-badge">
                                    <span>📅</span> JOINED <?php echo strtoupper($member_since); ?>
                                </span>
                                <span class="profile-badge">
                                    <span>⏰</span> LAST SEEN <?php echo strtoupper($last_active); ?>
                                </span>
                            </div>
                            
                            <div class="profile-stats">
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $friends_count; ?></div>
                                    <div class="stat-label">SQUAD</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $messages_sent; ?></div>
                                    <div class="stat-label">SENT</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $messages_received; ?></div>
                                    <div class="stat-label">RECEIVED</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Profile Content Grid -->
                <div class="profile-grid">
                    <!-- Left Column - Personal Info -->
                    <div class="profile-card">
                        <div class="card-title">
                            <span>👤</span> PLAYER INFO
                        </div>
                        
                        <form method="POST" id="profileForm">
                            <div class="form-group">
                                <label>USERNAME</label>
                                <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>EMAIL</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>PHONE</label>
                                <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="Not set">
                            </div>
                            
                            <div class="form-group">
                                <label>BIO</label>
                                <textarea name="bio" placeholder="Tell other players about yourself..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                            </div>
                            
                            <button type="submit" name="update_profile" class="btn btn-primary">SAVE CHANGES</button>
                        </form>
                    </div>
                    
                    <!-- Right Column - Stats & Info -->
                    <div class="profile-card">
                        <div class="card-title">
                            <span>📊</span> STATISTICS
                        </div>
                        
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="value"><?php echo $messages_sent; ?></div>
                                <div class="label">MESSAGES SENT</div>
                            </div>
                            <div class="stat-card">
                                <div class="value"><?php echo $messages_received; ?></div>
                                <div class="label">MESSAGES RECEIVED</div>
                            </div>
                            <?php if ($storage_used > 0): ?>
                            <div class="stat-card">
                                <div class="value"><?php echo formatFileSize($storage_used); ?></div>
                                <div class="label">STORAGE USED</div>
                            </div>
                            <?php endif; ?>
                            <div class="stat-card">
                                <div class="value"><?php echo $friends_count; ?></div>
                                <div class="label">SQUAD MEMBERS</div>
                            </div>
                        </div>
                        
                        <div class="card-title" style="margin-top: 30px;">
                            <span>📋</span> CONTACT INFO
                        </div>
                        
                        <div class="info-list">
                            <div class="info-item">
                                <div class="info-icon">📧</div>
                                <div class="info-content">
                                    <div class="info-label">EMAIL</div>
                                    <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-icon">📱</div>
                                <div class="info-content">
                                    <div class="info-label">PHONE</div>
                                    <div class="info-value"><?php echo htmlspecialchars($user['phone'] ?? 'Not set'); ?></div>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-icon">🆔</div>
                                <div class="info-content">
                                    <div class="info-label">PLAYER ID</div>
                                    <div class="info-value">#<?php echo $user_id; ?></div>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-icon">📅</div>
                                <div class="info-content">
                                    <div class="info-label">MEMBER SINCE</div>
                                    <div class="info-value"><?php echo $member_since; ?></div>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-icon">📝</div>
                                <div class="info-content">
                                    <div class="info-label">BIO</div>
                                    <div class="info-value"><?php echo htmlspecialchars($user['bio'] ?? 'No bio yet'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Avatar Upload Modal -->
    <div class="modal" id="avatarModal">
        <div class="modal-content">
            <h3>UPLOAD AVATAR</h3>
            
            <form method="POST" enctype="multipart/form-data" id="avatarForm">
                <div class="upload-area" onclick="document.getElementById('avatarInput').click()">
                    <div class="upload-icon">📷</div>
                    <div class="upload-text">CLICK TO CHOOSE IMAGE</div>
                    <div class="upload-hint">JPG, PNG, GIF, WEBP • MAX 5MB</div>
                </div>
                
                <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display: none;" onchange="previewAvatar(this)">
                
                <div id="avatarPreview" style="display: none;">
                    <img src="" alt="Preview">
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="modal-btn secondary" onclick="hideAvatarModal()">CANCEL</button>
                    <button type="submit" class="modal-btn primary" id="uploadBtn" disabled>UPLOAD</button>
                </div>
            </form>
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
    
    // Confirm logout function - submits the hidden form
    function confirmLogout() {
        document.getElementById('logoutForm').submit();
    }
    
    function showAvatarUpload() {
        document.getElementById('avatarModal').classList.add('show');
    }
    
    function hideAvatarModal() {
        document.getElementById('avatarModal').classList.remove('show');
        document.getElementById('avatarPreview').style.display = 'none';
        document.getElementById('uploadBtn').disabled = true;
        document.getElementById('avatarInput').value = '';
    }
    
    function previewAvatar(input) {
        const preview = document.getElementById('avatarPreview');
        const uploadBtn = document.getElementById('uploadBtn');
        
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            
            reader.onload = function(e) {
                preview.querySelector('img').src = e.target.result;
                preview.style.display = 'block';
                uploadBtn.disabled = false;
            }
            
            reader.readAsDataURL(input.files[0]);
            
            // Check file size
            if (input.files[0].size > 5 * 1024 * 1024) {
                alert('File size must be less than 5MB');
                input.value = '';
                preview.style.display = 'none';
                uploadBtn.disabled = true;
            }
        }
    }
    
    // Handle avatar form submission
    document.getElementById('avatarForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        fetch('profile.php', {
            method: 'POST',
            body: formData
        })
        .then(() => {
            hideAvatarModal();
            location.reload();
        })
        .catch(err => {
            console.error('Error:', err);
            alert('Failed to upload avatar');
        });
    });
    
    // Close modals when clicking outside
    window.addEventListener('click', function(e) {
        const avatarModal = document.getElementById('avatarModal');
        const logoutModal = document.getElementById('logoutModal');
        
        if (e.target === avatarModal) {
            avatarModal.classList.remove('show');
        }
        
        if (e.target === logoutModal) {
            logoutModal.classList.remove('show');
        }
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        });
    }, 5000);
    
    // Handle escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.getElementById('avatarModal').classList.remove('show');
            document.getElementById('logoutModal').classList.remove('show');
        }
    });
    </script>


</body>
</html>