<?php
require_once 'db.php';
$res = $conn->query("SELECT user_id, username, email FROM users");
while($row = $res->fetch_assoc()) {
    print_r($row);
    echo "<br>";
}
?>
