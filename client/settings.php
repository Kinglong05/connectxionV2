<?php
require_once 'db.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Get user data for profile
$user_data = dbGetRow($conn, "SELECT * FROM users WHERE user_id = ?", "i", $user_id);

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $bio = trim($_POST['bio'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        // Check if username/email already exists (excluding current user)
        $check = dbGetRow($conn, "SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?", "ssi", $username, $email, $user_id);
        
        if ($check) {
            $error = "Username or email already taken";
        } else {
            // Check if bio and phone columns exist
            $columns = $conn->query("SHOW COLUMNS FROM users");
            $has_bio = false;
            $has_phone = false;
            while($col = $columns->fetch_assoc()) {
                if ($col['Field'] == 'bio') $has_bio = true;
                if ($col['Field'] == 'phone') $has_phone = true;
            }
            
            $update_data = [
                'username' => $username,
                'email' => $email
            ];
            
            if ($has_bio && $has_phone) {
                $update_data['bio'] = $bio;
                $update_data['phone'] = $phone;
            }
            
            if (dbUpdate($conn, 'users', $update_data, "user_id = ?", "i", [$user_id])) {
                $_SESSION['username'] = $username;
                
                // Refresh user data
                $user_data = dbGetRow($conn, "SELECT * FROM users WHERE user_id = ?", "i", $user_id);
                
                $success = "Profile updated successfully";
            } else {
                $error = "Failed to update profile";
            }
        }
    }
    
    if (isset($_POST['change_password'])) {
        $current = $_POST['current_password'];
        $new = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];
        
        // Verify current password
        $user = dbGetRow($conn, "SELECT password FROM users WHERE user_id = ?", "i", $user_id);
        
        if (!$user || !password_verify($current, $user['password'])) {
            $error = "Current password is incorrect";
        } elseif (strlen($new) < 6) {
            $error = "New password must be at least 6 characters";
        } elseif ($new !== $confirm) {
            $error = "New passwords do not match";
        } else {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            if (prepareAndExecute($conn, "UPDATE users SET password = ? WHERE user_id = ?", "si", $hashed, $user_id)) {
                $success = "Password changed successfully";
            } else {
                $error = "Failed to update password";
            }
        }
    }
    
    if (isset($_POST['update_avatar'])) {
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['avatar']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed)) {
                $upload_dir = 'uploads/avatars/';
                
                // Create directory if it doesn't exist
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Generate unique filename
                $new_filename = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $upload_path)) {
                    // Delete old avatar if exists
                    if (!empty($user_data['avatar']) && file_exists($user_data['avatar'])) {
                        unlink($user_data['avatar']);
                    }
                    
                    // Update database
                    $conn->query("UPDATE users SET avatar = '$upload_path' WHERE user_id = $user_id");
                    
                    // Refresh user data
                    $user_data = $conn->query("SELECT * FROM users WHERE user_id = $user_id")->fetch_assoc();
                    
                    $success = "Avatar updated successfully";
                } else {
                    $error = "Failed to upload avatar";
                }
            } else {
                $error = "Invalid file type. Allowed: jpg, jpeg, png, gif";
            }
        } else {
            $error = "Please select an image file";
        }
    }
}

// Get statistics
$friends_count_row = dbGetRow($conn, "SELECT COUNT(*) as count FROM friends WHERE user_id = ?", "i", $user_id);
$friends_count = $friends_count_row['count'] ?? 0;

$messages_sent_row = dbGetRow($conn, "SELECT COUNT(*) as count FROM messages WHERE sender_id = ?", "i", $user_id);
$messages_sent = $messages_sent_row['count'] ?? 0;

$calls_made_row = dbGetRow($conn, "SELECT COUNT(*) as count FROM calls WHERE caller_id = ?", "i", $user_id);
$calls_made = $calls_made_row['count'] ?? 0;

// Get missed calls count for badge
$missed_row = dbGetRow($conn, "SELECT COUNT(*) as count FROM calls WHERE receiver_id = ? AND status = 'missed'", "i", $user_id);
$missed_calls = $missed_row['count'] ?? 0;

// Get unread messages count for badge
$unread_row = dbGetRow($conn, "SELECT COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0", "i", $user_id);
$total_unread = $unread_row['count'] ?? 0;

// Get friend requests count for badge
$requests_row = dbGetRow($conn, "SELECT COUNT(*) as count FROM friend_requests WHERE receiver_id = ? AND status = 'pending'", "i", $user_id);
$requests_count = $requests_row['count'] ?? 0;

// Helper function for avatar letter
function getAvatarLetter($username) {
    return strtoupper(substr($username, 0, 1));
}

// Helper function to check if user is online
$is_online = isset($user_data['last_active']) && $user_data['last_active'] && (time() - strtotime($user_data['last_active']) < 300);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php includeResponsive(); ?>
    <title>SETTINGS · CONNECTXION GAMING</title>
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

        /* Profile Header - Gaming Style */
        .profile-header {
            padding: 20px 30px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
            overflow: hidden;
        }

        .profile-header::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 70, 85, 0.05), transparent);
            animation: scanline 8s linear infinite;
            pointer-events: none;
        }

        .profile-avatar-large {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
            font-weight: bold;
            text-transform: uppercase;
            border: 3px solid transparent;
            transition: all 0.3s;
            box-shadow: var(--glow-effect);
            overflow: hidden;
        }

        .profile-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-avatar-large:hover {
            transform: scale(1.05);
            border-color: var(--accent);
        }

        .profile-info {
            flex: 1;
        }

        .profile-name {
            font-size: 28px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .profile-id {
            color: var(--text-muted);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .profile-badge {
            background: var(--bg-tertiary);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            color: var(--accent-secondary);
            border: 1px solid var(--border);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .profile-stat {
            display: flex;
            gap: 20px;
        }

        .profile-stat-item {
            text-align: center;
            background: var(--bg-tertiary);
            padding: 8px 16px;
            border-radius: 12px;
            border: 1px solid var(--border);
        }

        .profile-stat-value {
            color: var(--accent);
            font-weight: 700;
            font-size: 18px;
        }

        .profile-stat-label {
            color: var(--text-muted);
            font-size: 11px;
            text-transform: uppercase;
        }

        /* Content Header */
        .content-header {
            padding: 20px 30px;
            border-bottom: 1px solid var(--border);
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

        /* Settings Container */
        .settings-container {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
            scrollbar-width: thin;
            scrollbar-color: var(--accent) var(--bg-tertiary);
        }

        .settings-container::-webkit-scrollbar {
            width: 4px;
        }

        .settings-container::-webkit-scrollbar-track {
            background: var(--bg-tertiary);
        }

        .settings-container::-webkit-scrollbar-thumb {
            background: var(--accent);
            border-radius: 4px;
        }

        /* Settings Grid */
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
            gap: 25px;
        }

        .settings-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 24px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .settings-card::before {
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

        .settings-card:hover::before {
            transform: scaleY(1);
        }

        .settings-card:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .card-icon {
            width: 50px;
            height: 50px;
            background: var(--bg-tertiary);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
            font-size: 24px;
            border: 1px solid var(--border);
        }

        .card-title {
            flex: 1;
        }

        .card-title h3 {
            color: var(--text-primary);
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .card-title p {
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
        .form-group textarea,
        .form-group select {
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
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-glow);
        }

        .form-group input[readonly] {
            background: var(--bg-card);
            color: var(--text-muted);
            cursor: not-allowed;
            border-color: var(--border);
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

        /* File Upload - Gaming Style */
        .file-upload {
            position: relative;
            margin-bottom: 20px;
        }

        .file-upload input[type="file"] {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-upload-label {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            background: var(--bg-tertiary);
            border: 2px dashed var(--border);
            border-radius: 14px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.3s;
        }

        .file-upload-label:hover {
            border-color: var(--accent);
            background: var(--bg-hover);
        }

        .file-upload-label span {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Avatar Preview */
        .avatar-preview {
            width: 100px;
            height: 100px;
            border-radius: 20px;
            background: var(--bg-tertiary);
            margin: 0 auto 20px;
            overflow: hidden;
            border: 3px solid var(--border);
            transition: all 0.3s;
        }

        .avatar-preview:hover {
            border-color: var(--accent);
            transform: scale(1.05);
        }

        .avatar-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Gaming Buttons */
        .btn {
            padding: 14px 24px;
            border: none;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
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

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #d32f2f;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(240, 71, 71, 0.4);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--border);
            color: var(--text-secondary);
        }

        .btn-outline:hover {
            background: var(--bg-hover);
            border-color: var(--accent);
            color: var(--accent);
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

        .alert.success span {
            color: var(--success);
        }

        .alert.error {
            border-left-color: var(--danger);
            color: var(--danger);
        }

        .alert.error span {
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

        /* Toggle Switch - Gaming Style */
        .switch-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 0;
            border-bottom: 1px solid var(--border);
        }

        .switch-item:last-child {
            border-bottom: none;
        }

        .switch-info h4 {
            font-size: 15px;
            color: var(--text-primary);
            margin-bottom: 4px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .switch-info p {
            font-size: 12px;
            color: var(--text-muted);
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 56px;
            height: 28px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--border);
            transition: .3s;
            border-radius: 28px;
            border: 1px solid var(--border);
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 3px;
            bottom: 2px;
            background-color: var(--text-secondary);
            transition: .3s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--accent);
            border-color: var(--accent);
        }

        input:checked + .slider:before {
            transform: translateX(28px);
            background-color: white;
        }

        /* Stats Cards - Gaming */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 25px;
        }

        .stat-card {
            background: var(--bg-tertiary);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            border: 1px solid var(--border);
            transition: all 0.3s;
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

        .stat-value {
            font-size: 32px;
            font-weight: 900;
            color: var(--accent);
            margin-bottom: 5px;
            font-family: monospace;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Danger Zone */
        .danger-zone {
            margin-top: 30px;
            padding: 20px;
            background: rgba(240, 71, 71, 0.05);
            border: 1px solid rgba(240, 71, 71, 0.2);
            border-radius: 16px;
        }

        .danger-zone .card-header h3 {
            color: var(--danger);
        }

        .danger-zone p {
            color: var(--text-muted);
            font-size: 13px;
            margin-bottom: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Modal Styles */
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
            
            .settings-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
                padding: 20px;
            }
            
            .profile-id {
                justify-content: center;
            }
            
            .profile-stat {
                justify-content: center;
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
            <div class="nav-item" title="GROUPS" onclick="window.location.href='groups.php'">
    👪
</div>
            <div class="nav-item active" title="SETTINGS">
                ⚙️
            </div>
            
            <div class="nav-footer">
                <div class="avatar <?php echo $is_online ? 'online' : ''; ?>" onclick="window.location.href='profile.php'">
                    <?php if (!empty($user_data['avatar']) && file_exists($user_data['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($user_data['avatar']); ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo getAvatarLetter($user_data['username']); ?>
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
            <!-- Profile Header - Gaming Style -->
            <div class="profile-header">
                <div class="profile-avatar-large">
                    <?php if (!empty($user_data['avatar']) && file_exists($user_data['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($user_data['avatar']); ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo getAvatarLetter($user_data['username']); ?>
                    <?php endif; ?>
                </div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($user_data['username']); ?></div>
                    <div class="profile-id">
                        <span>PLAYER ID: <strong style="color: var(--accent);">#<?php echo $user_id; ?></strong></span>
                        <span class="profile-badge">MEMBER SINCE <?php echo isset($user_data['created_at']) ? date('M Y', strtotime($user_data['created_at'])) : '2024'; ?></span>
                    </div>
                    <div class="profile-stat">
                        <div class="profile-stat-item">
                            <div class="profile-stat-value"><?php echo $friends_count; ?></div>
                            <div class="profile-stat-label">SQUAD</div>
                        </div>
                        <div class="profile-stat-item">
                            <div class="profile-stat-value"><?php echo $messages_sent; ?></div>
                            <div class="profile-stat-label">MESSAGES</div>
                        </div>
                        
                    </div>
                </div>
            </div>
            
            <div class="content-header">
                <h1>SETTINGS</h1>
            </div>
            
            <div class="settings-container">
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
                
                <div class="settings-grid">
                    <!-- Avatar Settings Card -->
                    <div class="settings-card">
                        <div class="card-header">
                            <div class="card-icon">🖼️</div>
                            <div class="card-title">
                                <h3>AVATAR</h3>
                                <p>Change your profile picture</p>
                            </div>
                        </div>
                        
                        <div class="avatar-preview">
                            <?php if (!empty($user_data['avatar']) && file_exists($user_data['avatar'])): ?>
                                <img src="<?php echo htmlspecialchars($user_data['avatar']); ?>" alt="Current Avatar">
                            <?php else: ?>
                                <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: var(--gradient-secondary); color: white; font-size: 40px;">
                                    <?php echo getAvatarLetter($user_data['username']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data">
                            <div class="file-upload">
                                <input type="file" name="avatar" id="avatar" accept="image/jpeg,image/png,image/gif">
                                <label for="avatar" class="file-upload-label">
                                    <span>📁</span>
                                    <span>CHOOSE IMAGE</span>
                                </label>
                            </div>
                            <p style="color: var(--text-muted); font-size: 11px; margin-bottom: 20px; text-align: center;">Supported: JPG, PNG, GIF (Max 5MB)</p>
                            <button type="submit" name="update_avatar" class="btn btn-primary">UPLOAD AVATAR</button>
                        </form>
                    </div>
                    
                    <!-- Profile Settings Card -->
                    <div class="settings-card">
                        <div class="card-header">
                            <div class="card-icon">👤</div>
                            <div class="card-title">
                                <h3>PROFILE</h3>
                                <p>Update your player info</p>
                            </div>
                        </div>
                        
                        <form method="POST">
                            <div class="form-group">
                                <label>USERNAME</label>
                                <input type="text" name="username" value="<?php echo htmlspecialchars($user_data['username']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>EMAIL</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>BIO</label>
                                <textarea name="bio" placeholder="Tell other players about yourself..."><?php echo htmlspecialchars($user_data['bio'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>PHONE</label>
                                <input type="tel" name="phone" value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>" placeholder="Not set">
                            </div>
                            
                            <button type="submit" name="update_profile" class="btn btn-primary">SAVE CHANGES</button>
                        </form>
                    </div>
                    
                    <!-- Password Settings Card -->
                    <div class="settings-card">
                        <div class="card-header">
                            <div class="card-icon">🔐</div>
                            <div class="card-title">
                                <h3>SECURITY</h3>
                                <p>Change your password</p>
                            </div>
                        </div>
                        
                        <form method="POST">
                            <div class="form-group">
                                <label>CURRENT PASSWORD</label>
                                <input type="password" name="current_password" required>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>NEW PASSWORD</label>
                                    <input type="password" name="new_password" required>
                                </div>
                                
                                <div class="form-group">
                                    <label>CONFIRM</label>
                                    <input type="password" name="confirm_password" required>
                                </div>
                            </div>
                            
                            <button type="submit" name="change_password" class="btn btn-primary">UPDATE PASSWORD</button>
                        </form>
                        
                        <!-- Stats Grid -->
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $friends_count; ?></div>
                                <div class="stat-label">SQUAD</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $messages_sent; ?></div>
                                <div class="stat-label">MESSAGES</div>
                            </div>
                           
                        </div>
                    </div>
                    
                    <!-- About Card -->
                    <div class="settings-card">
                        <div class="card-header">
                            <div class="card-icon">ℹ️</div>
                            <div class="card-title">
                                <h3>ABOUT</h3>
                                <p>Game information</p>
                            </div>
                        </div>
                        
                        <div style="text-align: center; padding: 20px;">
                            <div style="width: 80px; height: 80px; margin: 0 auto 15px; filter: drop-shadow(0 0 20px var(--accent-glow));">
                                <img src="photos/logo.png" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;">
                            </div>
                            <h2 style="color: var(--text-primary); margin-bottom: 5px; font-weight: 800; text-transform: uppercase; letter-spacing: 2px;">CONNECTXION</h2>
                            <p style="color: var(--accent); margin-bottom: 20px; font-weight: 600;">GAMING EDITION v1.0.0</p>
                            
                            <div style="background: var(--bg-tertiary); border-radius: 14px; padding: 16px; text-align: left; margin-bottom: 20px; border: 1px solid var(--border);">
                                <p style="color: var(--text-secondary); margin-bottom: 8px;">© 2024 CONNECTXION. All rights reserved.</p>
                                <p style="color: var(--text-muted); font-size: 12px;">Secure gaming communication platform for players worldwide.</p>
                            </div>
                            
                            <div style="display: flex; gap: 20px; justify-content: center;">
                                <a href="#" style="color: var(--accent); text-decoration: none; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">TERMS</a>
                                <a href="#" style="color: var(--accent); text-decoration: none; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">PRIVACY</a>
                                <a href="#" style="color: var(--accent); text-decoration: none; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">LICENSES</a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Danger Zone Card -->
                    <div class="settings-card danger-zone">
                        <div class="card-header">
                            <div class="card-icon" style="color: var(--danger);">⚠️</div>
                            <div class="card-title">
                                <h3 style="color: var(--danger);">DANGER ZONE</h3>
                                <p>Irreversible actions</p>
                            </div>
                        </div>
                        
                        <p>Once you delete your account, there is no going back. All your data, messages, and connections will be permanently removed.</p>
                        
                        <button class="btn btn-danger" onclick="deleteAccount()" style="width: 100%;">DELETE ACCOUNT</button>
                    </div>
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
    
    // Confirm logout function - submits the hidden form
    function confirmLogout() {
        document.getElementById('logoutForm').submit();
    }
    
    function deleteAccount() {
        if (confirm('⚠️ WARNING: This will permanently delete your account and all data. Are you absolutely sure?')) {
            if (confirm('This action CANNOT be undone. Click OK to confirm deletion.')) {
                window.location.href = 'delete_account.php';
            }
        }
    }
    
    // Preview avatar before upload
    document.getElementById('avatar').addEventListener('change', function(e) {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.querySelector('.avatar-preview');
                if (preview) {
                    preview.innerHTML = `<img src="${e.target.result}" alt="Avatar Preview">`;
                }
            }
            reader.readAsDataURL(this.files[0]);
        }
    });
    
    // Dark mode toggle (maintains gaming theme)
    const darkModeToggle = document.getElementById('darkModeToggle');
    if (darkModeToggle) {
        darkModeToggle.addEventListener('change', function(e) {
            if (!e.target.checked) {
                // Keep dark mode always - gaming theme is dark
                e.target.checked = true;
                showToast('GAMING THEME REQUIRES DARK MODE', 'info');
            }
        });
    }
    
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            alert.style.opacity = '0';
            setTimeout(() => alert.style.display = 'none', 300);
        });
    }, 5000);
    
    // Toast notification function
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            padding: 16px 24px;
            border-radius: 14px;
            z-index: 2000;
            animation: slideInRight 0.3s;
            border-left: 4px solid ${type === 'info' ? 'var(--accent)' : type === 'success' ? 'var(--success)' : 'var(--danger)'};
            box-shadow: 0 8px 24px rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        `;
        toast.innerHTML = `
            <span style="flex:1;">${message}</span>
            <button onclick="this.parentElement.remove()" style="background:none; border:none; color:white; cursor:pointer; font-size:18px;">✕</button>
        `;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 3000);
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(e) {
        const logoutModal = document.getElementById('logoutModal');
        
        if (e.target === logoutModal) {
            logoutModal.classList.remove('show');
        }
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


</body>
</html>