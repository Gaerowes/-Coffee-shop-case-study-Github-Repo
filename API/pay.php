
<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../midtrans_config.php';

function json_response($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Hanya menerima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'message' => 'Method not allowed.'
    ], 405);
}

// Baca JSON request
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    json_response([
        'success' => false,
        'message' => 'Invalid JSON request.'
    ], 400);
}

// Validasi CSRF
$csrfToken = $input['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !is_string($csrfToken) ||
    !hash_equals($_SESSION['csrf_token'], $csrfToken)
) {
    json_response([
        'success' => false,
        'message' => 'Invalid CSRF token.'
    ], 403);
}

// Validasi order ID
$orderId = filter_var(
    $input['order_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$orderId || $orderId < 1) {
    json_response([
        'success' => false,
        'message' => 'Invalid order ID.'
    ], 400);
}

try {
    // Ambil data pesanan
    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            id,
            customer_id,
            status,
            payment_status,
            total_price,
            guest_token_hash
         FROM orders
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new Exception('Failed to prepare order query.');
    }

    mysqli_stmt_bind_param($stmt, 'i', $orderId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $order = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    // Pastikan pesanan ditemukan
    if (!$order) {
        json_response([
            'success' => false,
            'message' => 'Order not found.'
        ], 404);
    }

    // Hanya pesanan pending yang dapat dibayar
    if ($order['status'] !== 'pending') {
        json_response([
            'success' => false,
            'message' => 'Only pending orders can be paid.'
        ], 400);
    }

    // Cegah pembayaran ulang
    if ($order['payment_status'] !== 'unpaid') {
        json_response([
            'success' => false,
            'message' => 'This order has already been paid or is not payable.'
        ], 400);
    }

    // Validasi kepemilikan pesanan
    if (is_customer()) {

        // Customer hanya boleh membayar pesanan miliknya
        if ((int)$order['customer_id'] !== (int)$_SESSION['user_id']) {
            json_response([
                'success' => false,
                'message' => 'You cannot pay for this order.'
            ], 403);
        }

    } elseif (!is_logged_in()) {

        // Validasi guest order menggunakan guest token
        $guestToken = $input['guest_token'] ?? '';

        if (
            !is_string($guestToken) ||
            empty($order['guest_token_hash']) ||
            !hash_equals(
                $order['guest_token_hash'],
                hash('sha256', $guestToken)
            )
        ) {
            json_response([
                'success' => false,
                'message' => 'Invalid guest order credentials.'
            ], 403);
        }

    } else {

        // Admin dan kasir tidak diperbolehkan membayar melalui endpoint customer
        json_response([
            'success' => false,
            'message' => 'This account cannot pay for orders.'
        ], 403);
    }

    // Ambil nominal pembayaran dari database
    $grossAmount = (int) round((float)$order['total_price']);

    if ($grossAmount < 1) {
        json_response([
            'success' => false,
            'message' => 'Invalid order amount.'
        ], 400);
    }

    // Buat transaksi Midtrans Snap
    $transaction = [
        'transaction_details' => [
            'order_id' => 'KOPIPIRO-' . $orderId,
            'gross_amount' => $grossAmount
        ],
        'item_details' => [
            [
                'id' => 'ORDER-' . $orderId,
                'price' => $grossAmount,
                'quantity' => 1,
                'name' => 'Kopipiro Order #' . $orderId
            ]
        ]
    ];

    // Generate Snap Token
    $snapToken = \Midtrans\Snap::getSnapToken($transaction);

    // URL pembayaran Midtrans Sandbox
    $redirectUrl = 'https://app.sandbox.midtrans.com/snap/v2/vtweb/' . $snapToken;

    // Kirim respons sukses
    json_response([
        'success' => true,
        'message' => 'Payment transaction created successfully.',
        'data' => [
            'order_id' => (int)$orderId,
            'gross_amount' => $grossAmount,
            'snap_token' => $snapToken,
            'redirect_url' => $redirectUrl
        ]
    ]);

} catch (Throwable $e) {

    // Simpan detail error di PHP error log
    error_log('Midtrans payment error: ' . $e->getMessage());

    json_response([
        'success' => false,
        'message' => 'Failed to create payment transaction.'
    ], 500);
}
