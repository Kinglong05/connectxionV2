<?php
require_once 'db.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

if (empty($token)) {
    header("Location: login.php");
    exit();
}

// Verify token
$stmt = $conn->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $error = "INVALID OR EXPIRED RECOVERY LINK";
} else {
    $resetData = $result->fetch_assoc();
    $email = $resetData['email'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = $_POST['password'];
        $confirm = $_POST['confirm_password'];

        if ($password !== $confirm) {
            $error = "PASSWORDS DO NOT MATCH";
        } elseif (strlen($password) < 6) {
            $error = "PASSWORD MUST BE AT LEAST 6 CHARACTERS";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
            $stmt->bind_param("ss", $hashedPassword, $email);
            
            if ($stmt->execute()) {
                // Delete the used token
                $conn->query("DELETE FROM password_resets WHERE token = '$token'");
                $success = "PASSWORD RESET SUCCESSFUL! REDIRECTING...";
                header("Refresh: 3; URL=login.php");
            } else {
                $error = "FAILED TO UPDATE PASSWORD";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RESET ACCESS KEY · CONNECTXION</title>
    <style>
        :root {
            --bg-primary: #0a0c0f;
            --bg-secondary: #14181c;
            --bg-tertiary: #1e2329;
            --bg-card: #1a1f25;
            --text-primary: #ffffff;
            --text-secondary: #b0b7c2;
            --accent: #ff4655;
            --accent-glow: rgba(255, 70, 85, 0.3);
            --border: #2a313c;
            --gradient-primary: linear-gradient(135deg, #ff4655, #ff7b72);
            --glow-effect: 0 0 15px var(--accent-glow);
        }

        body {
            font-family: 'Segoe UI', 'Poppins', sans-serif;
            background: var(--bg-primary);
            color: #e0e0e0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: repeating-linear-gradient(0deg, rgba(0, 0, 0, 0.15) 0px, rgba(0, 0, 0, 0.15) 1px, transparent 1px, transparent 2px);
            pointer-events: none;
            z-index: 1;
        }

        .reset-container {
            width: 450px;
            max-width: 90%;
            background: linear-gradient(145deg, var(--bg-secondary), var(--bg-tertiary));
            border-radius: 30px;
            padding: 50px;
            border: 1px solid var(--border);
            position: relative;
            z-index: 10;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.7);
        }

        .reset-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--gradient-primary);
        }

        .header {
            margin-bottom: 35px;
            text-align: center;
        }

        .header h3 {
            font-size: 28px;
            font-weight: 900;
            color: var(--text-primary);
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 10px;
        }

        .header p {
            color: var(--text-muted);
            font-size: 14px;
            text-transform: uppercase;
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
            outline: none;
        }

        .input-wrapper input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-glow);
        }

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
            transition: all 0.3s;
            box-shadow: var(--glow-effect);
        }

        .game-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px var(--accent-glow);
        }

        .message {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 14px;
            text-align: center;
            font-weight: 600;
        }

        .error {
            background: rgba(240, 71, 71, 0.1);
            color: var(--accent);
            border: 1px solid var(--accent);
        }

        .success {
            background: rgba(67, 181, 129, 0.1);
            color: #43b581;
            border: 1px solid #43b581;
        }
        
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .back-link:hover {
            color: var(--accent);
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="header">
            <h3>NEW ACCESS KEY</h3>
            <p>Update your credentials</p>
        </div>

        <?php if ($error): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="message success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if (!$success && !$error || ($error && $result->num_rows > 0)): ?>
        <form method="POST">
            <div class="form-group">
                <div class="form-label"><span>🔐</span> NEW ACCESS KEY</div>
                <div class="input-wrapper">
                    <input type="password" name="password" placeholder="Enter new password" required>
                </div>
            </div>
            <div class="form-group">
                <div class="form-label"><span>🛡️</span> CONFIRM ACCESS KEY</div>
                <div class="input-wrapper">
                    <input type="password" name="confirm_password" placeholder="Confirm new password" required>
                </div>
            </div>
            <button type="submit" class="game-button">UPDATE SYSTEM KEY</button>
        </form>
        <?php endif; ?>
        
        <a href="login.php" class="back-link">RETURN TO LOGIN</a>
    </div>
</body>
</html>
