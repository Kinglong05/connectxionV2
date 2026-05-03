<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$room_name = isset($_POST['room_name']) ? trim($_POST['room_name']) : '';
$room_description = isset($_POST['room_description']) ? trim($_POST['room_description']) : '';
$is_private = isset($_POST['is_private']) ? (int)$_POST['is_private'] : 0;
$max_members = isset($_POST['max_members']) ? (int)$_POST['max_members'] : 50;

if (empty($room_name)) {
    echo json_encode(['success' => false, 'error' => 'Group name is required']);
    exit;
}


$conn->begin_transaction();

try {
    
    $stmt = $conn->prepare("
        INSERT INTO chat_rooms (room_name, room_description, created_by, is_private, max_members, created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("ssiii", $room_name, $room_description, $user_id, $is_private, $max_members);
    $stmt->execute();
    $room_id = $conn->insert_id;
    $stmt->close();

    
    $stmt = $conn->prepare("
        INSERT INTO chat_room_members (room_id, user_id, role, joined_at) 
        VALUES (?, ?, 'admin', NOW())
    ");
    $stmt->bind_param("ii", $room_id, $user_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'room_id' => $room_id,
        'message' => 'Group created successfully'
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Failed to create group: ' . $e->getMessage()]);
}
?>