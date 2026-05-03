<?php
/**
 * save_subscription.php
 * Saves browser push subscriptions to the database
 */
require_once 'db.php';
requireLogin();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['endpoint'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid subscription data']);
    exit;
}

$user_id = $_SESSION['user_id'];
$endpoint = $input['endpoint'];
$p256dh = $input['keys']['p256dh'] ?? null;
$auth = $input['keys']['auth'] ?? null;

// Use REPLACE INTO or INSERT ... ON DUPLICATE KEY UPDATE
$sql = "INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_key) 
        VALUES (?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh_key = VALUES(p256dh_key), auth_key = VALUES(auth_key)";

$stmt = $conn->prepare($sql);
$stmt->bind_param("isss", $user_id, $endpoint, $p256dh, $auth);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$stmt->close();
?>
