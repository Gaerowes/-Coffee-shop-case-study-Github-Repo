<?php
include 'auth.php';
include 'config.php';

require_admin();

$id = (int) ($_GET['id'] ?? 0);
$st = mysqli_prepare($conn, "SELECT * FROM product WHERE id = ?");
mysqli_stmt_bind_param($st, 'i', $id);
mysqli_stmt_execute($st);
$data = mysqli_fetch_assoc(mysqli_stmt_get_result($st));

if (!$data) {
	http_response_code(404);
	exit('Product not found.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	verify_csrf();
	$name = trim($_POST['name'] ?? '');
	$priceInput = $_POST['price'] ?? '';
	$price = filter_var($priceInput, FILTER_VALIDATE_INT);
	$image = trim($_POST['url_image'] ?? '');
	$category = $_POST['category'] ?? 'Coffee';
	$description = trim($_POST['description'] ?? '');
	$discount = max(0, min(100, (float) ($_POST['discount_percent'] ?? 0)));

	if ($name === '' || strlen($name) > 100 || $price === false || $price < 0 ||
		!in_array($category, ['Coffee', 'Non-Coffee'], true) ||
		($image !== '' && !preg_match('#^(https?://|[A-Za-z0-9_./-])#i', $image))) {
		$error = 'Please enter valid product data.';
	} else {
		$st = mysqli_prepare(
			$conn,
			"UPDATE product
			 SET name = ?, price = ?, url_image = ?, category = ?, description = ?, discount_percent = ?
			 WHERE id = ?"
		);
		mysqli_stmt_bind_param($st, 'sisssdi', $name, $price, $image, $category, $description, $discount, $id);

		if (mysqli_stmt_execute($st)) {
			header('Location: index.php');
			exit;
		}

		$error = 'Could not update product. Check that the name is unique.';
	}
}
?>
<!doctype html>
<html>
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>Edit Product</title>
		<link
			href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
			rel="stylesheet"
		>
	</head>
	<body class="bg-light">
		<?php include 'navbar.php'; ?>
		<main class="container py-4">
			<div class="card mx-auto" style="max-width: 650px">
				<div class="card-header bg-warning">
					<h5 class="mb-0">Edit Product</h5>
				</div>
				<div class="card-body">
					<?php if ($error): ?>
						<div class="alert alert-danger"><?= e($error) ?></div>
					<?php endif; ?>

					<form method="post">
					<?= csrf_field() ?>
						<div class="mb-3">
							<label class="form-label">Name</label>
							<input
								name="name"
								class="form-control"
								required
								value="<?= e($data['name']) ?>"
							>
						</div>
						<div class="mb-3">
							<label class="form-label">Price (IDR)</label>
							<input
								type="number"
								name="price"
								class="form-control"
								min="0"
								required
								value="<?= e($data['price']) ?>"
							>
						</div>
						<div class="mb-3">
							<label class="form-label">Image URL/path</label>
							<input
								name="url_image"
								class="form-control"
								required
								value="<?= e($data['url_image']) ?>"
							>
						</div>
						<div class="mb-3">
							<label class="form-label">Category</label>
							<select name="category" class="form-select">
								<option value="Coffee" <?= $data['category'] === 'Coffee' ? 'selected' : '' ?>>Coffee</option>
								<option value="Non-Coffee" <?= $data['category'] === 'Non-Coffee' ? 'selected' : '' ?>>Non-Coffee</option>
							</select>
						</div>
						<div class="mb-3">
							<label class="form-label">Description</label>
							<textarea name="description" class="form-control" rows="3"><?= e($data['description'] ?? '') ?></textarea>
						</div>
						<div class="mb-3">
							<label class="form-label">Discount (%)</label>
							<input
								type="number"
								name="discount_percent"
								class="form-control"
								min="0"
								max="100"
								step="0.01"
								value="<?= e($data['discount_percent']) ?>"
							>
						</div>
						<div class="d-flex justify-content-end gap-2">
							<a href="index.php" class="btn btn-secondary">Cancel</a>
							<button class="btn btn-warning">Update</button>
						</div>
					</form>
				</div>
			</div>
		</main>
	</body>
</html>
