<?php
require_once 'db.php';
$sql = "CREATE TABLE IF NOT EXISTS group_message_reactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_message_id INT NOT NULL,
    user_id INT NOT NULL,
    reaction VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reaction (group_message_id, user_id, reaction),
    INDEX (group_message_id),
    INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "✅ Table group_message_reactions created successfully\n";
} else {
    echo "❌ Error: " . $conn->error . "\n";
}

// Also ensure group_messages has is_deleted and updated_at
$columns = [
    'is_deleted' => "ALTER TABLE group_messages ADD COLUMN is_deleted TINYINT DEFAULT 0",
    'is_edited' => "ALTER TABLE group_messages ADD COLUMN is_edited TINYINT DEFAULT 0",
    'updated_at' => "ALTER TABLE group_messages ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL"
];

foreach ($columns as $col => $alter) {
    $check = $conn->query("SHOW COLUMNS FROM group_messages LIKE '$col'");
    if ($check->num_rows == 0) {
        $conn->query($alter);
        echo "✅ Added $col to group_messages\n";
    }
}
?>
