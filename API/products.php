
<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

function json_response($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Read JSON request body
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

// ==========================================
// GET: Retrieve products
// ==========================================
if ($method === 'GET') {

    $category = trim($_GET['category'] ?? '');

    $sql = "
        SELECT
            p.id,
            p.name,
            p.price,
            p.url_image,
            p.category,
            p.description,
            p.discount_percent,
            COALESCE(AVG(r.rating), 0) AS average_rating,
            COUNT(r.id) AS total_reviews
        FROM product p
        LEFT JOIN reviews r ON r.product_id = p.id
    ";

    if ($category !== '') {
        $sql .= " WHERE p.category = ?";
    }

    $sql .= "
        GROUP BY
            p.id,
            p.name,
            p.price,
            p.url_image,
            p.category,
            p.description,
            p.discount_percent
        ORDER BY p.id ASC
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        json_response([
            'success' => false,
            'message' => 'Database query failed'
        ], 500);
    }

    if ($category !== '') {
        mysqli_stmt_bind_param($stmt, 's', $category);
    }

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $products = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $row['id'] = (int) $row['id'];
        $row['price'] = (int) $row['price'];
        $row['discount_percent'] = (float) $row['discount_percent'];
        $row['average_rating'] = round((float) $row['average_rating'], 2);
        $row['total_reviews'] = (int) $row['total_reviews'];

        $products[] = $row;
    }

    mysqli_stmt_close($stmt);

    json_response([
        'success' => true,
        'total' => count($products),
        'products' => $products
    ]);
}

// ==========================================
// POST: Create product (admin only)
// ==========================================
if ($method === 'POST') {

    // Check admin role
    if (!is_admin()) {
        json_response([
            'success' => false,
            'message' => 'Forbidden. Admin access required.'
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
            'message' => 'Invalid or expired CSRF token'
        ], 419);
    }

    // Get product data
    $name = trim($input['name'] ?? '');
    $price = $input['price'] ?? null;
    $urlImage = trim($input['url_image'] ?? '');
    $category = trim($input['category'] ?? '');
    $description = trim($input['description'] ?? '');
    $discount = $input['discount_percent'] ?? 0;

    // Validate required fields
    if ($name === '' || $price === null || $category === '') {
        json_response([
            'success' => false,
            'message' => 'Name, price, and category are required'
        ], 400);
    }

    if (!is_numeric($price) || $price < 0) {
        json_response([
            'success' => false,
            'message' => 'Price must be a valid non-negative number'
        ], 400);
    }

    if (!in_array($category, ['Coffee', 'Non-Coffee'], true)) {
        json_response([
            'success' => false,
            'message' => 'Category must be Coffee or Non-Coffee'
        ], 400);
    }

    if (!is_numeric($discount) || $discount < 0 || $discount > 100) {
        json_response([
            'success' => false,
            'message' => 'Discount must be between 0 and 100'
        ], 400);
    }

    if ($urlImage !== '' && !filter_var($urlImage, FILTER_VALIDATE_URL)) {
        json_response([
            'success' => false,
            'message' => 'Image URL is invalid'
        ], 400);
    }

    // Insert product
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO product
        (name, price, url_image, category, description, discount_percent)
        VALUES (?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        json_response([
            'success' => false,
            'message' => 'Database query failed'
        ], 500);
    }

    $price = (int) $price;
    $discount = (float) $discount;

    mysqli_stmt_bind_param(
        $stmt,
        'sisssd',
        $name,
        $price,
        $urlImage,
        $category,
        $description,
        $discount
    );

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        json_response([
            'success' => false,
            'message' => 'Failed to create product'
        ], 500);
    }

    $newProductId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    json_response([
        'success' => true,
        'message' => 'Product created successfully',
        'product' => [
            'id' => $newProductId,
            'name' => $name,
            'price' => $price,
            'url_image' => $urlImage,
            'category' => $category,
            'description' => $description,
            'discount_percent' => $discount
        ]
    ], 201);
}

// ==========================================
// PUT: Update product price (admin only)
// ==========================================
if ($method === 'PUT') {

    // Check admin role
    if (!is_admin()) {
        json_response([
            'success' => false,
            'message' => 'Forbidden. Admin access required.'
        ], 403);
    }

    // Get product ID from URL
    $productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if (!$productId || $productId < 1) {
        json_response([
            'success' => false,
            'message' => 'Valid product ID is required'
        ], 400);
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
            'message' => 'Invalid or expired CSRF token'
        ], 419);
    }

    // Validate new price
    $price = $input['price'] ?? null;

    if (
        !is_numeric($price) ||
        !is_finite((float) $price) ||
        (float) $price < 0 ||
        (float) $price > 2147483647 ||
        floor((float) $price) != (float) $price
    ) {
        json_response([
            'success' => false,
            'message' => 'Price must be a valid non-negative integer'
        ], 400);
    }

    $price = (int) $price;

    // Check whether product exists
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, name FROM product WHERE id = ?"
    );

    mysqli_stmt_bind_param($stmt, 'i', $productId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $product = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    if (!$product) {
        json_response([
            'success' => false,
            'message' => 'Product not found'
        ], 404);
    }

    // Update product price
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE product SET price = ? WHERE id = ?"
    );

    mysqli_stmt_bind_param($stmt, 'ii', $price, $productId);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        json_response([
            'success' => false,
            'message' => 'Failed to update product'
        ], 500);
    }

    mysqli_stmt_close($stmt);

    json_response([
        'success' => true,
        'message' => 'Product price updated successfully',
        'product' => [
            'id' => (int) $productId,
            'name' => $product['name'],
            'price' => $price
        ]
    ]);
}

// ==========================================
// DELETE: Delete product (admin only)
// ==========================================
if ($method === 'DELETE') {

    // Check admin role
    if (!is_admin()) {
        json_response([
            'success' => false,
            'message' => 'Forbidden. Admin access required.'
        ], 403);
    }

    // Get product ID from URL
    $productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if (!$productId || $productId < 1) {
        json_response([
            'success' => false,
            'message' => 'Valid product ID is required'
        ], 400);
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
            'message' => 'Invalid or expired CSRF token'
        ], 419);
    }

    // Check whether product exists
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, name FROM product WHERE id = ?"
    );

    mysqli_stmt_bind_param($stmt, 'i', $productId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $product = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    if (!$product) {
        json_response([
            'success' => false,
            'message' => 'Product not found'
        ], 404);
    }

    // Delete product
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM product WHERE id = ?"
    );

    mysqli_stmt_bind_param($stmt, 'i', $productId);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        json_response([
            'success' => false,
            'message' => 'Failed to delete product. It may be referenced by existing orders or reviews.'
        ], 409);
    }

    mysqli_stmt_close($stmt);

    json_response([
        'success' => true,
        'message' => 'Product deleted successfully',
        'product' => [
            'id' => (int) $productId,
            'name' => $product['name']
        ]
    ]);
}
// ==========================================
// Unsupported HTTP method
// ==========================================
json_response([
    'success' => false,
    'message' => 'Method not allowed'
], 405);
