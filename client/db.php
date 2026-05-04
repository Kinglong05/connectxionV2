<?php


if (defined('DB_PHP')) {
    return;
}
define('DB_PHP', true);

require_once 'logger.php';


// Link to your Live Render Signaling Server
define('SOCKET_URL', 'https://connectxion-v2-server.onrender.com');

// Database Configuration (Clever Cloud Global DB)
$host = 'b7mjyceoodbe5dn9yy31-mysql.services.clever-cloud.com';
$username = 'uujsxrklus3xgvv7';
$password = 'hPtN2RxSOHG0fjlg3Ze4';
$database = 'b7mjyceoodbe5dn9yy31';
$port = 3306;


mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    
    $conn = new mysqli();
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    $conn->real_connect($host, $username, $password, $database, $port);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    
    $conn->set_charset("utf8mb4");
    $conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    
    $conn->query("SET time_zone = '+08:00'");
    
} catch (Exception $e) {
    
    error_log("Database connection error: " . $e->getMessage());
    
    
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
        die("Database Error: " . $e->getMessage());
    } else {
        die("Unable to connect to database. Please try again later.");
    }
}


if (session_status() === PHP_SESSION_NONE) {
    
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0); 
    ini_set('session.cookie_samesite', 'Strict');
    
    session_start();
}





/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Require login or redirect
 */
function requireLogin() {
    if (!isLoggedIn()) {
        
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header("Location: login.php");
        exit();
    }
    
    
    updateLastActive();
}

/**
 * Update user's last active timestamp
 */
function updateLastActive() {
    global $conn;
    
    if (isLoggedIn()) {
        $user_id = $_SESSION['user_id'];
        
        
        if (!isset($_SESSION['last_active_update']) || (time() - $_SESSION['last_active_update'] > 120)) {
            $stmt = prepareAndExecute(
                $conn,
                "UPDATE users SET last_active = NOW() WHERE user_id = ?",
                "i",
                $user_id
            );
            
            if ($stmt) {
                $_SESSION['last_active_update'] = time();
            }
        }
    }
}

/**
 * Get current user data
 * @return array|null
 */
function getCurrentUser() {
    global $conn;
    
    if (!isLoggedIn()) {
        return null;
    }
    
    static $user = null;
    
    if ($user === null) {
        $user_id = $_SESSION['user_id'];
        $result = safeQuery($conn, "SELECT * FROM users WHERE user_id = $user_id");
        
        if ($result && $result->num_rows > 0) {
            $user = $result->fetch_assoc();
        }
    }
    
    return $user;
}





/**
 * Execute a safe query with error handling
 * @param mysqli $conn
 * @param string $sql
 * @return mysqli_result|false
 */
function safeQuery($conn, $sql) {
    try {
        
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
            Logger::debug("SQL Query: " . $sql);
        }
        
        $result = $conn->query($sql);
        if ($result === false) {
            throw new Exception($conn->error);
        }
        return $result;
    } catch (Exception $e) {
        Logger::error("Query error", ['message' => $e->getMessage(), 'sql' => $sql]);
        return false;
    }
}

/**
 * Escape string for safe query
 * @param mysqli $conn
 * @param string $string
 * @return string
 */
function escape($conn, $string) {
    return $conn->real_escape_string(trim($string));
}

/**
 * Prepare and execute a statement with parameters
 * @param mysqli $conn
 * @param string $sql
 * @param string $types
 * @param mixed ...$params
 * @return mysqli_stmt|false
 */
function prepareAndExecute($conn, $sql, $types = '', ...$params) {
    try {
        
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
            Logger::debug("Prepared SQL: " . $sql, ['types' => $types]);
        }
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }
        
        if (!empty($types) && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        
        return $stmt;
    } catch (Exception $e) {
        Logger::error("Prepared statement error", ['message' => $e->getMessage(), 'sql' => $sql]);
        return false;
    }
}

/**
 * Get single row from database
 * @param mysqli $conn
 * @param string $sql
 * @param string $types
 * @param mixed ...$params
 * @return array|null
 */
function dbGetRow($conn, $sql, $types = '', ...$params) {
    $stmt = prepareAndExecute($conn, $sql, $types, ...$params);
    
    if ($stmt) {
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Get multiple rows from database
 * @param mysqli $conn
 * @param string $sql
 * @param string $types
 * @param mixed ...$params
 * @return array
 */
function dbGetAll($conn, $sql, $types = '', ...$params) {
    $stmt = prepareAndExecute($conn, $sql, $types, ...$params);
    
    if ($stmt) {
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    return [];
}

/**
 * Insert data and return inserted ID
 * @param mysqli $conn
 * @param string $table
 * @param array $data
 * @return int|false
 */
function dbInsert($conn, $table, $data) {
    $columns = implode(", ", array_keys($data));
    $placeholders = implode(", ", array_fill(0, count($data), "?"));
    $types = "";
    $values = [];
    
    foreach ($data as $key => $value) {
        if (is_int($value)) {
            $types .= "i";
        } elseif (is_float($value)) {
            $types .= "d";
        } else {
            $types .= "s";
        }
        $values[] = $value;
    }
    
    $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
    $stmt = prepareAndExecute($conn, $sql, $types, ...$values);
    
    if ($stmt) {
        return $conn->insert_id;
    }
    
    return false;
}

/**
 * Update data in database
 * @param mysqli $conn
 * @param string $table
 * @param array $data
 * @param string $where
 * @param string $whereTypes
 * @param array $whereParams
 * @return bool
 */
function dbUpdate($conn, $table, $data, $where, $whereTypes, $whereParams) {
    $set = [];
    $types = "";
    $values = [];
    
    foreach ($data as $key => $value) {
        $set[] = "$key = ?";
        if (is_int($value)) {
            $types .= "i";
        } elseif (is_float($value)) {
            $types .= "d";
        } else {
            $types .= "s";
        }
        $values[] = $value;
    }
    
    $types .= $whereTypes;
    $values = array_merge($values, $whereParams);
    
    $sql = "UPDATE $table SET " . implode(", ", $set) . " WHERE $where";
    $stmt = prepareAndExecute($conn, $sql, $types, ...$values);
    
    return $stmt !== false;
}

/**
 * Delete data from database
 * @param mysqli $conn
 * @param string $table
 * @param string $where
 * @param string $types
 * @param mixed ...$params
 * @return bool
 */
function dbDelete($conn, $table, $where, $types, ...$params) {
    $sql = "DELETE FROM $table WHERE $where";
    $stmt = prepareAndExecute($conn, $sql, $types, ...$params);
    
    return $stmt !== false;
}





/**
 * Check if a user is online
 * @param string $last_active
 * @param int $timeout Minutes to consider online
 * @return bool
 */
function isOnline($last_active, $timeout = 5) {
    if (empty($last_active)) {
        return false;
    }
    
    $last_active_time = strtotime($last_active);
    if ($last_active_time === false) {
        return false;
    }
    
    return (time() - $last_active_time) < ($timeout * 60);
}

/**
 * Get online status badge HTML
 * @param string $last_active
 * @param string $username
 * @return string
 */
function getOnlineBadge($last_active, $username = '') {
    $online = isOnline($last_active);
    $status_text = $online ? 'Online' : 'Offline';
    $class = $online ? 'online' : 'offline';
    $title = $online ? 'Currently online' : 'Last seen: ' . timeAgo($last_active);
    
    return "<span class=\"status-badge $class\" title=\"$title\">$status_text</span>";
}





/**
 * Get avatar letter from username
 * @param string $username
 * @return string
 */
if (!function_exists('getAvatarLetter')) {
    function getAvatarLetter($username) {
        if (empty($username)) return '?';
        
        
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $username);
        return strtoupper(substr($clean, 0, 1));
    }
}

/**
 * Get avatar color based on username
 * @param string $username
 * @return string
 */
function getAvatarColor($username) {
    $colors = [
        '#ff4655', '#0ed3c7', '#43b581', '#faa61a', '#f04747',
        '#7289da', '#ff7b72', '#10b3aa', '#9b59b6', '#3498db',
        '#e67e22', '#2ecc71', '#e74c3c', '#1abc9c', '#f39c12'
    ];
    
    $index = abs(crc32($username)) % count($colors);
    return $colors[$index];
}

/**
 * Format timestamp to "time ago" string
 * @param string $timestamp
 * @return string
 */
if (!function_exists('timeAgo')) {
    function timeAgo($timestamp) {
        if (!$timestamp || $timestamp == '0000-00-00 00:00:00') return 'Never';
        
        $time = strtotime($timestamp);
        $now = time();
        $diff = $now - $time;
        
        if ($diff < 0) return 'Just now';
        
        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . 'm ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . 'h ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . 'd ago';
        } elseif ($diff < 2592000) {
            $weeks = floor($diff / 604800);
            return $weeks . 'w ago';
        } elseif ($diff < 31536000) {
            $months = floor($diff / 2592000);
            return $months . 'mo ago';
        } else {
            $years = floor($diff / 31536000);
            return $years . 'y ago';
        }
    }
}

/**
 * Format file size to human readable
 * @param int $bytes
 * @return string
 */
if (!function_exists('formatFileSize')) {
    function formatFileSize($bytes) {
        if ($bytes === null || $bytes === 0) return '0 B';
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}

/**
 * Generate CSRF token
 * @return string
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * @param string $token
 * @return bool
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize input string
 * @param string $input
 * @return string
 */
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate random string
 * @param int $length
 * @return string
 */
function generateRandomString($length = 16) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Get client IP address
 * @return string
 */
function getClientIP() {
    $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    
    foreach ($ip_keys as $key) {
        if (isset($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
            return $_SERVER[$key];
        }
    }
    
    return '0.0.0.0';
}

/**
 * Log user activity
 * @param string $action
 * @param string $details
 */
function logActivity($action, $details = '') {
    global $conn;
    
    if (isLoggedIn()) {
        $user_id = $_SESSION['user_id'];
        $ip = getClientIP();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt = prepareAndExecute(
            $conn,
            "INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)",
            "issss",
            $user_id, $action, $details, $ip, $user_agent
        );
    }
}

/**
 * Get unread message count
 * @return int
 */
function getUnreadMessageCount() {
    global $conn;
    
    if (!isLoggedIn()) {
        return 0;
    }
    
    $user_id = $_SESSION['user_id'];
    $result = safeQuery($conn, "SELECT COUNT(*) as count FROM messages WHERE receiver_id = $user_id AND is_read = 0");
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['count'];
    }
    
    return 0;
}

/**
 * Get friend request count
 * @return int
 */
function getFriendRequestCount() {
    global $conn;
    
    if (!isLoggedIn()) {
        return 0;
    }
    
    $user_id = $_SESSION['user_id'];
    $result = safeQuery($conn, "SELECT COUNT(*) as count FROM friend_requests WHERE receiver_id = $user_id AND status = 'pending'");
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['count'];
    }
    
    return 0;
}






$query_cache = [];

/**
 * Get cached query result
 * @param string $key
 * @param int $ttl Time to live in seconds
 * @return mixed|null
 */
function cacheGet($key, $ttl = 300) {
    if (isset($_SESSION['cache'][$key]) && (time() - $_SESSION['cache'][$key]['time'] < $ttl)) {
        return $_SESSION['cache'][$key]['data'];
    }
    return null;
}

/**
 * Set cache data
 * @param string $key
 * @param mixed $data
 */
function cacheSet($key, $data) {
    if (!isset($_SESSION['cache'])) {
        $_SESSION['cache'] = [];
    }
    $_SESSION['cache'][$key] = [
        'time' => time(),
        'data' => $data
    ];
}

/**
 * Clear cache
 * @param string|null $key
 */
function cacheClear($key = null) {
    if ($key === null) {
        unset($_SESSION['cache']);
    } else {
        unset($_SESSION['cache'][$key]);
    }
}






if (isLoggedIn()) {
    updateLastActive();
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


date_default_timezone_set('Asia/Manila');


if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}


if (isset($_SESSION['cache'])) {
    foreach ($_SESSION['cache'] as $key => $cache) {
        if (time() - $cache['time'] > 3600) { 
            unset($_SESSION['cache'][$key]);
        }
    }
}

/**
 * Include mobile responsive meta tags, styles and scripts
 */
function includeResponsive() {
    $v = time(); // Cache buster
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">';
    echo '<link rel="stylesheet" href="responsive.css?v=' . $v . '">';
    echo '<script src="responsive.js?v=' . $v . '" defer></script>';
}
?>