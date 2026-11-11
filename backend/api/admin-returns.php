<?php
/**
 * StyleHub - Admin Returns Management API
 * View return requests, approve/reject returns, record admin notes,
 * update order status to Returned, and automatically restock product inventory upon approval.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/notifications.php';

Auth::requireAdmin(true);

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// 1. GET: Retrieve Returns List or Single Return Details
if ($method === 'GET') {
    $returnId     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $search       = trim((string)($_GET['search'] ?? ''));

    try {
        if ($returnId > 0) {
            $stmt = $pdo->prepare("
                SELECT 
                    r.*,
                    o.order_number,
                    o.total_amount,
                    o.status AS order_status,
                    o.payment_method,
                    u.name AS customer_name,
                    u.email AS customer_email,
                    u.phone AS customer_phone
                FROM `returns` r
                JOIN `orders` o ON r.order_id = o.id
                JOIN `users` u ON r.user_id = u.id
                WHERE r.id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $returnId]);
            $returnReq = $stmt->fetch();

            if (!$returnReq) {
                ApiResponse::error('Return request not found.', 404);
            }

            // Items in the returned order
            $itemStmt = $pdo->prepare("
                SELECT oi.*, img.image_url
                FROM `order_items` oi
                LEFT JOIN (
                    SELECT product_id, image_url FROM `product_images` WHERE is_primary = 1
                ) img ON oi.product_id = img.product_id
                WHERE oi.order_id = :oid
            ");
            $itemStmt->execute([':oid' => $returnReq['order_id']]);
            $items = $itemStmt->fetchAll();

            ApiResponse::success('Return request details loaded', [
                'return' => $returnReq,
                'items'  => $items
            ]);
        }

        // List all returns
        $sql = "
            SELECT 
                r.id, r.order_id, r.user_id, r.reason, r.details, r.status,
                r.admin_notes, r.created_at, r.updated_at,
                o.order_number, o.total_amount, o.status AS order_status,
                u.name AS customer_name, u.email AS customer_email
            FROM `returns` r
            JOIN `orders` o ON r.order_id = o.id
            JOIN `users` u ON r.user_id = u.id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($statusFilter)) {
            $sql .= " AND r.status = :status";
            $params[':status'] = $statusFilter;
        }

        if (!empty($search)) {
            $sql .= " AND (o.order_number LIKE :s1 OR u.name LIKE :s2 OR u.email LIKE :s3 OR r.reason LIKE :s4)";
            $params[':s1'] = "%{$search}%";
            $params[':s2'] = "%{$search}%";
            $params[':s3'] = "%{$search}%";
            $params[':s4'] = "%{$search}%";
        }

        $sql .= " ORDER BY r.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $returns = $stmt->fetchAll();

        ApiResponse::success('Return requests retrieved', [
            'count'   => count($returns),
            'returns' => $returns
        ]);

    } catch (Exception $e) {
        error_log("Admin Returns GET Error: " . $e->getMessage());
        ApiResponse::error('Failed to load return requests.', 500);
    }
}

// 2. POST: Process Return (Approve / Reject) with Inventory Restocking
if ($method === 'POST') {
    CSRF::enforce();

    $rawInput = file_get_contents('php://input');
    $data = [];
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) $data = $decoded;
    }
    if (empty($data)) $data = $_POST;

    $action     = trim((string)($data['action'] ?? 'process_return'));
    $returnId   = (int)($data['return_id'] ?? 0);
    $decision   = trim((string)($data['decision'] ?? ''));
    $adminNotes = trim((string)($data['admin_notes'] ?? ''));

    if ($returnId <= 0 || !in_array($decision, ['Approved', 'Rejected'], true)) {
        ApiResponse::error('Valid return ID and decision (Approved or Rejected) required.', 422);
    }

    try {
        $pdo->beginTransaction();

        // 1. Get return request & current status
        $chkStmt = $pdo->prepare("SELECT id, order_id, user_id, status FROM `returns` WHERE id = :id LIMIT 1");
        $chkStmt->execute([':id' => $returnId]);
        $returnReq = $chkStmt->fetch();

        if (!$returnReq) {
            $pdo->rollBack();
            ApiResponse::error('Return request not found.', 404);
        }

        $orderId = (int)$returnReq['order_id'];
        $wasAlreadyApproved = ($returnReq['status'] === 'Approved');

        // 2. Update return request status & admin notes
        $updRet = $pdo->prepare("
            UPDATE `returns` SET
                status = :status,
                admin_notes = :notes,
                updated_at = NOW()
            WHERE id = :id
        ");
        $updRet->execute([
            ':status' => $decision,
            ':notes'  => $adminNotes ?: null,
            ':id'     => $returnId
        ]);

        if ($decision === 'Approved') {
            // Update order status to Returned
            $updOrder = $pdo->prepare("UPDATE `orders` SET status = 'Returned' WHERE id = :oid");
            $updOrder->execute([':oid' => $orderId]);

            // Only replenish stock if not already approved previously
            if (!$wasAlreadyApproved) {
                // Fetch order items to replenish
                $itemsStmt = $pdo->prepare("SELECT product_id, colour_name, size_name, quantity FROM `order_items` WHERE order_id = :oid");
                $itemsStmt->execute([':oid' => $orderId]);
                $orderItems = $itemsStmt->fetchAll();

                foreach ($orderItems as $item) {
                    $pid = (int)($item['product_id'] ?? 0);
                    $qty = (int)($item['quantity'] ?? 1);

                    if ($pid <= 0 || $qty <= 0) continue;

                    // Find colour_id if colour_name matches
                    $colourId = null;
                    if (!empty($item['colour_name'])) {
                        $cStmt = $pdo->prepare("SELECT id FROM `product_colours` WHERE product_id = :pid AND colour_name = :cname LIMIT 1");
                        $cStmt->execute([':pid' => $pid, ':cname' => $item['colour_name']]);
                        $colourId = $cStmt->fetchColumn() ?: null;
                    }

                    // Find size_id if size_name matches
                    $sizeId = null;
                    if (!empty($item['size_name'])) {
                        $sStmt = $pdo->prepare("SELECT id FROM `product_sizes` WHERE product_id = :pid AND size_name = :sname LIMIT 1");
                        $sStmt->execute([':pid' => $pid, ':sname' => $item['size_name']]);
                        $sizeId = $sStmt->fetchColumn() ?: null;
                    }

                    // Try to find matching inventory row
                    $invFound = false;
                    if ($colourId !== null || $sizeId !== null) {
                        $invStmt = $pdo->prepare("
                            SELECT id FROM `product_inventory` 
                            WHERE product_id = :pid 
                              AND (:cid IS NULL OR colour_id = :cid2)
                              AND (:sid IS NULL OR size_id = :sid2)
                            LIMIT 1
                        ");
                        $invStmt->execute([
                            ':pid'  => $pid,
                            ':cid'  => $colourId,
                            ':cid2' => $colourId,
                            ':sid'  => $sizeId,
                            ':sid2' => $sizeId
                        ]);
                        $invId = $invStmt->fetchColumn();
                        if ($invId) {
                            $pdo->prepare("UPDATE `product_inventory` SET stock_quantity = stock_quantity + :qty WHERE id = :id")
                                ->execute([':qty' => $qty, ':id' => $invId]);
                            $invFound = true;
                        }
                    }

                    // If no specific colour/size inventory match found, increment the first inventory row for this product
                    if (!$invFound) {
                        $firstInv = $pdo->prepare("SELECT id FROM `product_inventory` WHERE product_id = :pid ORDER BY id ASC LIMIT 1");
                        $firstInv->execute([':pid' => $pid]);
                        $fallbackId = $firstInv->fetchColumn();

                        if ($fallbackId) {
                            $pdo->prepare("UPDATE `product_inventory` SET stock_quantity = stock_quantity + :qty WHERE id = :id")
                                ->execute([':qty' => $qty, ':id' => $fallbackId]);
                        } else {
                            // Insert a new inventory row if none existed
                            $pdo->prepare("INSERT INTO `product_inventory` (`product_id`, `colour_id`, `size_id`, `stock_quantity`) VALUES (:pid, :cid, :sid, :qty)")
                                ->execute([':pid' => $pid, ':cid' => $colourId, ':sid' => $sizeId, ':qty' => $qty]);
                        }
                    }
                }
            }
        }

        $pdo->commit();

        // Admin Notification for Return / Refund / Replacement decision
        $lowerReason = strtolower($returnReq['reason'] . ' ' . ($returnReq['details'] ?? ''));
        $isRefund = str_contains($lowerReason, 'refund');
        $isReplacement = str_contains($lowerReason, 'exchange') || str_contains($lowerReason, 'replacement');

        if ($decision === 'Approved') {
            $notifType = $isRefund ? 'refund_approved' : ($isReplacement ? 'replacement_approved' : 'return_approved');
            $notifTitle = $isRefund ? 'Refund Approved' : ($isReplacement ? 'Replacement Approved' : 'Return Request Approved');
            $notifMsg = "Return request #{$returnId} for order #{$returnReq['order_number']} was approved and inventory restocked.";
        } else {
            $notifType = $isRefund ? 'refund_rejected' : ($isReplacement ? 'replacement_rejected' : 'return_rejected');
            $notifTitle = $isRefund ? 'Refund Rejected' : ($isReplacement ? 'Replacement Rejected' : 'Return Request Rejected');
            $notifMsg = "Return request #{$returnId} for order #{$returnReq['order_number']} was rejected.";
        }

        AdminNotification::create([
            'notification_type' => $notifType,
            'title'             => $notifTitle,
            'message'           => $notifMsg,
            'related_user_id'   => (int)$returnReq['user_id'],
            'related_order_id'  => (int)$returnReq['order_id'],
            'related_return_id' => $returnId
        ], $pdo);

        if (!empty($orderItems)) {
            foreach ($orderItems as $item) {
                if (!empty($item['product_id'])) {
                    AdminNotification::checkProductStock((int)$item['product_id'], $pdo);
                }
            }
        }

        ApiResponse::success(
            $decision === 'Approved'
                ? "Return request #{$returnId} has been approved, order marked as Returned, and inventory restocked."
                : "Return request #{$returnId} has been rejected."
        );

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Admin Returns Process Error: " . $e->getMessage());
        ApiResponse::error('Failed to process return request: ' . $e->getMessage(), 500);
    }
} else {
    ApiResponse::error('Method Not Allowed', 405);
}

