<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;

if (!$message_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

// Check message and get room_id
$stmt = $conn->prepare("
    SELECT gm.id, gm.room_id, u.username
    FROM group_messages gm
    JOIN users u ON u.user_id = gm.user_id
    WHERE gm.id = ? AND gm.user_id = ? AND gm.is_deleted = 0
");
$stmt->bind_param("ii", $message_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Not found or permission denied']);
    $stmt->close();
    exit;
}
$msg_data = $result->fetch_assoc();
$room_id = $msg_data['room_id'];
$sender_name = $msg_data['username'];
$stmt->close();

// Mark as deleted
$stmt = $conn->prepare("
    UPDATE group_messages 
    SET is_deleted = 1, message = 'Message unsent'
    WHERE id = ?
");
$stmt->bind_param("i", $message_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
    
    // Notify Node.js
    $nodeUrl = "http://localhost:3000/api/group-message-update";
    $ch = curl_init($nodeUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'type' => 'delete',
        'message_id' => $message_id,
        'group_id' => $room_id,
        'sender_id' => $user_id,
        'data' => ['unsent_by' => $sender_name]
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_exec($ch);
    curl_close($ch);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to delete']);
}
$stmt->close();
?>
