<?php
/**
 * StyleHub - Customer Returns API
 * Allows customers to request returns on delivered orders, protected by CSRF.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/notifications.php';

Auth::requireCustomer(true);
$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    CSRF::enforce();

    $rawInput = file_get_contents('php://input');
    $data = [];
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) $data = $decoded;
    }
    if (empty($data)) $data = $_POST;

    $orderId = isset($data['order_id']) ? (int)$data['order_id'] : 0;
    $reason  = trim((string)($data['reason'] ?? ''));
    $details = trim((string)($data['details'] ?? ''));

    if ($orderId <= 0) {
        ApiResponse::error('Order ID is required.', 422);
    }
    if (empty($reason)) {
        ApiResponse::error('Please select a reason for the return request.', 422);
    }

    try {
        // Verify order exists, belongs to user, and is eligible
        $stmt = $pdo->prepare("SELECT id, order_number, status FROM `orders` WHERE id = :oid AND user_id = :uid LIMIT 1");
        $stmt->execute([':oid' => $orderId, ':uid' => $userId]);
        $order = $stmt->fetch();

        if (!$order) {
            ApiResponse::error('Order not found or unauthorized.', 404);
        }

        if ($order['status'] !== 'Delivered') {
            ApiResponse::error('Only orders that have been marked as Delivered are eligible for returns.', 400);
        }

        // Check if return request already exists
        $chk = $pdo->prepare("SELECT id FROM `returns` WHERE order_id = :oid LIMIT 1");
        $chk->execute([':oid' => $orderId]);
        if ($chk->fetch()) {
            ApiResponse::error('A return request has already been logged for this order.', 409);
        }

        $ins = $pdo->prepare("
            INSERT INTO `returns` (`order_id`, `user_id`, `reason`, `details`, `status`)
            VALUES (:oid, :uid, :reason, :details, 'Pending')
        ");
        $ins->execute([
            ':oid'     => $orderId,
            ':uid'     => $userId,
            ':reason'  => $reason,
            ':details' => $details ?: null
        ]);
        $newReturnId = (int)$pdo->lastInsertId();

        // Determine notification type based on reason & details
        $lowerText = strtolower($reason . ' ' . $details);
        $notifType = 'return_requested';
        $notifTitle = 'Return Request Submitted';
        if (str_contains($lowerText, 'exchange') || str_contains($lowerText, 'replacement')) {
            $notifType = 'replacement_requested';
            $notifTitle = 'Replacement Request Submitted';
        } elseif (str_contains($lowerText, 'refund')) {
            $notifType = 'refund_requested';
            $notifTitle = 'Refund Request Submitted';
        }

        AdminNotification::create([
            'notification_type' => $notifType,
            'title'             => $notifTitle,
            'message'           => "{$notifTitle} for order #{$order['order_number']}: {$reason}.",
            'related_user_id'   => $userId,
            'related_order_id'  => $orderId,
            'related_return_id' => $newReturnId
        ], $pdo);

        ApiResponse::success('Your return request has been submitted. Our concierge team will review it shortly.', [
            'return_id' => $newReturnId
        ], 201);

    } catch (Exception $e) {
        error_log("Return Request Error: " . $e->getMessage());
        ApiResponse::error('Failed to submit return request.', 500);
    }
} elseif ($method === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                r.id, r.order_id, r.reason, r.details, r.status, r.admin_notes, r.created_at,
                o.order_number, o.total_amount
            FROM `returns` r
            JOIN `orders` o ON r.order_id = o.id
            WHERE r.user_id = :uid
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([':uid' => $userId]);
        $returns = $stmt->fetchAll();

        ApiResponse::success('Return requests retrieved', [
            'returns' => $returns
        ]);
    } catch (Exception $e) {
        ApiResponse::error('Failed to retrieve returns.', 500);
    }
} else {
    ApiResponse::error('Method Not Allowed', 405);
}

