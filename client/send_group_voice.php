<?php
require_once 'db.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit();
}

$user_id = $_SESSION['user_id'];
$room_id = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
$duration = isset($_POST['duration']) ? (int)$_POST['duration'] : 0;
$reply_to = isset($_POST['reply_to']) && !empty($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;

if (!$room_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid room']);
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

// Get file extension
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (empty($extension)) $extension = 'webm';

// Create upload directory
$upload_dir = 'uploads/voice/groups/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Generate unique filename
$filename = 'group_voice_' . time() . '_' . uniqid() . '.' . $extension;
$filepath = $upload_dir . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save voice message']);
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // Insert into group_messages table
    $query = "INSERT INTO group_messages (room_id, user_id, message_type, file_path, file_size, reply_to_id, created_at) 
              VALUES (?, ?, 'voice', ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($query);
    $file_size = $file['size'];
    $stmt->bind_param("iisiii", $room_id, $user_id, $filepath, $file_size, $reply_to);
    $stmt->execute();
    
    $message_id = $conn->insert_id;
    
    // Also insert duration info if we had a voice_messages table for groups, 
    // but the current schema uses group_messages. We can use duration if we add the column.
    // For now, we'll just commit.
    
    $conn->commit();
    
    // Notify Node.js server
    $sender_info = $conn->query("SELECT username FROM users WHERE user_id = $user_id")->fetch_assoc();
    $nodeUrl = "http://localhost:3000/api/new-group-message";
    $ch = curl_init($nodeUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'message_id' => $message_id,
        'group_id' => $room_id,
        'sender_id' => $user_id,
        'message' => $filepath,
        'message_type' => 'voice',
        'sender_name' => $sender_info['username'],
        'timestamp' => time()
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_exec($ch);
    curl_close($ch);

    echo json_encode([
        'success' => true,
        'message_id' => $message_id,
        'file_path' => $filepath
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    if (file_exists($filepath)) unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

$stmt->close();
?>
