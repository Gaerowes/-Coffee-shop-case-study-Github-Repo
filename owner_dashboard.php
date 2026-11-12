<?php
include 'auth.php';
include 'config.php';
require_admin();

$sum = mysqli_fetch_assoc(mysqli_query(
	$conn,
	"SELECT COUNT(*) AS n,
			COALESCE(SUM(CASE WHEN status='completed' THEN total_price ELSE 0 END), 0) AS revenue
	FROM orders"
));

$r = mysqli_query(
	$conn,
	"SELECT DATE(order_date) AS day,
			SUM(CASE WHEN status='completed' THEN total_price ELSE 0 END) AS revenue
	FROM orders
	WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
	GROUP BY DATE(order_date)
	ORDER BY day"
);
$labels = [];
$vals = [];
while ($x = mysqli_fetch_assoc($r)) {
	$labels[] = $x['day'];
	$vals[] = (float) $x['revenue'];
}

$orders = mysqli_query(
	$conn,
	"SELECT o.*, u.username AS customer, c.username AS cashier
	 FROM orders o
	 JOIN users u ON u.id = o.customer_id
	 LEFT JOIN users c ON c.id = o.cashier_id
	 ORDER BY o.order_date DESC"
);
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Admin Dashboard</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
	<?php include 'navbar.php'; ?>
	<main class="container py-4">
		<div class="d-flex justify-content-between align-items-center">
			<h2>Admin Dashboard</h2>
			<a class="btn btn-success" href="export_orders.php">Export CSV for Excel</a>
		</div>

		<div class="row my-3">
			<div class="col-md-6">
				<div class="card p-3">
					All orders
					<h3><?= $sum['n'] ?></h3>
				</div>
			</div>
			<div class="col-md-6">
				<div class="card p-3">
					Completed sales
					<h3>Rp <?= number_format((float) $sum['revenue'], 0, ',', '.') ?></h3>
				</div>
			</div>
		</div>

		<div class="card p-3 mb-4">
			<h5>Revenue, last 14 days</h5>
			<canvas id="chart"></canvas>
		</div>

		<h4>All Orders</h4>
		<div class="table-responsive">
			<table class="table">
				<thead>
					<tr>
						<th>ID</th>
						<th>Customer</th>
						<th>Cashier</th>
						<th>Date</th>
						<th>Status</th>
						<th>Total</th>
						<th>Details</th>
					</tr>
				</thead>
				<tbody>
					<?php while ($o = mysqli_fetch_assoc($orders)): ?>
						<tr>
							<td><?= $o['id'] ?></td>
							<td><?= e($o['customer']) ?></td>
							<td><?= e($o['cashier'] ?? 'Unclaimed') ?></td>
							<td><?= e($o['order_date']) ?></td>
							<td><?= e($o['status']) ?></td>
							<td>Rp <?= number_format((float) $o['total_price'], 0, ',', '.') ?></td>
							<td><a href="order_detail.php?id=<?= $o['id'] ?>">View</a></td>
						</tr>
					<?php endwhile; ?>
				</tbody>
			</table>
		</div>
	</main>

	<script>
		new Chart(document.getElementById('chart'), {
			type: 'line',
			data: {
				labels: <?= json_encode($labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
				datasets: [{
					label: 'Completed revenue (IDR)',
					data: <?= json_encode($vals, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
					tension: 0.25
				}]
			},
			options: {
				scales: {
					y: {
						beginAtZero: true
					}
				}
			}
		});
	</script>
</body>
</html>
