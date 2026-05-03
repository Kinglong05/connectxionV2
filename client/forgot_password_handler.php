<?php
require_once 'db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit();
}

$email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

if (empty($email)) {
    echo json_encode(['success' => false, 'error' => 'Email is required']);
    exit();
}

// Check if user exists
$stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // For security, don't reveal if email exists, but here we'll be helpful
    echo json_encode(['success' => false, 'error' => 'Email not found in our database']);
    exit();
}

$token = bin2hex(random_bytes(32));
$expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

// Save token
$stmt = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $email, $token, $expires_at);

if ($stmt->execute()) {
    // In a real system, send email here.
    // For this project, we'll return the reset link in the response for the user to see (Simulation Mode +)
    $resetLink = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
    
    echo json_encode([
        'success' => true, 
        'message' => 'RECOVERY LINK GENERATED SUCCESSFULLY',
        'link' => $resetLink // In production, this would be emailed
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
}
?>
