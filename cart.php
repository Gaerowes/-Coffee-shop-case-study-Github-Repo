<?php
require_once 'auth.php';
require_once 'config.php';

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

function load_cart_products(mysqli $conn, array $cart): array {
    if (!$cart) return [];
    $ids = array_values(array_filter(array_map('intval', array_keys($cart)), fn($id) => $id > 0));
    if (!$ids) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = mysqli_prepare($conn, "SELECT id, name, price, url_image, discount_percent FROM product WHERE id IN ($placeholders)");
    mysqli_stmt_bind_param($stmt, $types, ...$ids);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $products = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $products[(int)$row['id']] = $row;
    }
    mysqli_stmt_close($stmt);
    return $products;
}

$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'add';

    if ($action === 'add') {
        $productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        if (!$productId || $productId < 1) {
            $error = 'Invalid product.';
        } else {
            $stmt = mysqli_prepare($conn, "SELECT id FROM product WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $productId);
            mysqli_stmt_execute($stmt);
            $exists = mysqli_stmt_get_result($stmt)->fetch_assoc();
            mysqli_stmt_close($stmt);
            if (!$exists) {
                $error = 'Product is no longer available.';
            } else {
                $_SESSION['cart'][$productId] = min(99, (int)($_SESSION['cart'][$productId] ?? 0) + 1);
                $notice = 'Product added to cart.';
            }
        }
    } elseif ($action === 'update') {
        $quantities = $_POST['quantity'] ?? [];
        if (!is_array($quantities)) {
            $error = 'Invalid cart quantities.';
        } else {
            foreach ($quantities as $id => $qty) {
                $id = filter_var($id, FILTER_VALIDATE_INT);
                $qty = filter_var($qty, FILTER_VALIDATE_INT);
                if (!$id || $id < 1) continue;
                if ($qty === false || $qty < 1) {
                    unset($_SESSION['cart'][$id]);
                } else {
                    $_SESSION['cart'][$id] = min(99, $qty);
                }
            }
            $notice = 'Cart updated.';
        }
    } elseif ($action === 'remove') {
        $productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        if ($productId) unset($_SESSION['cart'][$productId]);
        $notice = 'Product removed from cart.';
    }
}

$products = load_cart_products($conn, $_SESSION['cart']);
foreach ($_SESSION['cart'] as $id => $qty) {
    if (!isset($products[(int)$id])) unset($_SESSION['cart'][$id]);
}
$subtotal = 0;
foreach ($products as $id => $p) {
    $discount = max(0, min(100, (float)$p['discount_percent']));
    $unitPrice = (float)$p['price'] * (1 - $discount / 100);
    $subtotal += $unitPrice * (int)$_SESSION['cart'][$id];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your Cart - Coffee Shop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'navbar.php'; ?>
<main class="container py-4">
    <h2>Your Shopping Cart</h2>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>

    <?php if (!$products): ?>
        <div class="alert alert-info">Your cart is empty. <a href="index.php">Explore the menu</a>.</div>
    <?php else: ?>
        <form method="post" id="update-cart-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <div class="table-responsive">
                <table class="table table-bordered bg-white align-middle">
                    <thead><tr><th>Product</th><th>Price</th><th>Discount</th><th style="width:130px">Quantity</th><th>Subtotal</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($products as $id => $p):
                        $discount = max(0, min(100, (float)$p['discount_percent']));
                        $unitPrice = (float)$p['price'] * (1 - $discount / 100);
                        $qty = (int)$_SESSION['cart'][$id];
                        $lineTotal = $unitPrice * $qty;
                    ?>
                        <tr>
                            <td><?= e($p['name']) ?></td>
                            <td>
                                <?php if ($discount > 0): ?><del class="text-muted">Rp <?= number_format((float)$p['price'],0,',','.') ?></del><br><?php endif; ?>
                                Rp <?= number_format($unitPrice,0,',','.') ?>
                            </td>
                            <td><?= number_format($discount, 2) ?>%</td>
                            <td><input class="form-control" type="number" min="0" max="99" name="quantity[<?= (int)$id ?>]" value="<?= $qty ?>"></td>
                            <td>Rp <?= number_format($lineTotal,0,',','.') ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-danger" type="submit" form="remove-<?= (int)$id ?>">Remove</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <button class="btn btn-outline-dark" type="submit">Update Cart</button>
                <h5 class="mb-0">Total: Rp <?= number_format($subtotal,0,',','.') ?></h5>
            </div>
        </form>
        <?php foreach ($products as $id => $p): ?>
            <form id="remove-<?= (int)$id ?>" method="post" class="d-none">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="product_id" value="<?= (int)$id ?>">
            </form>
        <?php endforeach; ?>
        <div class="text-end mt-3"><a class="btn btn-success" href="checkout.php">Proceed to Checkout</a></div>
    <?php endif; ?>
</main>
</body>
</html>
