
<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../midtrans_config.php';

function json_response($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ======================================
// METHOD VALIDATION
// ======================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'message' => 'Method not allowed.'
    ], 405);
}

// ======================================
// READ NOTIFICATION
// ======================================

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    json_response([
        'success' => false,
        'message' => 'Invalid notification.'
    ], 400);
}

// Normalize values to strings
$orderCode = (string)($input['order_id'] ?? '');
$statusCode = (string)($input['status_code'] ?? '');
$transactionStatus = (string)($input['transaction_status'] ?? '');
$fraudStatus = (string)($input['fraud_status'] ?? '');
$grossAmount = (string)($input['gross_amount'] ?? '');
$signatureKey = (string)($input['signature_key'] ?? '');

if (
    $orderCode === '' ||
    $statusCode === '' ||
    $grossAmount === '' ||
    $signatureKey === ''
) {
    json_response([
        'success' => false,
        'message' => 'Missing notification fields.'
    ], 400);
}

// ======================================
// VERIFY SIGNATURE USING SERVER KEY
// ======================================

$expectedSignature = hash(
    'sha512',
    $orderCode .
    $statusCode .
    $grossAmount .
    \Midtrans\Config::$serverKey
);

if (!hash_equals($expectedSignature, $signatureKey)) {
    json_response([
        'success' => false,
        'message' => 'Invalid signature.'
    ], 403);
}

// ======================================
// HANDLE TEST NOTIFICATION
// ======================================

if (strpos($orderCode, 'payment_notif_test_') === 0) {
    json_response([
        'success' => true,
        'message' => 'Test notification received successfully.'
    ]);
}

// ======================================
// VALIDATE ORDER ID
// ======================================

if (!preg_match('/^KOPIPIRO-(\d+)$/', $orderCode, $matches)) {
    json_response([
        'success' => false,
        'message' => 'Invalid order ID format.'
    ], 400);
}

$orderId = (int)$matches[1];

try {

    // ==================================
    // GET ORDER
    // ==================================

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, status, payment_status, total_price
         FROM orders
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new Exception(mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, 'i', $orderId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $order = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    if (!$order) {
        json_response([
            'success' => false,
            'message' => 'Order not found.'
        ], 404);
    }

    // ==================================
    // VALIDATE PAYMENT AMOUNT
    // ==================================

    if (
        !is_numeric($grossAmount) ||
        abs(
            (float)$grossAmount -
            (float)$order['total_price']
        ) > 0.01
    ) {
        json_response([
            'success' => false,
            'message' => 'Payment amount mismatch.'
        ], 400);
    }

    // ==================================
    // DETERMINE PAYMENT STATUS
    // ==================================

    $newPaymentStatus = null;

    // Successful payment
    if (
        $statusCode === '200' &&
        $transactionStatus === 'settlement'
    ) {
        $newPaymentStatus = 'paid';
    }

    // Successful card capture
    elseif (
        $statusCode === '200' &&
        $transactionStatus === 'capture' &&
        strtolower($fraudStatus) === 'accept'
    ) {
        $newPaymentStatus = 'paid';
    }

    // Failed / cancelled / expired payment
    elseif (
        in_array(
            $transactionStatus,
            ['deny', 'cancel', 'expire'],
            true
        )
    ) {
        $newPaymentStatus = 'failed';
    }

    // Refund and partial refund
    elseif (
        in_array(
            $transactionStatus,
            ['refund', 'partial_refund'],
            true
        )
    ) {
        $newPaymentStatus = 'refunded';
    }

    // ==================================
    // UPDATE PAYMENT STATUS
    // ==================================

    if ($newPaymentStatus !== null) {

        if ($newPaymentStatus === 'refunded') {

            // Refund can update a previously paid order
            $update = mysqli_prepare(
                $conn,
                "UPDATE orders
                 SET payment_status = 'refunded'
                 WHERE id = ?
                 AND payment_status IN ('unpaid', 'paid', 'failed')"
            );

            if (!$update) {
                throw new Exception(mysqli_error($conn));
            }

            mysqli_stmt_bind_param($update, 'i', $orderId);

        } else {

            // Do not overwrite an already paid/refunded order
            $update = mysqli_prepare(
                $conn,
                "UPDATE orders
                 SET payment_status = ?
                 WHERE id = ?
                 AND payment_status = 'unpaid'"
            );

            if (!$update) {
                throw new Exception(mysqli_error($conn));
            }

            mysqli_stmt_bind_param(
                $update,
                'si',
                $newPaymentStatus,
                $orderId
            );
        }

        if (!mysqli_stmt_execute($update)) {
            throw new Exception(mysqli_stmt_error($update));
        }

        mysqli_stmt_close($update);
    }

    // ==================================
    // GET LATEST PAYMENT STATUS
    // ==================================

    $stmt = mysqli_prepare(
        $conn,
        "SELECT payment_status, status
         FROM orders
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new Exception(mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, 'i', $orderId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $latestOrder = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    // ==================================
    // SUCCESS RESPONSE
    // ==================================

    json_response([
        'success' => true,
        'message' => 'Payment notification processed successfully.',
        'order_id' => $orderId,
        'transaction_status' => $transactionStatus,
        'payment_status' => $latestOrder['payment_status'],
        'order_status' => $latestOrder['status']
    ]);

} catch (Throwable $e) {

    error_log(
        'Midtrans notification error: ' . $e->getMessage()
    );

    json_response([
        'success' => false,
        'message' => 'Failed to process notification.'
    ], 500);
}
