<?php
session_start();
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SHUTTING DOWN · CONNECTXION</title>
    <style>
        :root {
            --bg-primary: #0a0c0f;
            --accent: #ff4655;
            --accent-glow: rgba(255, 70, 85, 0.3);
            --gradient-primary: linear-gradient(135deg, #ff4655, #ff7b72);
        }
        body {
            background: var(--bg-primary);
            color: white;
            font-family: 'Segoe UI', 'Poppins', sans-serif;
            height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .shutdown-container {
            text-align: center;
            width: 300px;
        }
        .logo {
            width: 80px;
            margin: 0 auto 30px;
            filter: grayscale(1);
            animation: flicker 1s infinite;
        }
        .logo img { width: 100%; }
        .progress-bar {
            width: 100%;
            height: 4px;
            background: #1e2329;
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 15px;
        }
        .progress-fill {
            width: 100%;
            height: 100%;
            background: var(--gradient-primary);
            animation: shrink 1.5s linear forwards;
        }
        .status {
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 3px;
            color: var(--accent);
            text-transform: uppercase;
        }
        @keyframes shrink {
            from { width: 100%; }
            to { width: 0%; }
        }
        @keyframes flicker {
            0% { opacity: 1; }
            10% { opacity: 0.1; }
            20% { opacity: 1; }
            100% { opacity: 1; }
        }
    </style>
    <script>
        setTimeout(() => {
            window.location.href = 'login.php';
        }, 1800);
    </script>
</head>
<body>
    <div class="shutdown-container">
        <div class="logo">
            <img src="photos/logo.png" alt="Logo">
        </div>
        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>
        <div class="status">TERMINATING CONNECTION...</div>
    </div>
</body>
</html>