<?php
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$query = $_GET['query'] ?? '';

if (strlen($query) < 2) {
    echo json_encode(['success' => true, 'results' => []]);
    exit();
}

// Search users
$search_query = "
    SELECT u.user_id, u.username, u.avatar, u.bio,
    (SELECT status FROM friend_requests 
     WHERE (sender_id = $user_id AND receiver_id = u.user_id) 
        OR (sender_id = u.user_id AND receiver_id = $user_id)
     LIMIT 1) as request_status,
    (SELECT COUNT(*) FROM friends 
     WHERE (user_id = $user_id AND friend_id = u.user_id) 
        OR (user_id = u.user_id AND friend_id = $user_id)) as is_friend
    FROM users u
    WHERE u.username LIKE ? AND u.user_id != $user_id
    LIMIT 10
";

$stmt = $conn->prepare($search_query);
$search_term = "%$query%";
$stmt->bind_param("s", $search_term);

$results = [];
if ($stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $results[] = [
            'user_id' => $row['user_id'],
            'username' => $row['username'],
            'bio' => $row['bio'] ?? 'No bio available',
            'avatar' => $row['avatar'],
            'status' => $row['is_friend'] > 0 ? 'friend' : ($row['request_status'] ?? 'none')
        ];
    }
    echo json_encode(['success' => true, 'results' => $results]);
} else {
    echo json_encode(['success' => false, 'error' => 'Query failed: ' . $conn->error]);
}
?>
