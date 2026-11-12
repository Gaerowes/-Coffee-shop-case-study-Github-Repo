<?php
include 'auth.php';
include 'config.php';
require_admin();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="coffee_shop_orders.csv"');

$out = fopen('php://output', 'w');

function csv_safe($value): string {
    $value = (string) $value;
    if ($value !== '' && preg_match('/^[=+@\\-\\t\\r]/', $value)) {
        return "'" . $value;
    }
    return $value;
}
fputcsv($out, ['Order ID', 'Customer', 'Cashier', 'Order Date', 'Status', 'Total']);

$sql = "SELECT o.id, u.username AS customer, c.username AS cashier, o.order_date, o.status, o.total_price
		FROM orders o
		JOIN users u ON u.id = o.customer_id
		LEFT JOIN users c ON c.id = o.cashier_id
		ORDER BY o.order_date DESC";

$rs = mysqli_query($conn, $sql);
while ($row = mysqli_fetch_assoc($rs)) {
	fputcsv($out, [
		$row['id'],
		csv_safe($row['customer']),
		csv_safe($row['cashier'] ?? 'Unclaimed'),
		csv_safe($row['order_date']),
		csv_safe($row['status']),
		$row['total_price'],
	]);
}

fclose($out);
exit;
