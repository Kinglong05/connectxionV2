<?php
require_once 'db.php';
$res = $conn->query("SHOW FULL COLUMNS FROM users LIKE 'username'");
$row = $res->fetch_assoc();
print_r($row);
?>
