<?php
include 'auth.php';
include 'config.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	verify_csrf();
	$id = (int) ($_POST['id'] ?? 0);

	if ($id > 0) {
		$st = mysqli_prepare($conn, "DELETE FROM product WHERE id=?");
		mysqli_stmt_bind_param($st, 'i', $id);
		mysqli_stmt_execute($st);
	}
}

header('Location: index.php');
exit;
?>
