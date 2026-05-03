<?php
require_once 'db.php';

echo "<h2>Fixing Database Structure...</h2>";

// Function to check if column exists
function columnExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM $table LIKE '$column'");
    return $result && $result->num_rows > 0;
}

// Add reply_to column
if (!columnExists($conn, 'messages', 'reply_to')) {
    $conn->query("ALTER TABLE messages ADD COLUMN reply_to INT DEFAULT NULL AFTER message_type, ADD INDEX (reply_to)");
    echo "✅ Added reply_to column<br>";
} else {
    echo "⏺️ reply_to column already exists<br>";
}

// Add edited column
if (!columnExists($conn, 'messages', 'edited')) {
    $conn->query("ALTER TABLE messages ADD COLUMN edited TINYINT DEFAULT 0 AFTER reply_to");
    echo "✅ Added edited column<br>";
} else {
    echo "⏺️ edited column already exists<br>";
}

// Add deleted column
if (!columnExists($conn, 'messages', 'deleted')) {
    $conn->query("ALTER TABLE messages ADD COLUMN deleted TINYINT DEFAULT 0 AFTER edited");
    echo "✅ Added deleted column<br>";
} else {
    echo "⏺️ deleted column already exists<br>";
}

// Add read_status column
if (!columnExists($conn, 'messages', 'read_status')) {
    $conn->query("ALTER TABLE messages ADD COLUMN read_status ENUM('sent', 'delivered', 'read') DEFAULT 'sent' AFTER is_read");
    echo "✅ Added read_status column<br>";
} else {
    echo "⏺️ read_status column already exists<br>";
}

// Create message_reactions table
$table_check = $conn->query("SHOW TABLES LIKE 'message_reactions'");
if ($table_check->num_rows == 0) {
    $conn->query("
        CREATE TABLE message_reactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            reaction VARCHAR(10) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_reaction (message_id, user_id, reaction),
            INDEX (message_id),
            INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ Created message_reactions table<br>";
} else {
    echo "⏺️ message_reactions table already exists<br>";
}

echo "<h3>Database fix completed!</h3>";
echo "<p><a href='chat.php?id=" . ($_GET['id'] ?? '') . "'>Go back to chat</a></p>";
?>