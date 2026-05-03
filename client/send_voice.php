<?php
require_once 'db.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit();
}

$user_id = $_SESSION['user_id'];
$receiver_id = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
$duration = isset($_POST['duration']) ? (int)$_POST['duration'] : 0;
$reply_to = isset($_POST['reply_to']) && !empty($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;
$waveform = isset($_POST['waveform']) ? $_POST['waveform'] : null;

if (!$receiver_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid receiver']);
    exit();
}

if (!isset($_FILES['voice']) || $_FILES['voice']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No voice file uploaded']);
    exit();
}

$file = $_FILES['voice'];

// Validate file size (max 10MB)
if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'Voice message too large (max 10MB)']);
    exit();
}

// Get file extension from original name
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

// Allowed extensions
$allowed = ['webm', 'mp3', 'mp4', 'ogg', 'wav', 'm4a'];
if (!in_array($extension, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowed)]);
    exit();
}

// Create upload directory if it doesn't exist
$upload_dir = 'uploads/voice/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Generate unique filename
$filename = 'voice_' . time() . '_' . uniqid() . '.' . $extension;
$filepath = $upload_dir . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save voice message']);
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // Insert into messages table
    $query = "INSERT INTO messages (sender_id, receiver_id, message_type, file_path, duration, reply_to, created_at) 
              VALUES (?, ?, 'voice', ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iisii", $user_id, $receiver_id, $filepath, $duration, $reply_to);
    $stmt->execute();
    
    $message_id = $conn->insert_id;
    
    // Insert into voice_messages table
    if ($waveform) {
        $voice_query = "INSERT INTO voice_messages (message_id, duration, waveform_data) VALUES (?, ?, ?)";
        $voice_stmt = $conn->prepare($voice_query);
        $voice_stmt->bind_param("iis", $message_id, $duration, $waveform);
        $voice_stmt->execute();
        $voice_stmt->close();
    } else {
        // Insert without waveform
        $voice_query = "INSERT INTO voice_messages (message_id, duration) VALUES (?, ?)";
        $voice_stmt = $conn->prepare($voice_query);
        $voice_stmt->bind_param("ii", $message_id, $duration);
        $voice_stmt->execute();
        $voice_stmt->close();
    }
    
    $conn->commit();
    
    // ============================================
    // ADDED: Notify Node.js server about new voice message
    // ============================================
    $sender_info = $conn->query("SELECT username FROM users WHERE user_id = $user_id")->fetch_assoc();
    $nodeUrl = "http://localhost:3000/api/new-message";
    $ch = curl_init($nodeUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'message_id' => $message_id,
        'sender_id' => $user_id,
        'receiver_id' => $receiver_id,
        'message' => $filepath, // For voice, message field contains filepath
        'message_type' => 'voice',
        'duration' => $duration,
        'sender_name' => $sender_info['username'],
        'timestamp' => time()
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_exec($ch);
    curl_close($ch);
    // ============================================

    echo json_encode([
        'success' => true,
        'message_id' => $message_id,
        'file_path' => $filepath,
        'duration' => $duration
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    // Delete file if database insert fails
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

$stmt->close();
?>