<?php
/**
 * StyleHub - Admin Orders Management API
 * View all orders, inspect order items and destinations, change order statuses,
 * and assign dispatch dates.
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

// 1. GET: Orders List or Order Detail
if ($method === 'GET') {
    $orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $search = trim((string)($_GET['search'] ?? ''));

    try {
        if ($orderId > 0) {
            $stmt = $pdo->prepare("
                SELECT 
                    o.*,
                    u.name AS customer_name,
                    u.email AS customer_email,
                    u.phone AS customer_phone,
                    a.full_name AS shipping_name,
                    a.phone AS shipping_phone,
                    a.address_line1,
                    a.address_line2,
                    a.city,
                    a.state,
                    a.postal_code,
                    a.country
                FROM `orders` o
                LEFT JOIN `users` u ON o.user_id = u.id
                LEFT JOIN `addresses` a ON o.address_id = a.id
                WHERE o.id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                ApiResponse::error('Order not found.', 404);
            }

            // Order Items
            $itemStmt = $pdo->prepare("
                SELECT oi.*, img.image_url
                FROM `order_items` oi
                LEFT JOIN (
                    SELECT product_id, image_url FROM `product_images` WHERE is_primary = 1
                ) img ON oi.product_id = img.product_id
                WHERE oi.order_id = :oid
            ");
            $itemStmt->execute([':oid' => $orderId]);
            $items = $itemStmt->fetchAll();

            // Return request if any
            $retStmt = $pdo->prepare("SELECT * FROM `returns` WHERE order_id = :oid LIMIT 1");
            $retStmt->execute([':oid' => $orderId]);
            $returnReq = $retStmt->fetch();

            ApiResponse::success('Order details retrieved for admin', [
                'order'          => $order,
                'items'          => $items,
                'return_request' => $returnReq ?: null
            ]);
        }

        // List Orders
        $sql = "
            SELECT 
                o.id, o.order_number, o.user_id, o.total_amount, o.status,
                o.payment_method, o.dispatch_date, o.created_at,
                COALESCE(u.name, a.full_name, 'Guest Customer') AS customer_name,
                COALESCE(u.email, 'guest@stylehub.local') AS customer_email,
                COUNT(oi.id) AS item_count,
                r.id AS return_id,
                r.status AS return_status
            FROM `orders` o
            LEFT JOIN `users` u ON o.user_id = u.id
            LEFT JOIN `addresses` a ON o.address_id = a.id
            LEFT JOIN `order_items` oi ON o.id = oi.order_id
            LEFT JOIN `returns` r ON o.id = r.order_id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($statusFilter)) {
            $sql .= " AND o.status = :status";
            $params[':status'] = $statusFilter;
        }

        if (!empty($search)) {
            $sql .= " AND (o.order_number LIKE :s1 OR u.name LIKE :s2 OR u.email LIKE :s3)";
            $params[':s1'] = "%{$search}%";
            $params[':s2'] = "%{$search}%";
            $params[':s3'] = "%{$search}%";
        }

        $sql .= " GROUP BY o.id ORDER BY o.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll();

        ApiResponse::success('Orders list retrieved', [
            'count'  => count($orders),
            'orders' => $orders
        ]);

    } catch (Exception $e) {
        error_log("Admin Orders GET Error: " . $e->getMessage());
        ApiResponse::error('Failed to retrieve orders.', 500);
    }
}

// 2. POST: Status Update & Dispatch Date Setting
if ($method === 'POST') {
    CSRF::enforce();

    $rawInput = file_get_contents('php://input');
    $data = [];
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) $data = $decoded;
    }
    if (empty($data)) $data = $_POST;

    $orderId      = (int)($data['order_id'] ?? 0);
    $status       = trim((string)($data['status'] ?? ''));
    $dispatchDate = trim((string)($data['dispatch_date'] ?? ''));

    $validStatuses = ['Pending', 'In Queue', 'Dispatched', 'Delivered', 'Returned'];

    if ($orderId <= 0 || !in_array($status, $validStatuses, true)) {
        ApiResponse::error('Valid Order ID and recognized status required.', 422);
    }

    try {
        // Fetch current status and order info
        $chkOrder = $pdo->prepare("SELECT id, order_number, user_id, status FROM `orders` WHERE id = :id LIMIT 1");
        $chkOrder->execute([':id' => $orderId]);
        $orderRow = $chkOrder->fetch();

        if (!$orderRow) {
            ApiResponse::error('Order not found.', 404);
        }

        $currentStatus = $orderRow['status'];
        $orderNumber   = $orderRow['order_number'];
        $orderUserId   = $orderRow['user_id'] ? (int)$orderRow['user_id'] : null;

        // Validate order status transitions
        // 1. Cannot mark Delivered before Dispatched
        if ($status === 'Delivered' && $currentStatus !== 'Dispatched' && $currentStatus !== 'Delivered') {
            ApiResponse::error('Invalid order status progression. Orders must be Dispatched before they can be marked as Delivered.', 422);
        }

        // If status is Dispatched and no dispatch_date passed, default to current timestamp
        if ($status === 'Dispatched' && empty($dispatchDate)) {
            $dispatchDate = date('Y-m-d H:i:s');
        }

        $stmt = $pdo->prepare("
            UPDATE `orders` SET
                status        = :status,
                dispatch_date = :dispatch
            WHERE id = :id
        ");
        $stmt->execute([
            ':status'   => $status,
            ':dispatch' => !empty($dispatchDate) ? $dispatchDate : null,
            ':id'       => $orderId
        ]);

        // Create Admin Notification on Status Transition
        if ($status !== $currentStatus) {
            $notifType = 'order_status';
            $notifTitle = "Order {$status}";
            $notifMsg = "Order #{$orderNumber} status was updated to {$status}.";

            if ($status === 'In Queue') {
                $notifType = 'order_queued';
                $notifTitle = 'Order Entered Queue';
                $notifMsg = "Order #{$orderNumber} has entered the fulfillment queue.";
            } elseif ($status === 'Dispatched') {
                $notifType = 'order_dispatched';
                $notifTitle = 'Order Dispatched';
                $notifMsg = "Order #{$orderNumber} has been dispatched.";
            } elseif ($status === 'Delivered') {
                $notifType = 'order_delivered';
                $notifTitle = 'Order Delivered';
                $notifMsg = "Order #{$orderNumber} was successfully marked as delivered.";
            }

            AdminNotification::create([
                'notification_type' => $notifType,
                'title'             => $notifTitle,
                'message'           => $notifMsg,
                'related_user_id'   => $orderUserId,
                'related_order_id'  => $orderId
            ], $pdo);
        }

        ApiResponse::success("Order #{$orderId} status updated to {$status}.");

    } catch (Exception $e) {
        error_log("Admin Orders POST Error: " . $e->getMessage());
        ApiResponse::error('Failed to update order status.', 500);
    }
}

