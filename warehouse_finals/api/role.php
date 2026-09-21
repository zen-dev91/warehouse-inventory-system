<?php
require_once '../config/db.php';
sendJsonHeaders();

class RoleApi {
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
        $mode = isset($_GET['mode']) ? (string)$_GET['mode'] : 'all';
        if ($mode === 'active') {
            requirePermission(array('role', 'users'), 'view');
        } else {
            requirePermission('role', 'view');
        }
        sendResponse(true, 'Roles retrieved', $mode === 'active' ? $this->getActiveRoles() : $this->getRolesWithPermissions());
    }

    private function post() {
        $actor = requirePermission('role', 'manage');
        requireCsrf();

        $isActorAdmin = ($actor['role_name'] === 'Administrator' || (int)$actor['role_id'] === 1);
        if (!$isActorAdmin) {
            sendResponse(false, 'Only Administrators can create new roles.', null, 403);
        }

        $body = getRequestBody();
        $name = isset($body['role_name']) && is_string($body['role_name']) ? trim($body['role_name']) : '';

        if (!validateName($name, 50)) {
            sendResponse(false, 'Invalid role name.', null, 400);
        }

        $stmt = $this->conn->prepare("SELECT role_id FROM role WHERE LOWER(role_name) = LOWER(:name) LIMIT 1");
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->execute();
        if ($stmt->fetch()) {
            sendResponse(false, 'Role name already exists.', null, 409);
        }

        $rawPerms = isset($body['permissions']) && is_array($body['permissions']) ? $body['permissions'] : array();

        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("INSERT INTO role (role_name) VALUES (:name)");
            $stmt->bindParam(':name', $name, PDO::PARAM_STR);
            $stmt->execute();
            $id = (int)$this->conn->lastInsertId();

            $modules = getRbacModules();
            $insPerm = $this->conn->prepare("
                INSERT INTO role_permissions (role_id, module, can_view, can_manage)
                VALUES (:role_id, :module, :can_view, :can_manage)
            ");

            foreach ($modules as $mod) {
                $viewVal = 0;
                $manageVal = 0;
                if (isset($rawPerms[$mod]) && is_array($rawPerms[$mod])) {
                    $p = $rawPerms[$mod];
                    $manageVal = (!empty($p['manage']) || $p['manage'] === 1 || $p['manage'] === '1' || $p['manage'] === true) ? 1 : 0;
                    $viewVal = (!empty($p['view']) || $p['view'] === 1 || $p['view'] === '1' || $p['view'] === true || $manageVal === 1) ? 1 : 0;
                }
                $insPerm->execute(array(
                    ':role_id' => $id,
                    ':module' => $mod,
                    ':can_view' => $viewVal,
                    ':can_manage' => $manageVal
                ));
            }

            $this->conn->commit();
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Role creation failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 400);
        }

        auditLog($this->conn, $actor, 'ROLE_CREATE', 'role', $id, 'Created role ' . $name);
        sendResponse(true, 'Role added successfully.');
    }

    private function put() {
        $actor = requirePermission('role', 'manage');
        requireCsrf();

        $isActorAdmin = ($actor['role_name'] === 'Administrator' || (int)$actor['role_id'] === 1);
        if (!$isActorAdmin) {
            sendResponse(false, 'Only Administrators can modify roles and permissions.', null, 403);
        }

        $body = getRequestBody();
        $id = validatePositiveInt(isset($body['role_id']) ? $body['role_id'] : 0);
        $name = isset($body['role_name']) && is_string($body['role_name']) ? trim($body['role_name']) : '';
        $role = $this->getRole($id);

        if (!$role || !validateName($name, 50)) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        $isAdmin = ($role['role_name'] === 'Administrator' || $id === 1);

        if ($isAdmin && $name !== 'Administrator') {
            sendResponse(false, 'Administrator role cannot be renamed.', null, 409);
        }

        if (strtolower($role['role_name']) !== strtolower($name)) {
            $dup = $this->conn->prepare("SELECT role_id FROM role WHERE LOWER(role_name) = LOWER(:name) AND role_id != :id LIMIT 1");
            $dup->bindParam(':name', $name, PDO::PARAM_STR);
            $dup->bindParam(':id', $id, PDO::PARAM_INT);
            $dup->execute();
            if ($dup->fetch()) {
                sendResponse(false, 'Role name already exists.', null, 409);
            }
        }

        $rawPerms = isset($body['permissions']) && is_array($body['permissions']) ? $body['permissions'] : array();

        try {
            $this->conn->beginTransaction();

            if (strtolower($role['role_name']) !== strtolower($name)) {
                $stmt = $this->conn->prepare("UPDATE role SET role_name = :name WHERE role_id = :id");
                $stmt->bindParam(':name', $name, PDO::PARAM_STR);
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt->execute();
                auditLog($this->conn, $actor, 'ROLE_UPDATE', 'role', $id, 'Updated role to ' . $name);
            }

            $modules = getRbacModules();

            $stmtDel = $this->conn->prepare("DELETE FROM role_permissions WHERE role_id = :role_id");
            $stmtDel->bindParam(':role_id', $id, PDO::PARAM_INT);
            $stmtDel->execute();

            $insPerm = $this->conn->prepare("
                INSERT INTO role_permissions (role_id, module, can_view, can_manage)
                VALUES (:role_id, :module, :can_view, :can_manage)
            ");

            foreach ($modules as $mod) {
                if ($isAdmin) {
                    $viewVal = 1;
                    $manageVal = 1;
                } else {
                    $viewVal = 0;
                    $manageVal = 0;
                    if (isset($rawPerms[$mod]) && is_array($rawPerms[$mod])) {
                        $p = $rawPerms[$mod];
                        $manageVal = (!empty($p['manage']) || $p['manage'] === 1 || $p['manage'] === '1' || $p['manage'] === true) ? 1 : 0;
                        $viewVal = (!empty($p['view']) || $p['view'] === 1 || $p['view'] === '1' || $p['view'] === true || $manageVal === 1) ? 1 : 0;
                    }
                }

                $insPerm->execute(array(
                    ':role_id' => $id,
                    ':module' => $mod,
                    ':can_view' => $viewVal,
                    ':can_manage' => $manageVal
                ));
            }

            $this->conn->commit();
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Role update failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 400);
        }

        sendResponse(true, 'Role updated successfully.');
    }

    private function delete() {
        $actor = requirePermission('role', 'manage');
        requireCsrf();

        $isActorAdmin = ($actor['role_name'] === 'Administrator' || (int)$actor['role_id'] === 1);
        if (!$isActorAdmin) {
            sendResponse(false, 'Only Administrators can change role status.', null, 403);
        }

        $id = validatePositiveInt(isset($_GET['id']) ? $_GET['id'] : 0);
        $restore = isset($_GET['restore']) && (int)$_GET['restore'] === 1;
        $role = $this->getRole($id);

        if (!$role) {
            sendResponse(false, 'Request failed.', null, 404);
        }

        if (!$restore && ($role['role_name'] === 'Administrator' || $id === 1)) {
            sendResponse(false, 'Administrator role cannot be deactivated.', null, 409);
        }
        if (!$restore && ((int)$actor['role_id'] === $id || $role['role_name'] === $actor['role_name'])) {
            sendResponse(false, 'You cannot deactivate your currently assigned role.', null, 409);
        }

        $status = $restore ? 1 : 0;
        $stmt = $this->conn->prepare("UPDATE role SET is_active = :status WHERE role_id = :id");
        $stmt->bindParam(':status', $status, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        auditLog($this->conn, $actor, $restore ? 'ROLE_ACTIVATE' : 'ROLE_DEACTIVATE', 'role', $id, 'Role status changed');
        sendResponse(true, $restore ? 'Role restored successfully.' : 'Role deactivated successfully.');
    }

    private function getAllRoles() {
        $stmt = $this->conn->prepare("SELECT role_id, role_name, is_active, created_at FROM role ORDER BY role_name ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function getActiveRoles() {
        $stmt = $this->conn->prepare("SELECT role_id, role_name FROM role WHERE is_active = 1 ORDER BY role_name ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function getRolesWithPermissions() {
        $roles = $this->getAllRoles();
        $stmt = $this->conn->query("SELECT role_id, module, can_view, can_manage FROM role_permissions");
        $allPerms = $stmt->fetchAll();

        $modules = getRbacModules();
        $permMap = array();
        foreach ($allPerms as $p) {
            $rid = (int)$p['role_id'];
            $m = $p['module'];
            if (!isset($permMap[$rid])) {
                $permMap[$rid] = array();
            }
            $canManage = (int)$p['can_manage'] === 1;
            $canView = (int)$p['can_view'] === 1 || $canManage;
            $permMap[$rid][$m] = array(
                'view' => $canView,
                'manage' => $canManage
            );
        }

        foreach ($roles as &$r) {
            $rid = (int)$r['role_id'];
            $r['permissions'] = array();
            $isAdmin = ($r['role_name'] === 'Administrator' || $rid === 1);
            foreach ($modules as $m) {
                if ($isAdmin) {
                    $r['permissions'][$m] = array('view' => true, 'manage' => true);
                } else {
                    $r['permissions'][$m] = isset($permMap[$rid][$m])
                        ? $permMap[$rid][$m]
                        : array('view' => false, 'manage' => false);
                }
            }
        }
        unset($r);

        return $roles;
    }

    private function getRole($id) {
        $stmt = $this->conn->prepare("SELECT role_id, role_name, is_active FROM role WHERE role_id = :id LIMIT 1");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }
}

$conn = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

$api = new RoleApi($conn);
$api->handleRequest($method);

