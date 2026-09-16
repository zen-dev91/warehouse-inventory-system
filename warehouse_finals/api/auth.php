<?php
require_once '../config/db.php';
sendJsonHeaders();

class AuthApi {
    private PDO $conn;

    public function __construct(PDO $conn) {
        $this->conn = $conn;
    }

    public function handleRequest($method, $action) {
        switch ($action) {
            case 'register':
            case 'roles':
                sendResponse(false, 'You are not authorized to perform this action.', null, 403);
                break;
        }

        switch ($method) {
            case 'GET':
                switch ($action) {
                    case 'me':
                        $this->me();
                        break;
                    default:
                        sendResponse(false, 'Request failed.', null, 400);
                        break;
                }
                break;

            case 'POST':
                switch ($action) {
                    case 'login':
                        $this->login();
                        break;
                    case 'logout':
                        $this->logout();
                        break;
                    default:
                        sendResponse(false, 'Request failed.', null, 400);
                        break;
                }
                break;

            default:
                sendResponse(false, 'Request failed.', null, 405);
                break;
        }
    }

    private function me() {
        $user = currentUser();
        sendResponse(true, $user ? 'Session active' : 'No active session', array(
            'user' => $user,
            'permissions' => $user ? permissionsForRole($user['role_name']) : null,
            'csrf_token' => csrfToken()
        ));
    }

    private function login() {
        $body = getRequestBody();
        $username = isset($body['username']) && is_string($body['username']) ? trim($body['username']) : '';
        $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
        $ip = clientIp();

        if (!validateUsername($username) || $password === '' || strlen($password) > 255) {
            auditLog($this->conn, null, 'LOGIN_FAILURE', 'auth', null, 'Invalid login request');
            sendResponse(false, 'Invalid username or password.', null, 401);
        }

        $row = $this->findUserByUsername($username);
        $hash = $row && isset($row['password']) ? $row['password'] : $this->dummyPasswordHash();
        $passwordOk = password_verify($password, $hash);

        if (!$row || !$passwordOk || (int)$row['is_active'] !== 1 || (int)$row['role_is_active'] !== 1) {
            auditLog($this->conn, null, 'LOGIN_FAILURE', 'auth', null, 'Invalid username or password');
            sendResponse(false, 'Invalid username or password.', null, 401);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['user_id'];
        $_SESSION['user'] = $this->publicUserFromRow($row);
        $_SESSION['last_activity'] = time();
        unset($_SESSION['csrf_token']);
        $token = csrfToken();

        $user = $_SESSION['user'];
        auditLog($this->conn, $user, 'LOGIN_SUCCESS', 'auth', $user['user_id'], 'Successful login');

        sendResponse(true, 'Login successful.', array(
            'user' => $user,
            'permissions' => permissionsForRole($user['role_name']),
            'csrf_token' => $token
        ));
    }

    private function logout() {
        $user = currentUser();
        if ($user) {
            auditLog($this->conn, $user, 'LOGOUT', 'auth', $user['user_id'], 'User logged out');
        }

        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', array(
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => isset($params['domain']) ? $params['domain'] : '',
                'secure' => (bool)$params['secure'],
                'httponly' => true,
                'samesite' => 'Lax'
            ));
        }
        session_destroy();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
            session_regenerate_id(true);
        }
        $freshToken = csrfToken();

        sendResponse(true, 'Logged out successfully.', array(
            'csrf_token' => $freshToken
        ));
    }

    private function findUserByUsername($username) {
        $stmt = $this->conn->prepare(
            "SELECT u.user_id, u.role_id, r.role_name, u.first_name, u.last_name,
                    u.username, u.password, u.is_active, r.is_active AS role_is_active
             FROM users u
             INNER JOIN role r ON u.role_id = r.role_id
             WHERE LOWER(u.username) = LOWER(:username)
             LIMIT 1"
        );
        $cleanUsername = trim($username);
        $stmt->bindParam(':username', $cleanUsername, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetch();
    }

    private function dummyPasswordHash() {
        static $hash = null;
        if ($hash === null) {
            $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        }
        return $hash;
    }

    private function publicUserFromRow($row) {
        return array(
            'user_id' => (int)$row['user_id'],
            'role_id' => (int)$row['role_id'],
            'role_name' => $row['role_name'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'username' => $row['username']
        );
    }
}

$conn = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

$api = new AuthApi($conn);
$api->handleRequest($method, $action);

