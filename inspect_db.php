<?php
require 'client/db.php';
$res = $conn->query("DESCRIBE group_messages");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
?>
