<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

// Mark old unanswered calls as missed (30 seconds timeout)
$stmt = $conn->prepare("
    UPDATE calls 
    SET status = 'missed' 
    WHERE receiver_id = ? 
    AND status = 'calling' 
    AND created_at < NOW() - INTERVAL 30 SECOND
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->close();

// Check for active incoming call
$stmt = $conn->prepare("
    SELECT c.*, u.username 
    FROM calls c
    JOIN users u ON u.user_id = c.caller_id
    WHERE c.receiver_id = ? 
    AND c.status = 'calling'
    ORDER BY c.id DESC 
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $call = $result->fetch_assoc();
    
    // Add caller avatar letter
    $call['caller_avatar'] = getAvatarLetter($call['username']);
    
    echo json_encode($call);
} else {
    echo json_encode(['status' => 'none']);
}

$stmt->close();
?>