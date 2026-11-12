
<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

function json_response($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Only GET method is allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response([
        'success' => false,
        'message' => 'Method not allowed.'
    ], 405);
}

// Only admin can access sales reports
if (!is_admin()) {
    json_response([
        'success' => false,
        'message' => 'Only admin can access sales reports.'
    ], 403);
}

// Read requested action
$action = $_GET['action'] ?? '';

if (!in_array($action, [
    'summary',
    'daily',
    'top-products'
], true)) {
    json_response([
        'success' => false,
        'message' => 'Invalid sales report action.'
    ], 400);
}

// ==========================================
// SUMMARY: Sales overview
// ==========================================
if ($action === 'summary') {

    $sql = "
        SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(total_price), 0) AS total_revenue,
            COALESCE(AVG(total_price), 0) AS average_order_value
        FROM orders
        WHERE status = 'completed'
    ";

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        json_response([
            'success' => false,
            'message' => 'Failed to retrieve sales summary.'
        ], 500);
    }

    $summary = mysqli_fetch_assoc($result);
    mysqli_free_result($result);

    json_response([
        'success' => true,
        'message' => 'Sales summary retrieved successfully.',
        'data' => [
            'total_orders' => (int) $summary['total_orders'],
            'total_revenue' => (float) $summary['total_revenue'],
            'average_order_value' => (float) $summary['average_order_value']
        ]
    ]);
}

 // ==========================================
 // DAILY: Daily sales report
 // ==========================================
if ($action === 'daily') {

    $sql = "
        SELECT
            DATE(order_date) AS sales_date,
            COUNT(*) AS total_orders,
            COALESCE(SUM(total_price), 0) AS total_revenue
        FROM orders
        WHERE status = 'completed'
        GROUP BY DATE(order_date)
        ORDER BY sales_date DESC
    ";

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        json_response([
            'success' => false,
            'message' => 'Failed to retrieve daily sales.'
        ], 500);
    }

    $dailySales = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $dailySales[] = [
            'date' => $row['sales_date'],
            'total_orders' => (int) $row['total_orders'],
            'total_revenue' => (float) $row['total_revenue']
        ];
    }

    mysqli_free_result($result);

    json_response([
        'success' => true,
        'message' => 'Daily sales retrieved successfully.',
        'data' => $dailySales
    ]);
}

// ==========================================
// TOP PRODUCTS: Best-selling products
// ==========================================
if ($action === 'top-products') {

    $sql = "
        SELECT
            p.id AS product_id,
            p.name AS product_name,
            p.category,
            SUM(oi.quantity) AS total_sold,
            SUM(oi.subtotal) AS total_revenue
        FROM order_items oi
        INNER JOIN orders o ON oi.order_id = o.id
        INNER JOIN product p ON oi.product_id = p.id
        WHERE o.status = 'completed'
        GROUP BY p.id, p.name, p.category
        ORDER BY total_sold DESC, total_revenue DESC
        LIMIT 10
    ";

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        json_response([
            'success' => false,
            'message' => 'Failed to retrieve top products.'
        ], 500);
    }

    $topProducts = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $topProducts[] = [
            'product_id' => (int) $row['product_id'],
            'product_name' => $row['product_name'],
            'category' => $row['category'],
            'total_sold' => (int) $row['total_sold'],
            'total_revenue' => (float) $row['total_revenue']
        ];
    }

    mysqli_free_result($result);

    json_response([
        'success' => true,
        'message' => 'Top products retrieved successfully.',
        'data' => $topProducts
    ]);
}


// Report logic will be added here.
