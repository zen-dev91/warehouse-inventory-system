<?php
require_once '../config/db.php';
sendJsonHeaders();

class CategoryApi {
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
        requirePermission(array('category', 'product'), 'view');
        $mode = isset($_GET['mode']) ? (string)$_GET['mode'] : 'all';
        sendResponse(true, 'Categories retrieved', $mode === 'active' ? $this->getActiveCategories() : $this->getAllCategories());
    }

    private function post() {
        $actor = requirePermission('category', 'manage');
        requireCsrf();
        $body = getRequestBody();
        $name = isset($body['category_name']) && is_string($body['category_name']) ? trim($body['category_name']) : '';

        if (!validateName($name, 100)) {
            sendResponse(false, 'Invalid category name.', null, 400);
        }

        $stmt = $this->conn->prepare("SELECT category_id FROM category WHERE LOWER(category_name) = LOWER(:name) LIMIT 1");
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->execute();
        if ($stmt->fetch()) {
            sendResponse(false, 'Category name already exists.', null, 409);
        }

        try {
            $stmt = $this->conn->prepare("INSERT INTO category (category_name) VALUES (:name)");
            $stmt->bindParam(':name', $name, PDO::PARAM_STR);
            $stmt->execute();
            $id = (int)$this->conn->lastInsertId();
        } catch (Throwable $e) {
            error_log('Category creation failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 400);
        }

        auditLog($this->conn, $actor, 'CREATE', 'category', $id, 'Created category ' . $name);
        sendResponse(true, 'Category added successfully.');
    }

    private function put() {
        $actor = requirePermission('category', 'manage');
        requireCsrf();
        $body = getRequestBody();
        $id = validatePositiveInt(isset($body['category_id']) ? $body['category_id'] : 0);
        $name = isset($body['category_name']) && is_string($body['category_name']) ? trim($body['category_name']) : '';

        if ($id <= 0 || !validateName($name, 100) || !$this->categoryExists($id)) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        $stmt = $this->conn->prepare("SELECT category_id FROM category WHERE LOWER(category_name) = LOWER(:name) AND category_id != :id LIMIT 1");
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->fetch()) {
            sendResponse(false, 'Category name already exists.', null, 409);
        }

        try {
            $stmt = $this->conn->prepare("UPDATE category SET category_name = :name WHERE category_id = :id");
            $stmt->bindParam(':name', $name, PDO::PARAM_STR);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (Throwable $e) {
            error_log('Category update failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 400);
        }

        auditLog($this->conn, $actor, 'UPDATE', 'category', $id, 'Updated category to ' . $name);
        sendResponse(true, 'Category updated successfully.');
    }

    private function delete() {
        $actor = requirePermission('category', 'manage');
        requireCsrf();
        $id = validatePositiveInt(isset($_GET['id']) ? $_GET['id'] : 0);
        $restore = isset($_GET['restore']) && (int)$_GET['restore'] === 1;

        if ($id <= 0 || !$this->categoryExists($id)) {
            sendResponse(false, 'Request failed.', null, 404);
        }

        $status = $restore ? 1 : 0;
        $stmt = $this->conn->prepare("UPDATE category SET is_active = :status WHERE category_id = :id");
        $stmt->bindParam(':status', $status, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        auditLog($this->conn, $actor, $restore ? 'RESTORE' : 'DEACTIVATE', 'category', $id, 'Category status changed');
        sendResponse(true, $restore ? 'Category restored successfully.' : 'Category deactivated successfully.');
    }

    private function getAllCategories() {
        $stmt = $this->conn->query("SELECT category_id, category_name, is_active, created_at FROM category ORDER BY category_name ASC");
        return $stmt->fetchAll();
    }

    private function getActiveCategories() {
        $stmt = $this->conn->query("SELECT category_id, category_name FROM category WHERE is_active = 1 ORDER BY category_name ASC");
        return $stmt->fetchAll();
    }

    private function categoryExists($id) {
        $stmt = $this->conn->prepare("SELECT category_id FROM category WHERE category_id = :id LIMIT 1");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return (bool)$stmt->fetch();
    }
}

$conn = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

$api = new CategoryApi($conn);
$api->handleRequest($method);

