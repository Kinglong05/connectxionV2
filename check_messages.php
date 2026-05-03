<?php
require 'client/db.php';
$res = $conn->query("SELECT * FROM group_messages ORDER BY id DESC LIMIT 5");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
