<?php

function getRbacModules() {
    return array(
        'dashboard',
        'category',
        'product',
        'supplier',
        'product_supplier',
        'role',
        'users',
        'purchase_order',
        'stock_receiving',
        'stock_releasing',
        'purchase_return',
        'stocks_return',
        'adjustment'
    );
}

function getDefaultRolePermissions($roleName) {
    $clean = strtolower(trim((string)$roleName));
    $modules = getRbacModules();
    $result = array();
    foreach ($modules as $m) {
        $result[$m] = array('view' => false, 'manage' => false);
    }

    if ($clean === 'administrator') {
        foreach ($modules as $m) {
            $result[$m] = array('view' => true, 'manage' => true);
        }
        return $result;
    }

    if ($clean === 'purchasing officer') {
        $result['dashboard'] = array('view' => true, 'manage' => false);
        $result['category'] = array('view' => true, 'manage' => false);
        $result['product'] = array('view' => true, 'manage' => false);
        $result['supplier'] = array('view' => true, 'manage' => true);
        $result['product_supplier'] = array('view' => true, 'manage' => true);
        $result['purchase_order'] = array('view' => true, 'manage' => true);
        $result['stock_receiving'] = array('view' => true, 'manage' => false);
        $result['purchase_return'] = array('view' => true, 'manage' => true);
        return $result;
    }

    if ($clean === 'warehouse staff') {
        $result['dashboard'] = array('view' => true, 'manage' => false);
        $result['category'] = array('view' => true, 'manage' => false);
        $result['product'] = array('view' => true, 'manage' => false);
        $result['supplier'] = array('view' => true, 'manage' => false);
        $result['product_supplier'] = array('view' => true, 'manage' => false);
        $result['purchase_order'] = array('view' => true, 'manage' => false);
        $result['stock_receiving'] = array('view' => true, 'manage' => true);
        $result['stock_releasing'] = array('view' => true, 'manage' => true);
        $result['purchase_return'] = array('view' => true, 'manage' => true);
        $result['stocks_return'] = array('view' => true, 'manage' => true);
        $result['adjustment'] = array('view' => true, 'manage' => true);
        return $result;
    }

    if ($clean === 'store requester') {
        $result['dashboard'] = array('view' => true, 'manage' => false);
        $result['category'] = array('view' => true, 'manage' => false);
        $result['product'] = array('view' => true, 'manage' => false);
        $result['stock_releasing'] = array('view' => true, 'manage' => true);
        $result['stocks_return'] = array('view' => true, 'manage' => true);
        return $result;
    }

    return $result;
}

function permissionsForRole($roleIdentifier, $conn = null) {
    if ($conn === null) {
        $conn = getConnection();
    }

    $cleanRole = '';
    $roleId = 0;
    if (is_numeric($roleIdentifier)) {
        $roleId = (int)$roleIdentifier;
    } else {
        $cleanRole = is_string($roleIdentifier) ? trim($roleIdentifier) : '';
    }

    if ($roleId === 1 || strcasecmp($cleanRole, 'Administrator') === 0) {
        $modules = getRbacModules();
        $result = array();
        foreach ($modules as $m) {
            $result[$m] = array('view' => true, 'manage' => true);
        }
        return $result;
    }

    $result = getDefaultRolePermissions($cleanRole);

    try {
        if ($roleId > 0) {
            $stmt = $conn->prepare(
                "SELECT rp.module, rp.can_view, rp.can_manage, r.role_name
                 FROM role_permissions rp
                 INNER JOIN role r ON rp.role_id = r.role_id
                 WHERE rp.role_id = :role_id"
            );
            $stmt->bindParam(':role_id', $roleId, PDO::PARAM_INT);
        } else {
            $stmt = $conn->prepare(
                "SELECT rp.module, rp.can_view, rp.can_manage, r.role_name
                 FROM role_permissions rp
                 INNER JOIN role r ON rp.role_id = r.role_id
                 WHERE LOWER(TRIM(r.role_name)) = LOWER(TRIM(:role_name))"
            );
            $stmt->bindParam(':role_name', $cleanRole, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        if (!empty($rows)) {
            // When database records exist, they are the authoritative configuration
            foreach (getRbacModules() as $m) {
                $result[$m] = array('view' => false, 'manage' => false);
            }
            foreach ($rows as $row) {
                $m = $row['module'];
                if (isset($result[$m])) {
                    $canManage = ((int)$row['can_manage'] === 1);
                    $canView = ((int)$row['can_view'] === 1) || $canManage;
                    $result[$m] = array(
                        'view' => $canView,
                        'manage' => $canManage
                    );
                }
            }
        }
    } catch (Throwable $e) {
        error_log('permissionsForRole error: ' . $e->getMessage());
    }

    return $result;
}

function userCan($roleIdentifier, $module, $action) {
    $cleanRole = is_string($roleIdentifier) ? trim($roleIdentifier) : '';
    $roleId = is_numeric($roleIdentifier) ? (int)$roleIdentifier : 0;
    if ($roleId === 1 || strcasecmp($cleanRole, 'Administrator') === 0) {
        return true;
    }

    static $cache = array();
    $cacheKey = $roleId > 0 ? ('id_' . $roleId) : strtolower($cleanRole);
    if (!isset($cache[$cacheKey])) {
        $cache[$cacheKey] = permissionsForRole($roleIdentifier);
    }

    if (!isset($cache[$cacheKey][$module])) {
        return false;
    }

    if ($action === 'view') {
        return !empty($cache[$cacheKey][$module]['view']) || !empty($cache[$cacheKey][$module]['manage']);
    }

    if ($action === 'manage') {
        return !empty($cache[$cacheKey][$module]['manage']);
    }

    return false;
}

