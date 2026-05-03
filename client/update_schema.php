<?php
require_once 'db.php';

echo "<h2>Updating Database Schema for Advanced Features...</h2>";

// Function to check if column exists
function columnExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM $table LIKE '$column'");
    return $result && $result->num_rows > 0;
}

// Add is_pinned to messages
if (!columnExists($conn, 'messages', 'is_pinned')) {
    $conn->query("ALTER TABLE messages ADD COLUMN is_pinned TINYINT DEFAULT 0 AFTER read_status");
    echo "✅ Added is_pinned to messages<br>";
} else {
    echo "⏺️ is_pinned already exists in messages<br>";
}

// Add is_pinned to group_messages
if (!columnExists($conn, 'group_messages', 'is_pinned')) {
    $conn->query("ALTER TABLE group_messages ADD COLUMN is_pinned TINYINT DEFAULT 0 AFTER message_type");
    echo "✅ Added is_pinned to group_messages<br>";
} else {
    echo "⏺️ is_pinned already exists in group_messages<br>";
}

// Ensure read_status has the correct values
if (columnExists($conn, 'messages', 'read_status')) {
    $conn->query("ALTER TABLE messages MODIFY COLUMN read_status ENUM('sent', 'delivered', 'read') DEFAULT 'sent'");
    echo "✅ Verified read_status column type<br>";
}

echo "<h3>Database update completed!</h3>";
?>
