<?php
// delete_account.php - Complete account deletion script

require_once 'db.php';
requireLogin();

$user_id = $_SESSION['user_id'];

// Get user data for confirmation and cleanup
$user_data = $conn->query("SELECT username, avatar FROM users WHERE user_id = $user_id")->fetch_assoc();
$username = $user_data['username'];

// Handle the deletion confirmation
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['confirm_delete']) && $_POST['confirm_delete'] == 'yes') {
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // 1. Delete all user's messages and associated files
            $messages = $conn->query("SELECT file_path FROM messages WHERE sender_id = $user_id AND file_path IS NOT NULL");
            while ($msg = $messages->fetch_assoc()) {
                if (!empty($msg['file_path']) && file_exists($msg['file_path'])) {
                    unlink($msg['file_path']);
                }
            }
            
            // 2. Delete user's avatar if exists
            if (!empty($user_data['avatar']) && file_exists($user_data['avatar'])) {
                unlink($user_data['avatar']);
            }
            
            // 3. Delete from all tables (cascading deletes will handle related records)
            $conn->query("DELETE FROM users WHERE user_id = $user_id");
            
            $conn->commit();
            
            // Clear session and logout
            $_SESSION = array();
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time()-3600, '/');
            }
            session_destroy();
            
            // Redirect to goodbye page
            header("Location: login.php?account_deleted=1");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to delete account. Please try again later.";
        }
    }
}

// Get counts for display
$friends_count = $conn->query("SELECT COUNT(*) as count FROM friends WHERE user_id = $user_id")->fetch_assoc()['count'];
$messages_count = $conn->query("SELECT COUNT(*) as count FROM messages WHERE sender_id = $user_id")->fetch_assoc()['count'];
$calls_count = $conn->query("SELECT COUNT(*) as count FROM calls WHERE caller_id = $user_id OR receiver_id = $user_id")->fetch_assoc()['count'];

// Helper function for avatar letter
function getAvatarLetter($username) {
    return strtoupper(substr($username, 0, 1));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DELETE ACCOUNT · CONNECTXION GAMING</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', 'Poppins', system-ui, sans-serif;
            background: #0a0c0f;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
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
            --warning: #faa61a;
            --gradient-primary: linear-gradient(135deg, #ff4655, #ff7b72);
            --gradient-secondary: linear-gradient(135deg, #0ed3c7, #10b3aa);
            --glow-effect: 0 0 15px var(--accent-glow);
        }

        .delete-container {
            width: 500px;
            max-width: 90%;
            margin: 20px;
        }

        .delete-card {
            background: linear-gradient(145deg, var(--bg-secondary), var(--bg-tertiary));
            border-radius: 30px;
            overflow: hidden;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.7);
            border: 1px solid var(--border);
            position: relative;
            animation: slideUp 0.5s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .delete-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--gradient-primary);
        }

        .delete-header {
            padding: 30px;
            text-align: center;
            border-bottom: 1px solid var(--border);
        }

        .delete-icon {
            width: 80px;
            height: 80px;
            background: rgba(240, 71, 71, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 40px;
            color: var(--danger);
            border: 2px solid var(--danger);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(240, 71, 71, 0.4); }
            70% { box-shadow: 0 0 0 15px rgba(240, 71, 71, 0); }
            100% { box-shadow: 0 0 0 0 rgba(240, 71, 71, 0); }
        }

        .delete-header h1 {
            color: var(--danger);
            font-size: 32px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 10px;
        }

        .delete-header p {
            color: var(--text-muted);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .delete-content {
            padding: 30px;
        }

        .warning-box {
            background: rgba(240, 71, 71, 0.1);
            border: 1px solid var(--danger);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .warning-title {
            color: var(--danger);
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .warning-list {
            list-style: none;
            padding: 0;
        }

        .warning-list li {
            padding: 10px 0;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(240, 71, 71, 0.2);
        }

        .warning-list li:last-child {
            border-bottom: none;
        }

        .warning-list li span {
            color: var(--danger);
            font-weight: 600;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 25px 0;
        }

        .stat-item {
            background: var(--bg-tertiary);
            border-radius: 16px;
            padding: 15px;
            text-align: center;
            border: 1px solid var(--border);
        }

        .stat-value {
            font-size: 24px;
            font-weight: 900;
            color: var(--danger);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 20px;
            background: var(--bg-tertiary);
            border-radius: 16px;
            margin-bottom: 25px;
            border: 1px solid var(--border);
        }

        .user-avatar {
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
            text-transform: uppercase;
        }

        .user-details {
            flex: 1;
        }

        .user-name {
            color: var(--text-primary);
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .user-id {
            color: var(--text-muted);
            font-size: 13px;
        }

        .confirmation-input {
            margin: 25px 0;
        }

        .confirmation-input label {
            display: block;
            margin-bottom: 10px;
            color: var(--text-secondary);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .confirmation-input input {
            width: 100%;
            padding: 15px 18px;
            background: var(--bg-tertiary);
            border: 2px solid var(--border);
            border-radius: 14px;
            font-size: 16px;
            color: var(--text-primary);
            transition: all 0.3s;
        }

        .confirmation-input input:focus {
            outline: none;
            border-color: var(--danger);
            box-shadow: 0 0 0 4px rgba(240, 71, 71, 0.2);
        }

        .confirmation-input input.error {
            border-color: var(--danger);
            animation: shake 0.3s;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        .btn {
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
            text-decoration: none;
            display: inline-block;
            text-align: center;
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

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(240, 71, 71, 0.4);
        }

        .btn-danger:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--bg-hover);
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .error-message {
            color: var(--danger);
            font-size: 13px;
            margin-top: 10px;
            padding: 10px;
            background: rgba(240, 71, 71, 0.1);
            border-radius: 8px;
            text-align: center;
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
            max-width: 400px;
            width: 90%;
            transform: translateY(20px) scale(0.95);
            transition: all 0.3s;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.7);
            text-align: center;
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
            color: var(--danger);
            margin-bottom: 15px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .modal-content p {
            color: var(--text-secondary);
            margin-bottom: 25px;
            font-size: 14px;
            line-height: 1.6;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
        }

        .modal-btn {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .modal-btn.danger {
            background: var(--danger);
            color: white;
        }

        .modal-btn.danger:hover {
            background: #d32f2f;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(240, 71, 71, 0.4);
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
    </style>
</head>
<body>
    <div class="delete-container">
        <div class="delete-card">
            <div class="delete-header">
                <div class="delete-icon">⚠️</div>
                <h1>DELETE ACCOUNT</h1>
                <p>This action cannot be undone</p>
            </div>
            
            <div class="delete-content">
                <?php if (isset($error)): ?>
                    <div class="error-message"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <div class="warning-box">
                    <div class="warning-title">
                        <span>⚠️</span> YOU WILL LOSE:
                    </div>
                    <ul class="warning-list">
                        <li><span>•</span> All your messages and chat history</li>
                        <li><span>•</span> <?php echo $friends_count; ?> squad connections</li>
                        <li><span>•</span> <?php echo $messages_count; ?> sent messages</li>
                        <li><span>•</span> <?php echo $calls_count; ?> call records</li>
                        <li><span>•</span> Your profile and avatar</li>
                        <li><span>•</span> All uploaded files and media</li>
                    </ul>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $friends_count; ?></div>
                        <div class="stat-label">SQUAD</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $messages_count; ?></div>
                        <div class="stat-label">MESSAGES</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $calls_count; ?></div>
                        <div class="stat-label">CALLS</div>
                    </div>
                </div>
                
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo getAvatarLetter($username); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($username); ?></div>
                        <div class="user-id">Player ID: #<?php echo $user_id; ?></div>
                    </div>
                </div>
                
                <form method="POST" id="deleteForm" onsubmit="return validateForm()">
                    <input type="hidden" name="confirm_delete" value="yes">
                    
                    <div class="confirmation-input">
                        <label>Type "DELETE" to confirm</label>
                        <input type="text" id="confirmText" placeholder="DELETE" autocomplete="off">
                    </div>
                    
                    <div class="action-buttons">
                        <a href="settings.php" class="btn btn-secondary">CANCEL</a>
                        <button type="button" class="btn btn-danger" onclick="showFinalConfirmation()" id="deleteBtn" disabled>DELETE ACCOUNT</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Final Confirmation Modal -->
    <div class="modal" id="finalConfirmModal">
        <div class="modal-content">
            <h3>⚠️ FINAL WARNING</h3>
            <p>This is your last chance. All your data will be permanently deleted and cannot be recovered. Are you absolutely sure?</p>
            <div class="modal-actions">
                <button class="modal-btn secondary" onclick="hideFinalModal()">CANCEL</button>
                <button class="modal-btn danger" onclick="submitDelete()">YES, DELETE FOREVER</button>
            </div>
        </div>
    </div>
    
    <script>
        const confirmInput = document.getElementById('confirmText');
        const deleteBtn = document.getElementById('deleteBtn');
        
        // Enable delete button only when "DELETE" is typed
        confirmInput.addEventListener('input', function() {
            deleteBtn.disabled = this.value !== 'DELETE';
            if (this.value !== 'DELETE') {
                this.classList.add('error');
            } else {
                this.classList.remove('error');
            }
        });
        
        // Show final confirmation modal
        function showFinalConfirmation() {
            if (confirmInput.value === 'DELETE') {
                document.getElementById('finalConfirmModal').classList.add('show');
            }
        }
        
        function hideFinalModal() {
            document.getElementById('finalConfirmModal').classList.remove('show');
        }
        
        // Submit the form
        function submitDelete() {
            document.getElementById('deleteForm').submit();
        }
        
        // Validate form before submission
        function validateForm() {
            return confirmInput.value === 'DELETE';
        }
        
        // Handle escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hideFinalModal();
            }
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('finalConfirmModal');
            if (e.target === modal) {
                modal.classList.remove('show');
            }
        });
    </script>
</body>
</html>