<?php
require_once '../config/db.php';
sendJsonHeaders();

class StockReleasingApi {
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
            default:
                sendResponse(false, 'Request failed.', null, 405);
                break;
        }
    }

    private function get() {
        requirePermission('stock_releasing', 'view');
        sendResponse(true, 'Stock releasing records retrieved', $this->getAllStockReleasing());
    }

    private function post() {
        $actor = requirePermission('stock_releasing', 'manage');
        requireCsrf();
        $b = getRequestBody();
        $requested = isset($b['requested_by']) && is_string($b['requested_by']) ? trim($b['requested_by']) : '';
        $date = isset($b['releasing_date']) ? $b['releasing_date'] : date('Y-m-d');
        $items = ensureItemList(isset($b['items']) ? $b['items'] : array(), 'quantity_released');

        if (!validateName($requested, 100) || !validateDateValue($date) || $items === false) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        try {
            $id = $this->createStockReleasing($requested, $date, $items, $actor);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage() === 'insufficient stock'
                ? 'Insufficient stock on hand to release the requested quantity.'
                : 'Invalid product selected.';
            sendResponse(false, $msg, null, 409);
        } catch (Throwable $e) {
            error_log('Stock releasing failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 500);
        }

        auditLog($this->conn, $actor, 'STOCK_RELEASING', 'stock_releasing', $id, 'Stock releasing recorded');
        sendResponse(true, 'Stock releasing recorded successfully.');
    }

    private function getAllStockReleasing() {
        $s = $this->conn->query("SELECT r.releasing_id, r.requested_by, CONCAT(u.first_name,' ',u.last_name) AS released_by, r.releasing_date, (SELECT COUNT(*) FROM stock_releasing_detail d WHERE d.releasing_id=r.releasing_id) AS item_count FROM stock_releasing r INNER JOIN users u ON r.user_id=u.user_id ORDER BY r.releasing_date DESC, r.releasing_id DESC");
        return $s->fetchAll();
    }

    private function createStockReleasing($requestedBy, $date, $items, $user) {
        $this->conn->beginTransaction();
        try {
            $chkPid = 0;
            $chk = $this->conn->prepare("SELECT product_id FROM product WHERE product_id=:id AND is_active=1 FOR UPDATE");
            $chk->bindParam(':id', $chkPid, PDO::PARAM_INT);
            foreach ($items as $pid => $qty) {
                $chkPid = (int)$pid;
                $chk->execute();
                if (!$chk->fetch()) {
                    throw new RuntimeException('invalid product');
                }
                $stock = productStock($this->conn, $pid);
                if ($stock === null || $qty > $stock) {
                    throw new RuntimeException('insufficient stock');
                }
            }

            $q = $this->conn->prepare("INSERT INTO stock_releasing(requested_by, user_id, releasing_date) VALUES(:requested, :u, :d)");
            $userId = (int)$user['user_id'];
            $q->bindParam(':requested', $requestedBy, PDO::PARAM_STR);
            $q->bindParam(':u', $userId, PDO::PARAM_INT);
            $q->bindParam(':d', $date, PDO::PARAM_STR);
            $q->execute();
            $id = (int)$this->conn->lastInsertId();

            $itemPid = 0;
            $itemQty = 0;
            $d = $this->conn->prepare("INSERT INTO stock_releasing_detail(releasing_id, product_id, quantity_released) VALUES(:r, :p, :q)");
            $d->bindParam(':r', $id, PDO::PARAM_INT);
            $d->bindParam(':p', $itemPid, PDO::PARAM_INT);
            $d->bindParam(':q', $itemQty, PDO::PARAM_INT);
            foreach ($items as $pid => $qty) {
                $itemPid = (int)$pid;
                $itemQty = (int)$qty;
                $d->execute();
            }

            $this->conn->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }
}

$conn = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

$api = new StockReleasingApi($conn);
$api->handleRequest($method);

