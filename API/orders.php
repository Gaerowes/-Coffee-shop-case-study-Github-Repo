
<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// JSON response helper
function json_response($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Read JSON request
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    $input = [];
}

$method = $_SERVER['REQUEST_METHOD'];

// ==========================================
// POST: Create order (guest/customer)
// ==========================================
if ($method === 'POST') {

    // Only guest and customer can create orders
    if (is_logged_in() && !is_customer()) {
        json_response([
            'success' => false,
            'message' => 'Only guests and customers can create orders.'
        ], 403);
    }

    // Validate CSRF
    $submittedToken = $input['csrf_token'] ?? '';
    $storedToken = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !is_string($storedToken) ||
        $storedToken === '' ||
        !hash_equals($storedToken, $submittedToken)
    ) {
        json_response([
            'success' => false,
            'message' => 'Invalid or expired CSRF token.'
        ], 419);
    }

    // Validate customer name
    if (is_customer()) {
        $customerId = (int) $_SESSION['user_id'];
        $customerName = $_SESSION['username'] ?? 'Customer';
    } else {
        $customerId = null;
        $customerName = trim($input['customer_name'] ?? '');

        if ($customerName === '' || strlen($customerName) > 100) {
            json_response([
                'success' => false,
                'message' => 'A valid customer name is required.'
            ], 400);
        }
    }

    // Validate items
    $requestedItems = $input['items'] ?? [];

    if (!is_array($requestedItems) || empty($requestedItems)) {
        json_response([
            'success' => false,
            'message' => 'Order items are required.'
        ], 400);
    }

    // Normalize product IDs and quantities
    $quantities = [];

    foreach ($requestedItems as $item) {
        $productId = filter_var(
            $item['product_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $quantity = filter_var(
            $item['quantity'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (
            !$productId || $productId < 1 ||
            !$quantity || $quantity < 1 || $quantity > 99
        ) {
            json_response([
                'success' => false,
                'message' => 'Invalid product ID or quantity. Quantity must be 1-99.'
            ], 400);
        }

        $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;

        if ($quantities[$productId] > 99) {
            json_response([
                'success' => false,
                'message' => 'Maximum quantity per product is 99.'
            ], 400);
        }
    }

    // Load product prices from database
    $productIds = array_keys($quantities);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $types = str_repeat('i', count($productIds));

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, name, price, discount_percent
         FROM product
         WHERE id IN ($placeholders)"
    );

    if (!$stmt) {
        json_response([
            'success' => false,
            'message' => 'Database query failed.'
        ], 500);
    }

    mysqli_stmt_bind_param($stmt, $types, ...$productIds);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $products = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $products[] = $row;
    }

    mysqli_stmt_close($stmt);

    if (count($products) !== count($quantities)) {
        json_response([
            'success' => false,
            'message' => 'One or more products are unavailable.'
        ], 400);
    }

    // Calculate prices on the server
    $items = [];
    $total = 0;

    foreach ($products as $product) {
        $id = (int) $product['id'];
        $price = (float) $product['price'];
        $productDiscount = max(
            0,
            min(100, (float) $product['discount_percent'])
        );

        // Customer receives an additional 10% member discount.
        $memberDiscount = is_customer() ? 10 : 0;

        // Combine product discount and member discount.
        $effectiveDiscount = 100 * (
            1 - (1 - $productDiscount / 100)
              * (1 - $memberDiscount / 100)
        );

        $quantity = $quantities[$id];

        $subtotal = round(
            $price * (1 - $effectiveDiscount / 100) * $quantity,
            2
        );

        $items[] = [
            'id' => $id,
            'price' => $price,
            'discount' => $effectiveDiscount,
            'quantity' => $quantity,
            'subtotal' => $subtotal
        ];

        $total += $subtotal;
    }

    $total = round($total, 2);

    // Generate guest access token
    $guestToken = null;
    $guestTokenHash = null;

    if (!is_customer()) {
        $guestToken = bin2hex(random_bytes(32));
        $guestTokenHash = hash('sha256', $guestToken);
    }

    // Save order and items in one transaction
    mysqli_begin_transaction($conn);

    try {
        $stmtOrder = mysqli_prepare(
            $conn,
            "INSERT INTO orders
             (customer_id, customer_name, guest_token_hash,
              cashier_id, status, total_price)
             VALUES (?, ?, ?, NULL, 'pending', ?)"
        );

        if (!$stmtOrder) {
            throw new RuntimeException('Could not prepare order.');
        }

        mysqli_stmt_bind_param(
            $stmtOrder,
            'issd',
            $customerId,
            $customerName,
            $guestTokenHash,
            $total
        );

        if (!mysqli_stmt_execute($stmtOrder)) {
            throw new RuntimeException('Could not create order.');
        }

        $orderId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmtOrder);

        $stmtItem = mysqli_prepare(
            $conn,
            "INSERT INTO order_items
             (order_id, product_id, quantity, price,
              discount_percent, subtotal)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        if (!$stmtItem) {
            throw new RuntimeException('Could not prepare order items.');
        }

        foreach ($items as $item) {
            $productId = $item['id'];
            $quantity = $item['quantity'];
            $price = $item['price'];
            $discount = $item['discount'];
            $subtotal = $item['subtotal'];

            mysqli_stmt_bind_param(
                $stmtItem,
                'iiiddd',
                $orderId,
                $productId,
                $quantity,
                $price,
                $discount,
                $subtotal
            );

            if (!mysqli_stmt_execute($stmtItem)) {
                throw new RuntimeException('Could not save order items.');
            }
        }

        mysqli_stmt_close($stmtItem);

        mysqli_commit($conn);

        // Keep guest token in session for immediate access.
        if ($guestToken !== null) {
            $_SESSION['guest_order_tokens'][$orderId] = $guestToken;
        }

        json_response([
            'success' => true,
            'message' => 'Order created successfully.',
            'order' => [
                'id' => $orderId,
                'customer_name' => $customerName,
                'status' => 'pending',
                'total_price' => $total,
                'items' => $items
            ],
            'guest_token' => $guestToken
        ], 201);

    } catch (Throwable $e) {
        mysqli_rollback($conn);

        json_response([
            'success' => false,
            'message' => 'Failed to create order.'
        ], 500);
    }
}


// ==========================================
// GET: Retrieve orders based on user role
// ==========================================
if ($method === 'GET') {

    // Guest: access a specific order using order ID and guest token
    if (!is_logged_in()) {

        $orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $guestToken = $_GET['guest_token'] ?? '';

        if (!$orderId || !is_string($guestToken) || $guestToken === '') {
            json_response([
                'success' => false,
                'message' => 'Order ID and guest token are required.'
            ], 400);
        }

        $tokenHash = hash('sha256', $guestToken);

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, customer_name, status, total_price, order_date
             FROM orders
             WHERE id = ?
               AND customer_id IS NULL
               AND guest_token_hash = ?"
        );

        if (!$stmt) {
            json_response([
                'success' => false,
                'message' => 'Database query failed.'
            ], 500);
        }

        mysqli_stmt_bind_param($stmt, 'is', $orderId, $tokenHash);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $order = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if (!$order) {
            json_response([
                'success' => false,
                'message' => 'Order not found or invalid guest token.'
            ], 404);
        }

        $orders = [$order];

    } else {

        // Customer: retrieve only their own orders
        if (is_customer()) {
            $userId = (int) $_SESSION['user_id'];

            $stmt = mysqli_prepare(
                $conn,
                "SELECT id, customer_name, status, total_price, order_date
                 FROM orders
                 WHERE customer_id = ?
                 ORDER BY order_date DESC"
            );

            mysqli_stmt_bind_param($stmt, 'i', $userId);

        // Cashier: retrieve assigned orders
        } elseif (is_cashier()) {
            $userId = (int) $_SESSION['user_id'];

            $stmt = mysqli_prepare(
                $conn,
                "SELECT id, customer_name, status, total_price, order_date
                 FROM orders
                 WHERE cashier_id = ?
                 ORDER BY order_date DESC"
            );

            mysqli_stmt_bind_param($stmt, 'i', $userId);

        // Admin: retrieve all orders
        } elseif (is_admin()) {

            $stmt = mysqli_prepare(
                $conn,
                "SELECT id, customer_name, status, total_price, order_date
                 FROM orders
                 ORDER BY order_date DESC"
            );

        } else {
            json_response([
                'success' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        if (!$stmt) {
            json_response([
                'success' => false,
                'message' => 'Database query failed.'
            ], 500);
        }

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $orders = [];

        while ($row = mysqli_fetch_assoc($result)) {
            $orders[] = $row;
        }

        mysqli_stmt_close($stmt);
    }

    // Retrieve order items
    foreach ($orders as &$order) {

        $stmtItems = mysqli_prepare(
            $conn,
            "SELECT
                oi.product_id,
                p.name AS product_name,
                oi.quantity,
                oi.price,
                oi.discount_percent,
                oi.subtotal
             FROM order_items oi
             LEFT JOIN product p ON p.id = oi.product_id
             WHERE oi.order_id = ?"
        );

        if (!$stmtItems) {
            json_response([
                'success' => false,
                'message' => 'Could not retrieve order items.'
            ], 500);
        }

        $orderId = (int) $order['id'];

        mysqli_stmt_bind_param($stmtItems, 'i', $orderId);
        mysqli_stmt_execute($stmtItems);

        $itemsResult = mysqli_stmt_get_result($stmtItems);
        $order['items'] = [];

        while ($item = mysqli_fetch_assoc($itemsResult)) {
            $order['items'][] = $item;
        }

        mysqli_stmt_close($stmtItems);
    }

    unset($order);

    json_response([
        'success' => true,
        'orders' => $orders
    ]);
}


// ==========================================
// PUT: Update order status / assign cashier
// ==========================================
if ($method === 'PUT') {

    // Only admin and cashier can update orders
    if (!is_admin() && !is_cashier()) {
        json_response([
            'success' => false,
            'message' => 'Only admin and cashier can update orders.'
        ], 403);
    }

    // Validate CSRF token
    $submittedToken = $input['csrf_token'] ?? '';
    $storedToken = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !is_string($storedToken) ||
        $storedToken === '' ||
        !hash_equals($storedToken, $submittedToken)
    ) {
        json_response([
            'success' => false,
            'message' => 'Invalid or expired CSRF token.'
        ], 419);
    }

    // Validate order ID
    $orderId = filter_var(
        $input['order_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$orderId || $orderId < 1) {
        json_response([
            'success' => false,
            'message' => 'Valid order ID is required.'
        ], 400);
    }

    $isAdmin = is_admin();
    $cashierId = (int) $_SESSION['user_id'];

    mysqli_begin_transaction($conn);

    try {

        // Lock order while updating
        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, cashier_id, status, payment_status
FROM orders
WHERE id = ?
FOR UPDATE"
        );

        if (!$stmt) {
            throw new RuntimeException('Could not prepare order query.');
        }

        mysqli_stmt_bind_param($stmt, 'i', $orderId);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $order = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if (!$order) {
            mysqli_rollback($conn);

            json_response([
                'success' => false,
                'message' => 'Order not found.'
            ], 404);
        }

        // ======================================
// Validasi pembayaran sebelum klaim
// ======================================
if (
    isset($input['status']) &&
    $input['status'] === 'claimed' &&
    $order['payment_status'] !== 'paid'
) {
    mysqli_rollback($conn);

    json_response([
        'success' => false,
        'message' => 'Order must be paid before it can be claimed.'
    ], 400);
}

// Cashier can only update assigned orders
if (
    !$isAdmin &&
    (int) $order['cashier_id'] !== $cashierId
) {
    mysqli_rollback($conn);

    json_response([
        'success' => false,
        'message' => 'You are not assigned to this order.'
    ], 403);
}

        // Cashier can only update assigned orders
        if (
            !$isAdmin &&
            (int) $order['cashier_id'] !== $cashierId
        ) {
            mysqli_rollback($conn);

            json_response([
                'success' => false,
                'message' => 'You are not assigned to this order.'
            ], 403);
        }

        $updates = [];
        $types = '';
        $values = [];

        // ======================================
        // Update status
        // ======================================
        if (array_key_exists('status', $input)) {

            $newStatus = $input['status'];

            $allowedStatuses = [
                'pending',
                'claimed',
                'preparing',
                'ready',
                'completed',
                'cancelled'
            ];

            if (
                !is_string($newStatus) ||
                !in_array($newStatus, $allowedStatuses, true)
            ) {
                mysqli_rollback($conn);

                json_response([
                    'success' => false,
                    'message' => 'Invalid order status.'
                ], 400);
            }

            // Define allowed status transitions
            $transitions = [
                'pending' => ['claimed', 'cancelled'],
                'claimed' => ['preparing', 'cancelled'],
                'preparing' => ['ready', 'cancelled'],
                'ready' => ['completed'],
                'completed' => [],
                'cancelled' => []
            ];

            $currentStatus = $order['status'];

            if (
                !in_array(
                    $newStatus,
                    $transitions[$currentStatus] ?? [],
                    true
                )
            ) {
                mysqli_rollback($conn);

                json_response([
                    'success' => false,
                    'message' => 'Invalid status transition.'
                ], 400);
            }

            // Cashier cannot cancel orders
            if (!$isAdmin && $newStatus === 'cancelled') {
                mysqli_rollback($conn);

                json_response([
                    'success' => false,
                    'message' => 'Only admin can cancel orders.'
                ], 403);
            }

            $updates[] = 'status = ?';
            $types .= 's';
            $values[] = $newStatus;
        }

        // ======================================
        // Assign cashier (admin only)
        // ======================================
        if (array_key_exists('cashier_id', $input)) {

            if (!$isAdmin) {
                mysqli_rollback($conn);

                json_response([
                    'success' => false,
                    'message' => 'Only admin can assign a cashier.'
                ], 403);
            }

            $newCashierId = $input['cashier_id'];

            if ($newCashierId !== null) {

                $newCashierId = filter_var(
                    $newCashierId,
                    FILTER_VALIDATE_INT
                );

                if (!$newCashierId || $newCashierId < 1) {
                    mysqli_rollback($conn);

                    json_response([
                        'success' => false,
                        'message' => 'Invalid cashier ID.'
                    ], 400);
                }

                // Verify that the selected user is a cashier
                $stmtCashier = mysqli_prepare(
                    $conn,
                    "SELECT id
                     FROM users
                     WHERE id = ?
                       AND role = 'cashier'"
                );

                mysqli_stmt_bind_param(
                    $stmtCashier,
                    'i',
                    $newCashierId
                );

                mysqli_stmt_execute($stmtCashier);
                $cashierResult = mysqli_stmt_get_result($stmtCashier);
                $cashierExists = mysqli_fetch_assoc($cashierResult);
                mysqli_stmt_close($stmtCashier);

                if (!$cashierExists) {
                    mysqli_rollback($conn);

                    json_response([
                        'success' => false,
                        'message' => 'Selected user is not a cashier.'
                    ], 400);
                }
            }

            $updates[] = 'cashier_id = ?';
            $types .= 'i';
            $values[] = $newCashierId;
        }

        if (empty($updates)) {
            mysqli_rollback($conn);

            json_response([
                'success' => false,
                'message' => 'No fields to update.'
            ], 400);
        }

        // Execute update
        $values[] = $orderId;
        $types .= 'i';

        $sql = "UPDATE orders
                SET " . implode(', ', $updates) . "
                WHERE id = ?";

        $stmtUpdate = mysqli_prepare($conn, $sql);

        if (!$stmtUpdate) {
            throw new RuntimeException('Could not prepare update query.');
        }

        mysqli_stmt_bind_param($stmtUpdate, $types, ...$values);

        if (!mysqli_stmt_execute($stmtUpdate)) {
            throw new RuntimeException('Could not update order.');
        }

        mysqli_stmt_close($stmtUpdate);

        // Retrieve updated order
        $stmtFinal = mysqli_prepare(
            $conn,
            "SELECT id, customer_name, cashier_id,
                    status, total_price, order_date
             FROM orders
             WHERE id = ?"
        );

        mysqli_stmt_bind_param($stmtFinal, 'i', $orderId);
        mysqli_stmt_execute($stmtFinal);

        $finalResult = mysqli_stmt_get_result($stmtFinal);
        $updatedOrder = mysqli_fetch_assoc($finalResult);
        mysqli_stmt_close($stmtFinal);

        mysqli_commit($conn);

        json_response([
            'success' => true,
            'message' => 'Order updated successfully.',
            'order' => $updatedOrder
        ]);

    } catch (Throwable $e) {

        mysqli_rollback($conn);

        json_response([
            'success' => false,
            'message' => 'Failed to update order.'
        ], 500);
    }
}

// ==========================================
// Unsupported method
// ==========================================
json_response([
    'success' => false,
    'message' => 'Method not allowed.'
], 405);

