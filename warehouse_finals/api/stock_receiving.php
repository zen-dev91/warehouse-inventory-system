<?php
require_once '../config/db.php';
sendJsonHeaders();

class StockReceivingApi {
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
        requirePermission('stock_receiving', 'view');

        if (isset($_GET['po_id'])) {
            $id = validatePositiveInt($_GET['po_id']);
            if ($id <= 0) {
                sendResponse(false, 'Request failed.', null, 400);
            }
            sendResponse(true, 'Purchase order items retrieved', $this->getPurchaseOrderItemsForReceiving($id));
        }

        sendResponse(true, 'Stock receiving records retrieved', $this->getAllStockReceiving());
    }

    private function post() {
        $actor = requirePermission('stock_receiving', 'manage');
        requireCsrf();
        $b = getRequestBody();
        $poId = validatePositiveInt(isset($b['po_id']) ? $b['po_id'] : 0);
        $date = isset($b['receiving_date']) ? $b['receiving_date'] : date('Y-m-d');
        $items = ensureItemList(isset($b['items']) ? $b['items'] : array(), 'quantity_received');

        if ($poId <= 0 || !validateDateValue($date) || $items === false) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        try {
            $id = $this->createStockReceiving($poId, $date, $items, $actor);
        } catch (RuntimeException $e) {
            $msg = 'Stock receiving failed: ' . $e->getMessage();
            sendResponse(false, $msg, null, 409);
        } catch (Throwable $e) {
            error_log('Stock receiving failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 500);
        }

        auditLog($this->conn, $actor, 'STOCK_RECEIVING', 'stock_receiving', $id, 'Stock receiving recorded');
        sendResponse(true, 'Stock receiving recorded successfully.');
    }

    private function getAllStockReceiving() {
        $s = $this->conn->query(
            "SELECT r.receiving_id, r.po_id, s.supplier_name, CONCAT(u.first_name,' ',u.last_name) AS received_by,
                    r.receiving_date,
                    (SELECT COUNT(*) FROM stock_receiving_detail d WHERE d.receiving_id=r.receiving_id) AS item_count
             FROM stock_receiving r
             INNER JOIN purchase_order po ON r.po_id=po.po_id
             INNER JOIN supplier s ON po.supplier_id=s.supplier_id
             INNER JOIN users u ON r.user_id=u.user_id
             ORDER BY r.receiving_date DESC, r.receiving_id DESC"
        );
        return $s->fetchAll();
    }

    private function getPurchaseOrderItemsForReceiving($poId) {
        $s = $this->conn->prepare(
            "SELECT d.product_id, p.product_name, d.quantity_ordered
             FROM purchase_order_detail d
             INNER JOIN product p ON d.product_id=p.product_id
             WHERE d.po_id=:id"
        );
        $s->bindParam(':id', $poId, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    private function createStockReceiving($poId, $date, $items, $user) {
        $this->conn->beginTransaction();
        try {
            $q = $this->conn->prepare("SELECT po_id, status FROM purchase_order WHERE po_id=:id FOR UPDATE");
            $q->bindParam(':id', $poId, PDO::PARAM_INT);
            $q->execute();
            $po = $q->fetch();
            if (!$po || $po['status'] !== 'Pending') {
                throw new RuntimeException('invalid PO');
            }

            $q = $this->conn->prepare("SELECT receiving_id FROM stock_receiving WHERE po_id=:id LIMIT 1");
            $q->bindParam(':id', $poId, PDO::PARAM_INT);
            $q->execute();
            if ($q->fetch()) {
                throw new RuntimeException('already received');
            }

            $q = $this->conn->prepare("SELECT product_id, quantity_ordered FROM purchase_order_detail WHERE po_id=:id");
            $q->bindParam(':id', $poId, PDO::PARAM_INT);
            $q->execute();
            $lines = $q->fetchAll();
            if (!$lines || count($lines) !== count($items)) {
                throw new RuntimeException('line mismatch');
            }

            $chkPid = 0;
            $chk = $this->conn->prepare("SELECT product_id FROM product WHERE product_id=:id FOR UPDATE");
            $chk->bindParam(':id', $chkPid, PDO::PARAM_INT);
            foreach ($lines as $line) {
                $pid = (int)$line['product_id'];
                if (!isset($items[$pid]) || (int)$items[$pid] !== (int)$line['quantity_ordered']) {
                    throw new RuntimeException('quantity mismatch');
                }
                $chkPid = $pid;
                $chk->execute();
                if (!$chk->fetch()) {
                    throw new RuntimeException('missing product');
                }
            }

            $q = $this->conn->prepare("INSERT INTO stock_receiving(po_id, user_id, receiving_date) VALUES(:po, :u, :d)");
            $userId = (int)$user['user_id'];
            $q->bindParam(':po', $poId, PDO::PARAM_INT);
            $q->bindParam(':u', $userId, PDO::PARAM_INT);
            $q->bindParam(':d', $date, PDO::PARAM_STR);
            $q->execute();
            $id = (int)$this->conn->lastInsertId();

            $itemPid = 0;
            $itemQty = 0;
            $d = $this->conn->prepare("INSERT INTO stock_receiving_detail(receiving_id, product_id, quantity_received) VALUES(:r, :p, :q)");
            $d->bindParam(':r', $id, PDO::PARAM_INT);
            $d->bindParam(':p', $itemPid, PDO::PARAM_INT);
            $d->bindParam(':q', $itemQty, PDO::PARAM_INT);
            foreach ($items as $pid => $qty) {
                $itemPid = (int)$pid;
                $itemQty = (int)$qty;
                $d->execute();
            }

            $q = $this->conn->prepare("UPDATE purchase_order SET status='Received' WHERE po_id=:id AND status='Pending'");
            $q->bindParam(':id', $poId, PDO::PARAM_INT);
            $q->execute();
            if ($q->rowCount() !== 1) {
                throw new RuntimeException('status update failed');
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

$api = new StockReceivingApi($conn);
$api->handleRequest($method);

