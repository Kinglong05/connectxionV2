<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$call_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$user_id = $_SESSION['user_id'];

if (!$call_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid call ID']);
    exit;
}

// Verify user is part of this call
$stmt = $conn->prepare("
    SELECT id FROM calls 
    WHERE id = ? AND (caller_id = ? OR receiver_id = ?)
");

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

$stmt->bind_param("iii", $call_id, $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Call not found']);
    $stmt->close();
    exit;
}
$stmt->close();

// Update call status
$stmt = $conn->prepare("
    UPDATE calls 
    SET status = 'ended', ended_at = NOW() 
    WHERE id = ?
");

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

$stmt->bind_param("i", $call_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to end call']);
}

$stmt->close();
?>