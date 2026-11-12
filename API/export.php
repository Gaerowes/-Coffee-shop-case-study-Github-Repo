
<?php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// Hanya menerima GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response([
        'success' => false,
        'message' => 'Method not allowed.'
    ], 405);
}

// Hanya admin yang boleh export
if (!is_admin()) {
    json_response([
        'success' => false,
        'message' => 'Only admin can export sales data.'
    ], 403);
}

try {
    $spreadsheet = new Spreadsheet();

    // =====================================
    // SHEET 1: ORDERS
    // =====================================
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Orders');

    $headers = [
        'Order ID',
        'Order Date',
        'Customer',
        'Cashier',
        'Total Price',
        'Status'
    ];

    $sheet->fromArray($headers, null, 'A1');

    $sql = "
        SELECT
            o.id,
            o.order_date,
            COALESCE(o.customer_name, u_customer.username, 'Guest') AS customer,
            COALESCE(u_cashier.username, 'Unassigned') AS cashier,
            o.total_price,
            o.status
        FROM orders o
        LEFT JOIN users u_customer ON u_customer.id = o.customer_id
        LEFT JOIN users u_cashier ON u_cashier.id = o.cashier_id
        ORDER BY o.id DESC
    ";

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        throw new RuntimeException('Failed to retrieve orders.');
    }

    $row = 2;

    while ($order = mysqli_fetch_assoc($result)) {
        $sheet->fromArray([
            (int)$order['id'],
            $order['order_date'],
            $order['customer'],
            $order['cashier'],
            (float)$order['total_price'],
            $order['status']
        ], null, 'A' . $row);

        $row++;
    }

    // Styling Orders
    $sheet->getStyle('A1:F1')->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF']
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '5C4033']
        ]
    ]);

    foreach (range('A', 'F') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    $sheet->freezePane('A2');
    $sheet->setAutoFilter('A1:F' . max(1, $row - 1));

    // =====================================
    // SHEET 2: ORDER ITEMS
    // =====================================
    $detailSheet = $spreadsheet->createSheet();
    $detailSheet->setTitle('Order Items');

    $detailHeaders = [
        'Order ID',
        'Product',
        'Quantity',
        'Price',
        'Discount (%)',
        'Subtotal'
    ];

    $detailSheet->fromArray($detailHeaders, null, 'A1');

    $detailSql = "
        SELECT
            oi.order_id,
            p.name AS product_name,
            oi.quantity,
            oi.price,
            oi.discount_percent,
            oi.subtotal
        FROM order_items oi
        LEFT JOIN product p ON p.id = oi.product_id
        ORDER BY oi.order_id DESC, oi.id ASC
    ";

    $detailResult = mysqli_query($conn, $detailSql);

    if (!$detailResult) {
        throw new RuntimeException('Failed to retrieve order details.');
    }

    $detailRow = 2;

    while ($item = mysqli_fetch_assoc($detailResult)) {
        $detailSheet->fromArray([
            (int)$item['order_id'],
            $item['product_name'] ?? 'Unavailable Product',
            (int)$item['quantity'],
            (float)$item['price'],
            (float)$item['discount_percent'],
            (float)$item['subtotal']
        ], null, 'A' . $detailRow);

        $detailRow++;
    }

    $detailSheet->getStyle('A1:F1')->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF']
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '5C4033']
        ]
    ]);

    foreach (range('A', 'F') as $column) {
        $detailSheet->getColumnDimension($column)->setAutoSize(true);
    }

    $detailSheet->freezePane('A2');
    $detailSheet->setAutoFilter('A1:F' . max(1, $detailRow - 1));

    // =====================================
    // DOWNLOAD EXCEL
    // =====================================
    $filename = 'kopipiro_sales_' . date('Y-m-d_H-i-s') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

    $spreadsheet->disconnectWorksheets();
    exit;

} catch (Throwable $e) {
    error_log('Export error: ' . $e->getMessage());

    json_response([
        'success' => false,
        'message' => 'Failed to export sales data.'
    ], 500);
}
