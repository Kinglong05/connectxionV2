<?php
// save as fix_messages_table.php
require_once 'db.php';

// Check if messages table exists
$table_check = $conn->query("SHOW TABLES LIKE 'messages'");
if ($table_check->num_rows == 0) {
    // Create messages table with correct structure
    $sql = "CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        message TEXT,
        message_type ENUM('text', 'image', 'file', 'voice') DEFAULT 'text',
        file_path VARCHAR(500),
        file_size INT,
        is_read TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (sender_id),
        INDEX (receiver_id),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if ($conn->query($sql)) {
        echo "Messages table created successfully!<br>";
    } else {
        echo "Error creating table: " . $conn->error . "<br>";
    }
} else {
    echo "Messages table exists. Checking structure...<br>";
    
    // Check if message_type column exists
    $column_check = $conn->query("SHOW COLUMNS FROM messages LIKE 'message_type'");
    if ($column_check->num_rows == 0) {
        $conn->query("ALTER TABLE messages ADD COLUMN message_type ENUM('text', 'image', 'file', 'voice') DEFAULT 'text' AFTER message");
        echo "Added message_type column<br>";
    }
    
    // Check if file_path column exists
    $column_check = $conn->query("SHOW COLUMNS FROM messages LIKE 'file_path'");
    if ($column_check->num_rows == 0) {
        $conn->query("ALTER TABLE messages ADD COLUMN file_path VARCHAR(500) AFTER message_type");
        echo "Added file_path column<br>";
    }
    
    // Check if file_size column exists
    $column_check = $conn->query("SHOW COLUMNS FROM messages LIKE 'file_size'");
    if ($column_check->num_rows == 0) {
        $conn->query("ALTER TABLE messages ADD COLUMN file_size INT AFTER file_path");
        echo "Added file_size column<br>";
    }
    
    // Check if is_read column exists
    $column_check = $conn->query("SHOW COLUMNS FROM messages LIKE 'is_read'");
    if ($column_check->num_rows == 0) {
        $conn->query("ALTER TABLE messages ADD COLUMN is_read TINYINT DEFAULT 0 AFTER file_size");
        echo "Added is_read column<br>";
    }
}

echo "<br>Database fix completed!";
?>