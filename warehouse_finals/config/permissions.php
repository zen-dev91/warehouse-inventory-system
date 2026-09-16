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

function permissionsForRole($roleName, $conn = null) {
    if ($conn === null) {
        $conn = getConnection();
    }

    $modules = getRbacModules();
    $result = array();
    foreach ($modules as $m) {
        $result[$m] = array('view' => false, 'manage' => false);
    }

    if ($roleName === 'Administrator') {
        foreach ($modules as $m) {
            $result[$m] = array('view' => true, 'manage' => true);
        }
        return $result;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT rp.module, rp.can_view, rp.can_manage
             FROM role_permissions rp
             INNER JOIN role r ON rp.role_id = r.role_id
             WHERE r.role_name = :role_name"
        );
        $stmt->bindParam(':role_name', $roleName, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $m = $row['module'];
            if (isset($result[$m])) {
                $canManage = (int)$row['can_manage'] === 1;
                $canView = (int)$row['can_view'] === 1 || $canManage;
                $result[$m] = array(
                    'view' => $canView,
                    'manage' => $canManage
                );
            }
        }
    } catch (Throwable $e) {
        error_log('permissionsForRole error: ' . $e->getMessage());
    }

    return $result;
}

function userCan($roleName, $module, $action) {
    if ($roleName === 'Administrator') {
        return true;
    }

    static $cache = array();
    if (!isset($cache[$roleName])) {
        $cache[$roleName] = permissionsForRole($roleName);
    }

    if (!isset($cache[$roleName][$module])) {
        return false;
    }

    if ($action === 'view') {
        return !empty($cache[$roleName][$module]['view']) || !empty($cache[$roleName][$module]['manage']);
    }

    if ($action === 'manage') {
        return !empty($cache[$roleName][$module]['manage']);
    }

    return false;
}

