<?php
include 'auth.php';
include 'config.php';
require_admin();

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
			'INSERT INTO product(name,price,url_image,category,description,discount_percent) VALUES(?,?,?,?,?,?)'
		);
		mysqli_stmt_bind_param($st, 'sisssd', $name, $price, $image, $category, $description, $discount);

		if (mysqli_stmt_execute($st)) {
			header('Location: index.php');
			exit;
		} else {
			$error = 'Could not save product. Check that the name is unique.';
		}
	}
}
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Add Product</title>
	<link
		href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
		rel="stylesheet"
	>
</head>
<body class="bg-light">
	<?php include 'navbar.php'; ?>

	<main class="container py-4">
		<div class="card mx-auto" style="max-width: 650px">
			<div class="card-header bg-success text-white">
				<h5 class="mb-0">Add Product</h5>
			</div>
			<div class="card-body">
				<?php if ($error): ?>
					<div class="alert alert-danger"><?= e($error) ?></div>
				<?php endif; ?>

				<form method="post">
					<?= csrf_field() ?>
					<div class="mb-3">
						<label class="form-label">Name</label>
						<input name="name" class="form-control" required maxlength="100">
					</div>
					<div class="mb-3">
						<label class="form-label">Price (IDR)</label>
						<input type="number" name="price" class="form-control" min="0" required>
					</div>
					<div class="mb-3">
						<label class="form-label">Image URL/path</label>
						<input name="url_image" class="form-control" required>
					</div>
					<div class="mb-3">
						<label class="form-label">Category</label>
						<select name="category" class="form-select">
							<option>Coffee</option>
							<option>Non-Coffee</option>
						</select>
					</div>
					<div class="mb-3">
						<label class="form-label">Description</label>
						<textarea name="description" class="form-control" rows="3"></textarea>
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
							value="0"
						>
					</div>
					<div class="d-flex justify-content-end gap-2">
						<a href="index.php" class="btn btn-secondary">Cancel</a>
						<button class="btn btn-success">Save</button>
					</div>
				</form>
			</div>
		</div>
	</main>
</body>
</html>
