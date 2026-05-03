<?php
// realtime_api.php - Complete Real-time Synchronization API
// This file handles all real-time updates for the CONNECTXION application

require_once 'db.php';

// Enable error reporting for debugging (disable in production)
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Set JSON content type
header('Content-Type: application/json');

// Allow cross-origin requests if needed
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Require login for all endpoints
if (!isLoggedIn()) {
    echo json_encode(['error' => 'Not logged in', 'status' => 'unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : 'sync');

/**
 * Main handler for real-time sync
 */
function handleSync($conn, $user_id) {
    $last_update = isset($_GET['last_update']) ? (int)$_GET['last_update'] : 0;
    $include_messages = isset($_GET['include_messages']) ? (int)$_GET['include_messages'] : 1;
    $friend_id = isset($_GET['friend_id']) ? (int)$_GET['friend_id'] : 0;
    $room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
    
    $response = [
        'status' => 'success',
        'timestamp' => time(),
        'updates' => [],
        'sync_id' => uniqid(),
        'user_id' => $user_id
    ];
    
    // ============================================
    // 1. CHECK FOR NEW MESSAGES
    // ============================================
    if ($include_messages && $friend_id > 0) {
        $response['updates']['messages'] = getNewMessages($conn, $user_id, $friend_id, $last_update);
    }
    
    // ============================================
    // 2. CHECK FOR GROUP MESSAGES
    // ============================================
    if ($room_id > 0) {
        $response['updates']['group_messages'] = getNewGroupMessages($conn, $user_id, $room_id, $last_update);
    }
    
    // ============================================
    // 3. CHECK FOR FRIEND REQUESTS
    // ============================================
    $response['updates']['friend_requests'] = getFriendRequests($conn, $user_id, $last_update);
    
    // ============================================
    // 4. CHECK FOR TYPING INDICATORS
    // ============================================
    if ($friend_id > 0) {
        $response['updates']['typing'] = getTypingStatus($conn, $user_id, $friend_id);
    }
    
    // ============================================
    // 5. CHECK ONLINE STATUS OF FRIENDS
    // ============================================
    $response['updates']['online_friends'] = getOnlineFriends($conn, $user_id);
    
    // ============================================
    // 6. CHECK FOR ACTIVE CALLS
    // ============================================
    $response['updates']['calls'] = getActiveCalls($conn, $user_id);
    
    // ============================================
    // 7. GET UNREAD COUNTS
    // ============================================
    $response['updates']['unread_counts'] = getUnreadCounts($conn, $user_id);
    
    // ============================================
    // 8. UPDATE USER LAST ACTIVE
    // ============================================
    updateLastActive($conn, $user_id);
    
    return $response;
}

/**
 * Get new messages since last update
 */
function getNewMessages($conn, $user_id, $friend_id, $last_update) {
    $messages = [];
    
    // Check if messages table has updated_at column
    $column_check = $conn->query("SHOW COLUMNS FROM messages LIKE 'updated_at'");
    $has_updated_at = $column_check && $column_check->num_rows > 0;
    
    $date_column = $has_updated_at ? 'updated_at' : 'created_at';
    
    // Get messages that are new or updated
    $query = "
        SELECT 
            m.*,
            u.username as sender_name,
            u.avatar as sender_avatar,
            CASE 
                WHEN m.reply_to IS NOT NULL THEN (
                    SELECT message FROM messages WHERE message_id = m.reply_to
                )
                ELSE NULL
            END as reply_to_message
        FROM messages m
        JOIN users u ON u.user_id = m.sender_id
        WHERE ((m.sender_id = ? AND m.receiver_id = ?)
            OR (m.sender_id = ? AND m.receiver_id = ?))
            AND UNIX_TIMESTAMP(m.$date_column) > ?
            AND (m.deleted IS NULL OR m.deleted = 0)
        ORDER BY m.created_at ASC
        LIMIT 100
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiiii", $user_id, $friend_id, $friend_id, $user_id, $last_update);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $is_own = ($row['sender_id'] == $user_id);
        
        // Get reactions for this message
        $reactions = [];
        $reaction_query = "SELECT reaction, user_id FROM message_reactions WHERE message_id = ?";
        $reaction_stmt = $conn->prepare($reaction_query);
        $reaction_stmt->bind_param("i", $row['message_id']);
        $reaction_stmt->execute();
        $reaction_result = $reaction_stmt->get_result();
        while ($react = $reaction_result->fetch_assoc()) {
            $reactions[] = [
                'emoji' => $react['reaction'],
                'user_id' => $react['user_id']
            ];
        }
        $reaction_stmt->close();
        
        $messages[] = [
            'id' => $row['message_id'],
            'sender_id' => $row['sender_id'],
            'sender_name' => $row['sender_name'],
            'sender_avatar' => $row['sender_avatar'],
            'is_own' => $is_own,
            'message' => htmlspecialchars_decode($row['message']),
            'message_type' => $row['message_type'] ?? 'text',
            'file_path' => $row['file_path'],
            'file_size' => $row['file_size'],
            'is_read' => $row['is_read'],
            'read_status' => $row['read_status'] ?? 'sent',
            'reply_to' => $row['reply_to'],
            'reply_to_message' => $row['reply_to_message'],
            'edited' => $row['edited'] ?? 0,
            'deleted' => $row['deleted'] ?? 0,
            'reactions' => $reactions,
            'is_pinned' => $row['is_pinned'] ?? 0,
            'time' => date('h:i A', strtotime($row['created_at'])),
            'full_time' => $row['created_at']
        ];
    }
    $stmt->close();
    
    return $messages;
}

/**
 * Get new group messages
 */
function getNewGroupMessages($conn, $user_id, $room_id, $last_update) {
    $messages = [];
    
    // Check if user is member
    $member_check = $conn->query("
        SELECT * FROM chat_room_members 
        WHERE room_id = $room_id AND user_id = $user_id
    ");
    
    if ($member_check->num_rows === 0) {
        return $messages;
    }
    
    // Check if group_messages table has updated_at column
    $column_check = $conn->query("SHOW COLUMNS FROM group_messages LIKE 'updated_at'");
    $has_updated_at = $column_check && $column_check->num_rows > 0;
    
    $date_column = $has_updated_at ? 'updated_at' : 'created_at';
    
    $query = "
        SELECT 
            gm.*,
            u.username,
            u.avatar,
            CASE 
                WHEN gm.reply_to_id IS NOT NULL THEN (
                    SELECT message FROM group_messages WHERE id = gm.reply_to_id
                )
                ELSE NULL
            END as reply_to_message
        FROM group_messages gm
        JOIN users u ON u.user_id = gm.user_id
        WHERE gm.room_id = ? 
            AND (gm.is_deleted = 0 OR gm.is_deleted IS NULL)
            AND UNIX_TIMESTAMP(gm.$date_column) > ?
        ORDER BY gm.created_at ASC
        LIMIT 100
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $room_id, $last_update);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Get reactions for this message
        $reactions = [];
        $reaction_query = "SELECT reaction, user_id FROM message_reactions WHERE message_id = ?";
        $reaction_stmt = $conn->prepare($reaction_query);
        $reaction_stmt->bind_param("i", $row['id']);
        $reaction_stmt->execute();
        $reaction_result = $reaction_stmt->get_result();
        while ($react = $reaction_result->fetch_assoc()) {
            $reactions[] = [
                'emoji' => $react['reaction'],
                'user_id' => $react['user_id']
            ];
        }
        $reaction_stmt->close();
        
        $messages[] = [
            'id' => $row['id'],
            'user_id' => $row['user_id'],
            'username' => $row['username'],
            'avatar' => $row['avatar'],
            'is_own' => ($row['user_id'] == $user_id),
            'message' => htmlspecialchars_decode($row['message']),
            'message_type' => $row['message_type'],
            'file_path' => $row['file_path'],
            'file_name' => $row['file_name'],
            'file_size' => $row['file_size'],
            'reply_to_id' => $row['reply_to_id'],
            'reply_to_message' => $row['reply_to_message'],
            'is_edited' => $row['is_edited'] ?? 0,
            'is_deleted' => $row['is_deleted'] ?? 0,
            'reactions' => $reactions,
            'is_pinned' => $row['is_pinned'] ?? 0,
            'time' => date('h:i A', strtotime($row['created_at'])),
            'full_time' => $row['created_at']
        ];
    }
    $stmt->close();
    
    return $messages;
}

/**
 * Get pending friend requests
 */
function getFriendRequests($conn, $user_id, $last_update) {
    $requests = [];
    
    $query = "
        SELECT 
            fr.*,
            u.username,
            u.avatar,
            u.email
        FROM friend_requests fr
        JOIN users u ON u.user_id = fr.sender_id
        WHERE fr.receiver_id = ? 
            AND fr.status = 'pending'
            AND UNIX_TIMESTAMP(fr.created_at) > ?
        ORDER BY fr.created_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $user_id, $last_update);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $requests[] = [
            'id' => $row['id'],
            'sender_id' => $row['sender_id'],
            'username' => $row['username'],
            'avatar' => $row['avatar'],
            'email' => $row['email'],
            'time_ago' => timeAgo($row['created_at']),
            'created_at' => $row['created_at']
        ];
    }
    $stmt->close();
    
    return $requests;
}

/**
 * Get typing status
 */
function getTypingStatus($conn, $user_id, $friend_id) {
    $typing = false;
    
    // Typing status stored in session - we need to query from database or shared storage
    // For simplicity, check a typing_status table or use session-based approach
    
    $query = "SELECT is_typing FROM typing_status 
              WHERE user_id = ? AND receiver_id = ? 
              AND last_typing > NOW() - INTERVAL 3 SECOND";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $friend_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $typing = (bool)$row['is_typing'];
    }
    $stmt->close();
    
    return $typing;
}

/**
 * Get online friends
 */
function getOnlineFriends($conn, $user_id) {
    $online_friends = [];
    
    $query = "
        SELECT 
            u.user_id,
            u.username,
            u.avatar,
            u.last_active
        FROM friends f
        JOIN users u ON u.user_id = f.friend_id
        WHERE f.user_id = ? 
            AND u.last_active > NOW() - INTERVAL 5 MINUTE
        ORDER BY u.username ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $online_friends[] = [
            'user_id' => $row['user_id'],
            'username' => $row['username'],
            'avatar' => $row['avatar'],
            'last_active' => $row['last_active'],
            'time_ago' => timeAgo($row['last_active'])
        ];
    }
    $stmt->close();
    
    return $online_friends;
}

/**
 * Get active calls for user
 */
function getActiveCalls($conn, $user_id) {
    $calls = [];
    
    // Get incoming calls
    $incoming = $conn->query("
        SELECT 
            c.*,
            u.username as caller_name,
            u.avatar as caller_avatar
        FROM calls c
        JOIN users u ON u.user_id = c.caller_id
        WHERE c.receiver_id = $user_id 
            AND c.status = 'calling'
            AND c.created_at > NOW() - INTERVAL 30 SECOND
        ORDER BY c.created_at DESC
        LIMIT 1
    ");
    
    if ($incoming && $incoming->num_rows > 0) {
        $call = $incoming->fetch_assoc();
        $calls['incoming'] = [
            'id' => $call['id'],
            'caller_id' => $call['caller_id'],
            'caller_name' => $call['caller_name'],
            'caller_avatar' => $call['caller_avatar'],
            'call_type' => $call['call_type'],
            'created_at' => $call['created_at']
        ];
    }
    
    // Get ongoing calls
    $ongoing = $conn->query("
        SELECT 
            c.*,
            CASE 
                WHEN c.caller_id = $user_id THEN receiver.username
                ELSE caller.username
            END as other_name,
            CASE 
                WHEN c.caller_id = $user_id THEN receiver.user_id
                ELSE caller.user_id
            END as other_id
        FROM calls c
        JOIN users caller ON caller.user_id = c.caller_id
        JOIN users receiver ON receiver.user_id = c.receiver_id
        WHERE (c.caller_id = $user_id OR c.receiver_id = $user_id)
            AND c.status = 'answered'
            AND c.started_at > NOW() - INTERVAL 1 HOUR
        ORDER BY c.started_at DESC
        LIMIT 1
    ");
    
    if ($ongoing && $ongoing->num_rows > 0) {
        $call = $ongoing->fetch_assoc();
        $calls['ongoing'] = [
            'id' => $call['id'],
            'other_id' => $call['other_id'],
            'other_name' => $call['other_name'],
            'call_type' => $call['call_type'],
            'started_at' => $call['started_at']
        ];
    }
    
    return $calls;
}

/**
 * Get unread message counts per conversation
 */
function getUnreadCounts($conn, $user_id) {
    $counts = [];
    
    $query = "
        SELECT 
            sender_id,
            COUNT(*) as count
        FROM messages
        WHERE receiver_id = ? 
            AND (is_read = 0 OR is_read IS NULL)
            AND (deleted = 0 OR deleted IS NULL)
        GROUP BY sender_id
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $counts[$row['sender_id']] = (int)$row['count'];
    }
    $stmt->close();
    
    // Total unread
    $counts['total'] = array_sum($counts);
    
    return $counts;
}

/**
 * Update user's last active timestamp
 */
function updateLastActive($conn, $user_id) {
    $conn->query("UPDATE users SET last_active = NOW() WHERE user_id = $user_id");
}

/**
 * Mark messages as read
 */
function markMessagesAsRead($conn, $user_id, $sender_id) {
    $stmt = $conn->prepare("
        UPDATE messages 
        SET is_read = 1, 
            read_status = 'read', 
            read_at = NOW() 
        WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
    ");
    $stmt->bind_param("ii", $sender_id, $user_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    return $affected;
}

/**
 * Send a typing indicator
 */
function sendTyping($conn, $user_id, $receiver_id, $typing) {
    // Insert or update typing status
    $query = "INSERT INTO typing_status (user_id, receiver_id, is_typing, last_typing) 
              VALUES (?, ?, ?, NOW())
              ON DUPLICATE KEY UPDATE 
              is_typing = ?, last_typing = NOW()";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiii", $user_id, $receiver_id, $typing, $typing);
    $result = $stmt->execute();
    $stmt->close();
    
    return ['success' => $result];
}

/**
 * Send a new message via API
 */
function sendMessage($conn, $user_id, $receiver_id, $message, $reply_to = null) {
    if (empty(trim($message))) {
        return ['error' => 'Message cannot be empty'];
    }
    
    // Check if users are friends
    $friend_check = $conn->query("
        SELECT * FROM friends 
        WHERE (user_id = $user_id AND friend_id = $receiver_id)
           OR (user_id = $receiver_id AND friend_id = $user_id)
    ");
    
    if ($friend_check->num_rows === 0) {
        return ['error' => 'You are not friends with this user'];
    }
    
    // Apply profanity filter if the file exists
    $filtered_message = $message;
    $was_censored = false;
    
    if (file_exists('profanity_filter.php')) {
        require_once 'profanity_filter.php';
        if (class_exists('ProfanityFilter')) {
            $filter = new ProfanityFilter();
            $filtered_message = $filter->filter($message);
            $was_censored = ($filtered_message !== $message);
        }
    }
    
    // Insert message
    if ($reply_to) {
        $stmt = $conn->prepare("
            INSERT INTO messages (sender_id, receiver_id, message, message_type, reply_to, created_at, read_status) 
            VALUES (?, ?, ?, 'text', ?, NOW(), 'sent')
        ");
        $stmt->bind_param("iisi", $user_id, $receiver_id, $filtered_message, $reply_to);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO messages (sender_id, receiver_id, message, message_type, created_at, read_status) 
            VALUES (?, ?, ?, 'text', NOW(), 'sent')
        ");
        $stmt->bind_param("iis", $user_id, $receiver_id, $filtered_message);
    }
    
    if ($stmt->execute()) {
        $message_id = $stmt->insert_id;
        $stmt->close();
        
        // Get sender info
        $sender_query = $conn->prepare("SELECT username, avatar FROM users WHERE user_id = ?");
        $sender_query->bind_param("i", $user_id);
        $sender_query->execute();
        $sender_info = $sender_query->get_result()->fetch_assoc();
        $sender_query->close();
        
        return [
            'success' => true,
            'message_id' => $message_id,
            'timestamp' => time(),
            'created_at' => date('Y-m-d H:i:s'),
            'was_censored' => $was_censored,
            'message_data' => [
                'id' => $message_id,
                'sender_id' => $user_id,
                'sender_name' => $sender_info['username'],
                'sender_avatar' => $sender_info['avatar'],
                'message' => $filtered_message,
                'reply_to' => $reply_to,
                'time' => date('h:i A'),
                'timestamp' => time()
            ]
        ];
    }
    
    $stmt->close();
    return ['error' => 'Failed to send message'];
}

/**
 * Send a reaction to a message
 */
function sendReaction($conn, $user_id, $message_id, $reaction) {
    // Check if message exists
    $check = $conn->prepare("SELECT message_id FROM messages WHERE message_id = ? AND deleted = 0");
    $check->bind_param("i", $message_id);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows === 0) {
        $check->close();
        return ['error' => 'Message not found'];
    }
    $check->close();
    
    // Check if user already reacted with this emoji
    $check = $conn->prepare("SELECT id FROM message_reactions WHERE message_id = ? AND user_id = ? AND reaction = ?");
    $check->bind_param("iis", $message_id, $user_id, $reaction);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows > 0) {
        // Remove reaction
        $stmt = $conn->prepare("DELETE FROM message_reactions WHERE message_id = ? AND user_id = ? AND reaction = ?");
        $stmt->bind_param("iis", $message_id, $user_id, $reaction);
        $stmt->execute();
        $stmt->close();
        $check->close();
        return ['success' => true, 'action' => 'removed'];
    } else {
        // Add reaction
        $stmt = $conn->prepare("INSERT INTO message_reactions (message_id, user_id, reaction) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $message_id, $user_id, $reaction);
        $stmt->execute();
        $stmt->close();
        $check->close();
        return ['success' => true, 'action' => 'added'];
    }
}

/**
 * Create a typing_status table if it doesn't exist
 */
function ensureTypingTable($conn) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS typing_status (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            receiver_id INT NOT NULL,
            is_typing TINYINT DEFAULT 0,
            last_typing TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_typing (user_id, receiver_id),
            INDEX idx_receiver (receiver_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Helper function for time ago
 */
function timeAgo($timestamp) {
    if (!$timestamp) return '';
    
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d', $time);
}

// Ensure required tables exist
ensureTypingTable($conn);

// Handle different actions
switch ($action) {
    case 'sync':
        // Full sync - get all updates since last timestamp
        $response = handleSync($conn, $user_id);
        break;
        
    case 'send':
        // Send a new message
        $receiver_id = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
        $message = isset($_POST['message']) ? trim($_POST['message']) : '';
        $reply_to = isset($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;
        
        if (!$receiver_id || empty($message)) {
            $response = ['error' => 'Missing parameters', 'status' => 'error'];
        } else {
            $result = sendMessage($conn, $user_id, $receiver_id, $message, $reply_to);
            if (isset($result['success']) && $result['success']) {
                $response = $result;
                $response['status'] = 'success';
            } else {
                $response = $result;
                $response['status'] = 'error';
            }
        }
        break;
        
    case 'reaction':
        // Add/remove reaction
        $message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        $reaction = isset($_POST['reaction']) ? $_POST['reaction'] : '';
        
        if (!$message_id || !$reaction) {
            $response = ['error' => 'Missing parameters', 'status' => 'error'];
        } else {
            $response = sendReaction($conn, $user_id, $message_id, $reaction);
            $response['status'] = 'success';
        }
        break;
        
    case 'typing':
        // Send typing indicator
        $receiver_id = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
        $typing = isset($_POST['typing']) ? (int)$_POST['typing'] : 0;
        
        if (!$receiver_id) {
            $response = ['error' => 'Missing receiver_id', 'status' => 'error'];
        } else {
            $response = sendTyping($conn, $user_id, $receiver_id, $typing);
            $response['status'] = 'success';
        }
        break;
        
    case 'read':
        // Mark messages as read
        $sender_id = isset($_POST['sender_id']) ? (int)$_POST['sender_id'] : 0;
        
        if (!$sender_id) {
            $response = ['error' => 'Missing sender_id', 'status' => 'error'];
        } else {
            $count = markMessagesAsRead($conn, $user_id, $sender_id);
            $response = [
                'success' => true,
                'count' => $count,
                'status' => 'success'
            ];
        }
        break;
        
    case 'ping':
        // Simple ping to update online status
        updateLastActive($conn, $user_id);
        $response = [
            'status' => 'success',
            'timestamp' => time(),
            'message' => 'pong'
        ];
        break;
        
    case 'pin':
        // Pin/unpin a message
        $message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        $is_pinned = isset($_POST['is_pinned']) ? (int)$_POST['is_pinned'] : 0;
        $is_group = isset($_POST['is_group']) ? (int)$_POST['is_group'] : 0;
        
        if (!$message_id) {
            $response = ['error' => 'Missing message_id', 'status' => 'error'];
        } else {
            $table = $is_group ? 'group_messages' : 'messages';
            $idCol = $is_group ? 'id' : 'message_id';
            
            $stmt = $conn->prepare("UPDATE $table SET is_pinned = ? WHERE $idCol = ?");
            $stmt->bind_param("ii", $is_pinned, $message_id);
            $stmt->execute();
            $stmt->close();
            
            $response = ['status' => 'success', 'is_pinned' => $is_pinned];
        }
        break;
        
    case 'get_online':
        // Get online friends list
        $response = [
            'status' => 'success',
            'online_friends' => getOnlineFriends($conn, $user_id)
        ];
        break;
        
    case 'get_unread':
        // Get unread counts
        $response = [
            'status' => 'success',
            'unread_counts' => getUnreadCounts($conn, $user_id)
        ];
        break;
        
    default:
        $response = ['error' => 'Invalid action', 'status' => 'error'];
        break;
}

// Output response
echo json_encode($response);
?>