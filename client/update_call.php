<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$call_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$status = isset($_POST['status']) ? $_POST['status'] : '';
$user_id = $_SESSION['user_id'];

if (!$call_id || !in_array($status, ['answered', 'ended', 'missed'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Verify user is part of this call
$check = $conn->prepare("
    SELECT id FROM calls 
    WHERE id = ? AND (caller_id = ? OR receiver_id = ?)
");
$check->bind_param("iii", $call_id, $user_id, $user_id);
$check->execute();
$result = $check->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Call not found']);
    $check->close();
    exit;
}
$check->close();

// Update call status
if ($status == 'answered') {
    $stmt = $conn->prepare("
        UPDATE calls 
        SET status = ?, started_at = NOW() 
        WHERE id = ?
    ");
} else {
    $stmt = $conn->prepare("
        UPDATE calls 
        SET status = ?, ended_at = NOW() 
        WHERE id = ?
    ");
}

$stmt->bind_param("si", $status, $call_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to update call']);
}

$stmt->close();
?>