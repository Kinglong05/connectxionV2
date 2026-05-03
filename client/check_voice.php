<?php
require 'db.php';
$res = $conn->query("SELECT file_path FROM messages WHERE message_type = 'voice' ORDER BY message_id DESC LIMIT 5");
while($row = $res->fetch_assoc()) {
    $path = $row['file_path'];
    $exists = file_exists($path) ? "EXISTS" : "MISSING";
    echo "$path | $exists\n";
}
?>
