<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
$reaction = isset($_POST['reaction']) ? trim($_POST['reaction']) : '';

if (!$message_id || !$reaction) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Check if message exists and get sender/receiver
$msg_check = $conn->prepare("
    SELECT sender_id, receiver_id FROM messages 
    WHERE message_id = ? AND deleted = 0
");
$msg_check->bind_param("i", $message_id);
$msg_check->execute();
$msg_result = $msg_check->get_result();

if ($msg_result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Message not found']);
    $msg_check->close();
    exit;
}
$msg_data = $msg_result->fetch_assoc();
$receiver_id = ($user_id == $msg_data['sender_id']) ? $msg_data['receiver_id'] : $msg_data['sender_id'];
$msg_check->close();

$reaction_check = $conn->prepare("
    SELECT id FROM message_reactions 
    WHERE message_id = ? AND user_id = ? AND reaction = ?
");
$reaction_check->bind_param("iis", $message_id, $user_id, $reaction);
$reaction_check->execute();
$result = $reaction_check->get_result();

$action = '';
if ($result->num_rows > 0) {
    $delete = $conn->prepare("
        DELETE FROM message_reactions 
        WHERE message_id = ? AND user_id = ? AND reaction = ?
    ");
    $delete->bind_param("iis", $message_id, $user_id, $reaction);
    if ($delete->execute()) {
        $action = 'removed';
    }
    $delete->close();
} else {
    $insert = $conn->prepare("
        INSERT INTO message_reactions (message_id, user_id, reaction) 
        VALUES (?, ?, ?)
    ");
    $insert->bind_param("iis", $message_id, $user_id, $reaction);
    if ($insert->execute()) {
        $action = 'added';
    }
    $insert->close();
}

if ($action) {
    // Fetch updated reactions list for this message
    $reactions = [];
    $get_reactions = $conn->prepare("SELECT reaction, user_id FROM message_reactions WHERE message_id = ?");
    $get_reactions->bind_param("i", $message_id);
    $get_reactions->execute();
    $react_result = $get_reactions->get_result();
    while ($r = $react_result->fetch_assoc()) {
        $reactions[] = $r;
    }
    $get_reactions->close();

    echo json_encode(['success' => true, 'action' => $action, 'reactions' => $reactions]);
    
    // Notify Node.js
    $nodeUrl = "http://localhost:3000/api/message-update";
    $ch = curl_init($nodeUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'type' => 'react',
        'message_id' => $message_id,
        'sender_id' => $user_id,
        'receiver_id' => $receiver_id,
        'data' => [
            'reactions' => $reactions,
            'last_reaction' => $reaction, 
            'last_action' => $action
        ]
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_exec($ch);
    curl_close($ch);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to update reaction']);
}

$reaction_check->close();
?>