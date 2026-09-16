<?php

$db_host = '127.0.0.1';
$db_name = 'warehouse_inventory_db';
$db_user = 'root';
$db_pass = '';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

function sendJsonHeaders() {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

function sendResponse($success, $message, $data = null, $status = null) {
    if ($status !== null) {
        http_response_code((int)$status);
    }
    $response = array(
        'success' => (bool)$success,
        'message' => $message,
        'data' => $data
    );
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function ($e) {
    error_log('Warehouse application exception: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(array('success' => false, 'message' => 'Request failed.', 'data' => null));
    exit;
});

function getConnection() {
    global $db_host, $db_name, $db_user, $db_pass;

    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
    $options = array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5
    );

    try {
        return new PDO($dsn, $db_user, $db_pass, $options);
    } catch (PDOException $e) {
        error_log('Database connection error: ' . $e->getMessage());
        sendResponse(false, 'Database connection failed. Please ensure MySQL is running in XAMPP.', null, 500);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '1800');

    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ));
    session_start();
}

require_once __DIR__ . '/permissions.php';

function getRequestBody() {
    if (!empty($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return array();
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        sendResponse(false, 'Request failed.', null, 400);
    }
    return $body;
}

function clientIp() {

    return isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : null;
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function requireCsrf() {
    $token = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        sendResponse(false, 'Request failed.', null, 403);
    }
}

function currentUser() {
    static $loaded = false;
    static $user = null;

    if ($loaded) {
        return $user;
    }
    $loaded = true;

    if (empty($_SESSION['user_id']) || !filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)))) {
        return null;
    }
    if (!empty($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > 1800) {
        $_SESSION = array();
        session_destroy();
        return null;
    }

    $conn = getConnection();
    $stmt = $conn->prepare(
        "SELECT u.user_id, u.role_id, r.role_name, u.first_name, u.last_name, u.username, u.is_active,
                r.is_active AS role_is_active
         FROM users u
         INNER JOIN role r ON u.role_id = r.role_id
         WHERE u.user_id = :user_id
         LIMIT 1"
    );
    $id = (int)$_SESSION['user_id'];
    $stmt->bindParam(':user_id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row || (int)$row['is_active'] !== 1 || (int)$row['role_is_active'] !== 1) {
        $_SESSION = array();
        session_destroy();
        return null;
    }

    $user = array(
        'user_id' => (int)$row['user_id'],
        'role_id' => (int)$row['role_id'],
        'role_name' => $row['role_name'],
        'first_name' => $row['first_name'],
        'last_name' => $row['last_name'],
        'username' => $row['username']
    );
    $_SESSION['user'] = $user;
    $_SESSION['last_activity'] = time();
    return $user;
}

function requireAuthenticated() {
    $user = currentUser();
    if (!$user) {
        sendResponse(false, 'Request failed.', null, 401);
    }

    $timeout = 1800;
    if (!empty($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $timeout) {
        $_SESSION = array();
        session_destroy();
        sendResponse(false, 'Request failed.', null, 401);
    }

    $_SESSION['last_activity'] = time();
    return $user;
}

function requirePermission($module, $action = 'view') {
    $user = requireAuthenticated();

    if (is_array($module)) {
        $allowed = false;
        foreach ($module as $m) {
            if (userCan($user['role_name'], $m, $action)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            sendResponse(false, 'You are not authorized to perform this action.', null, 403);
        }
    } else {
        if (!userCan($user['role_name'], $module, $action)) {
            sendResponse(false, 'You are not authorized to perform this action.', null, 403);
        }
    }

    return $user;
}

function requireMutationSecurity() {
    requireCsrf();
    return requireAuthenticated();
}

function validatePositiveInt($value) {
    return is_int($value) || (is_string($value) && ctype_digit($value) && (int)$value > 0)
        ? (int)$value : 0;
}

function validateDateValue($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $dt = DateTime::createFromFormat('!Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value;
}

function validateNonNegativeInt($value) {
    if (is_int($value)) {
        return $value >= 0 && $value <= 2147483647 ? $value : null;
    }
    if (is_string($value) && preg_match('/^\d+$/', $value)) {
        $n = (int)$value;
        return $n <= 2147483647 ? $n : null;
    }
    return null;
}

function validateUsername($value) {
    return is_string($value) && strlen($value) >= 3 && strlen($value) <= 50
        && preg_match('/^[A-Za-z0-9._-]+$/', $value);
}

function validatePassword($value) {
    return is_string($value) && strlen($value) >= 4 && strlen($value) <= 255;
}

function validateName($value, $max = 100) {
    return is_string($value) && $value !== '' && strlen($value) <= $max
        && !preg_match('/[\x00-\x1F\x7F]/', $value);
}

function validateContact($value) {
    return $value === '' || (is_string($value) && strlen($value) <= 20
        && preg_match('/^[0-9+() .-]+$/', $value));
}

function validateNonNegativeDecimal($value, $max = '99999999.99') {
    if (is_int($value) || is_float($value)) {
        $value = (string)$value;
    }
    if (!is_string($value) || !preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $value)) {
        return false;
    }
    return (float)$value >= 0 && (float)$value <= (float)$max;
}

function validatePositiveQuantity($value) {
    if (is_int($value)) {
        return $value > 0 && $value <= 2147483647 ? $value : 0;
    }
    if (is_string($value) && ctype_digit($value)) {
        $n = (int)$value;
        return $n > 0 && $n <= 2147483647 ? $n : 0;
    }
    return 0;
}

function auditLog($conn, $user, $action, $module, $recordId = null, $description = '') {

    return;
}

function productStock($conn, $productId) {
    $stmt = $conn->prepare(
        "SELECT
            COALESCE((SELECT SUM(d.quantity_received) FROM stock_receiving_detail d WHERE d.product_id = p.product_id), 0)
          + COALESCE((SELECT SUM(d.quantity_returned) FROM stocks_return_detail d WHERE d.product_id = p.product_id), 0)
          + COALESCE((SELECT SUM(d.quantity) FROM adjustment_detail d WHERE d.product_id = p.product_id AND d.adjustment_type = 'Addition'), 0)
          - COALESCE((SELECT SUM(d.quantity_released) FROM stock_releasing_detail d WHERE d.product_id = p.product_id), 0)
          - COALESCE((SELECT SUM(d.quantity_returned) FROM purchase_return_detail d WHERE d.product_id = p.product_id), 0)
          - COALESCE((SELECT SUM(d.quantity) FROM adjustment_detail d WHERE d.product_id = p.product_id AND d.adjustment_type = 'Deduction'), 0)
          AS stock_qty
         FROM product p
         WHERE p.product_id = :product_id
         FOR UPDATE"
    );
    $stmt->bindParam(':product_id', $productId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    return $row ? (int)$row['stock_qty'] : null;
}

function ensureItemList($items, $quantityKey) {
    if (!is_array($items) || count($items) < 1 || count($items) > 100) {
        return false;
    }
    $normalized = array();
    foreach ($items as $item) {
        if (!is_array($item)) {
            return false;
        }
        $productId = validatePositiveInt(isset($item['product_id']) ? $item['product_id'] : 0);
        $qty = validatePositiveQuantity(isset($item[$quantityKey]) ? $item[$quantityKey] : 0);
        if ($productId <= 0 || $qty <= 0) {
            return false;
        }
        if (!isset($normalized[$productId])) {
            $normalized[$productId] = 0;
        }
        $normalized[$productId] += $qty;
        if ($normalized[$productId] > 2147483647) {
            return false;
        }
    }
    return $normalized;
}

