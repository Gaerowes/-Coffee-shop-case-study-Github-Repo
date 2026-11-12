<?php
require_once 'auth.php';
require_cashier();
require_once 'config.php';

$cashierId = (int) $_SESSION['user_id'];
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

    if (!$orderId || $orderId < 1) {
        $error = 'Invalid order.';
    } elseif ($action === 'claim') {
        // Atomic claim: only one cashier can claim a pending, unassigned order.
        $stmt = mysqli_prepare($conn, "UPDATE orders SET cashier_id = ?, status = 'claimed' WHERE id = ? AND cashier_id IS NULL AND status = 'pending'");
        mysqli_stmt_bind_param($stmt, 'ii', $cashierId, $orderId);
        mysqli_stmt_execute($stmt);
        $changed = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        if ($changed === 1) $notice = "Order #{$orderId} has been assigned to you.";
        else $error = 'This order is no longer available to claim.';
    } else {
        $allowed = [
            'preparing' => ['claimed', 'preparing'],
            'ready' => ['preparing'],
            'completed' => ['ready']
        ];
        if (!isset($allowed[$action])) {
            $error = 'Invalid status action.';
        } else {
            $allowedFrom = $allowed[$action];
            $placeholders = implode(',', array_fill(0, count($allowedFrom), '?'));
            $sql = "UPDATE orders SET status = ? WHERE id = ? AND cashier_id = ? AND status IN ($placeholders)";
            $stmt = mysqli_prepare($conn, $sql);
            $types = 'sii' . str_repeat('s', count($allowedFrom));
            $params = array_merge([$action, $orderId, $cashierId], $allowedFrom);
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            mysqli_stmt_execute($stmt);
            $changed = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            if ($changed === 1) $notice = "Order #{$orderId} updated to {$action}.";
            else $error = 'The order status could not be changed. Refresh and check its current status.';
        }
    }
}

$sql = "SELECT o.id, o.cashier_id, o.order_date, o.status, o.total_price, u.username AS customer
        FROM orders o
        JOIN users u ON u.id = o.customer_id
        WHERE o.cashier_id = ? OR (o.cashier_id IS NULL AND o.status = 'pending')
        ORDER BY CASE WHEN o.cashier_id = ? THEN 0 ELSE 1 END, o.order_date ASC";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'ii', $cashierId, $cashierId);
mysqli_stmt_execute($stmt);
$orders = mysqli_stmt_get_result($stmt);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cashier Order Queue</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'navbar.php'; ?>
<main class="container py-4">
    <h2>Order Queue</h2>
    <p class="text-muted">You can claim unassigned pending orders. Orders already assigned to another cashier are not shown.</p>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>

    <div class="table-responsive">
        <table class="table table-bordered table-hover bg-white align-middle">
            <thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Status</th><th>Total</th><th>Action</th></tr></thead>
            <tbody>
            <?php if (mysqli_num_rows($orders) === 0): ?>
                <tr><td colspan="6" class="text-center text-muted">No orders to display.</td></tr>
            <?php else: ?>
                <?php while ($o = mysqli_fetch_assoc($orders)): ?>
                    <tr>
                        <td>#<?= (int)$o['id'] ?></td>
                        <td><?= e($o['customer']) ?></td>
                        <td><?= e($o['order_date']) ?></td>
                        <td><span class="badge text-bg-secondary"><?= e($o['status']) ?></span></td>
                        <td>Rp <?= number_format((float)$o['total_price'],0,',','.') ?></td>
                        <td>
                            <?php if ($o['status'] === 'pending' && $o['cashier_id'] === null): ?>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                    <button class="btn btn-sm btn-primary" name="action" value="claim">Claim Order</button>
                                </form>
                            <?php else: ?>
                                <a class="btn btn-sm btn-outline-secondary" href="order_detail.php?id=<?= (int)$o['id'] ?>">Details</a>
                                <?php
                                $next = [
                                    'claimed' => ['preparing', 'Start Preparing'],
                                    'preparing' => ['ready', 'Mark Ready'],
                                    'ready' => ['completed', 'Complete Order']
                                ][$o['status']] ?? null;
                                ?>
                                <?php if ($next): ?>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                        <button class="btn btn-sm btn-success" name="action" value="<?= e($next[0]) ?>"><?= e($next[1]) ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html>
