<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$call_id = isset($_GET['call_id']) ? (int)$_GET['call_id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : '';

if (!$call_id || !$type) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Get the latest signal
$stmt = $conn->prepare("
    SELECT data FROM call_signals 
    WHERE call_id = ? AND type = ? 
    ORDER BY id DESC LIMIT 1
");
$stmt->bind_param("is", $call_id, $type);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode([$type => json_decode($row['data'])]);
} else {
    echo json_encode([$type => null]);
}

$stmt->close();
?>