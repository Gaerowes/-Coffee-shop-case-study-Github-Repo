<?php
include 'auth.php';
include 'config.php';
require_admin();

$message = '';

if (isset($_POST['add_user'])) {
	verify_csrf();
	$username = trim($_POST['username'] ?? '');
	$password = $_POST['password'] ?? '';
	$role = $_POST['role'] ?? 'cashier';

	if (strlen($password) < 6) {
		$message = "<div class='alert alert-danger'>Password must be at least 6 characters.</div>";
	} elseif (!in_array($role, ['admin', 'cashier', 'customer'], true)) {
		$message = "<div class='alert alert-danger'>Invalid role.</div>";
	} else {
		$hash = password_hash($password, PASSWORD_DEFAULT);
		$st = mysqli_prepare($conn, 'INSERT INTO users(username, password, role) VALUES(?, ?, ?)');
		mysqli_stmt_bind_param($st, 'sss', $username, $hash, $role);

		try {
			mysqli_stmt_execute($st);
			$message = "<div class='alert alert-success'>User added successfully.</div>";
		} catch (mysqli_sql_exception $ex) {
			$message = "<div class='alert alert-danger'>Username is already taken or role is invalid.</div>";
		}
	}
}

$users = mysqli_query($conn, 'SELECT id, username, role FROM users ORDER BY id');
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>User Management</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
	<?php include 'navbar.php'; ?>
	<main class="container py-4">
		<?= $message ?>
		<div class="row g-4">
			<div class="col-md-4">
				<div class="card">
					<div class="card-header bg-success text-white">Add User</div>
					<div class="card-body">
						<form method="post">
					<?= csrf_field() ?>
							<div class="mb-3">
								<label class="form-label">Username</label>
								<input name="username" class="form-control" required>
							</div>
							<div class="mb-3">
								<label class="form-label">Password (min 6 chars)</label>
								<input type="password" name="password" class="form-control" minlength="6" required>
							</div>
							<div class="mb-3">
								<label class="form-label">Role</label>
								<select name="role" class="form-select">
									<option value="cashier">Cashier</option>
									<option value="admin">Admin</option>
									<option value="customer">Customer</option>
								</select>
							</div>
							<button class="btn btn-success" name="add_user">Save User</button>
						</form>
					</div>
				</div>
			</div>
			<div class="col-md-8">
				<div class="card">
					<div class="card-header bg-primary text-white">User List</div>
					<div class="card-body table-responsive">
						<table class="table table-striped">
							<thead>
								<tr>
									<th>ID</th>
									<th>Username</th>
									<th>Role</th>
								</tr>
							</thead>
							<tbody>
								<?php while ($u = mysqli_fetch_assoc($users)): ?>
									<tr>
										<td><?= $u['id'] ?></td>
										<td>
											<?= e($u['username']) ?>
											<?php if ((int) $u['id'] === (int) $_SESSION['user_id']): ?>
												<span class="badge bg-info">you</span>
											<?php endif; ?>
										</td>
										<td>
											<span class="badge <?= $u['role'] === 'admin' ? 'bg-danger' : ($u['role'] === 'cashier' ? 'bg-warning text-dark' : 'bg-secondary') ?>">
												<?= e($u['role']) ?>
											</span>
										</td>
									</tr>
								<?php endwhile; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</main>
</body>
</html>
