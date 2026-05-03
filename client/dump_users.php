<?php
require_once 'db.php';
$res = $conn->query("SELECT username FROM users");
$users = [];
while($row = $res->fetch_assoc()) $users[] = $row['username'];
echo json_encode($users);
?>
