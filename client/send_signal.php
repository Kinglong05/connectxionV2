<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$call_id = isset($_POST['call_id']) ? (int)$_POST['call_id'] : 0;
$type = isset($_POST['type']) ? $_POST['type'] : '';
$data = isset($_POST['data']) ? $_POST['data'] : '';

if (!$call_id || !$type || !$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Store signal in database (you'll need to create a signals table)
$stmt = $conn->prepare("
    INSERT INTO call_signals (call_id, type, data, created_at) 
    VALUES (?, ?, ?, NOW())
");
$stmt->bind_param("iss", $call_id, $type, $data);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to store signal']);
}

$stmt->close();
?>