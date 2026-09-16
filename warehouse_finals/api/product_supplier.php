<?php
require_once '../config/db.php';
sendJsonHeaders();

class ProductSupplierApi {
    private PDO $conn;

    public function __construct(PDO $conn) {
        $this->conn = $conn;
    }

    public function handleRequest($method, $action) {
        switch ($method) {
            case 'GET':
                $this->get();
                break;
            case 'POST':
                switch ($action) {
                    case 'bulk_save':
                        $this->bulkSave();
                        break;
                    default:
                        $this->postSingle();
                        break;
                }
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
        requirePermission('product_supplier', 'view');

        if (isset($_GET['supplier_id'])) {
            $sid = validatePositiveInt($_GET['supplier_id']);
            if ($sid <= 0) {
                sendResponse(false, 'Invalid supplier ID.', null, 400);
            }
            sendResponse(true, 'Products for supplier retrieved', $this->getProductsForSupplier($sid));
        }

        sendResponse(true, 'Product-supplier links retrieved', $this->getAllProductSuppliers());
    }

    private function bulkSave() {
        $actor = requirePermission('product_supplier', 'manage');
        requireCsrf();
        $b = getRequestBody();

        $supplierId = validatePositiveInt(isset($b['supplier_id']) ? $b['supplier_id'] : 0);
        if ($supplierId <= 0 || !$this->activeSupplier($supplierId)) {
            sendResponse(false, 'Invalid supplier.', null, 400);
        }

        $links = isset($b['links']) && is_array($b['links']) ? $b['links'] : array();

        $validLinks = array();
        foreach ($links as $link) {
            $pid  = validatePositiveInt(isset($link['product_id']) ? $link['product_id'] : 0);
            $cost = isset($link['supplier_unit_cost']) ? $link['supplier_unit_cost'] : '0';
            if ($pid <= 0 || !$this->activeProduct($pid) || !validateNonNegativeDecimal($cost)) {
                sendResponse(false, 'Invalid product or cost value.', null, 400);
            }
            $validLinks[$pid] = (string)$cost;
        }

        $stmt = $this->conn->prepare("SELECT product_id, supplier_unit_cost FROM product_supplier WHERE supplier_id = :sid");
        $stmt->bindParam(':sid', $supplierId, PDO::PARAM_INT);
        $stmt->execute();
        $existing = array();
        while ($row = $stmt->fetch()) {
            $existing[(int)$row['product_id']] = $row['supplier_unit_cost'];
        }

        $this->conn->beginTransaction();
        try {
            foreach ($existing as $pid => $cost) {
                if (!isset($validLinks[$pid])) {
                    $del = $this->conn->prepare("DELETE FROM product_supplier WHERE product_id = :p AND supplier_id = :s");
                    $del->bindParam(':p', $pid, PDO::PARAM_INT);
                    $del->bindParam(':s', $supplierId, PDO::PARAM_INT);
                    $del->execute();
                }
            }

            foreach ($validLinks as $pid => $cost) {
                if (isset($existing[$pid])) {
                    if ((string)$existing[$pid] !== $cost) {
                        $upd = $this->conn->prepare("UPDATE product_supplier SET supplier_unit_cost = :cost WHERE product_id = :p AND supplier_id = :s");
                        $upd->bindParam(':cost', $cost, PDO::PARAM_STR);
                        $upd->bindParam(':p', $pid, PDO::PARAM_INT);
                        $upd->bindParam(':s', $supplierId, PDO::PARAM_INT);
                        $upd->execute();
                    }
                } else {
                    $ins = $this->conn->prepare("INSERT INTO product_supplier (product_id, supplier_id, supplier_unit_cost) VALUES (:p, :s, :cost)");
                    $ins->bindParam(':p', $pid, PDO::PARAM_INT);
                    $ins->bindParam(':s', $supplierId, PDO::PARAM_INT);
                    $ins->bindParam(':cost', $cost, PDO::PARAM_STR);
                    $ins->execute();
                }
            }
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollBack();
            error_log('Bulk save failed: ' . $e->getMessage());
            sendResponse(false, 'Failed to save links.', null, 500);
        }

        auditLog($this->conn, $actor, 'BULK_SAVE', 'product_supplier', $supplierId, 'Bulk supplier links saved');
        sendResponse(true, 'Product-supplier links saved successfully.');
    }

    private function postSingle() {
        $actor = requirePermission('product_supplier', 'manage');
        requireCsrf();
        $b = getRequestBody();
        $p = validatePositiveInt(isset($b['product_id']) ? $b['product_id'] : 0);
        $s = validatePositiveInt(isset($b['supplier_id']) ? $b['supplier_id'] : 0);
        $cost = isset($b['supplier_unit_cost']) ? $b['supplier_unit_cost'] : '0';

        if ($p <= 0 || $s <= 0 || !$this->activeProduct($p) || !$this->activeSupplier($s) || !validateNonNegativeDecimal($cost) || $this->linkExists($p, $s)) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        try {
            $costStr = (string)$cost;
            $q = $this->conn->prepare("INSERT INTO product_supplier(product_id,supplier_id,supplier_unit_cost) VALUES(:p,:s,:cost)");
            $q->bindParam(':p', $p, PDO::PARAM_INT);
            $q->bindParam(':s', $s, PDO::PARAM_INT);
            $q->bindParam(':cost', $costStr, PDO::PARAM_STR);
            $q->execute();
        } catch (Throwable $e) {
            error_log('Product-supplier create failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 400);
        }

        auditLog($this->conn, $actor, 'CREATE', 'product_supplier', $p, 'Supplier linked to product');
        sendResponse(true, 'Supplier linked to product successfully.');
    }

    private function put() {
        $actor = requirePermission('product_supplier', 'manage');
        requireCsrf();
        $b = getRequestBody();
        $p = validatePositiveInt(isset($b['product_id']) ? $b['product_id'] : 0);
        $s = validatePositiveInt(isset($b['supplier_id']) ? $b['supplier_id'] : 0);
        $cost = isset($b['supplier_unit_cost']) ? $b['supplier_unit_cost'] : '0';

        if ($p <= 0 || $s <= 0 || !$this->activeProduct($p) || !$this->activeSupplier($s) || !validateNonNegativeDecimal($cost) || !$this->linkExists($p, $s)) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        $costStr = (string)$cost;
        $q = $this->conn->prepare("UPDATE product_supplier SET supplier_unit_cost=:cost WHERE product_id=:p AND supplier_id=:s");
        $q->bindParam(':cost', $costStr, PDO::PARAM_STR);
        $q->bindParam(':p', $p, PDO::PARAM_INT);
        $q->bindParam(':s', $s, PDO::PARAM_INT);
        $q->execute();

        auditLog($this->conn, $actor, 'MODIFY', 'product_supplier', $p, 'Supplier cost modified');
        sendResponse(true, 'Supplier cost updated successfully.');
    }

    private function delete() {
        $actor = requirePermission('product_supplier', 'manage');
        requireCsrf();
        $p = validatePositiveInt(isset($_GET['product_id']) ? $_GET['product_id'] : 0);
        $s = validatePositiveInt(isset($_GET['supplier_id']) ? $_GET['supplier_id'] : 0);

        if ($p <= 0 || $s <= 0) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        $q = $this->conn->prepare("DELETE FROM product_supplier WHERE product_id=:p AND supplier_id=:s");
        $q->bindParam(':p', $p, PDO::PARAM_INT);
        $q->bindParam(':s', $s, PDO::PARAM_INT);
        $q->execute();

        auditLog($this->conn, $actor, 'DELETE', 'product_supplier', $p, 'Supplier link removed');
        sendResponse(true, 'Supplier unlinked from product successfully.');
    }

    private function activeProduct($id) {
        $s = $this->conn->prepare("SELECT product_id FROM product WHERE product_id=:id AND is_active=1 LIMIT 1");
        $s->bindParam(':id', $id, PDO::PARAM_INT);
        $s->execute();
        return (bool)$s->fetch();
    }

    private function activeSupplier($id) {
        $s = $this->conn->prepare("SELECT supplier_id FROM supplier WHERE supplier_id=:id AND is_active=1 LIMIT 1");
        $s->bindParam(':id', $id, PDO::PARAM_INT);
        $s->execute();
        return (bool)$s->fetch();
    }

    private function linkExists($p, $s) {
        $q = $this->conn->prepare("SELECT product_id FROM product_supplier WHERE product_id=:p AND supplier_id=:s LIMIT 1");
        $q->bindParam(':p', $p, PDO::PARAM_INT);
        $q->bindParam(':s', $s, PDO::PARAM_INT);
        $q->execute();
        return (bool)$q->fetch();
    }

    private function getAllProductSuppliers() {
        $s = $this->conn->query("SELECT ps.product_id,p.product_name,ps.supplier_id,s.supplier_name,ps.supplier_unit_cost FROM product_supplier ps INNER JOIN product p ON ps.product_id=p.product_id INNER JOIN supplier s ON ps.supplier_id=s.supplier_id ORDER BY p.product_name ASC,s.supplier_name ASC");
        return $s->fetchAll();
    }

    private function getProductsForSupplier($supplierId) {
        $stmt = $this->conn->prepare(
            "SELECT p.product_id, p.product_name, p.unit_price,
                    CASE WHEN ps.supplier_id IS NOT NULL THEN 1 ELSE 0 END AS is_linked,
                    COALESCE(ps.supplier_unit_cost, p.unit_price) AS supplier_unit_cost
             FROM product p
             LEFT JOIN product_supplier ps ON p.product_id = ps.product_id AND ps.supplier_id = :sid
             WHERE p.is_active = 1
             ORDER BY p.product_name ASC"
        );
        $stmt->bindParam(':sid', $supplierId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

$conn = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

$api = new ProductSupplierApi($conn);
$api->handleRequest($method, $action);

