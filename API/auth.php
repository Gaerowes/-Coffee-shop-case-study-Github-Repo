
<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config.php';

function json_response($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Read JSON request body
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// GET: Check currently logged-in user
if ($action === 'me') {
    if ($method !== 'GET') {
        json_response([
            'success' => false,
            'message' => 'Method not allowed'
        ], 405);
    }

    $user = null;

    if (is_logged_in()) {
        $user = [
            'id' => (int) $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
            'role' => $_SESSION['role']
        ];
    }

    json_response([
        'success' => true,
        'user' => $user,
        'csrf_token' => csrf_token()
    ]);
}

// POST: Login
if ($action === 'login') {
    if ($method !== 'POST') {
        json_response([
            'success' => false,
            'message' => 'Method not allowed'
        ], 405);
    }

    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if ($username === '' || $password === '') {
        json_response([
            'success' => false,
            'message' => 'Username and password are required'
        ], 400);
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, username, password, role
        FROM users
        WHERE username = ?"
    );

    if (!$stmt) {
        json_response([
            'success' => false,
            'message' => 'Database query failed'
        ], 500);
    }

    mysqli_stmt_bind_param($stmt, 's', $username);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    if (!$user || !password_verify($password, $user['password'])) {
        json_response([
            'success' => false,
            'message' => 'Incorrect username or password'
        ], 401);
    }

    // Prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];

    json_response([
        'success' => true,
        'message' => 'Login successful',
        'user' => [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'role' => $user['role']
        ],
        'csrf_token' => csrf_token()
    ]);
}

// POST: Register new customer
if ($action === 'register') {
    if ($method !== 'POST') {
        json_response([
            'success' => false,
            'message' => 'Method not allowed'
        ], 405);
    }

    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    // Validate input
    if ($username === '' || $password === '') {
        json_response([
            'success' => false,
            'message' => 'Username and password are required'
        ], 400);
    }

    if (strlen($username) < 3 || strlen($username) > 50) {
        json_response([
            'success' => false,
            'message' => 'Username must be 3-50 characters'
        ], 400);
    }

    if (strlen($password) < 6) {
        json_response([
            'success' => false,
            'message' => 'Password must be at least 6 characters'
        ], 400);
    }

    // Check whether username already exists
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id FROM users WHERE username = ?"
    );

    mysqli_stmt_bind_param($stmt, 's', $username);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $existingUser = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    if ($existingUser) {
        json_response([
            'success' => false,
            'message' => 'Username already exists'
        ], 409);
    }

    // Hash password before saving
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO users (username, password, role)
        VALUES (?, ?, 'customer')"
    );

    mysqli_stmt_bind_param(
        $stmt,
        'ss',
        $username,
        $hashedPassword
    );

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        json_response([
            'success' => false,
            'message' => 'Registration failed'
        ], 500);
    }

    $newUserId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    json_response([
        'success' => true,
        'message' => 'Registration successful',
        'user' => [
            'id' => $newUserId,
            'username' => $username,
            'role' => 'customer'
        ]
    ], 201);
}


// POST: Logout
if ($action === 'logout') {
    if ($method !== 'POST') {
        json_response([
            'success' => false,
            'message' => 'Method not allowed'
        ], 405);
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

    // Destroy current session
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    json_response([
        'success' => true,
        'message' => 'Logout successful'
    ]);
}


json_response([
    'success' => false,
    'message' => 'Invalid action'
], 404);
