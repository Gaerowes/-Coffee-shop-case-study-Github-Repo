<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'db_kopipiro';

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
	http_response_code(500);
	die('Database connection failed. Check config.php.');
}

mysqli_set_charset($conn, 'utf8mb4');
?>
