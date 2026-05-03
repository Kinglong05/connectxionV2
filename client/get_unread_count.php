<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

// Check if is_read column exists
$column_check = $conn->query("SHOW COLUMNS FROM messages LIKE 'is_read'");
$is_read_exists = $column_check && $column_check->num_rows > 0;

if ($is_read_exists) {
    $result = $conn->query("SELECT COUNT(*) as count FROM messages WHERE receiver_id = $user_id AND is_read = 0");
    $count = $result->fetch_assoc()['count'];
} else {
    // If is_read doesn't exist, return 0
    $count = 0;
}

echo json_encode(['count' => (int)$count]);
?>