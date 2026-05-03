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

// Check message and get room_id
$stmt = $conn->prepare("
    SELECT id, room_id FROM group_messages 
    WHERE id = ? AND is_deleted = 0
");
$stmt->bind_param("i", $message_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Message not found']);
    $stmt->close();
    exit;
}
$msg_data = $result->fetch_assoc();
$room_id = $msg_data['room_id'];
$stmt->close();

// Toggle reaction
$check = $conn->prepare("
    SELECT id FROM group_message_reactions 
    WHERE group_message_id = ? AND user_id = ? AND reaction = ?
");
$check->bind_param("iis", $message_id, $user_id, $reaction);
$check->execute();
$res = $check->get_result();

$action = '';
if ($res->num_rows > 0) {
    $del = $conn->prepare("DELETE FROM group_message_reactions WHERE id = ?");
    $reaction_id = $res->fetch_assoc()['id'];
    $del->bind_param("i", $reaction_id);
    if ($del->execute()) $action = 'removed';
    $del->close();
} else {
    $ins = $conn->prepare("INSERT INTO group_message_reactions (group_message_id, user_id, reaction) VALUES (?, ?, ?)");
    $ins->bind_param("iis", $message_id, $user_id, $reaction);
    if ($ins->execute()) $action = 'added';
    $ins->close();
}

// Get updated reactions
$reactions = [];
$get = $conn->prepare("SELECT reaction, user_id FROM group_message_reactions WHERE group_message_id = ?");
$get->bind_param("i", $message_id);
$get->execute();
$res_react = $get->get_result();
while ($r = $res_react->fetch_assoc()) {
    $reactions[] = $r;
}
$get->close();

if ($action) {
    echo json_encode(['success' => true, 'action' => $action, 'reactions' => $reactions]);
    
    // Notify Node.js
    $nodeUrl = "http://localhost:3000/api/group-message-update";
    $ch = curl_init($nodeUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'type' => 'react',
        'message_id' => $message_id,
        'group_id' => $room_id,
        'sender_id' => $user_id,
        'data' => ['reactions' => $reactions]
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_exec($ch);
    curl_close($ch);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed']);
}
?>
