<?php
require_once '../config/db.php';
sendJsonHeaders();

class AdjustmentApi {
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
        requirePermission('adjustment', 'view');
        sendResponse(true, 'Adjustments retrieved', $this->getAllAdjustments());
    }

    private function post() {
        $actor = requirePermission('adjustment', 'manage');
        requireCsrf();
        $b = getRequestBody();
        $date = isset($b['adjustment_date']) ? $b['adjustment_date'] : date('Y-m-d');
        $reason = isset($b['reason']) && is_string($b['reason']) ? trim($b['reason']) : '';
        $raw = isset($b['items']) ? $b['items'] : array();
        $norm = ensureItemList($raw, 'quantity');

        if (!validateDateValue($date) || strlen($reason) > 255 || $norm === false) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        $items = array();
        foreach ($raw as $it) {
            $pid = validatePositiveInt(isset($it['product_id']) ? $it['product_id'] : 0);
            $type = isset($it['adjustment_type']) && is_string($it['adjustment_type']) ? $it['adjustment_type'] : '';
            if ($pid <= 0 || !isset($norm[$pid]) || !in_array($type, array('Addition', 'Deduction'), true)) {
                sendResponse(false, 'Request failed.', null, 400);
            }
            if (!isset($items[$pid])) {
                $items[$pid] = array('quantity' => $norm[$pid], 'adjustment_type' => $type);
            } elseif ($items[$pid]['adjustment_type'] !== $type) {
                sendResponse(false, 'Request failed.', null, 400);
            }
        }

        try {
            $id = $this->createAdjustment($date, $reason, $items, $actor);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage() === 'insufficient stock'
                ? 'Cannot deduct more than current stock on hand.'
                : 'Invalid product selected.';
            sendResponse(false, $msg, null, 409);
        } catch (Throwable $e) {
            error_log('Adjustment failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 500);
        }

        auditLog($this->conn, $actor, 'INVENTORY_ADJUSTMENT', 'adjustment_detail', $id, 'Inventory adjustment recorded');
        sendResponse(true, 'Adjustment recorded successfully.');
    }

    private function getAllAdjustments() {
        $s = $this->conn->query("SELECT d.adjustment_detail_id, p.product_name, d.quantity, d.adjustment_type FROM adjustment_detail d INNER JOIN product p ON d.product_id=p.product_id ORDER BY d.adjustment_detail_id DESC");
        return $s->fetchAll();
    }

    private function createAdjustment($date, $reason, $items, $user) {
        $this->conn->beginTransaction();
        try {
            $chkPid = 0;
            $chk = $this->conn->prepare("SELECT product_id FROM product WHERE product_id=:id AND is_active=1 FOR UPDATE");
            $chk->bindParam(':id', $chkPid, PDO::PARAM_INT);
            foreach ($items as $pid => $item) {
                $chkPid = (int)$pid;
                $chk->execute();
                if (!$chk->fetch()) {
                    throw new RuntimeException('invalid product');
                }
                if ($item['adjustment_type'] === 'Deduction') {
                    $stock = productStock($this->conn, $pid);
                    if ($stock === null || $item['quantity'] > $stock) {
                        throw new RuntimeException('insufficient stock');
                    }
                }
            }

            $itemPid = 0;
            $itemQty = 0;
            $itemType = '';
            $d = $this->conn->prepare("INSERT INTO adjustment_detail(product_id, quantity, adjustment_type) VALUES(:p, :q, :t)");
            $d->bindParam(':p', $itemPid, PDO::PARAM_INT);
            $d->bindParam(':q', $itemQty, PDO::PARAM_INT);
            $d->bindParam(':t', $itemType, PDO::PARAM_STR);
            $lastId = 0;
            foreach ($items as $pid => $item) {
                $itemPid = (int)$pid;
                $itemQty = (int)$item['quantity'];
                $itemType = (string)$item['adjustment_type'];
                $d->execute();
                $lastId = (int)$this->conn->lastInsertId();
            }

            $this->conn->commit();
            return $lastId;
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

$api = new AdjustmentApi($conn);
$api->handleRequest($method);

