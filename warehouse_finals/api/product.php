<?php
require_once '../config/db.php';
sendJsonHeaders();

class ProductApi {
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
        requirePermission(array('product', 'product_supplier', 'purchase_order', 'stock_receiving', 'stock_releasing', 'purchase_return', 'stocks_return', 'adjustment'), 'view');
        $mode = isset($_GET['mode']) ? (string)$_GET['mode'] : 'all';
        sendResponse(true, 'Products retrieved', $mode === 'active' ? $this->getActiveProducts() : $this->getAllProducts());
    }

    private function post() {
        $actor = requirePermission('product', 'manage');
        requireCsrf();
        $body = getRequestBody();
        $categoryId = validatePositiveInt(isset($body['category_id']) ? $body['category_id'] : 0);
        $name = isset($body['product_name']) && is_string($body['product_name']) ? trim($body['product_name']) : '';
        $unit = isset($body['unit_measure']) && is_string($body['unit_measure']) ? trim($body['unit_measure']) : '';
        $price = isset($body['unit_price']) ? $body['unit_price'] : '0';
        $reorder = validateNonNegativeInt(isset($body['reorder_level']) ? $body['reorder_level'] : 0);

        if ($categoryId <= 0 || !$this->activeCategoryExists($categoryId) || !validateName($name, 150)
            || !validateName($unit, 30) || !validateNonNegativeDecimal($price) || $reorder === null) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        try {
            $priceStr = (string)$price;
            $stmt = $this->conn->prepare("INSERT INTO product (category_id, product_name, unit_measure, unit_price, reorder_level) VALUES (:category_id, :name, :unit, :price, :reorder)");
            $stmt->bindParam(':category_id', $categoryId, PDO::PARAM_INT);
            $stmt->bindParam(':name', $name, PDO::PARAM_STR);
            $stmt->bindParam(':unit', $unit, PDO::PARAM_STR);
            $stmt->bindParam(':price', $priceStr, PDO::PARAM_STR);
            $stmt->bindParam(':reorder', $reorder, PDO::PARAM_INT);
            $stmt->execute();
            $id = (int)$this->conn->lastInsertId();
        } catch (Throwable $e) {
            error_log('Product creation failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 400);
        }

        auditLog($this->conn, $actor, 'CREATE', 'product', $id, 'Created product ' . $name);
        sendResponse(true, 'Product added successfully.');
    }

    private function put() {
        $actor = requirePermission('product', 'manage');
        requireCsrf();
        $body = getRequestBody();
        $id = validatePositiveInt(isset($body['product_id']) ? $body['product_id'] : 0);
        $categoryId = validatePositiveInt(isset($body['category_id']) ? $body['category_id'] : 0);
        $name = isset($body['product_name']) && is_string($body['product_name']) ? trim($body['product_name']) : '';
        $unit = isset($body['unit_measure']) && is_string($body['unit_measure']) ? trim($body['unit_measure']) : '';
        $price = isset($body['unit_price']) ? $body['unit_price'] : '0';
        $reorder = validateNonNegativeInt(isset($body['reorder_level']) ? $body['reorder_level'] : 0);

        if ($id <= 0 || !$this->productExists($id) || $categoryId <= 0 || !$this->activeCategoryExists($categoryId)
            || !validateName($name, 150) || !validateName($unit, 30) || !validateNonNegativeDecimal($price) || $reorder === null) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        try {
            $priceStr = (string)$price;
            $stmt = $this->conn->prepare("UPDATE product SET category_id = :category_id, product_name = :name, unit_measure = :unit, unit_price = :price, reorder_level = :reorder WHERE product_id = :id");
            $stmt->bindParam(':category_id', $categoryId, PDO::PARAM_INT);
            $stmt->bindParam(':name', $name, PDO::PARAM_STR);
            $stmt->bindParam(':unit', $unit, PDO::PARAM_STR);
            $stmt->bindParam(':price', $priceStr, PDO::PARAM_STR);
            $stmt->bindParam(':reorder', $reorder, PDO::PARAM_INT);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (Throwable $e) {
            error_log('Product update failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 400);
        }

        auditLog($this->conn, $actor, 'UPDATE', 'product', $id, 'Updated product to ' . $name);
        sendResponse(true, 'Product updated successfully.');
    }

    private function delete() {
        $actor = requirePermission('product', 'manage');
        requireCsrf();
        $id = validatePositiveInt(isset($_GET['id']) ? $_GET['id'] : 0);
        $restore = isset($_GET['restore']) && (int)$_GET['restore'] === 1;

        if ($id <= 0 || !$this->productExists($id)) {
            sendResponse(false, 'Request failed.', null, 404);
        }

        $status = $restore ? 1 : 0;
        $stmt = $this->conn->prepare("UPDATE product SET is_active = :status WHERE product_id = :id");
        $stmt->bindParam(':status', $status, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        auditLog($this->conn, $actor, $restore ? 'RESTORE' : 'DEACTIVATE', 'product', $id, 'Product status changed');
        sendResponse(true, $restore ? 'Product restored successfully.' : 'Product deactivated successfully.');
    }

    private function getAllProducts() {
        $stmt = $this->conn->query(
            "SELECT p.product_id, p.category_id, c.category_name, p.product_name, p.unit_measure,
                    p.unit_price, p.reorder_level, p.is_active, p.created_at
             FROM product p INNER JOIN category c ON p.category_id = c.category_id
             ORDER BY p.product_name ASC"
        );
        return $stmt->fetchAll();
    }

    private function getActiveProducts() {
        $stmt = $this->conn->query("SELECT product_id, product_name, unit_measure, unit_price FROM product WHERE is_active = 1 ORDER BY product_name ASC");
        return $stmt->fetchAll();
    }

    private function productExists($id) {
        $stmt = $this->conn->prepare("SELECT product_id FROM product WHERE product_id = :id LIMIT 1");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return (bool)$stmt->fetch();
    }

    private function activeCategoryExists($id) {
        $stmt = $this->conn->prepare("SELECT category_id FROM category WHERE category_id = :id AND is_active = 1 LIMIT 1");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return (bool)$stmt->fetch();
    }
}

$conn = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

$api = new ProductApi($conn);
$api->handleRequest($method);

