<?php
require_once 'db.php';
requireLogin();

$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'];
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'home.php';

// Validate redirect URL to prevent open redirect vulnerabilities
$allowed_redirects = ['home.php', 'friend_requests.php', 'friends.php'];
$redirect_path = parse_url($referer, PHP_URL_PATH);
$redirect_file = basename($redirect_path);

$redirect_url = in_array($redirect_file, $allowed_redirects) ? $redirect_file : 'home.php';

if (!$request_id) {
    $_SESSION['friend_error'] = "Invalid request.";
    header("Location: " . $redirect_url);
    exit();
}

try {
    // Check if request exists and is meant for this user
    $request = dbGetRow(
        $conn, 
        "SELECT * FROM friend_requests WHERE id = ? AND receiver_id = ? AND status = 'pending'",
        "ii",
        $request_id, $user_id
    );

    if (!$request) {
        $_SESSION['friend_error'] = "Friend request not found or already processed.";
        header("Location: " . $redirect_url);
        exit();
    }

    $sender_id = $request['sender_id'];
    $receiver_id = $request['receiver_id'];

    // Prevent duplicate entries by checking if they are already friends
    $existing = dbGetRow(
        $conn,
        "SELECT * FROM friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)",
        "iiii",
        $sender_id, $receiver_id, $receiver_id, $sender_id
    );

    $conn->begin_transaction();

    // Mark the friend request as accepted
    prepareAndExecute(
        $conn, 
        "UPDATE friend_requests SET status = 'accepted' WHERE id = ?",
        "i", 
        $request_id
    );

    // If not already friends, insert both mutual relationships
    if (!$existing) {
        prepareAndExecute(
            $conn,
            "INSERT INTO friends (user_id, friend_id, created_at) VALUES (?, ?, NOW()), (?, ?, NOW())",
            "iiii",
            $sender_id, $receiver_id,
            $receiver_id, $sender_id
        );
    }

    $conn->commit();
    $_SESSION['friend_success'] = "Friend request accepted!";
    logActivity("friend_request_accepted", "Accepted friend request from user ID: " . $sender_id);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log("Error accepting friend request: " . $e->getMessage());
    $_SESSION['friend_error'] = "Failed to accept friend request. Please try again.";
}

header("Location: " . $redirect_url);
exit();
?>