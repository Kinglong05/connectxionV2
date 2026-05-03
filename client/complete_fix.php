<?php
// save as complete_fix.php
require_once 'db.php';

echo "<h1>CONNECTXION - Complete Database Fix</h1>";
echo "<pre>";

// Function to check if column exists
function columnExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

// Function to check if table exists
function tableExists($conn, $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return $result && $result->num_rows > 0;
}

echo "\n📊 Checking database structure...\n\n";

// ============================================
// USERS TABLE
// ============================================
echo "👤 USERS TABLE:\n";
echo "----------------\n";

if (!tableExists($conn, 'users')) {
    $conn->query("
        CREATE TABLE users (
            user_id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            avatar VARCHAR(500),
            bio TEXT,
            phone VARCHAR(20),
            last_active TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_username (username),
            INDEX idx_email (email),
            INDEX idx_last_active (last_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ Created users table\n";
} else {
    echo "✅ Users table exists\n";
    
    // Check for missing columns
    $columns = ['avatar', 'bio', 'phone', 'last_active'];
    foreach ($columns as $column) {
        if (!columnExists($conn, 'users', $column)) {
            if ($column == 'last_active') {
                $conn->query("ALTER TABLE users ADD COLUMN last_active TIMESTAMP NULL");
            } else {
                $conn->query("ALTER TABLE users ADD COLUMN $column TEXT");
            }
            echo "  ✅ Added $column column\n";
        }
    }
}

// ============================================
// MESSAGES TABLE
// ============================================
echo "\n💬 MESSAGES TABLE:\n";
echo "------------------\n";

if (!tableExists($conn, 'messages')) {
    $conn->query("
        CREATE TABLE messages (
            message_id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            receiver_id INT NOT NULL,
            message TEXT,
            message_type ENUM('text', 'image', 'file', 'voice') DEFAULT 'text',
            file_path VARCHAR(500),
            file_size INT,
            is_read TINYINT DEFAULT 0,
            read_status ENUM('sent', 'delivered', 'read') DEFAULT 'sent',
            reply_to INT,
            edited TINYINT DEFAULT 0,
            deleted TINYINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            read_at TIMESTAMP NULL,
            edited_at TIMESTAMP NULL,
            INDEX idx_sender (sender_id),
            INDEX idx_receiver (receiver_id),
            INDEX idx_created_at (created_at),
            INDEX idx_reply_to (reply_to),
            FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (receiver_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ Created messages table\n";
} else {
    echo "✅ Messages table exists\n";
    
    // Check for missing columns
    $columns = [
        'message_type', 'file_path', 'file_size', 'is_read', 
        'read_status', 'reply_to', 'edited', 'deleted', 'read_at', 'edited_at'
    ];
    foreach ($columns as $column) {
        if (!columnExists($conn, 'messages', $column)) {
            if ($column == 'read_status') {
                $conn->query("ALTER TABLE messages ADD COLUMN read_status ENUM('sent', 'delivered', 'read') DEFAULT 'sent'");
            } elseif ($column == 'message_type') {
                $conn->query("ALTER TABLE messages ADD COLUMN message_type ENUM('text', 'image', 'file', 'voice') DEFAULT 'text'");
            } elseif ($column == 'reply_to') {
                $conn->query("ALTER TABLE messages ADD COLUMN reply_to INT DEFAULT NULL, ADD INDEX (reply_to)");
            } elseif ($column == 'edited' || $column == 'deleted') {
                $conn->query("ALTER TABLE messages ADD COLUMN $column TINYINT DEFAULT 0");
            } elseif ($column == 'file_size') {
                $conn->query("ALTER TABLE messages ADD COLUMN file_size INT");
            } else {
                $conn->query("ALTER TABLE messages ADD COLUMN $column TIMESTAMP NULL");
            }
            echo "  ✅ Added $column column\n";
        }
    }
}

// ============================================
// MESSAGE REACTIONS TABLE
// ============================================
echo "\n❤️ MESSAGE REACTIONS TABLE:\n";
echo "---------------------------\n";

if (!tableExists($conn, 'message_reactions')) {
    $conn->query("
        CREATE TABLE message_reactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            reaction VARCHAR(10) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_reaction (message_id, user_id, reaction),
            INDEX idx_message (message_id),
            INDEX idx_user (user_id),
            FOREIGN KEY (message_id) REFERENCES messages(message_id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ Created message_reactions table\n";
} else {
    echo "✅ message_reactions table exists\n";
}

// ============================================
// FRIENDS TABLE
// ============================================
echo "\n👥 FRIENDS TABLE:\n";
echo "-----------------\n";

if (!tableExists($conn, 'friends')) {
    $conn->query("
        CREATE TABLE friends (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            friend_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_friendship (user_id, friend_id),
            INDEX idx_user (user_id),
            INDEX idx_friend (friend_id),
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (friend_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ Created friends table\n";
} else {
    echo "✅ Friends table exists\n";
}

// ============================================
// FRIEND REQUESTS TABLE
// ============================================
echo "\n📨 FRIEND REQUESTS TABLE:\n";
echo "------------------------\n";

if (!tableExists($conn, 'friend_requests')) {
    $conn->query("
        CREATE TABLE friend_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            receiver_id INT NOT NULL,
            status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sender (sender_id),
            INDEX idx_receiver (receiver_id),
            INDEX idx_status (status),
            FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (receiver_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ Created friend_requests table\n";
} else {
    echo "✅ Friend requests table exists\n";
}

// ============================================
// CALLS TABLE
// ============================================
echo "\n📞 CALLS TABLE:\n";
echo "---------------\n";

if (!tableExists($conn, 'calls')) {
    $conn->query("
        CREATE TABLE calls (
            id INT AUTO_INCREMENT PRIMARY KEY,
            caller_id INT NOT NULL,
            receiver_id INT NOT NULL,
            call_type ENUM('voice', 'video') DEFAULT 'voice',
            status ENUM('calling', 'answered', 'ended', 'missed') DEFAULT 'calling',
            started_at TIMESTAMP NULL,
            ended_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_caller (caller_id),
            INDEX idx_receiver (receiver_id),
            INDEX idx_status (status),
            FOREIGN KEY (caller_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (receiver_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ Created calls table\n";
} else {
    echo "✅ Calls table exists\n";
}

// ============================================
// CHAT ROOMS TABLES (for group chat)
// ============================================
echo "\n👥 GROUP CHAT TABLES:\n";
echo "--------------------\n";

// Chat rooms
if (!tableExists($conn, 'chat_rooms')) {
    $conn->query("
        CREATE TABLE chat_rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_name VARCHAR(255) NOT NULL,
            room_description TEXT,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_private TINYINT DEFAULT 0,
            max_members INT DEFAULT 50,
            INDEX idx_created_by (created_by),
            FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ Created chat_rooms table\n";
} else {
    echo "✅ chat_rooms table exists\n";
}

// Chat room members
if (!tableExists($conn, 'chat_room_members')) {
    $conn->query("
        CREATE TABLE chat_room_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            user_id INT NOT NULL,
            role VARCHAR(50) DEFAULT 'member',
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_room_user (room_id, user_id),
            INDEX idx_room (room_id),
            INDEX idx_user (user_id),
            FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ Created chat_room_members table\n";
} else {
    echo "✅ chat_room_members table exists\n";
}

// Group messages
if (!tableExists($conn, 'group_messages')) {
    $conn->query("
        CREATE TABLE group_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            user_id INT NOT NULL,
            message TEXT,
            message_type VARCHAR(20) DEFAULT 'text',
            file_path VARCHAR(500),
            file_name VARCHAR(255),
            file_size INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_edited TINYINT DEFAULT 0,
            is_deleted TINYINT DEFAULT 0,
            INDEX idx_room (room_id),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ Created group_messages table\n";
} else {
    echo "✅ group_messages table exists\n";
}

// ============================================
// CREATE UPLOAD DIRECTORIES
// ============================================
echo "\n📁 CREATING UPLOAD DIRECTORIES:\n";
echo "-------------------------------\n";

$directories = [
    'uploads',
    'uploads/avatars',
    'uploads/files',
    'uploads/images',
    'uploads/voice'
];

foreach ($directories as $dir) {
    if (!file_exists($dir)) {
        if (mkdir($dir, 0777, true)) {
            echo "✅ Created $dir directory\n";
            
            // Create .htaccess for security
            $htaccess = $dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                $content = "Options -Indexes\n";
                $content .= "<FilesMatch \"\.(php|php3|php4|php5|phtml|pl|cgi)$\">\n";
                $content .= "    Order Deny,Allow\n";
                $content .= "    Deny from all\n";
                $content .= "</FilesMatch>";
                file_put_contents($htaccess, $content);
            }
        } else {
            echo "❌ Failed to create $dir directory\n";
        }
    } else {
        echo "✅ $dir directory exists\n";
    }
}

// ============================================
// CREATE INDEX.HTML FOR SECURITY
// ============================================
echo "\n🔒 CREATING SECURITY FILES:\n";
echo "--------------------------\n";

if (!file_exists('uploads/index.html')) {
    file_put_contents('uploads/index.html', '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1></body></html>');
    echo "✅ Created uploads/index.html\n";
}

// ============================================
// CHECK DATABASE CONNECTION
// ============================================
echo "\n🔌 CHECKING DATABASE CONNECTION:\n";
echo "--------------------------------\n";

try {
    $test = $conn->query("SELECT 1");
    if ($test) {
        echo "✅ Database connection is working\n";
        
        // Get table counts
        $tables = ['users', 'messages', 'friends', 'friend_requests', 'calls'];
        foreach ($tables as $table) {
            if (tableExists($conn, $table)) {
                $count = $conn->query("SELECT COUNT(*) as c FROM $table")->fetch_assoc()['c'];
                echo "  📊 $table: $count records\n";
            }
        }
    }
} catch (Exception $e) {
    echo "❌ Database connection error: " . $e->getMessage() . "\n";
}

echo "\n";
echo "═══════════════════════════════════════════════\n";
echo "          DATABASE FIX COMPLETED!              \n";
echo "═══════════════════════════════════════════════\n";
echo "\n";
echo "Next steps:\n";
echo "1. Delete this file (complete_fix.php) after running\n";
echo "2. Test the application\n";
echo "3. If you encounter any issues, check error logs\n";
echo "\n";

echo "</pre>";
?>