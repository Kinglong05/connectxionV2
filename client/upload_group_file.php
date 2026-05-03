<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$room_id = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;

if (!$room_id || !isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$file = $_FILES['file'];
$fileName = $file['name'];
$fileTmp = $file['tmp_name'];
$fileSize = $file['size'];
$fileError = $file['error'];

$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'docx', 'txt', 'zip', 'mp3', 'wav'];

if (in_array($fileExt, $allowed)) {
    if ($fileError === 0) {
        if ($fileSize < 50000000) { // 50MB
            $fileNameNew = "group_" . $room_id . "_" . uniqid('', true) . "." . $fileExt;
            $fileDestination = 'uploads/' . $fileNameNew;
            
            if (!is_dir('uploads')) mkdir('uploads', 0777, true);

            if (move_uploaded_file($fileTmp, $fileDestination)) {
                $message_type = in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif']) ? 'image' : 'file';
                $message_text = ($message_type === 'image') ? $fileDestination : "[File] " . $fileName;

                $stmt = $conn->prepare("INSERT INTO group_messages (room_id, user_id, message, message_type, file_path, file_name, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("iissss", $room_id, $user_id, $message_text, $message_type, $fileDestination, $fileName);
                
                if ($stmt->execute()) {
                    $message_id = $stmt->insertId;
                    
                    // Notify Node.js
                    $nodeUrl = "http://localhost:3000/api/new-group-message";
                    $ch = curl_init($nodeUrl);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                        'sender_id' => $user_id,
                        'group_id' => $room_id,
                        'message' => $message_text,
                        'message_type' => $message_type,
                        'message_id' => $message_id,
                        'file_path' => $fileDestination,
                        'file_name' => $fileName,
                        'sender_name' => $_SESSION['username'] ?? 'User'
                    ]));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                    curl_exec($ch);
                    curl_close($ch);

                    echo json_encode(['success' => true, 'message_id' => $message_id, 'file_path' => $fileDestination]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Database error']);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'File too large (max 50MB)']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'File upload error']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid file type']);
}
?>
