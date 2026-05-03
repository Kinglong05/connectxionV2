<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

$result = $conn->query("
    SELECT COUNT(*) as count FROM friend_requests 
    WHERE receiver_id = $user_id AND status = 'pending'
");

$count = $result ? $result->fetch_assoc()['count'] : 0;

echo json_encode(['count' => (int)$count]);
?>