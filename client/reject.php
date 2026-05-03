<?php
require_once 'db.php';
requireLogin();

$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'];

if (!$request_id) {
    $_SESSION['friend_error'] = "Invalid request";
    header("Location: home.php");
    exit();
}

$stmt = $conn->prepare("
    UPDATE friend_requests 
    SET status = 'rejected' 
    WHERE id = ? AND receiver_id = ? AND status = 'pending'
");
$stmt->bind_param("ii", $request_id, $user_id);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    $_SESSION['friend_success'] = "Friend request rejected";
} else {
    $_SESSION['friend_error'] = "Failed to reject request";
}

$stmt->close();
header("Location: home.php");
exit();
?>