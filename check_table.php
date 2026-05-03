<?php
require 'client/db.php';
$res = $conn->query("SHOW TABLES LIKE 'group_message_reactions'");
if ($res->num_rows > 0) {
    echo "✅ Table group_message_reactions exists\n";
    $res2 = $conn->query("DESCRIBE group_message_reactions");
    while($row = $res2->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "❌ Table group_message_reactions DOES NOT EXIST\n";
}
?>
