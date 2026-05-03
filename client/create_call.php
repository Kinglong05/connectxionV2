<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$caller = $_SESSION['user_id'];
$receiver = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
$type = isset($_POST['type']) ? $_POST['type'] : 'voice';


if (!$receiver) {
    echo json_encode(['success' => false, 'error' => 'Invalid receiver']);
    exit;
}


$check = $conn->query("SELECT user_id FROM users WHERE user_id = $receiver");
if ($check->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Receiver not found']);
    exit;
}


$friends = $conn->query("
    SELECT * FROM friends 
    WHERE (user_id = $caller AND friend_id = $receiver)
       OR (user_id = $receiver AND friend_id = $caller)
");

if ($friends->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'You are not friends with this user']);
    exit;
}


$stmt = $conn->prepare("
    INSERT INTO calls (caller_id, receiver_id, call_type, status, created_at) 
    VALUES (?, ?, ?, 'calling', NOW())
");

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

$stmt->bind_param("iis", $caller, $receiver, $type);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true, 
        'call_id' => $conn->insert_id,
        'message' => 'Call initiated'
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to create call']);
}

$stmt->close();
?>