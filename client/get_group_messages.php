<?php
require_once 'db.php';
require_once 'profanity_filter.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$filter = new ProfanityFilter();

if (!$room_id) {
    echo json_encode(['success' => false, 'error' => 'No room ID provided']);
    exit();
}

// Check if user is member
$member_check = $conn->query("
    SELECT * FROM chat_room_members 
    WHERE room_id = $room_id AND user_id = $user_id
");

if ($member_check->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Not a member']);
    exit();
}

// Get messages with reactions
$sql = "
    SELECT 
        gm.*,
        u.username,
        u.avatar,
        (SELECT GROUP_CONCAT(CONCAT(reaction, ':', user_id)) FROM group_message_reactions WHERE group_message_id = gm.id) as reactions_data
    FROM group_messages gm
    JOIN users u ON u.user_id = gm.user_id
    WHERE gm.room_id = $room_id AND gm.is_deleted = 0
    ORDER BY gm.created_at ASC
    LIMIT $limit OFFSET $offset
";

$result = $conn->query($sql);
$messages = [];

if ($result && $result->num_rows > 0) {
    while ($msg = $result->fetch_assoc()) {
        // Parse reactions
        $reactions = [];
        if (!empty($msg['reactions_data'])) {
            $parts = explode(',', $msg['reactions_data']);
            foreach ($parts as $part) {
                list($emoji, $uid) = explode(':', $part);
                if (!isset($reactions[$emoji])) {
                    $reactions[$emoji] = ['count' => 0, 'users' => []];
                }
                $reactions[$emoji]['count']++;
                $reactions[$emoji]['users'][] = (int)$uid;
            }
        }

        $messages[] = [
            'id' => $msg['id'],
            'user_id' => $msg['user_id'],
            'username' => $msg['username'],
            'avatar' => $msg['avatar'],
            'message' => $filter->filter(htmlspecialchars_decode($msg['message'])),
            'message_type' => $msg['message_type'],
            'file_path' => $msg['file_path'],
            'is_edited' => (bool)$msg['is_edited'],
            'time' => date('h:i A', strtotime($msg['created_at'])),
            'timestamp' => $msg['created_at'],
            'reactions' => $reactions,
            'reply_to' => $msg['reply_to'] ?? null
        ];
    }
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'messages' => $messages
]);
?>