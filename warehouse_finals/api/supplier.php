<?php
require_once '../config/db.php';
sendJsonHeaders();

class SupplierApi {
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
        requirePermission(array('supplier', 'product_supplier', 'purchase_order', 'purchase_return'), 'view');
        $mode = isset($_GET['mode']) ? (string)$_GET['mode'] : 'all';
        sendResponse(true, 'Suppliers retrieved', $mode === 'active' ? $this->getActiveSuppliers() : $this->getAllSuppliers());
    }

    private function post() {
        $actor = requirePermission('supplier', 'manage');
        requireCsrf();
        $body = getRequestBody();
        $name = isset($body['supplier_name']) && is_string($body['supplier_name']) ? trim($body['supplier_name']) : '';
        $contact = isset($body['contact_person']) && is_string($body['contact_person']) ? trim($body['contact_person']) : '';
        $phone = isset($body['phone']) && is_string($body['phone']) ? trim($body['phone']) : '';
        $email = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        $address = isset($body['address']) && is_string($body['address']) ? trim($body['address']) : '';

        if (!validateName($name, 150) || strlen($contact) > 100 || !validateContact($phone) || !$this->validateEmailValue($email) || strlen($address) > 255) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        try {
            $stmt = $this->conn->prepare("INSERT INTO supplier (supplier_name, contact_person, phone, email, address) VALUES (:name, :contact, :phone, :email, :address)");
            $stmt->bindParam(':name', $name, PDO::PARAM_STR);
            $stmt->bindParam(':contact', $contact, PDO::PARAM_STR);
            $stmt->bindParam(':phone', $phone, PDO::PARAM_STR);
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->bindParam(':address', $address, PDO::PARAM_STR);
            $stmt->execute();
            $id = (int)$this->conn->lastInsertId();
        } catch (Throwable $e) {
            error_log('Supplier creation failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 400);
        }

        auditLog($this->conn, $actor, 'CREATE', 'supplier', $id, 'Created supplier ' . $name);
        sendResponse(true, 'Supplier added successfully.');
    }

    private function put() {
        $actor = requirePermission('supplier', 'manage');
        requireCsrf();
        $body = getRequestBody();
        $id = validatePositiveInt(isset($body['supplier_id']) ? $body['supplier_id'] : 0);
        $name = isset($body['supplier_name']) && is_string($body['supplier_name']) ? trim($body['supplier_name']) : '';
        $contact = isset($body['contact_person']) && is_string($body['contact_person']) ? trim($body['contact_person']) : '';
        $phone = isset($body['phone']) && is_string($body['phone']) ? trim($body['phone']) : '';
        $email = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        $address = isset($body['address']) && is_string($body['address']) ? trim($body['address']) : '';

        if ($id <= 0 || !$this->supplierExists($id) || !validateName($name, 150) || strlen($contact) > 100
            || !validateContact($phone) || !$this->validateEmailValue($email) || strlen($address) > 255) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        try {
            $stmt = $this->conn->prepare("UPDATE supplier SET supplier_name = :name, contact_person = :contact, phone = :phone, email = :email, address = :address WHERE supplier_id = :id");
            $stmt->bindParam(':name', $name, PDO::PARAM_STR);
            $stmt->bindParam(':contact', $contact, PDO::PARAM_STR);
            $stmt->bindParam(':phone', $phone, PDO::PARAM_STR);
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->bindParam(':address', $address, PDO::PARAM_STR);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (Throwable $e) {
            error_log('Supplier update failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 400);
        }

        auditLog($this->conn, $actor, 'UPDATE', 'supplier', $id, 'Updated supplier to ' . $name);
        sendResponse(true, 'Supplier updated successfully.');
    }

    private function delete() {
        $actor = requirePermission('supplier', 'manage');
        requireCsrf();
        $id = validatePositiveInt(isset($_GET['id']) ? $_GET['id'] : 0);
        $restore = isset($_GET['restore']) && (int)$_GET['restore'] === 1;

        if ($id <= 0 || !$this->supplierExists($id)) {
            sendResponse(false, 'Request failed.', null, 404);
        }

        $status = $restore ? 1 : 0;
        $stmt = $this->conn->prepare("UPDATE supplier SET is_active = :status WHERE supplier_id = :id");
        $stmt->bindParam(':status', $status, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        auditLog($this->conn, $actor, $restore ? 'RESTORE' : 'DEACTIVATE', 'supplier', $id, 'Supplier status changed');
        sendResponse(true, $restore ? 'Supplier restored successfully.' : 'Supplier deactivated successfully.');
    }

    private function getAllSuppliers() {
        $stmt = $this->conn->query("SELECT supplier_id, supplier_name, contact_person, phone, email, address, is_active, created_at FROM supplier ORDER BY supplier_name ASC");
        return $stmt->fetchAll();
    }

    private function getActiveSuppliers() {
        $stmt = $this->conn->query("SELECT supplier_id, supplier_name FROM supplier WHERE is_active = 1 ORDER BY supplier_name ASC");
        return $stmt->fetchAll();
    }

    private function supplierExists($id) {
        $stmt = $this->conn->prepare("SELECT supplier_id FROM supplier WHERE supplier_id = :id LIMIT 1");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return (bool)$stmt->fetch();
    }

    private function validateEmailValue($value) {
        return $value === '' || (is_string($value) && strlen($value) <= 100 && filter_var($value, FILTER_VALIDATE_EMAIL));
    }
}

$conn = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

$api = new SupplierApi($conn);
$api->handleRequest($method);

