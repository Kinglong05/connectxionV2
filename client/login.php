<?php
require_once 'db.php';

if (isLoggedIn()) {
    header("Location: home.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if (empty($email) || empty($password)) {
        $error = "All fields are required";
    } else {
        $user = dbGetRow($conn, "SELECT * FROM users WHERE email = ? OR username = ?", "ss", $email, $email);
        
        if ($user) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                header("Location: home.php");
                exit();
            } else {
                $error = "Invalid password";
            }
        } else {
            $error = "User not found";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php includeResponsive(); ?>
    <title>LOGIN · CONNECTXION GAMING</title>
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
            position: relative;
            overflow: hidden;
            color: #e0e0e0;
        }

        /* Gaming Theme Variables - Matching chat.php */
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

        /* Gaming grid overlay */
        body::before {
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
            z-index: 1;
        }

        /* Static orbs - NO ANIMATION */
        .orb {
            position: absolute;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.15;
            z-index: 0;
        }
        
        .orb-1 {
            background: var(--accent);
            top: -100px;
            left: -100px;
        }
        
        .orb-2 {
            background: var(--accent-secondary);
            bottom: -100px;
            right: -100px;
        }
        
        .orb-3 {
            background: #9966ff;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 500px;
            height: 500px;
            opacity: 0.1;
        }

        /* Main Container */
        .auth-container {
            width: 1000px;
            max-width: 90%;
            position: relative;
            z-index: 10;
        }

        /* Gaming Panel - Matching chat.php card style */
        .gaming-panel {
            background: linear-gradient(145deg, var(--bg-secondary), var(--bg-tertiary));
            border-radius: 30px;
            overflow: hidden;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.7);
            border: 1px solid var(--border);
            position: relative;
        }

        .gaming-panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--gradient-primary);
            z-index: 2;
        }

        /* Panel Grid */
        .panel-grid {
            display: flex;
            min-height: 550px;
        }

        /* Left Side - Gaming Visual with Big Logo */
        .visual-side {
            flex: 1;
            background: linear-gradient(145deg, #1a1f25, #14181c);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            border-right: 1px solid var(--border);
        }

        .visual-side::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 30% 50%, var(--accent-glow) 0%, transparent 70%);
            pointer-events: none;
        }

        .gaming-logo {
            text-align: center;
            position: relative;
            z-index: 2;
        }

        /* Big Logo Image */
        .logo-image {
            width: 350px;
            height: 350px;
            margin: 0 auto;
            position: relative;
            filter: drop-shadow(var(--glow-effect));
        }

        .logo-image img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        /* Right Side - Login Form */
        .form-side {
            flex: 1;
            padding: 50px;
            background: var(--bg-secondary);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-header {
            margin-bottom: 35px;
        }

        .form-header h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 8px;
        }

        .form-header p {
            color: var(--text-muted);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            color: var(--accent);
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-label span {
            font-size: 16px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper input {
            width: 100%;
            padding: 16px 18px;
            background: var(--bg-card);
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 15px;
            color: var(--text-primary);
            transition: all 0.3s;
            font-family: 'Poppins', sans-serif;
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-glow);
        }

        .input-wrapper input::placeholder {
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 12px;
        }

        /* Gaming Button - Matching chat.php send button */
        .game-button {
            width: 100%;
            padding: 16px;
            background: var(--gradient-primary);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all 0.3s;
            box-shadow: var(--glow-effect);
            margin-top: 15px;
        }

        .game-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .game-button:hover::before {
            left: 100%;
        }

        .game-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px var(--accent-glow);
        }

        /* Error/Success Messages - Matching toast style */
        .message {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            border-left: 4px solid;
        }

        .message.error {
            background: rgba(240, 71, 71, 0.1);
            border-left-color: var(--danger);
            color: var(--danger);
        }

        .message.success {
            background: rgba(67, 181, 129, 0.1);
            border-left-color: var(--success);
            color: var(--success);
        }

        .message-icon {
            font-size: 20px;
        }

        /* Account Deleted Message - New Addition */
        .message.goodbye {
            background: rgba(14, 211, 199, 0.1);
            border-left-color: var(--accent-secondary);
            color: var(--accent-secondary);
        }

        /* Sign Up Link */
        .signup-section {
            margin-top: 30px;
            text-align: center;
            padding-top: 25px;
            border-top: 1px solid var(--border);
        }

        .signup-link {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 12px 30px;
            border: 2px solid var(--border);
            border-radius: 30px;
            transition: all 0.3s;
        }

        .signup-link:hover {
            border-color: var(--accent-secondary);
            color: var(--accent-secondary);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px var(--accent-glow-secondary);
        }

        .signup-link span {
            font-size: 18px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .panel-grid {
                flex-direction: column;
            }
            
            .visual-side {
                padding: 40px 20px;
                border-right: none;
                border-bottom: 1px solid var(--border);
            }
            
            .form-side {
                padding: 35px;
            }
            
            .logo-image {
                width: 250px;
                height: 250px;
            }
        }

        @media (max-width: 480px) {
            .form-side {
                padding: 25px;
            }
            
            .logo-image {
                width: 200px;
                height: 200px;
            }
        }
    </style>
</head>
<body>
    <!-- Static Orbs -->
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    
    <div class="auth-container">
        <div class="gaming-panel">
            <div class="panel-grid">
                <!-- Left Side - Only Big Logo -->
                <div class="visual-side">
                    <div class="gaming-logo">
                        <div class="logo-image">
                            <img src="photos/logo.png" alt="CONNECTXION GAMING">
                        </div>
                    </div>
                </div>
                
                <!-- Right Side - Login Form -->
                <div class="form-side">
                    <div class="form-header">
                        <h3>PLAYER LOGIN</h3>
                        <p>Enter your credentials to start</p>
                    </div>
                    
                    <?php if (isset($_GET['registered'])): ?>
                        <div class="message success">
                            <span class="message-icon">✅</span>
                            REGISTRATION SUCCESSFUL! READY TO PLAY?
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_GET['account_deleted'])): ?>
                        <div class="message goodbye">
                            <span class="message-icon">👋</span>
                            ACCOUNT DELETED SUCCESSFULLY. WE HOPE TO SEE YOU AGAIN!
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="message error">
                            <span class="message-icon">⚠️</span>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="form-group">
                            <div class="form-label">
                                <span>🎮</span>
                                GAMER TAG / EMAIL
                            </div>
                            <div class="input-wrapper">
                                <input type="text" name="email" placeholder="Enter your username or email" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="form-label" style="justify-content: space-between;">
                                <div><span>🔐</span> ACCESS KEY</div>
                                <a href="javascript:void(0)" onclick="showForgotModal()" style="color: var(--text-muted); font-size: 10px; text-decoration: none;">FORGOT?</a>
                            </div>
                            <div class="input-wrapper">
                                <input type="password" name="password" placeholder="Enter your password" required>
                            </div>
                        </div>
                        
                        <button type="submit" class="game-button" id="loginBtn">
                            ▶ LAUNCH GAME
                        </button>
                    </form>
                    
                    <div class="signup-section">
                        <a href="register.php" class="signup-link">
                            <span>➕</span>
                            CREATE NEW PLAYER
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div class="modal" id="forgotModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>RECOVER ACCESS</h3>
                <p>Enter your email to reset your key</p>
            </div>
            <div class="form-group">
                <div class="form-label"><span>📧</span> REGISTERED EMAIL</div>
                <div class="input-wrapper">
                    <input type="email" id="forgotEmail" placeholder="Enter your email address">
                </div>
            </div>
            <div class="modal-actions" style="display: flex; gap: 15px;">
                <button class="game-button secondary" onclick="hideForgotModal()" style="background: var(--bg-card); flex: 1;">CANCEL</button>
                <button class="game-button" onclick="handleForgotSubmit()" style="flex: 2;">SEND RESET LINK</button>
            </div>
        </div>
    </div>

    <!-- Transition Overlay -->
    <div class="transition-overlay" id="transitionOverlay">
        <div class="loader-content">
            <div class="loader-logo">
                <img src="photos/logo.png" alt="Logo">
            </div>
            <div class="loader-bar">
                <div class="loader-progress"></div>
            </div>
            <div class="loader-text">INITIALIZING SYSTEM...</div>
        </div>
    </div>

    <style>
        /* Modal Styles */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.9);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .modal.show {
            opacity: 1;
            visibility: visible;
        }
        .modal-content {
            background: var(--bg-secondary);
            padding: 40px;
            border-radius: 24px;
            width: 400px;
            max-width: 90%;
            border: 1px solid var(--border);
            transform: scale(0.9) translateY(20px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .modal.show .modal-content {
            transform: scale(1) translateY(0);
        }

        /* Transition Overlay */
        .transition-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--bg-primary);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.5s ease;
        }
        .transition-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .loader-content {
            text-align: center;
        }
        .loader-logo {
            width: 100px;
            margin: 0 auto 30px;
            animation: pulse 2s infinite;
        }
        .loader-logo img { width: 100%; }
        .loader-bar {
            width: 250px;
            height: 4px;
            background: var(--bg-tertiary);
            border-radius: 2px;
            overflow: hidden;
            margin: 0 auto 20px;
        }
        .loader-progress {
            width: 0%;
            height: 100%;
            background: var(--gradient-primary);
            box-shadow: var(--glow-effect);
            transition: width 0.3s ease;
        }
        .loader-text {
            color: var(--accent);
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 3px;
            text-transform: uppercase;
        }
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }
    </style>

    <script>
        // Transition animation on load
        window.addEventListener('load', () => {
            const overlay = document.getElementById('transitionOverlay');
            overlay.classList.add('active');
            const progress = overlay.querySelector('.loader-progress');
            const text = overlay.querySelector('.loader-text');
            
            setTimeout(() => { progress.style.width = '30%'; }, 100);
            setTimeout(() => { progress.style.width = '70%'; text.innerText = 'CONNECTING TO SERVER...'; }, 400);
            setTimeout(() => { progress.style.width = '100%'; text.innerText = 'ACCESS GRANTED'; }, 800);
            setTimeout(() => { overlay.classList.remove('active'); }, 1200);
        });

        // Transition on login submit
        document.querySelector('form').addEventListener('submit', function(e) {
            const overlay = document.getElementById('transitionOverlay');
            const text = overlay.querySelector('.loader-text');
            const progress = overlay.querySelector('.loader-progress');
            
            overlay.classList.add('active');
            progress.style.width = '0%';
            text.innerText = 'VERIFYING CREDENTIALS...';
            
            setTimeout(() => { progress.style.width = '60%'; }, 200);
            // Form will submit normally
        });

        // Toast notification system
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast-notif ${type}`;
            toast.innerHTML = `
                <div class="toast-icon">${type === 'success' ? '✅' : (type === 'error' ? '❌' : 'ℹ️')}</div>
                <div class="toast-content">
                    <div class="toast-title">${type.toUpperCase()}</div>
                    <div class="toast-message">${message}</div>
                </div>
            `;
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 100);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        // Forgot Modal functions
        function showForgotModal() {
            document.getElementById('forgotModal').classList.add('show');
        }
        function hideForgotModal() {
            document.getElementById('forgotModal').classList.remove('show');
        }
        function handleForgotSubmit() {
            const email = document.getElementById('forgotEmail').value;
            if (!email) {
                showToast('PLEASE ENTER YOUR REGISTERED EMAIL', 'error');
                return;
            }
            
            const btn = event.target;
            const originalText = btn.innerText;
            btn.innerText = 'PROCESSING...';
            btn.disabled = true;

            const formData = new FormData();
            formData.append('email', email);

            fetch('forgot_password_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btn.innerText = originalText;
                btn.disabled = false;
                
                if (data.success) {
                    showToast(data.message, 'success');
                    console.log("RECOVERY LINK (DEBUG):", data.link);
                    // For the user's convenience in this environment, we'll also show the link in a non-alert way if possible,
                    // but for now, we'll just log it and show the toast.
                    hideForgotModal();
                } else {
                    showToast(data.error, 'error');
                }
            })
            .catch(err => {
                btn.innerText = originalText;
                btn.disabled = false;
                showToast('SYSTEM CONNECTION ERROR', 'error');
            });
        }
    </script>

    <style>
        /* Toast Notifications */
        .toast-notif {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-left: 4px solid var(--accent);
            padding: 15px 25px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 15px;
            z-index: 10000;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            transform: translateX(120%);
            transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .toast-notif.show {
            transform: translateX(0);
        }
        .toast-notif.success { border-left-color: #43b581; }
        .toast-notif.error { border-left-color: var(--danger); }
        .toast-icon { font-size: 20px; }
        .toast-title { font-size: 10px; font-weight: 800; color: var(--text-muted); letter-spacing: 1px; }
        .toast-message { font-size: 13px; font-weight: 600; color: var(--text-primary); }
    </style>
</body>
</html>