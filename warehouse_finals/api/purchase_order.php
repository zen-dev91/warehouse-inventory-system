<?php
require_once '../config/db.php';
sendJsonHeaders();

class PurchaseOrderApi {
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
            default:
                sendResponse(false, 'Request failed.', null, 405);
                break;
        }
    }

    private function get() {
        $mode = isset($_GET['mode']) ? (string)$_GET['mode'] : 'all';
        if ($mode === 'pending' || $mode === 'detail') {
            requirePermission(array('purchase_order', 'stock_receiving'), 'view');
        } else {
            requirePermission('purchase_order', 'view');
        }

        switch ($mode) {
            case 'pending':
                sendResponse(true, 'Pending purchase orders retrieved', $this->getPendingPurchaseOrders());
                break;
            case 'detail':
                $id = validatePositiveInt(isset($_GET['po_id']) ? $_GET['po_id'] : 0);
                if ($id <= 0) {
                    sendResponse(false, 'Request failed.', null, 400);
                }
                sendResponse(true, 'Purchase order items retrieved', $this->getPurchaseOrderDetails($id));
                break;
            case 'all':
            default:
                sendResponse(true, 'Purchase orders retrieved', $this->getAllPurchaseOrders());
                break;
        }
    }

    private function post() {
        $actor = requirePermission('purchase_order', 'manage');
        requireCsrf();
        $b = getRequestBody();
        $supplierId = validatePositiveInt(isset($b['supplier_id']) ? $b['supplier_id'] : 0);
        $date = isset($b['order_date']) ? $b['order_date'] : date('Y-m-d');
        $normalized = ensureItemList(isset($b['items']) ? $b['items'] : array(), 'quantity_ordered');

        if ($supplierId <= 0 || !validateDateValue($date) || $normalized === false) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        $items = array();
        foreach ($b['items'] as $rawItem) {
            $pid = validatePositiveInt(isset($rawItem['product_id']) ? $rawItem['product_id'] : 0);
            $cost = isset($rawItem['unit_cost']) ? $rawItem['unit_cost'] : '-1';
            if ($pid <= 0 || !isset($normalized[$pid]) || !validateNonNegativeDecimal($cost)) {
                sendResponse(false, 'Request failed.', null, 400);
            }
            if (!isset($items[$pid])) {
                $items[$pid] = array('quantity_ordered' => $normalized[$pid], 'unit_cost' => (string)$cost);
            }
        }

        if (!$this->activeSupplierExists($supplierId)) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        try {
            $id = $this->createPurchaseOrder($supplierId, $date, $items, $actor);
        } catch (Throwable $e) {
            error_log('PO create failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 400);
        }

        auditLog($this->conn, $actor, 'PURCHASE_ORDER', 'purchase_order', $id, 'Purchase order created');
        sendResponse(true, 'Purchase order created successfully.');
    }

    private function put() {
        $actor = requirePermission('purchase_order', 'manage');
        requireCsrf();
        $b = getRequestBody();
        $id = validatePositiveInt(isset($b['po_id']) ? $b['po_id'] : 0);
        $status = isset($b['status']) && is_string($b['status']) ? $b['status'] : '';

        if ($id <= 0 || $status !== 'Cancelled') {
            sendResponse(false, 'Request failed.', null, 400);
        }

        $this->conn->beginTransaction();
        try {
            $q = $this->conn->prepare("SELECT status FROM purchase_order WHERE po_id=:id FOR UPDATE");
            $q->bindParam(':id', $id, PDO::PARAM_INT);
            $q->execute();
            $row = $q->fetch();
            if (!$row || $row['status'] !== 'Pending') {
                throw new RuntimeException('invalid status');
            }
            $q = $this->conn->prepare("UPDATE purchase_order SET status='Cancelled' WHERE po_id=:id");
            $q->bindParam(':id', $id, PDO::PARAM_INT);
            $q->execute();
            $this->conn->commit();
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            sendResponse(false, 'Request failed.', null, 409);
        }

        auditLog($this->conn, $actor, 'PURCHASE_ORDER_CANCEL', 'purchase_order', $id, 'Purchase order cancelled');
        sendResponse(true, 'Purchase order status updated.');
    }

    private function activeSupplierExists($id) {
        $s = $this->conn->prepare("SELECT supplier_id FROM supplier WHERE supplier_id=:id AND is_active=1 LIMIT 1");
        $s->bindParam(':id', $id, PDO::PARAM_INT);
        $s->execute();
        return (bool)$s->fetch();
    }

    private function getAllPurchaseOrders() {
        $s = $this->conn->query(
            "SELECT po.po_id, po.supplier_id, s.supplier_name, CONCAT(u.first_name,' ',u.last_name) AS ordered_by,
                    po.order_date, po.status,
                    (SELECT COUNT(*) FROM purchase_order_detail d WHERE d.po_id=po.po_id) AS item_count,
                    (SELECT SUM(quantity_ordered*unit_cost) FROM purchase_order_detail d WHERE d.po_id=po.po_id) AS total_cost
             FROM purchase_order po
             INNER JOIN supplier s ON po.supplier_id=s.supplier_id
             INNER JOIN users u ON po.user_id=u.user_id
             ORDER BY po.order_date DESC, po.po_id DESC"
        );
        return $s->fetchAll();
    }

    private function getPendingPurchaseOrders() {
        $s = $this->conn->query(
            "SELECT po.po_id, s.supplier_name, po.order_date
             FROM purchase_order po
             INNER JOIN supplier s ON po.supplier_id=s.supplier_id
             WHERE po.status='Pending'
             ORDER BY po.order_date ASC"
        );
        return $s->fetchAll();
    }

    private function getPurchaseOrderDetails($id) {
        $s = $this->conn->prepare(
            "SELECT d.product_id, p.product_name, d.quantity_ordered, d.unit_cost
             FROM purchase_order_detail d
             INNER JOIN product p ON d.product_id=p.product_id
             WHERE d.po_id=:id"
        );
        $s->bindParam(':id', $id, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    private function createPurchaseOrder($supplierId, $orderDate, $items, $user) {
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
            foreach ($items as $pid => $item) {
                $chkPid = (int)$pid;
                $chk->execute();
                if (!$chk->fetch()) {
                    throw new RuntimeException('invalid product');
                }
            }

            $q = $this->conn->prepare("INSERT INTO purchase_order(supplier_id, user_id, order_date, status) VALUES(:s, :u, :d, 'Pending')");
            $userId = (int)$user['user_id'];
            $q->bindParam(':s', $supplierId, PDO::PARAM_INT);
            $q->bindParam(':u', $userId, PDO::PARAM_INT);
            $q->bindParam(':d', $orderDate, PDO::PARAM_STR);
            $q->execute();
            $id = (int)$this->conn->lastInsertId();

            $itemPid = 0;
            $itemQty = 0;
            $itemCost = '0';
            $d = $this->conn->prepare("INSERT INTO purchase_order_detail(po_id, product_id, quantity_ordered, unit_cost) VALUES(:po, :p, :q, :c)");
            $d->bindParam(':po', $id, PDO::PARAM_INT);
            $d->bindParam(':p', $itemPid, PDO::PARAM_INT);
            $d->bindParam(':q', $itemQty, PDO::PARAM_INT);
            $d->bindParam(':c', $itemCost, PDO::PARAM_STR);
            foreach ($items as $pid => $item) {
                $itemPid = (int)$pid;
                $itemQty = (int)$item['quantity_ordered'];
                $itemCost = (string)$item['unit_cost'];
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

$api = new PurchaseOrderApi($conn);
$api->handleRequest($method);

