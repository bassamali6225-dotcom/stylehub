<?php
/**
 * StyleHub - Checkout & Order Placement API
 * Processes cart contents into MySQL orders and order items.
 * Uses Cash on Delivery (Sample/Demo Checkout).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/notifications.php';

CSRF::initSession();
$pdo = getDB();

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    ApiResponse::error('Method Not Allowed', 405);
}

// Enforce CSRF security
CSRF::enforce();

$userId = $_SESSION['user_id'] ?? null;
$sessionId = session_id();

$rawInput = file_get_contents('php://input');
$data = [];
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) $data = $decoded;
}
if (empty($data)) $data = $_POST;

$addressId     = isset($data['address_id']) && is_numeric($data['address_id']) ? (int)$data['address_id'] : null;
$fullName      = trim((string)($data['full_name'] ?? ''));
$phone         = trim((string)($data['phone'] ?? ''));
$addressLine1  = trim((string)($data['address_line1'] ?? ''));
$addressLine2  = trim((string)($data['address_line2'] ?? ''));
$city          = trim((string)($data['city'] ?? ''));
$state         = trim((string)($data['state'] ?? ''));
$postalCode    = trim((string)($data['postal_code'] ?? ''));
$country       = trim((string)($data['country'] ?? 'United States'));
$paymentMethod = 'Cash on Delivery (Demo Checkout)';

try {
    $pdo->beginTransaction();

    // 1. Locate Cart
    $cartStmt = $pdo->prepare("
        SELECT id FROM `carts` 
        WHERE (user_id = :uid AND :uid2 IS NOT NULL) OR (session_id = :sid AND user_id IS NULL)
        LIMIT 1
    ");
    $cartStmt->execute([
        ':uid'  => $userId,
        ':uid2' => $userId,
        ':sid'  => $sessionId
    ]);
    $cartId = $cartStmt->fetchColumn();

    if (!$cartId) {
        $pdo->rollBack();
        ApiResponse::error('No active shopping cart found.', 400);
    }

    // 2. Fetch Cart Items
    $itemStmt = $pdo->prepare("
        SELECT 
            ci.id, ci.product_id, ci.colour_id, ci.size_id, ci.quantity,
            p.name AS product_name, p.price,
            c.colour_name,
            s.size_name
        FROM `cart_items` ci
        JOIN `products` p ON ci.product_id = p.id
        LEFT JOIN `product_colours` c ON ci.colour_id = c.id
        LEFT JOIN `product_sizes` s ON ci.size_id = s.id
        WHERE ci.cart_id = :cid
    ");
    $itemStmt->execute([':cid' => $cartId]);
    $cartItems = $itemStmt->fetchAll();

    if (empty($cartItems)) {
        $pdo->rollBack();
        ApiResponse::error('Your shopping bag is empty. Please add items before checking out.', 400);
    }

    // 3. Resolve or create delivery address
    $resolvedAddressId = null;
    if ($addressId !== null && $userId !== null) {
        $chkAddr = $pdo->prepare("SELECT id FROM `addresses` WHERE id = :id AND user_id = :uid LIMIT 1");
        $chkAddr->execute([':id' => $addressId, ':uid' => $userId]);
        if ($chkAddr->fetch()) {
            $resolvedAddressId = $addressId;
        }
    }

    if ($resolvedAddressId === null) {
        if (empty($fullName) || empty($phone) || empty($addressLine1) || empty($city) || empty($postalCode)) {
            $pdo->rollBack();
            ApiResponse::error('Please provide a complete shipping address and contact telephone number.', 422);
        }

        $insAddr = $pdo->prepare("
            INSERT INTO `addresses` 
            (`user_id`, `full_name`, `phone`, `address_line1`, `address_line2`, `city`, `state`, `postal_code`, `country`, `is_default`)
            VALUES (:uid, :fn, :ph, :a1, :a2, :city, :st, :pc, :co, 0)
        ");
        $insAddr->execute([
            ':uid'  => $userId,
            ':fn'   => $fullName,
            ':ph'   => $phone,
            ':a1'   => $addressLine1,
            ':a2'   => $addressLine2 ?: null,
            ':city' => $city,
            ':st'   => $state ?: 'N/A',
            ':pc'   => $postalCode,
            ':co'   => $country
        ]);
        $resolvedAddressId = (int)$pdo->lastInsertId();
    }

    // 4. Calculate Totals
    $subtotal = 0.0;
    foreach ($cartItems as $it) {
        $subtotal += round((float)$it['price'] * (int)$it['quantity'], 2);
    }
    $shipping = $subtotal >= 150.0 ? 0.0 : 15.0;
    $grandTotal = round($subtotal + $shipping, 2);

    // 5. Generate unique order number
    $orderNumber = 'ORD-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));

    // 6. Insert Order
    $insOrder = $pdo->prepare("
        INSERT INTO `orders` 
        (`order_number`, `user_id`, `address_id`, `total_amount`, `shipping_amount`, `tax_amount`, `status`, `payment_method`)
        VALUES (:num, :uid, :aid, :total, :ship, 0.00, 'Pending', :pm)
    ");
    $insOrder->execute([
        ':num'   => $orderNumber,
        ':uid'   => $userId,
        ':aid'   => $resolvedAddressId,
        ':total' => $grandTotal,
        ':ship'  => $shipping,
        ':pm'    => $paymentMethod
    ]);
    $orderId = (int)$pdo->lastInsertId();

    // 7. Insert Order Items (historical snapshot)
    $insItem = $pdo->prepare("
        INSERT INTO `order_items`
        (`order_id`, `product_id`, `product_name`, `colour_name`, `size_name`, `quantity`, `unit_price`, `total_price`)
        VALUES (:oid, :pid, :pname, :col, :sz, :qty, :unit, :tot)
    ");

    foreach ($cartItems as $it) {
        $qty = (int)$it['quantity'];
        $unitPrice = (float)$it['price'];
        $itemTotal = round($unitPrice * $qty, 2);

        $insItem->execute([
            ':oid'   => $orderId,
            ':pid'   => (int)$it['product_id'],
            ':pname' => $it['product_name'],
            ':col'   => $it['colour_name'] ?: 'Standard',
            ':sz'    => $it['size_name'] ?: 'One Size',
            ':qty'   => $qty,
            ':unit'  => $unitPrice,
            ':tot'   => $itemTotal
        ]);
    }

    // 8. Clear Cart
    $pdo->prepare("DELETE FROM `cart_items` WHERE cart_id = :cid")->execute([':cid' => $cartId]);

    $pdo->commit();

    // 9. Notify Admin of New Order & Check Inventory Thresholds
    $customerName = 'Guest Customer';
    if ($userId) {
        $uStmt = $pdo->prepare("SELECT name FROM `users` WHERE id = :id LIMIT 1");
        $uStmt->execute([':id' => $userId]);
        $customerName = $uStmt->fetchColumn() ?: ($fullName ?: 'Customer');
    } elseif (!empty($fullName)) {
        $customerName = $fullName;
    }

    AdminNotification::create([
        'notification_type' => 'order_placed',
        'title'             => 'New Order Placed',
        'message'           => "New order #{$orderNumber} was placed by {$customerName} for \${$grandTotal}.",
        'related_user_id'   => $userId ? (int)$userId : null,
        'related_order_id'  => $orderId
    ], $pdo);

    foreach ($cartItems as $it) {
        AdminNotification::checkProductStock((int)$it['product_id'], $pdo);
    }

    ApiResponse::success('Order placed successfully! Thank you for shopping with StyleHub.', [
        'order_id'       => $orderId,
        'order_number'   => $orderNumber,
        'grand_total'    => $grandTotal,
        'payment_method' => $paymentMethod,
        'redirect'       => "/frontend/order-tracking.html?order={$orderNumber}"
    ], 201);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Checkout Error: " . $e->getMessage());
    ApiResponse::error('Failed to process order. Please try again.', 500);
}

