<?php
// send.php - Complete with Real-time API Integration
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$sender = $_SESSION['user_id'];
$receiver = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
$reply_to = isset($_POST['reply_to']) && !empty($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

// Validate input
if (!$receiver) {
    echo json_encode(['success' => false, 'error' => 'No receiver specified']);
    exit;
}

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
    exit;
}

// Check if receiver exists
$check_user = $conn->prepare("SELECT user_id FROM users WHERE user_id = ?");
$check_user->bind_param("i", $receiver);
$check_user->execute();
$user_result = $check_user->get_result();

if ($user_result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Receiver not found']);
    $check_user->close();
    exit;
}
$check_user->close();

// Check if users are friends
$check_friend = $conn->prepare("
    SELECT * FROM friends 
    WHERE (user_id = ? AND friend_id = ?) 
       OR (user_id = ? AND friend_id = ?)
");
$check_friend->bind_param("iiii", $sender, $receiver, $receiver, $sender);
$check_friend->execute();
$friend_result = $check_friend->get_result();

if ($friend_result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'You are not friends with this user']);
    $check_friend->close();
    exit;
}
$check_friend->close();

// Apply profanity filter
require_once 'profanity_filter.php';
$filter = new ProfanityFilter();
$original_message = $message;
$filtered_message = $filter->filter($message);
$was_censored = ($filtered_message !== $original_message);

// Insert message using prepared statement with filtered content
if ($reply_to) {
    $stmt = $conn->prepare("
        INSERT INTO messages (sender_id, receiver_id, message, message_type, reply_to, created_at, read_status) 
        VALUES (?, ?, ?, 'text', ?, NOW(), 'sent')
    ");
    $stmt->bind_param("iisi", $sender, $receiver, $filtered_message, $reply_to);
} else {
    $stmt = $conn->prepare("
        INSERT INTO messages (sender_id, receiver_id, message, message_type, created_at, read_status) 
        VALUES (?, ?, ?, 'text', NOW(), 'sent')
    ");
    $stmt->bind_param("iis", $sender, $receiver, $filtered_message);
}

if ($stmt && $stmt->execute()) {
    $message_id = $conn->insert_id;
    
    // Clear typing indicator
    $typing_key = 'typing_' . $receiver;
    if (isset($_SESSION[$typing_key])) {
        unset($_SESSION[$typing_key]);
    }
    
    // Get sender info for real-time response
    $sender_info = $conn->query("SELECT username, avatar FROM users WHERE user_id = $sender")->fetch_assoc();
    
    // Prepare response with full message data for real-time updates
    $response = [
        'success' => true,
        'message_id' => $message_id,
        'created_at' => date('Y-m-d H:i:s'),
        'was_censored' => $was_censored,
        'message_data' => [
            'id' => $message_id,
            'sender_id' => $sender,
            'sender_name' => $sender_info['username'],
            'sender_avatar' => $sender_info['avatar'],
            'message' => $was_censored ? $filtered_message : $message,
            'original_message' => $original_message,
            'filtered_message' => $filtered_message,
            'reply_to' => $reply_to,
            'time' => date('h:i A'),
            'timestamp' => time()
        ]
    ];
    
    // If message was censored, add a warning
    if ($was_censored) {
        $response['warning'] = 'Message contained inappropriate content and was filtered';
    }
    
    echo json_encode($response);
    
    // ============================================
    // ADDED: Notify Node.js server about new message
    // ============================================
    $nodeUrl = "http://localhost:3000/api/new-message";
    $ch = curl_init($nodeUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'message_id' => $message_id,
        'sender_id' => $sender,
        'receiver_id' => $receiver,
        'message' => $was_censored ? $filtered_message : $message,
        'sender_name' => $sender_info['username'],
        'timestamp' => time()
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout to not slow down response
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1); // For faster timeout on some systems
    
    // Execute asynchronously (we don't need to wait for response)
    curl_exec($ch);
    curl_close($ch);
    // ============================================
    
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to send message: ' . ($conn->error ?? 'Unknown error')]);
}

if ($stmt) $stmt->close();
?>