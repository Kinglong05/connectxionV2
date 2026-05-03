<?php
// realtime_stream.php - Server-Sent Events for Real-time Updates
require_once 'db.php';

// Disable compression and buffering for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering
ob_end_clean(); // Clear output buffer

// Set unlimited execution time
set_time_limit(0);

// Check if user is logged in
if (!isLoggedIn()) {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'Not logged in']) . "\n\n";
    flush();
    exit;
}

$user_id = $_SESSION['user_id'];
$last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

// Keep connection alive
$last_message_id = 0;
$last_ping = time();

while (true) {
    // Check for new messages
    $new_messages = [];
    
    // Get new private messages
    $private_query = "
        SELECT m.*, u.username as sender_name, u.avatar as sender_avatar
        FROM messages m
        JOIN users u ON u.user_id = m.sender_id
        WHERE (m.receiver_id = ? OR m.sender_id = ?)
            AND m.message_id > ?
            AND (m.deleted = 0 OR m.deleted IS NULL)
        ORDER BY m.message_id ASC
    ";
    
    $stmt = $conn->prepare($private_query);
    $stmt->bind_param("iii", $user_id, $user_id, $last_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $is_own = ($row['sender_id'] == $user_id);
        $new_messages[] = [
            'type' => 'private',
            'data' => [
                'id' => $row['message_id'],
                'sender_id' => $row['sender_id'],
                'sender_name' => $row['sender_name'],
                'sender_avatar' => $row['sender_avatar'],
                'receiver_id' => $row['receiver_id'],
                'message' => htmlspecialchars_decode($row['message']),
                'message_type' => $row['message_type'],
                'reply_to' => $row['reply_to'],
                'is_own' => $is_own,
                'time' => date('h:i A', strtotime($row['created_at'])),
                'created_at' => $row['created_at']
            ]
        ];
        
        if ($row['message_id'] > $last_id) {
            $last_id = $row['message_id'];
        }
    }
    $stmt->close();
    
    // Get new group messages
    $groups_query = "
        SELECT gm.*, u.username, u.avatar
        FROM group_messages gm
        JOIN users u ON u.user_id = gm.user_id
        WHERE gm.id > ?
            AND gm.is_deleted = 0
            AND gm.room_id IN (
                SELECT room_id FROM chat_room_members WHERE user_id = ?
            )
        ORDER BY gm.id ASC
    ";
    
    $stmt = $conn->prepare($groups_query);
    $stmt->bind_param("ii", $last_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $new_messages[] = [
            'type' => 'group',
            'data' => [
                'id' => $row['id'],
                'room_id' => $row['room_id'],
                'user_id' => $row['user_id'],
                'username' => $row['username'],
                'avatar' => $row['avatar'],
                'message' => htmlspecialchars_decode($row['message']),
                'message_type' => $row['message_type'],
                'is_own' => ($row['user_id'] == $user_id),
                'time' => date('h:i A', strtotime($row['created_at'])),
                'created_at' => $row['created_at']
            ]
        ];
        
        if ($row['id'] > $last_id) {
            $last_id = $row['id'];
        }
    }
    $stmt->close();
    
    // Send messages if any
    if (!empty($new_messages)) {
        echo "event: messages\n";
        echo "data: " . json_encode($new_messages) . "\n\n";
        flush();
    }
    
    // Check for friend requests
    $requests_query = "
        SELECT fr.*, u.username, u.avatar
        FROM friend_requests fr
        JOIN users u ON u.user_id = fr.sender_id
        WHERE fr.receiver_id = ? 
            AND fr.status = 'pending'
            AND fr.id > ?
    ";
    
    $stmt = $conn->prepare($requests_query);
    $stmt->bind_param("ii", $user_id, $last_request_id ?? 0);
    $stmt->execute();
    $requests_result = $stmt->get_result();
    
    $new_requests = [];
    while ($row = $requests_result->fetch_assoc()) {
        $new_requests[] = [
            'id' => $row['id'],
            'sender_id' => $row['sender_id'],
            'username' => $row['username'],
            'avatar' => $row['avatar'],
            'time_ago' => timeAgo($row['created_at'])
        ];
        
        if (!isset($last_request_id) || $row['id'] > $last_request_id) {
            $last_request_id = $row['id'];
        }
    }
    $stmt->close();
    
    if (!empty($new_requests)) {
        echo "event: friend_requests\n";
        echo "data: " . json_encode($new_requests) . "\n\n";
        flush();
    }
    
    // Check for typing indicators
    $typing_query = "
        SELECT user_id, is_typing 
        FROM typing_status 
        WHERE receiver_id = ? 
        AND last_typing > NOW() - INTERVAL 3 SECOND
    ";
    
    $stmt = $conn->prepare($typing_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $typing_result = $stmt->get_result();
    
    $typing_users = [];
    while ($row = $typing_result->fetch_assoc()) {
        if ($row['is_typing']) {
            $typing_users[] = $row['user_id'];
        }
    }
    $stmt->close();
    
    if (!empty($typing_users)) {
        echo "event: typing\n";
        echo "data: " . json_encode($typing_users) . "\n\n";
        flush();
    }
    
    // Update online status
    $conn->query("UPDATE users SET last_active = NOW() WHERE user_id = $user_id");
    
    // Send heartbeat ping every 15 seconds
    if (time() - $last_ping > 15) {
        echo "event: ping\n";
        echo "data: " . json_encode(['timestamp' => time()]) . "\n\n";
        flush();
        $last_ping = time();
    }
    
    // Wait before next check
    sleep(1);
}

function timeAgo($timestamp) {
    if (!$timestamp) return '';
    $time = strtotime($timestamp);
    $diff = time() - $time;
    
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}
?>