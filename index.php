<?php
include 'auth.php';
include 'config.php';

$cat = $_GET['category'] ?? '';
$q = trim($_GET['q'] ?? '');
$sql = "SELECT p.*, (SELECT AVG(r.rating) FROM reviews r WHERE r.product_id=p.id) AS avg_rating FROM product p WHERE 1=1";
$types = '';
$args = [];

if (in_array($cat, ['Coffee', 'Non-Coffee'], true)) {
	$sql .= " AND p.category=?";
	$types .= 's';
	$args[] = $cat;
}

if ($q !== '') {
	$sql .= " AND (p.name LIKE ? OR p.description LIKE ?)";
	$types .= 'ss';
	$args[] = "%$q%";
	$args[] = "%$q%";
}

$sql .= " ORDER BY p.name";
$stmt = mysqli_prepare($conn, $sql);

if ($types !== '') {
	mysqli_stmt_bind_param($stmt, $types, ...$args);
}

mysqli_stmt_execute($stmt);
$products = mysqli_stmt_get_result($stmt);
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Coffee Shop</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
	<?php include 'navbar.php'; ?>
	<main class="container py-4">
		<div class="d-flex justify-content-between align-items-center">
			<h2>Explore Menu</h2>
			<?php if (is_admin()): ?>
				<a class="btn btn-success" href="create.php">Add Product</a>
			<?php endif; ?>
		</div>

		<form class="row g-2 my-3">
			<div class="col-md-5">
				<input name="q" class="form-control" placeholder="Search products" value="<?= e($q) ?>">
			</div>
			<div class="col-md-3">
				<select name="category" class="form-select">
					<option value="">All categories</option>
					<option value="Coffee" <?= $cat === 'Coffee' ? 'selected' : '' ?>>Coffee</option>
					<option value="Non-Coffee" <?= $cat === 'Non-Coffee' ? 'selected' : '' ?>>Non-Coffee</option>
				</select>
			</div>
			<div class="col-auto">
				<button class="btn btn-dark">Search</button>
			</div>
		</form>

		<div class="row g-3">
			<?php while ($p = mysqli_fetch_assoc($products)): ?>
				<?php
				$d = max(0, min(100, (float) $p['discount_percent']));
				$final = (float) $p['price'] * (1 - $d / 100);
				?>
				<div class="col-md-4">
					<div class="card h-100">
						<?php if (!empty($p['url_image'])): ?>
							<img
								src="<?= e($p['url_image']) ?>"
								class="card-img-top"
								alt="<?= e($p['name']) ?>"
								style="height: 180px; object-fit: cover"
							>
						<?php endif; ?>
						<div class="card-body">
							<span class="badge bg-secondary"><?= e($p['category']) ?></span>
							<h5 class="mt-2"><?= e($p['name']) ?></h5>
							<p><?= e($p['description'] ?? '') ?></p>
							<p>⭐ <?= number_format((float) ($p['avg_rating'] ?? 0), 1) ?>/5</p>

							<?php if ($d > 0): ?>
								<del>Rp <?= number_format((float) $p['price'], 0, ',', '.') ?></del>
								<span class="badge bg-danger">-<?= number_format($d, 0) ?>%</span>
							<?php endif; ?>

							<h5>Rp <?= number_format($final, 0, ',', '.') ?></h5>

							<?php if (is_customer() || !is_logged_in()): ?>
								<form method="post" action="cart.php">
									<?= csrf_field() ?>
									<input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
									<input type="hidden" name="action" value="add">
									<button class="btn btn-dark w-100">Add to cart</button>
								</form>
							<?php endif; ?>

							<?php if (is_admin()): ?>
								<div class="mt-2 d-flex gap-2">
									<a class="btn btn-sm btn-warning" href="edit.php?id=<?= $p['id'] ?>">Edit</a>
									<form method="post" action="delete.php" onsubmit="return confirm('Delete this product?')">
										<?= csrf_field() ?>
									<input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
										<button class="btn btn-sm btn-danger">Delete</button>
									</form>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</div>
			<?php endwhile; ?>
		</div>
	</main>
</body>
</html>
