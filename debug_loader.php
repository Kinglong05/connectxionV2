<?php
$_GET['room_id'] = 39;
session_start();
$_SESSION['user_id'] = 12;
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('error_log', 'c:/xampp/htdocs/New folder (3)/php_error.log');
include 'client/load_group_messages.php';
?>
