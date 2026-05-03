<?php
require_once 'db.php';

if (isLoggedIn()) {
    header("Location: home.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (empty($username) || empty($email) || empty($password)) {
        $error = "All fields are required";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters";
    } else {
        $check = dbGetRow($conn, "SELECT user_id FROM users WHERE username = ? OR email = ?", "ss", $username, $email);
        if ($check) {
            $error = "Username or email already exists";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $data = [
                'username' => $username,
                'email' => $email,
                'password' => $hashed_password
            ];
            
            if (dbInsert($conn, 'users', $data)) {
                header("Location: login.php?registered=1");
                exit();
            } else {
                $error = "Registration failed";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php includeResponsive(); ?>
    <title>REGISTER · CONNECTXION GAMING</title>
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
            min-height: 600px;
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

        /* Right Side - Register Form */
        .form-side {
            flex: 1;
            padding: 50px;
            background: var(--bg-secondary);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-header {
            margin-bottom: 25px;
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
            margin-bottom: 20px;
        }

        .form-label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            color: var(--accent);
            font-size: 12px;
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
            padding: 14px 18px;
            background: var(--bg-card);
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 14px;
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
            font-size: 11px;
        }

        /* Password strength indicator */
        .password-strength {
            margin-top: 8px;
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0;
            transition: all 0.3s;
        }

        .strength-bar.weak {
            width: 33.33%;
            background: var(--danger);
        }

        .strength-bar.medium {
            width: 66.66%;
            background: var(--warning);
        }

        .strength-bar.strong {
            width: 100%;
            background: var(--success);
        }

        .strength-text {
            font-size: 11px;
            margin-top: 5px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            margin-top: 10px;
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
            animation: slideIn 0.3s;
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

        /* Terms and conditions */
        .terms-check {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 15px 0;
        }

        .terms-check input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--accent);
        }

        .terms-check label {
            color: var(--text-secondary);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .terms-check a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }

        .terms-check a:hover {
            text-decoration: underline;
        }

        /* Login Link */
        .login-section {
            margin-top: 25px;
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        .login-link {
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

        .login-link:hover {
            border-color: var(--accent-secondary);
            color: var(--accent-secondary);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px var(--accent-glow-secondary);
        }

        .login-link span {
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
                
                <!-- Right Side - Register Form -->
                <div class="form-side">
                    <div class="form-header">
                        <h3>CREATE PLAYER</h3>
                        <p>Join the gaming arena</p>
                    </div>
                    
                    <?php if ($error): ?>
                        <div class="message error">
                            <span class="message-icon">⚠️</span>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" id="registerForm">
                        <div class="form-group">
                            <div class="form-label">
                                <span>🎮</span>
                                GAMER TAG
                            </div>
                            <div class="input-wrapper">
                                <input type="text" name="username" placeholder="Choose your username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="form-label">
                                <span>📧</span>
                                EMAIL
                            </div>
                            <div class="input-wrapper">
                                <input type="email" name="email" placeholder="Enter your email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="form-label">
                                <span>🔐</span>
                                PASSWORD
                            </div>
                            <div class="input-wrapper">
                                <input type="password" name="password" id="password" placeholder="Create a password" required>
                            </div>
                            <div class="password-strength">
                                <div class="strength-bar" id="strengthBar"></div>
                            </div>
                            <div class="strength-text" id="strengthText">Minimum 6 characters</div>
                        </div>
                        
                        <div class="form-group">
                            <div class="form-label">
                                <span>✓</span>
                                CONFIRM PASSWORD
                            </div>
                            <div class="input-wrapper">
                                <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm your password" required>
                            </div>
                            <div id="matchMessage" style="font-size: 11px; margin-top: 5px; color: var(--text-muted); text-transform: uppercase;"></div>
                        </div>
                        
                        <div class="terms-check">
                            <input type="checkbox" id="terms" required>
                            <label for="terms">I agree to the <a href="#" target="_blank">Terms of Service</a> and <a href="#" target="_blank">Privacy Policy</a></label>
                        </div>
                        
                        <button type="submit" class="game-button" id="submitBtn">
                            ▶ CREATE ACCOUNT
                        </button>
                    </form>
                    
                    <div class="login-section">
                        <a href="login.php" class="login-link">
                            <span>➡️</span>
                            ALREADY HAVE AN ACCOUNT?
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Password strength checker
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    const matchMessage = document.getElementById('matchMessage');
    const submitBtn = document.getElementById('submitBtn');
    const termsCheck = document.getElementById('terms');

    function checkPasswordStrength() {
        const val = password.value;
        let strength = 0;
        
        if (val.length >= 6) strength += 1;
        if (val.match(/[a-z]/) && val.match(/[A-Z]/)) strength += 1;
        if (val.match(/[0-9]/)) strength += 1;
        if (val.match(/[^a-zA-Z0-9]/)) strength += 1;
        
        strengthBar.className = 'strength-bar';
        
        if (val.length === 0) {
            strengthBar.style.width = '0';
            strengthText.textContent = 'Enter a password';
        } else if (val.length < 6) {
            strengthBar.classList.add('weak');
            strengthText.textContent = 'Too short - minimum 6 characters';
        } else if (strength <= 2) {
            strengthBar.classList.add('weak');
            strengthText.textContent = 'Weak password';
        } else if (strength === 3) {
            strengthBar.classList.add('medium');
            strengthText.textContent = 'Medium password';
        } else {
            strengthBar.classList.add('strong');
            strengthText.textContent = 'Strong password';
        }
        
        checkPasswordMatch();
    }

    function checkPasswordMatch() {
        if (confirmPassword.value.length === 0) {
            matchMessage.textContent = '';
        } else if (password.value === confirmPassword.value) {
            matchMessage.innerHTML = '✓ PASSWORDS MATCH';
            matchMessage.style.color = 'var(--success)';
        } else {
            matchMessage.innerHTML = '✗ PASSWORDS DO NOT MATCH';
            matchMessage.style.color = 'var(--danger)';
        }
        
        validateForm();
    }

    function validateForm() {
        const passwordValid = password.value.length >= 6;
        const passwordsMatch = password.value === confirmPassword.value;
        const termsChecked = termsCheck.checked;
        
        if (passwordValid && passwordsMatch && termsChecked) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
        } else {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
        }
    }

    password.addEventListener('input', checkPasswordStrength);
    confirmPassword.addEventListener('input', checkPasswordMatch);
    termsCheck.addEventListener('change', validateForm);

    // Initial validation
    validateForm();

    // Form submission
    document.getElementById('registerForm').addEventListener('submit', function(e) {
        if (!termsCheck.checked) {
            e.preventDefault();
            alert('You must agree to the Terms of Service and Privacy Policy');
        }
    });

    // Auto-hide error message after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.message').forEach(msg => {
            msg.style.opacity = '0';
            setTimeout(() => msg.style.display = 'none', 300);
        });
    }, 5000);
    </script>
</body>
</html>