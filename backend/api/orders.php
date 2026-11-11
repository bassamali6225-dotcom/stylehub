<?php
/**
 * StyleHub - Orders & Order Tracking API
 * Fetches order history for logged-in customers, or individual order tracking details.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

CSRF::initSession();
$pdo = getDB();

$userId = $_SESSION['user_id'] ?? null;
$orderNumber = trim($_GET['order_number'] ?? '');

try {
    // 1. Specific Order Tracking Request
    if ($orderNumber !== '') {
        $stmt = $pdo->prepare("
            SELECT 
                o.id, o.order_number, o.user_id, o.total_amount, o.shipping_amount,
                o.tax_amount, o.status, o.payment_method, o.dispatch_date, o.created_at,
                a.full_name AS shipping_name,
                a.phone AS shipping_phone,
                a.address_line1,
                a.address_line2,
                a.city,
                a.state,
                a.postal_code,
                a.country
            FROM `orders` o
            LEFT JOIN `addresses` a ON o.address_id = a.id
            WHERE o.order_number = :num
            LIMIT 1
        ");
        $stmt->execute([':num' => $orderNumber]);
        $order = $stmt->fetch();

        if (!$order) {
            ApiResponse::error('Order reference not found. Please verify the code.', 404);
        }

        // Fetch Order Items
        $itStmt = $pdo->prepare("
            SELECT 
                oi.id, oi.product_id, oi.product_name, oi.colour_name, oi.size_name,
                oi.quantity, oi.unit_price, oi.total_price,
                img.image_url
            FROM `order_items` oi
            LEFT JOIN (
                SELECT product_id, image_url 
                FROM `product_images` 
                WHERE is_primary = 1
            ) img ON oi.product_id = img.product_id
            WHERE oi.order_id = :oid
            ORDER BY oi.id ASC
        ");
        $itStmt->execute([':oid' => $order['id']]);
        $items = $itStmt->fetchAll();

        // Check if a return has already been requested for this order
        $retStmt = $pdo->prepare("SELECT id, status, reason, created_at FROM `returns` WHERE order_id = :oid LIMIT 1");
        $retStmt->execute([':oid' => $order['id']]);
        $existingReturn = $retStmt->fetch();

        // Tracking timeline definition
        $statuses = ['Pending', 'In Queue', 'Dispatched', 'Delivered'];
        $currentStatus = $order['status'];
        $isReturned = ($currentStatus === 'Returned');

        $timeline = [];
        $reached = true;
        foreach ($statuses as $st) {
            $timeline[] = [
                'status'    => $st,
                'completed' => $reached,
                'current'   => $currentStatus === $st
            ];
            if ($currentStatus === $st) {
                $reached = false;
            }
        }

        ApiResponse::success('Order details retrieved', [
            'order' => [
                'id'              => (int)$order['id'],
                'order_number'    => $order['order_number'],
                'created_at'      => $order['created_at'],
                'status'          => $order['status'],
                'dispatch_date'   => $order['dispatch_date'],
                'total_amount'    => (float)$order['total_amount'],
                'shipping_amount' => (float)$order['shipping_amount'],
                'payment_method'  => $order['payment_method'],
                'shipping_address' => [
                    'name'         => $order['shipping_name'],
                    'phone'        => $order['shipping_phone'],
                    'line1'        => $order['address_line1'],
                    'line2'        => $order['address_line2'],
                    'city'         => $order['city'],
                    'state'        => $order['state'],
                    'postal_code'  => $order['postal_code'],
                    'country'      => $order['country']
                ],
                'items'           => $items,
                'timeline'        => $timeline,
                'is_returned'     => $isReturned,
                'is_return_eligible' => ($currentStatus === 'Delivered' && !$existingReturn),
                'existing_return' => $existingReturn ?: null
            ]
        ]);
    }

    // 2. Customer Order History
    Auth::requireCustomer(true);

    $stmt = $pdo->prepare("
        SELECT 
            o.id, o.order_number, o.total_amount, o.status, o.dispatch_date, o.created_at,
            COUNT(oi.id) AS total_items,
            r.id AS return_id,
            r.status AS return_status
        FROM `orders` o
        LEFT JOIN `order_items` oi ON o.id = oi.order_id
        LEFT JOIN `returns` r ON o.id = r.order_id
        WHERE o.user_id = :uid
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([':uid' => $userId]);
    $orders = $stmt->fetchAll();

    ApiResponse::success('Customer order history', [
        'count'  => count($orders),
        'orders' => $orders
    ]);

} catch (Exception $e) {
    error_log("Orders API Error: " . $e->getMessage());
    ApiResponse::error('Failed to load order information.', 500);
}

