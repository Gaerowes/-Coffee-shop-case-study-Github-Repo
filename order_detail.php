
<?php
require_once 'auth.php';
require_once 'config.php';

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    http_response_code(400);
    exit('Invalid order ID.');
}

// ======================================
// AUTHENTICATED USERS
// ======================================
if (is_logged_in()) {

    if (!is_customer() && !is_cashier() && !is_admin()) {
        http_response_code(403);
        exit('Access denied.');
    }

    $st = mysqli_prepare(
        $conn,
        "SELECT o.*,
                u.username AS customer,
                c.username AS cashier
         FROM orders o
         LEFT JOIN users u ON u.id = o.customer_id
         LEFT JOIN users c ON c.id = o.cashier_id
         WHERE o.id = ?"
    );

    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);

    $o = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);

    if (!$o) {
        http_response_code(404);
        exit('Order not found.');
    }

    if (
        is_customer() &&
        (int)$o['customer_id'] !== (int)$_SESSION['user_id']
    ) {
        http_response_code(403);
        exit('Access denied.');
    }

    if (
        is_cashier() &&
        (int)$o['cashier_id'] !== (int)$_SESSION['user_id']
    ) {
        http_response_code(403);
        exit('This is not your assigned order.');
    }

    $isGuest = false;
    $guestToken = '';

}

// ======================================
// GUEST ACCESS
// ======================================
else {

    $token = trim($_GET['token'] ?? '');

    if ($token === '') {
        $token = $_SESSION['guest_order_tokens'][$id] ?? '';
    }

    if ($token === '') {
        http_response_code(403);
        exit('Access token is required.');
    }

    $tokenHash = hash('sha256', $token);

    $st = mysqli_prepare(
        $conn,
        "SELECT o.*,
                o.customer_name AS customer,
                c.username AS cashier
         FROM orders o
         LEFT JOIN users c ON c.id = o.cashier_id
         WHERE o.id = ?
         AND o.customer_id IS NULL
         AND o.guest_token_hash = ?"
    );

    mysqli_stmt_bind_param($st, 'is', $id, $tokenHash);
    mysqli_stmt_execute($st);

    $o = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);

    if (!$o) {
        http_response_code(404);
        exit('Order not found or invalid access token.');
    }

    $isGuest = true;
    $guestToken = $token;
}

// ======================================
// PAYMENT STATUS
// ======================================
$paymentStatus = $o['payment_status'] ?? 'unpaid';

$paymentLabels = [
    'unpaid' => 'Unpaid',
    'paid' => 'Paid',
    'failed' => 'Failed',
    'refunded' => 'Refunded'
];

$paymentColors = [
    'unpaid' => 'warning',
    'paid' => 'success',
    'failed' => 'danger',
    'refunded' => 'info'
];

$paymentLabel = $paymentLabels[$paymentStatus] ?? ucfirst($paymentStatus);
$paymentColor = $paymentColors[$paymentStatus] ?? 'secondary';

// ======================================
// GET ORDER ITEMS
// ======================================
$st = mysqli_prepare(
    $conn,
    "SELECT oi.*, p.name AS current_name
     FROM order_items oi
     LEFT JOIN product p ON p.id = oi.product_id
     WHERE oi.order_id = ?"
);

mysqli_stmt_bind_param($st, 'i', $id);
mysqli_stmt_execute($st);

$items = mysqli_stmt_get_result($st);
mysqli_stmt_close($st);

// ======================================
// PAYMENT PERMISSION
// ======================================
$canPay = (
    $o['status'] === 'pending' &&
    $paymentStatus === 'unpaid' &&
    ($isGuest || is_customer())
);

$csrfToken = csrf_token();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <title>Order Details</title>
</head>

<body>

<?php include 'navbar.php'; ?>

<main class="container py-4">

    <h2>Order #<?= (int)$o['id'] ?></h2>

    <div class="card mb-4">
        <div class="card-body">

            <p>
                <strong>Customer:</strong>
                <?= e($o['customer'] ?? 'Guest') ?>
            </p>

            <p>
                <strong>Cashier:</strong>
                <?= e($o['cashier'] ?? 'Unassigned') ?>
            </p>

            <p>
                <strong>Status:</strong>
                <span class="badge bg-secondary">
                    <?= e(ucfirst($o['status'])) ?>
                </span>
            </p>

            <p>
                <strong>Payment Status:</strong>
                <span class="badge bg-<?= e($paymentColor) ?>">
                    <?= e($paymentLabel) ?>
                </span>
            </p>

            <p class="mb-0">
                <strong>Order Date:</strong>
                <?= e($o['order_date']) ?>
            </p>

        </div>
    </div>

    <h4>Order Items</h4>

    <div class="table-responsive">
        <table class="table table-bordered align-middle">

            <thead>
                <tr>
                    <th>Product</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Discount</th>
                    <th>Line Total</th>
                </tr>
            </thead>

            <tbody>

                <?php if (mysqli_num_rows($items) === 0): ?>
                    <tr>
                        <td colspan="5" class="text-center">
                            No order items found.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php while ($i = mysqli_fetch_assoc($items)): ?>
                    <tr>
                        <td>
                            <?= e($i['current_name'] ?? 'Unavailable product') ?>
                        </td>

                        <td>
                            <?= (int)$i['quantity'] ?>
                        </td>

                        <td>
                            Rp <?= number_format((float)$i['price'], 0, ',', '.') ?>
                        </td>

                        <td>
                            <?= number_format((float)$i['discount_percent'], 2) ?>%
                        </td>

                        <td>
                            Rp <?= number_format((float)$i['subtotal'], 0, ',', '.') ?>
                        </td>
                    </tr>
                <?php endwhile; ?>

            </tbody>
        </table>
    </div>

    <h4 class="text-end">
        Total: Rp <?= number_format((float)$o['total_price'], 0, ',', '.') ?>
    </h4>

    <?php if ($canPay): ?>

        <div class="text-end mt-3">

            <button
                type="button"
                id="payNowBtn"
                class="btn btn-success btn-lg"
                data-order-id="<?= (int)$o['id'] ?>"
                data-csrf="<?= e($csrfToken) ?>"
                data-guest-token="<?= e($guestToken) ?>"
            >
                Pay Now
            </button>

            <div
                id="paymentMessage"
                class="mt-2"
                role="alert">
            </div>

        </div>

    <?php elseif ($paymentStatus === 'paid'): ?>

        <div class="alert alert-success mt-3 text-end">
            Payment completed successfully.
        </div>

    <?php elseif ($paymentStatus === 'refunded'): ?>

        <div class="alert alert-info mt-3 text-end">
            This order has been refunded.
        </div>

    <?php elseif ($paymentStatus === 'failed'): ?>

        <div class="alert alert-danger mt-3 text-end">
            Payment failed. Please contact the cashier or administrator.
        </div>

    <?php endif; ?>

    <a href="my_orders.php"
       class="btn btn-outline-secondary mt-3">
        Back to My Orders
    </a>

</main>

<?php if ($canPay): ?>

<script>
const payButton = document.getElementById('payNowBtn');
const paymentMessage = document.getElementById('paymentMessage');

payButton.addEventListener('click', async function () {

    payButton.disabled = true;
    payButton.textContent = 'Preparing Payment...';

    paymentMessage.textContent = '';

    const payload = {
        order_id: Number(payButton.dataset.orderId),
        csrf_token: payButton.dataset.csrf
    };

    const guestToken = payButton.dataset.guestToken;

    if (guestToken) {
        payload.guest_token = guestToken;
    }

    try {

        const response = await fetch('api/pay.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(
                result.message || 'Unable to create payment.'
            );
        }

        const redirectUrl =
            result.redirect_url ||
            result.data?.redirect_url;

        if (!redirectUrl) {
            throw new Error('Payment URL was not returned.');
        }

        window.location.href = redirectUrl;

    } catch (error) {

        paymentMessage.className = 'mt-2 text-danger';
        paymentMessage.textContent = error.message;

        payButton.disabled = false;
        payButton.textContent = 'Pay Now';
    }

});
</script>

<?php endif; ?>

</body>
</html>
