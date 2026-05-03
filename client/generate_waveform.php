<?php
// generate_waveform.php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

if (!isset($_POST['message_id']) || !isset($_POST['waveform'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$message_id = (int)$_POST['message_id'];
$waveform = $_POST['waveform'];

// Update waveform data
$stmt = $conn->prepare("UPDATE voice_messages SET waveform_data = ? WHERE message_id = ?");
$stmt->bind_param("si", $waveform, $message_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to save waveform']);
}

$stmt->close();
?>