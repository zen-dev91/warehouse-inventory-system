<?php
require_once '../config/db.php';
sendJsonHeaders();

class PurchaseReturnApi {
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
        requirePermission('purchase_return', 'view');
        sendResponse(true, 'Purchase returns retrieved', $this->getAllPurchaseReturns());
    }

    private function post() {
        $actor = requirePermission('purchase_return', 'manage');
        requireCsrf();
        $b = getRequestBody();
        $supplierId = validatePositiveInt(isset($b['supplier_id']) ? $b['supplier_id'] : 0);
        $date = isset($b['return_date']) ? $b['return_date'] : date('Y-m-d');
        $reason = isset($b['reason']) && is_string($b['reason']) ? trim($b['reason']) : '';
        $items = ensureItemList(isset($b['items']) ? $b['items'] : array(), 'quantity_returned');

        if ($supplierId <= 0 || !validateDateValue($date) || strlen($reason) > 255 || $items === false) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        try {
            $id = $this->createPurchaseReturn($supplierId, $date, $reason, $items, $actor);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage() === 'insufficient stock'
                ? 'Insufficient stock on hand to return the requested quantity.'
                : 'Invalid product selected.';
            sendResponse(false, $msg, null, 409);
        } catch (Throwable $e) {
            error_log('Purchase return failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 500);
        }

        auditLog($this->conn, $actor, 'PURCHASE_RETURN', 'purchase_return', $id, 'Purchase return recorded');
        sendResponse(true, 'Purchase return recorded successfully.');
    }

    private function getAllPurchaseReturns() {
        $s = $this->conn->query("SELECT pr.purchase_return_id, s.supplier_name, CONCAT(u.first_name,' ',u.last_name) AS returned_by, pr.return_date, pr.reason, (SELECT COUNT(*) FROM purchase_return_detail d WHERE d.purchase_return_id=pr.purchase_return_id) AS item_count FROM purchase_return pr INNER JOIN supplier s ON pr.supplier_id=s.supplier_id INNER JOIN users u ON pr.user_id=u.user_id ORDER BY pr.return_date DESC, pr.purchase_return_id DESC");
        return $s->fetchAll();
    }

    private function createPurchaseReturn($supplierId, $date, $reason, $items, $user) {
        $this->conn->beginTransaction();
        try {
            $q = $this->conn->prepare("SELECT supplier_id FROM supplier WHERE supplier_id=:id AND is_active=1 FOR UPDATE");
            $q->bindParam(':id', $supplierId, PDO::PARAM_INT);
            $q->execute();
            if (!$q->fetch()) {
                throw new RuntimeException('invalid supplier');
            }

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

            $q = $this->conn->prepare("INSERT INTO purchase_return(supplier_id, user_id, return_date, reason) VALUES(:s, :u, :d, :r)");
            $userId = (int)$user['user_id'];
            $q->bindParam(':s', $supplierId, PDO::PARAM_INT);
            $q->bindParam(':u', $userId, PDO::PARAM_INT);
            $q->bindParam(':d', $date, PDO::PARAM_STR);
            $q->bindParam(':r', $reason, PDO::PARAM_STR);
            $q->execute();
            $id = (int)$this->conn->lastInsertId();

            $itemPid = 0;
            $itemQty = 0;
            $d = $this->conn->prepare("INSERT INTO purchase_return_detail(purchase_return_id, product_id, quantity_returned) VALUES(:r, :p, :q)");
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

$api = new PurchaseReturnApi($conn);
$api->handleRequest($method);

