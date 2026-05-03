<?php
// search_messages.php - FIXED VERSION
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$friend_id = isset($_GET['friend_id']) ? (int)$_GET['friend_id'] : 0;
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

// Validate input
if (!$friend_id) {
    echo json_encode(['error' => 'No friend specified']);
    exit;
}

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

// Prepare search query with proper escaping
$search_term = '%' . $conn->real_escape_string($query) . '%';

// Use prepared statement for security
$stmt = $conn->prepare("
    SELECT 
        m.message_id,
        m.sender_id,
        m.receiver_id,
        m.message,
        m.message_type,
        m.file_path,
        m.created_at,
        u.username as sender_name
    FROM messages m
    JOIN users u ON u.user_id = m.sender_id
    WHERE ((m.sender_id = ? AND m.receiver_id = ?) 
       OR (m.sender_id = ? AND m.receiver_id = ?))
       AND m.message LIKE ?
       AND (m.deleted IS NULL OR m.deleted = 0)
    ORDER BY m.created_at DESC
    LIMIT 50
");

if (!$stmt) {
    echo json_encode(['error' => 'Database error: ' . $conn->error]);
    exit;
}

$stmt->bind_param("iiiis", $user_id, $friend_id, $friend_id, $user_id, $search_term);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
while ($row = $result->fetch_assoc()) {
    $display_message = $row['message'];
    if ($row['message_type'] == 'image') {
        $display_message = '📷 Photo';
    } elseif ($row['message_type'] == 'file') {
        $display_message = '📎 File';
    } elseif ($row['message_type'] == 'voice') {
        $display_message = '🎤 Voice message';
    }
    
    $messages[] = [
        'message_id' => $row['message_id'],
        'sender_id' => $row['sender_id'],
        'receiver_id' => $row['receiver_id'],
        'message' => $display_message,
        'original_message' => $row['message'],
        'message_type' => $row['message_type'],
        'created_at' => $row['created_at'],
        'sender_name' => $row['sender_name']
    ];
}

$stmt->close();

echo json_encode($messages);
?>