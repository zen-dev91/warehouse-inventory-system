<?php
require_once '../config/db.php';
sendJsonHeaders();

class UsersApi {
    private PDO $conn;

    public function __construct(PDO $conn) {
        $this->conn = $conn;
    }

    public function handleRequest($method) {
        switch ($method) {
            case 'GET':
                $this->get();
                break;
            case 'POST':
                $this->post();
                break;
            case 'PUT':
                $this->put();
                break;
            case 'DELETE':
                $this->delete();
                break;
            default:
                sendResponse(false, 'Request failed.', null, 405);
                break;
        }
    }

    private function get() {
        requirePermission('users', 'view');
        sendResponse(true, 'Users retrieved', $this->getAllUsers());
    }

    private function post() {
        $actor = requirePermission('users', 'manage');
        requireCsrf();

        $body = getRequestBody();
        $roleId = validatePositiveInt(isset($body['role_id']) ? $body['role_id'] : 0);
        $firstName = isset($body['first_name']) && is_string($body['first_name']) ? trim($body['first_name']) : '';
        $lastName = isset($body['last_name']) && is_string($body['last_name']) ? trim($body['last_name']) : '';
        $username = isset($body['username']) && is_string($body['username']) ? trim($body['username']) : '';
        $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
        $contact = isset($body['contact_number']) && is_string($body['contact_number']) ? trim($body['contact_number']) : '';

        $role = $this->getRoleById($roleId);
        if (!$role || (int)$role['is_active'] !== 1) {
            sendResponse(false, 'Please select a valid, active role.', null, 400);
        }

        $isActorAdmin = ($actor['role_name'] === 'Administrator' || (int)$actor['role_id'] === 1);
        if ((int)$roleId === 1 && !$isActorAdmin) {
            sendResponse(false, 'Only Administrators can assign the Administrator role.', null, 403);
        }

        if (!validateName($firstName, 50)) {
            sendResponse(false, 'First name is required (max 50 characters).', null, 400);
        }
        if (!validateName($lastName, 50)) {
            sendResponse(false, 'Last name is required (max 50 characters).', null, 400);
        }
        if (!validateUsername($username)) {
            sendResponse(false, 'Username must be 3-50 characters (letters, numbers, dots, dashes).', null, 400);
        }
        if (!validatePassword($password)) {
            sendResponse(false, 'Password must be at least 4 characters.', null, 400);
        }
        if (!validateContact($contact)) {
            sendResponse(false, 'Contact number is invalid.', null, 400);
        }

        $dup = $this->conn->prepare("SELECT user_id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1");
        $dup->bindParam(':username', $username, PDO::PARAM_STR);
        $dup->execute();
        if ($dup->fetch()) {
            sendResponse(false, 'Username already exists.', null, 409);
        }

        try {
            $newId = $this->createUser($roleId, $firstName, $lastName, $username, $password, $contact);
        } catch (Throwable $e) {
            error_log('User creation failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 400);
        }

        auditLog($this->conn, $actor, 'USER_CREATE', 'users', $newId, 'Created user ' . $username . ' with role ' . $role['role_name']);
        sendResponse(true, 'User added successfully.');
    }

    private function put() {
        $actor = requirePermission('users', 'manage');
        requireCsrf();

        $body = getRequestBody();
        $id = validatePositiveInt(isset($body['user_id']) ? $body['user_id'] : 0);
        $action = isset($body['action']) && is_string($body['action']) ? $body['action'] : 'update_details';

        if ($id <= 0) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        $targetStmt = $this->conn->prepare("SELECT user_id, role_id, username, is_active FROM users WHERE user_id = :id LIMIT 1");
        $targetStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $targetStmt->execute();
        $target = $targetStmt->fetch();
        if (!$target) {
            sendResponse(false, 'Request failed.', null, 404);
        }

        $isActorAdmin = ($actor['role_name'] === 'Administrator' || (int)$actor['role_id'] === 1);

        switch ($action) {
            case 'reset_password':
                if ($this->userIsActiveAdmin($id) && !$isActorAdmin) {
                    sendResponse(false, 'Only Administrators can reset Administrator passwords.', null, 403);
                }
                $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
                if (!validatePassword($password)) {
                    sendResponse(false, 'Request failed.', null, 400);
                }
                $this->resetUserPassword($id, $password);
                auditLog($this->conn, $actor, 'PASSWORD_RESET', 'users', $id, 'Administrator reset a user password');
                sendResponse(true, 'Password reset successfully.');
                break;

            case 'update_details':
            default:
                if ($this->userIsActiveAdmin($id) && !$isActorAdmin) {
                    sendResponse(false, 'You do not have permission to modify an Administrator account.', null, 403);
                }

                $roleId = validatePositiveInt(isset($body['role_id']) ? $body['role_id'] : 0);
                if ((int)$roleId === 1 && !$isActorAdmin) {
                    sendResponse(false, 'Only Administrators can assign the Administrator role.', null, 403);
                }
                $firstName = isset($body['first_name']) && is_string($body['first_name']) ? trim($body['first_name']) : '';
                $lastName = isset($body['last_name']) && is_string($body['last_name']) ? trim($body['last_name']) : '';
                $username = isset($body['username']) && is_string($body['username']) ? trim($body['username']) : '';
                $contact = isset($body['contact_number']) && is_string($body['contact_number']) ? trim($body['contact_number']) : '';

                $role = $this->getRoleById($roleId);
                if (!$role || (int)$role['is_active'] !== 1) {
                    sendResponse(false, 'Please select a valid, active role.', null, 400);
                }
                if (!validateName($firstName, 50)) {
                    sendResponse(false, 'First name is required (max 50 characters).', null, 400);
                }
                if (!validateName($lastName, 50)) {
                    sendResponse(false, 'Last name is required (max 50 characters).', null, 400);
                }
                if (!validateUsername($username)) {
                    sendResponse(false, 'Username must be 3-50 characters (letters, numbers, dots, dashes).', null, 400);
                }
                if (!validateContact($contact)) {
                    sendResponse(false, 'Contact number is invalid.', null, 400);
                }

                if (strtolower($target['username']) !== strtolower($username)) {
                    $dup = $this->conn->prepare("SELECT user_id FROM users WHERE LOWER(username) = LOWER(:username) AND user_id != :id LIMIT 1");
                    $dup->bindParam(':username', $username, PDO::PARAM_STR);
                    $dup->bindParam(':id', $id, PDO::PARAM_INT);
                    $dup->execute();
                    if ($dup->fetch()) {
                        sendResponse(false, 'Username already exists.', null, 409);
                    }
                }

                $changingAdminAway = $this->userIsActiveAdmin($id) && $role['role_name'] !== 'Administrator';
                if ($changingAdminAway && $this->countActiveAdmins() <= 1) {
                    sendResponse(false, 'Request failed.', null, 409);
                }

                try {
                    $this->updateUser($id, $roleId, $firstName, $lastName, $username, $contact);
                } catch (Throwable $e) {
                    error_log('User update failed: ' . $e->getMessage());
                    sendResponse(false, 'Request failed.', null, 400);
                }

                if ((int)$target['role_id'] !== $roleId) {
                    auditLog($this->conn, $actor, 'ROLE_CHANGE', 'users', $id, 'User role changed');
                }
                auditLog($this->conn, $actor, 'USER_MODIFY', 'users', $id, 'User details modified');
                sendResponse(true, 'User updated successfully.');
                break;
        }
    }

    private function delete() {
        $actor = requirePermission('users', 'manage');
        requireCsrf();

        $id = validatePositiveInt(isset($_GET['id']) ? $_GET['id'] : 0);
        $restore = isset($_GET['restore']) && (int)$_GET['restore'] === 1;
        if ($id <= 0) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        if (!$restore && (int)$actor['user_id'] === $id) {
            sendResponse(false, 'You cannot deactivate your currently logged-in account.', null, 409);
        }

        $isActorAdmin = ($actor['role_name'] === 'Administrator' || (int)$actor['role_id'] === 1);
        if ($this->userIsActiveAdmin($id) && !$isActorAdmin) {
            sendResponse(false, 'You do not have permission to change Administrator status.', null, 403);
        }

        switch ($restore) {
            case true:
                $targetStmt = $this->conn->prepare("SELECT u.user_id, u.is_active, r.role_name, r.is_active AS role_is_active FROM users u INNER JOIN role r ON u.role_id = r.role_id WHERE u.user_id = :id LIMIT 1");
                $targetStmt->bindParam(':id', $id, PDO::PARAM_INT);
                $targetStmt->execute();
                $target = $targetStmt->fetch();

                if (!$target || (int)$target['role_is_active'] !== 1) {
                    sendResponse(false, 'Request failed.', null, 409);
                }
                $this->setUserStatus($id, 1);
                auditLog($this->conn, $actor, 'ACCOUNT_ACTIVATE', 'users', $id, 'User account activated');
                sendResponse(true, 'User restored successfully.');
                break;

            case false:
            default:
                if (!$this->setUserStatus($id, 0)) {
                    sendResponse(false, 'Request failed.', null, 409);
                }
                auditLog($this->conn, $actor, 'ACCOUNT_DEACTIVATE', 'users', $id, 'User account deactivated');
                sendResponse(true, 'User deactivated successfully.');
                break;
        }
    }

    private function getRoleById($roleId) {
        $stmt = $this->conn->prepare("SELECT role_id, role_name, is_active FROM role WHERE role_id = :id LIMIT 1");
        $stmt->bindParam(':id', $roleId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    private function createUser($roleId, $firstName, $lastName, $username, $password, $contact) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->conn->prepare(
            "INSERT INTO users (role_id, first_name, last_name, username, password, contact_number)
             VALUES (:role_id, :first_name, :last_name, :username, :password, :contact)"
        );
        $stmt->bindParam(':role_id', $roleId, PDO::PARAM_INT);
        $stmt->bindParam(':first_name', $firstName, PDO::PARAM_STR);
        $stmt->bindParam(':last_name', $lastName, PDO::PARAM_STR);
        $stmt->bindParam(':username', $username, PDO::PARAM_STR);
        $stmt->bindParam(':password', $hashedPassword, PDO::PARAM_STR);
        $stmt->bindParam(':contact', $contact, PDO::PARAM_STR);
        $stmt->execute();
        return (int)$this->conn->lastInsertId();
    }

    private function getAllUsers() {
        $stmt = $this->conn->prepare(
            "SELECT u.user_id, u.role_id, r.role_name, u.first_name, u.last_name,
                    u.username, u.contact_number, u.is_active, u.created_at
             FROM users u
             INNER JOIN role r ON u.role_id = r.role_id
             ORDER BY u.last_name ASC, u.first_name ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function countActiveAdmins() {
        $stmt = $this->conn->query(
            "SELECT COUNT(*) AS total
             FROM users u INNER JOIN role r ON u.role_id = r.role_id
             WHERE r.role_name = 'Administrator' AND u.is_active = 1 AND r.is_active = 1"
        );
        return (int)$stmt->fetch()['total'];
    }

    private function userIsActiveAdmin($id) {
        $stmt = $this->conn->prepare(
            "SELECT u.is_active, r.role_name, r.is_active AS role_is_active
             FROM users u INNER JOIN role r ON u.role_id = r.role_id
             WHERE u.user_id = :id LIMIT 1"
        );
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row && (int)$row['is_active'] === 1 && (int)$row['role_is_active'] === 1
            && $row['role_name'] === 'Administrator';
    }

    private function updateUser($id, $roleId, $firstName, $lastName, $username, $contact) {
        $stmt = $this->conn->prepare(
            "UPDATE users
             SET role_id = :role_id, first_name = :first_name, last_name = :last_name,
                 username = :username, contact_number = :contact
             WHERE user_id = :id"
        );
        $stmt->bindParam(':role_id', $roleId, PDO::PARAM_INT);
        $stmt->bindParam(':first_name', $firstName, PDO::PARAM_STR);
        $stmt->bindParam(':last_name', $lastName, PDO::PARAM_STR);
        $stmt->bindParam(':username', $username, PDO::PARAM_STR);
        $stmt->bindParam(':contact', $contact, PDO::PARAM_STR);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() >= 0;
    }

    private function resetUserPassword($id, $password) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->conn->prepare("UPDATE users SET password = :password WHERE user_id = :id");
        $stmt->bindParam(':password', $hashedPassword, PDO::PARAM_STR);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() >= 0;
    }

    private function setUserStatus($id, $status) {
        if ($status === 0 && $this->userIsActiveAdmin($id) && $this->countActiveAdmins() <= 1) {
            return false;
        }
        $stmt = $this->conn->prepare("UPDATE users SET is_active = :status WHERE user_id = :id");
        $stmt->bindParam(':status', $status, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return true;
    }
}

$conn = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

$api = new UsersApi($conn);
$api->handleRequest($method);

