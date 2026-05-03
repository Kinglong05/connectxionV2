<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'db.php';

$query = $_GET['query'] ?? 'test';
$user_id = $_SESSION['user_id'] ?? 1;

echo "Searching for: '$query' (User ID: $user_id)<br>";

// Show raw users for comparison
$all = $conn->query("SELECT user_id, username FROM users");
echo "Raw users in DB:<br>";
while($r = $all->fetch_assoc()) {
    echo "ID: {$r['user_id']}, Name: '{$r['username']}'<br>";
}

$search_term = "%$query%";
$search_query = "
    SELECT u.user_id, u.username, u.avatar, u.bio
    FROM users u
    WHERE u.username LIKE ? AND u.user_id != ?
";

$stmt = $conn->prepare($search_query);
$stmt->bind_param("si", $search_term, $user_id);
$stmt->execute();
$result = $stmt->get_result();

echo "Rows found: " . $result->num_rows . "<br>";
while ($row = $result->fetch_assoc()) {
    print_r($row);
    echo "<br>";
}
?>
