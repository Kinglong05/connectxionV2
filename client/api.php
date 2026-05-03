<?php
/**
 * ConnectXion Unified API Entry Point
 */

require_once 'db.php';
requireLogin();

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';
$response = ['success' => false, 'error' => 'Unknown action'];

try {
    switch ($action) {
        case 'typing':
            handleTyping();
            break;
            
        case 'mark_as_read':
            handleMarkAsRead();
            break;

        case 'edit_message':
            handleEditMessage();
            break;

        case 'delete_message':
            handleDeleteMessage();
            break;

        case 'react':
            handleReaction();
            break;

        default:
            $response['error'] = "Action '$action' not implemented.";
            Logger::security("Invalid API action attempt", ['action' => $action]);
            break;
    }
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    Logger::error("API Error", ['action' => $action, 'message' => $e->getMessage()]);
}

echo json_encode($response);
exit;

/**
 * Handle typing status updates
 */
function handleTyping() {
    global $response;
    $user_id = $_SESSION['user_id'];
    $receiver_id = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
    $typing = isset($_POST['typing']) ? (int)$_POST['typing'] : 0;

    if (!$receiver_id) {
        $response['error'] = 'Invalid receiver';
        return;
    }

    // Store typing status in session (legacy support)
    $typing_key = 'typing_' . $receiver_id;
    $_SESSION[$typing_key] = [
        'user_id' => $user_id,
        'is_typing' => $typing,
        'timestamp' => time()
    ];

    $response['success'] = true;
    unset($response['error']);
}

/**
 * Handle marking messages as read
 */
function handleMarkAsRead() {
    global $conn, $response;
    $user_id = $_SESSION['user_id'];
    $sender_id = isset($_POST['sender_id']) ? (int)$_POST['sender_id'] : 0;

    if (!$sender_id) {
        $response['error'] = 'Invalid sender';
        return;
    }

    $stmt = prepareAndExecute(
        $conn,
        "UPDATE messages SET is_read = 1 WHERE receiver_id = ? AND sender_id = ? AND is_read = 0",
        "ii",
        $user_id, $sender_id
    );

    if ($stmt) {
        $response['success'] = true;
        unset($response['error']);
    } else {
        $response['error'] = 'Failed to update read status';
    }
}

/**
 * Handle message editing
 */
function handleEditMessage() {
    global $conn, $response;
    $user_id = $_SESSION['user_id'];
    $message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
    $new_message = isset($_POST['message']) ? trim($_POST['message']) : '';

    if (!$message_id || !$new_message) {
        $response['error'] = 'Invalid parameters';
        return;
    }

    // Get message info before editing
    $msg = dbGetRow($conn, "SELECT message_id, receiver_id FROM messages WHERE message_id = ? AND sender_id = ? AND deleted = 0", "ii", $message_id, $user_id);

    if (!$msg) {
        $response['error'] = 'Message not found or permission denied';
        return;
    }

    $success = dbUpdate($conn, 'messages', 
        ['message' => $new_message, 'edited' => 1, 'edited_at' => date('Y-m-d H:i:s')], 
        "message_id = ?", "i", [$message_id]
    );

    if ($success) {
        $response['success'] = true;
        unset($response['error']);
        
        // Notify Node.js
        notifyNode('edit', $message_id, $user_id, $msg['receiver_id'], ['new_message' => $new_message]);
    } else {
        $response['error'] = 'Failed to update message';
    }
}

/**
 * Handle message deletion (unsend)
 */
function handleDeleteMessage() {
    global $conn, $response;
    $user_id = $_SESSION['user_id'];
    $message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;

    if (!$message_id) {
        $response['error'] = 'Invalid message ID';
        return;
    }

    // Get message info before deleting
    $sql = "SELECT m.message_id, m.file_path, m.sender_id, m.receiver_id, u.username as sender_name 
            FROM messages m JOIN users u ON u.user_id = m.sender_id 
            WHERE m.message_id = ? AND m.sender_id = ? AND m.deleted = 0";
    $msg = dbGetRow($conn, $sql, "ii", $message_id, $user_id);

    if (!$msg) {
        $response['error'] = 'Message not found or permission denied';
        return;
    }

    $conn->begin_transaction();
    try {
        $success = dbUpdate($conn, 'messages', [
            'deleted' => 1,
            'message' => $msg['sender_name'] . ' unsent a message',
            'message_type' => 'text',
            'file_path' => null,
            'file_size' => null,
            'edited' => 0
        ], "message_id = ?", "i", [$message_id]);

        if (!$success) throw new Exception("Update failed");

        // Delete file if exists
        if (!empty($msg['file_path']) && file_exists($msg['file_path'])) {
            unlink($msg['file_path']);
        }

        // Delete reactions
        dbDelete($conn, 'message_reactions', "message_id = ?", "i", $message_id);

        $conn->commit();
        $response['success'] = true;
        unset($response['error']);
        
        // Notify Node.js
        notifyNode('delete', $message_id, $user_id, $msg['receiver_id'], ['unsent_by' => $msg['sender_name']]);
    } catch (Exception $e) {
        $conn->rollback();
        $response['error'] = 'Failed to delete: ' . $e->getMessage();
    }
}

/**
 * Handle message reactions
 */
function handleReaction() {
    global $conn, $response;
    $user_id = $_SESSION['user_id'];
    $message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
    $reaction = isset($_POST['reaction']) ? trim($_POST['reaction']) : '';

    if (!$message_id || !$reaction) {
        $response['error'] = 'Invalid parameters';
        return;
    }

    // Check if message exists
    $msg = dbGetRow($conn, "SELECT sender_id, receiver_id FROM messages WHERE message_id = ? AND deleted = 0", "i", $message_id);

    if (!$msg) {
        $response['error'] = 'Message not found';
        return;
    }

    $receiver_id = ($user_id == $msg['sender_id']) ? $msg['receiver_id'] : $msg['sender_id'];

    // Toggle reaction
    $existing = dbGetRow($conn, "SELECT id FROM message_reactions WHERE message_id = ? AND user_id = ? AND reaction = ?", "iis", $message_id, $user_id, $reaction);

    $action = '';
    if ($existing) {
        if (dbDelete($conn, 'message_reactions', "id = ?", "i", $existing['id'])) {
            $action = 'removed';
        }
    } else {
        if (dbInsert($conn, 'message_reactions', ['message_id' => $message_id, 'user_id' => $user_id, 'reaction' => $reaction])) {
            $action = 'added';
        }
    }

    if ($action) {
        $reactions = dbGetAll($conn, "SELECT reaction, user_id FROM message_reactions WHERE message_id = ?", "i", $message_id);
        
        $response['success'] = true;
        $response['action'] = $action;
        $response['reactions'] = $reactions;
        unset($response['error']);

        // Notify Node.js
        notifyNode('react', $message_id, $user_id, $receiver_id, [
            'reactions' => $reactions,
            'last_reaction' => $reaction,
            'last_action' => $action
        ]);
    } else {
        $response['error'] = 'Failed to update reaction';
    }
}

/**
 * Helper to notify Node.js server of updates
 */
function notifyNode($type, $message_id, $sender_id, $receiver_id, $data) {
    $nodeUrl = "http://localhost:3000/api/message-update";
    $payload = json_encode([
        'type' => $type,
        'message_id' => $message_id,
        'sender_id' => $sender_id,
        'receiver_id' => $receiver_id,
        'data' => $data
    ]);

    $ch = curl_init($nodeUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_exec($ch);
    curl_close($ch);
}
?>
