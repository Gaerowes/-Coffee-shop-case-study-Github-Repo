<?php
require_once 'auth.php';
require_once 'config.php';

if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    header('Location: cart.php');
    exit;
}

function get_checkout_items(mysqli $conn, array $cart): array {
    $ids = [];
    foreach ($cart as $id => $qty) {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        $qty = filter_var($qty, FILTER_VALIDATE_INT);
        if ($id && $id > 0 && $qty && $qty > 0) $ids[$id] = min(99, $qty);
    }
    if (!$ids) return [];

    $keys = array_keys($ids);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $types = str_repeat('i', count($keys));
    $stmt = mysqli_prepare($conn, "SELECT id, name, price, discount_percent FROM product WHERE id IN ($placeholders) ORDER BY name");
    mysqli_stmt_bind_param($stmt, $types, ...$keys);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $items = [];
    while ($p = mysqli_fetch_assoc($result)) {
        $id = (int)$p['id'];
        $discount = max(0, min(100, (float)$p['discount_percent']));
        $price = (float)$p['price'];
        $unitAfterDiscount = round($price * (1 - $discount / 100), 2);
        $qty = $ids[$id];
        $items[] = [
            'id' => $id, 'name' => $p['name'], 'price' => $price,
            'discount' => $discount, 'quantity' => $qty,
            'subtotal' => round($unitAfterDiscount * $qty, 2)
        ];
    }
    mysqli_stmt_close($stmt);
    return $items;
}

$items = get_checkout_items($conn, $_SESSION['cart']);
if (!$items) {
    $_SESSION['cart'] = [];
    header('Location: cart.php');
    exit;
}
$total = array_sum(array_column($items, 'subtotal'));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    mysqli_begin_transaction($conn);
    try {
        // Re-read product data inside the transaction so the order uses current database prices/discounts.
        $items = get_checkout_items($conn, $_SESSION['cart']);
        if (!$items) {
            throw new RuntimeException('Your cart is empty or the products are no longer available.');
        }
        $total = array_sum(array_column($items, 'subtotal'));

        $guestToken = null;
        $guestTokenHash = null;

        if (!is_customer()) {
            $guestToken = bin2hex(random_bytes(32));
            $guestTokenHash = hash('sha256', $guestToken);
}
        $customerId = is_customer() ? (int)$_SESSION['user_id'] : null;

        $customerName = is_customer()
            ? ($_SESSION['username'] ?? 'Customer')
            : trim($_POST['customer_name'] ?? '');

        if (!is_customer() && ($customerName === '' || strlen($customerName) > 100)) {
            throw new RuntimeException('Please enter your name (maximum 100 characters).');
        }

        $stmtOrder = mysqli_prepare(
            $conn,
            "INSERT INTO orders (
                customer_id, customer_name, guest_token_hash,
                cashier_id, status, total_price
            ) VALUES (?, ?, ?, NULL, 'pending', ?)"
        );

        mysqli_stmt_bind_param(
            $stmtOrder,
            'issd',
            $customerId,
            $customerName,
            $guestTokenHash,
            $total
        );

        if (!mysqli_stmt_execute($stmtOrder)) {
            throw new RuntimeException('Could not create the order.');
        }
        $orderId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmtOrder);

        $stmtItem = mysqli_prepare($conn, "INSERT INTO order_items (order_id, product_id, quantity, price, discount_percent, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($items as $item) {
            $productId = $item['id'];
            $quantity = $item['quantity'];
            $price = $item['price'];
            $discount = $item['discount'];
            $lineSubtotal = $item['subtotal'];
            mysqli_stmt_bind_param($stmtItem, 'iiiddd', $orderId, $productId, $quantity, $price, $discount, $lineSubtotal);
            if (!mysqli_stmt_execute($stmtItem)) throw new RuntimeException('Could not save an order item.');
        }
        mysqli_stmt_close($stmtItem);

        mysqli_commit($conn);
        $_SESSION['cart'] = [];

        if (is_customer()) {
            header('Location: my_orders.php?created=' . $orderId);
        } else {
            $_SESSION['guest_order_tokens'][$orderId] = $guestToken;

            header('Location: my_orders.php?created=' . $orderId);
}
exit;   
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Checkout failed. Please try again.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - Coffee Shop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'navbar.php'; ?>
<main class="container py-4">
    <h2>Checkout</h2>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Order Summary</h5>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>Product</th><th>Price</th><th>Discount</th><th>Qty</th><th>Subtotal</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= e($item['name']) ?></td>
                            <td>Rp <?= number_format($item['price'],0,',','.') ?></td>
                            <td><?= number_format($item['discount'],2) ?>%</td>
                            <td><?= (int)$item['quantity'] ?></td>
                            <td>Rp <?= number_format($item['subtotal'],0,',','.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><th colspan="4" class="text-end">Total</th><th>Rp <?= number_format($total,0,',','.') ?></th></tr></tfoot>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-end gap-3">
                <a href="cart.php" class="btn btn-outline-secondary">Back to Cart</a>

                <form method="post" class="d-flex flex-column gap-3 flex-grow-1 ms-auto" style="max-width: 360px;">
                    <?= csrf_field() ?>

                    <?php if (!is_customer()): ?>
                        <div class="mb-0">
                            <label for="customer_name" class="form-label">Your Name</label>
                            <input
                                type="text"
                                name="customer_name"
                                id="customer_name"
                                class="form-control"
                                maxlength="100"
                                required
                                placeholder="Enter your name"
                            >
                        </div>
                    <?php endif; ?>

                    <button class="btn btn-success ms-auto" type="submit">
                        Place Order
                    </button>
                </form>
            </div>
        </div>
    </div>
</main>
</body>
</html>
