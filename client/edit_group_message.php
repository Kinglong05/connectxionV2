<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
$new_message = isset($_POST['message']) ? trim($_POST['message']) : '';

if (!$message_id || !$new_message) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Check message and get room_id
$stmt = $conn->prepare("
    SELECT id, room_id FROM group_messages 
    WHERE id = ? AND user_id = ? AND is_deleted = 0
");
$stmt->bind_param("ii", $message_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Message not found or permission denied']);
    $stmt->close();
    exit;
}
$msg_data = $result->fetch_assoc();
$room_id = $msg_data['room_id'];
$stmt->close();

// Update
$stmt = $conn->prepare("
    UPDATE group_messages 
    SET message = ?, is_edited = 1, updated_at = NOW()
    WHERE id = ?
");
$stmt->bind_param("si", $new_message, $message_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
    
    // Notify Node.js
    $nodeUrl = "http://localhost:3000/api/group-message-update";
    $ch = curl_init($nodeUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'type' => 'edit',
        'message_id' => $message_id,
        'group_id' => $room_id,
        'sender_id' => $user_id,
        'data' => ['new_message' => $new_message]
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_exec($ch);
    curl_close($ch);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to update message']);
}
$stmt->close();
?>
