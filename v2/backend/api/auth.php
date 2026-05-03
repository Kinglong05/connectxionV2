<?php
header('Content-Type: application/json');

require_once '../core/Database.php';
use App\Core\Database;

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if ($action === 'login') {
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        try {
            $user = Database::fetch("SELECT * FROM users WHERE username = ?", [$username]);

            if ($user && password_verify($password, $user['password'])) {
                session_start();
                $_SESSION['user_id'] = $user['user_id'];
                
                echo json_encode([
                    'success' => true,
                    'user' => [
                        'id' => $user['user_id'],
                        'username' => $user['username']
                    ]
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
