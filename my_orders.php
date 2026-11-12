
<?php
require_once 'auth.php';
require_once 'config.php';

// Admin dan cashier tidak diperbolehkan mengakses halaman ini.
if (is_logged_in() && !is_customer()) {
    http_response_code(403);
    exit('Access denied.');
}

$orders = [];
$guestOrder = null;
$error = '';

// CUSTOMER LOGIN: tampilkan semua riwayat pesanan.
if (is_customer()) {
    $uid = (int) $_SESSION['user_id'];

    $st = mysqli_prepare(
        $conn,
        "SELECT o.*, u.username AS cashier
        FROM orders o
        LEFT JOIN users u ON u.id = o.cashier_id
        WHERE o.customer_id = ?
        ORDER BY o.order_date DESC"
    );

    mysqli_stmt_bind_param($st, 'i', $uid);
    mysqli_stmt_execute($st);
    $rs = mysqli_stmt_get_result($st);

    while ($o = mysqli_fetch_assoc($rs)) {
        $orders[] = $o;
    }

    mysqli_stmt_close($st);
}

// GUEST: akses pesanan menggunakan Order ID dan token.
else {
    $orderId = 0;
    $token = '';

    // Jika baru selesai checkout, ambil token dari session.
    if (isset($_GET['created'])) {
        $orderId = filter_var($_GET['created'], FILTER_VALIDATE_INT);
        $token = $_SESSION['guest_order_tokens'][$orderId] ?? '';
    }

    // Jika guest ingin mencari pesanan secara manual.
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();

        $orderId = filter_var(
            $_POST['order_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        $token = trim($_POST['guest_token'] ?? '');
    }

    if ($orderId && $orderId > 0 && $token !== '') {
        $tokenHash = hash('sha256', $token);

        $st = mysqli_prepare(
            $conn,
            "SELECT o.*, u.username AS cashier
            FROM orders o
            LEFT JOIN users u ON u.id = o.cashier_id
            WHERE o.id = ?
            AND o.customer_id IS NULL
            AND o.guest_token_hash = ?"
        );

        mysqli_stmt_bind_param($st, 'is', $orderId, $tokenHash);
        mysqli_stmt_execute($st);

        $rs = mysqli_stmt_get_result($st);
        $guestOrder = mysqli_fetch_assoc($rs);

        mysqli_stmt_close($st);

        if (!$guestOrder) {
    		$error = 'Order not found. Please check your Order ID and access token.';
		} else {
    		$_SESSION['guest_order_tokens'][$orderId] = $token;
		}
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['created'])) {
        $error = 'Order ID or access token is invalid.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>My Orders</title>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="container py-4">
        <h2>My Orders</h2>

        <?php if (isset($_GET['created']) && $guestOrder): ?>
    <div class="alert alert-success">
        <h5>Order placed successfully!</h5>

        <p>
            Order #<?= (int) $guestOrder['id'] ?> has been placed.
            Waiting for a cashier to claim it.
        </p>

        <hr>

        <strong>Your Access Token:</strong>

        <div class="bg-white border rounded p-2 mt-2">
            <code><?= e($token) ?></code>
        </div>

        <small class="text-danger d-block mt-2">
            Save this token! You need it to access your order again.
        </small>
    </div>
        <?php elseif (isset($_GET['created']) && is_customer()): ?>
            <div class="alert alert-success">
                Order #<?= e($_GET['created']) ?> placed.
                Waiting for a cashier to claim it.
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php if (is_customer()): ?>

            <!-- Customer order history -->
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Cashier</th>
                            <th>Status</th>
                            <th>Total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$orders): ?>
                            <tr>
                                <td colspan="5" class="text-center">
                                    No orders yet.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td>#<?= (int) $o['id'] ?></td>
                                <td><?= e($o['cashier'] ?? 'Unassigned') ?></td>
                                <td><?= e($o['status']) ?></td>
                                <td>
                                    Rp <?= number_format((float) $o['total_price'], 0, ',', '.') ?>
                                </td>
                                <td>
                                    <a href="order_detail.php?id=<?= (int) $o['id'] ?>">
                                        Detail
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <!-- Guest order lookup -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5>Find Your Order</h5>
                    <p class="text-muted">
                        Enter your Order ID and access token to view your order.
                    </p>

                    <form method="post" class="row g-3">
                        <?= csrf_field() ?>

                        <div class="col-md-5">
                            <label class="form-label" for="order_id">
                                Order ID
                            </label>
                            <input
                                type="number"
                                class="form-control"
                                name="order_id"
                                id="order_id"
                                min="1"
                                required
                                value="<?= isset($_POST['order_id']) ? e($_POST['order_id']) : '' ?>"
                            >
                        </div>

                        <div class="col-md-5">
                            <label class="form-label" for="guest_token">
                                Access Token
                            </label>
                            <input
                                type="text"
                                class="form-control"
                                name="guest_token"
                                id="guest_token"
                                required
                            >
                        </div>

                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                Find Order
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($guestOrder): ?>
                <h5>Order Information</h5>

                <div class="table-responsive">
                    <table class="table">
                        <tr>
                            <th>Order</th>
                            <td>#<?= (int) $guestOrder['id'] ?></td>
                        </tr>
                        <tr>
                            <th>Name</th>
                            <td><?= e($guestOrder['customer_name']) ?></td>
                        </tr>
                        <tr>
                            <th>Cashier</th>
                            <td><?= e($guestOrder['cashier'] ?? 'Unassigned') ?></td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td><?= e($guestOrder['status']) ?></td>
                        </tr>
                        <tr>
                            <th>Total</th>
                            <td>
                                Rp <?= number_format((float) $guestOrder['total_price'], 0, ',', '.') ?>
                            </td>
                        </tr>
                    </table>
                </div>
				<a
    				href="order_detail.php?id=<?= (int)$guestOrder['id'] ?>"
    				class="btn btn-primary mt-3"
				>
    				View Order Details
				</a>

                <a href="index.php" class="btn btn-outline-secondary">
                    Back to Home
                </a>
            <?php endif; ?>

        <?php endif; ?>
    </main>
</body>
</html>
