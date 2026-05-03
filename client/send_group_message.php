<?php
require_once 'db.php';
require_once 'profanity_filter.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: home.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$room_id = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

// Validate inputs
if (!$room_id || empty($message)) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Message or Room ID is invalid']);
        exit();
    }
    $_SESSION['error'] = 'Message cannot be empty';
    header("Location: group_chat.php?room_id=$room_id");
    exit();
}

// Check if user is a member of this room
$member_check = dbGetRow($conn, "
    SELECT * FROM chat_room_members 
    WHERE room_id = ? AND user_id = ?
", "ii", $room_id, $user_id);

if (!$member_check) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'You are not a member of this group']);
        exit();
    }
    $_SESSION['error'] = 'You are not a member of this group';
    header("Location: group_chat.php?room_id=$room_id");
    exit();
}

// Apply profanity filter
$filter = new ProfanityFilter();
$filtered_message = $filter->filter($message);

// Check if message was censored
$was_censored = ($filtered_message !== $message);

// Insert filtered message into database
$message_id = dbInsert($conn, 'group_messages', [
    'room_id' => $room_id,
    'user_id' => $user_id,
    'message' => $filtered_message,
    'message_type' => 'text',
    'created_at' => date('Y-m-d H:i:s'),
    'is_edited' => 0,
    'is_deleted' => 0
]);

if ($message_id) {
    // Get the inserted message with user info for real-time update
    $message_data = dbGetRow($conn, "
        SELECT 
            gm.*,
            u.username,
            u.last_active
        FROM group_messages gm
        JOIN users u ON u.user_id = gm.user_id
        WHERE gm.id = ?
    ", "i", $message_id);
    
    if ($message_data) {
        // Notify Node.js server about new group message
        $nodeUrl = "http://localhost:3000/api/new-group-message";
        $ch = curl_init($nodeUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'message_id' => $message_id,
            'sender_id' => $user_id,
            'group_id' => $room_id,
            'message' => $filtered_message,
            'sender_name' => $message_data['username'],
            'timestamp' => time()
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_exec($ch);
        curl_close($ch);

        // If it's an AJAX request, return JSON
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'was_censored' => $was_censored,
                'message' => [
                    'id' => $message_data['id'],
                    'text' => $message_data['message'],
                    'username' => $message_data['username'],
                    'time' => date('H:i', strtotime($message_data['created_at'])),
                    'user_id' => $message_data['user_id']
                ]
            ]);
            exit();
        }
    }
    
    // Regular form submission
    header("Location: group_chat.php?room_id=$room_id");
    exit();
    
} else {
    // Error inserting message
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'error' => 'Failed to send message to database',
            'debug' => [
                'room_id' => $room_id,
                'user_id' => $user_id,
                'message_len' => strlen($message)
            ]
        ]);
        exit();
    }
    
    $_SESSION['error'] = 'Failed to send message';
    header("Location: group_chat.php?room_id=$room_id");
    exit();
}
?>