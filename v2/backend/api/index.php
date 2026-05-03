<?php
/**
 * ConnectXion v2.0 - Centralized API Router
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Configure this for production
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once '../core/Database.php';
use App\Core\Database;

// Simple Routing
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts = explode('/', trim($uri, '/'));
// Assuming URL structure like: /v2/backend/api/index.php/messages
$resource = end($parts);

$input = json_decode(file_get_contents('php://input'), true);

try {
    switch ($resource) {
        case 'messages':
            handleMessages($_SERVER['REQUEST_METHOD'], $input);
            break;
        case 'groups':
            handleGroups($_SERVER['REQUEST_METHOD'], $input);
            break;
        case 'friends':
            handleFriends($_SERVER['REQUEST_METHOD'], $input);
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Resource not found: ' . $resource]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * --- RESOURCE HANDLERS ---
 */

function handleMessages($method, $data) {
    if ($method === 'GET') {
        $roomId = $_GET['room_id'] ?? null;
        if ($roomId) {
            $messages = Database::fetchAll(
                "SELECT gm.*, u.username FROM group_messages gm 
                 JOIN users u ON gm.user_id = u.user_id 
                 WHERE gm.room_id = ? ORDER BY gm.created_at ASC LIMIT 100",
                [$roomId]
            );
            echo json_encode($messages);
        }
    }
    // POST handled via Socket server in v2.0
}

function handleGroups($method, $data) {
    if ($method === 'GET') {
        $groups = Database::fetchAll("SELECT * FROM chat_rooms ORDER BY name ASC");
        echo json_encode($groups);
    } elseif ($method === 'POST') {
        // Create Group logic here
    }
}

function handleFriends($method, $data) {
    // Friends list, requests, etc.
}
