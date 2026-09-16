<?php
require_once '../config/db.php';
sendJsonHeaders();

class StocksReturnApi {
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
        requirePermission('stocks_return', 'view');
        sendResponse(true, 'Stocks returns retrieved', $this->getAllStocksReturns());
    }

    private function post() {
        $actor = requirePermission('stocks_return', 'manage');
        requireCsrf();
        $b = getRequestBody();
        $requested = isset($b['requested_by']) && is_string($b['requested_by']) ? trim($b['requested_by']) : '';
        $date = isset($b['return_date']) ? $b['return_date'] : date('Y-m-d');
        $reason = isset($b['reason']) && is_string($b['reason']) ? trim($b['reason']) : '';
        $items = ensureItemList(isset($b['items']) ? $b['items'] : array(), 'quantity_returned');

        if (!validateName($requested, 100) || !validateDateValue($date) || strlen($reason) > 255 || $items === false) {
            sendResponse(false, 'Request failed.', null, 400);
        }

        try {
            $id = $this->createStocksReturn($requested, $date, $reason, $items, $actor);
        } catch (Throwable $e) {
            error_log('Stocks return failed: ' . $e->getMessage());
            sendResponse(false, 'Request failed.', null, 409);
        }

        auditLog($this->conn, $actor, 'STOCKS_RETURN', 'stocks_return', $id, 'Stocks return recorded');
        sendResponse(true, 'Stocks return recorded successfully.');
    }

    private function getAllStocksReturns() {
        $s = $this->conn->query("SELECT sr.stocks_return_id, sr.requested_by, CONCAT(u.first_name,' ',u.last_name) AS processed_by, sr.return_date, sr.reason, (SELECT COUNT(*) FROM stocks_return_detail d WHERE d.stocks_return_id=sr.stocks_return_id) AS item_count FROM stocks_return sr INNER JOIN users u ON sr.user_id=u.user_id ORDER BY sr.return_date DESC, sr.stocks_return_id DESC");
        return $s->fetchAll();
    }

    private function createStocksReturn($requestedBy, $date, $reason, $items, $user) {
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
            }

            $q = $this->conn->prepare("INSERT INTO stocks_return(requested_by, user_id, return_date, reason) VALUES(:requested, :u, :d, :r)");
            $userId = (int)$user['user_id'];
            $q->bindParam(':requested', $requestedBy, PDO::PARAM_STR);
            $q->bindParam(':u', $userId, PDO::PARAM_INT);
            $q->bindParam(':d', $date, PDO::PARAM_STR);
            $q->bindParam(':r', $reason, PDO::PARAM_STR);
            $q->execute();
            $id = (int)$this->conn->lastInsertId();

            $itemPid = 0;
            $itemQty = 0;
            $d = $this->conn->prepare("INSERT INTO stocks_return_detail(stocks_return_id, product_id, quantity_returned) VALUES(:r, :p, :q)");
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

$api = new StocksReturnApi($conn);
$api->handleRequest($method);

