<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;

if (!$message_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid message ID']);
    exit;
}

// Get message info before deleting
$stmt = $conn->prepare("
    SELECT m.message_id, m.file_path, m.sender_id, m.receiver_id,
           u.username as sender_name
    FROM messages m
    JOIN users u ON u.user_id = m.sender_id
    WHERE m.message_id = ? AND m.sender_id = ? AND m.deleted = 0
");

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

$stmt->bind_param("ii", $message_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Message not found or permission denied']);
    $stmt->close();
    exit;
}

$message_data = $result->fetch_assoc();
$receiver_id = $message_data['receiver_id'];
$sender_name = $message_data['sender_name'];
$stmt->close();

$conn->begin_transaction();

try {
    // Mark as deleted
    $stmt = $conn->prepare("
        UPDATE messages 
        SET deleted = 1, 
            message = CONCAT(?, ' unsent a message'),
            message_type = 'text',
            file_path = NULL,
            file_size = NULL,
            edited = 0
        WHERE message_id = ?
    ");
    
    $stmt->bind_param("si", $sender_name, $message_id);
    $stmt->execute();
    
    // Delete file if exists
    if (!empty($message_data['file_path']) && file_exists($message_data['file_path'])) {
        unlink($message_data['file_path']);
    }
    
    // Delete reactions
    $stmt_reactions = $conn->prepare("DELETE FROM message_reactions WHERE message_id = ?");
    $stmt_reactions->bind_param("i", $message_id);
    $stmt_reactions->execute();
    $stmt_reactions->close();
    
    $conn->commit();
    echo json_encode(['success' => true]);
    
    // Notify Node.js
    $nodeUrl = "http://localhost:3000/api/message-update";
    $ch = curl_init($nodeUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'type' => 'delete',
        'message_id' => $message_id,
        'sender_id' => $user_id,
        'receiver_id' => $receiver_id,
        'data' => ['unsent_by' => $sender_name]
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_exec($ch);
    curl_close($ch);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Failed to delete message: ' . $e->getMessage()]);
}
?>