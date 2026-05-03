<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'No user specified']);
    exit;
}

$stmt = $conn->prepare("
    SELECT user_id, username, email, bio, phone, avatar, created_at, last_active 
    FROM users 
    WHERE user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

$user = $result->fetch_assoc();

// Statistics
$friends_count = $conn->query("SELECT COUNT(*) as count FROM friends WHERE user_id = $user_id")->fetch_assoc()['count'] ?? 0;
$messages_sent = $conn->query("SELECT COUNT(*) as count FROM messages WHERE sender_id = $user_id")->fetch_assoc()['count'] ?? 0;

$user['member_since'] = date('F Y', strtotime($user['created_at']));
$user['friends_count'] = $friends_count;
$user['messages_count'] = $messages_sent;
$user['is_online'] = (strtotime($user['last_active']) > (time() - 300)); // Active in last 5 mins

echo json_encode(['success' => true, 'user' => $user]);
?>
