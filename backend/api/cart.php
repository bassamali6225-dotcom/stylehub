<?php
/**
 * StyleHub - Cart API
 * Manages shopping bag items for authenticated customers and guest sessions.
 * Actions: get, add, update, remove, clear.
 * State-changing actions are strictly guarded by CSRF.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/notifications.php';

CSRF::initSession();
$pdo = getDB();

$userId = $_SESSION['user_id'] ?? null;
$sessionId = session_id();

/**
 * Get or create cart for current user or session
 */
function getOrCreateCart(PDO $pdo, ?int $userId, string $sessionId): int {
    if ($userId !== null) {
        $stmt = $pdo->prepare("SELECT id FROM `carts` WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $cartId = $stmt->fetchColumn();
        if ($cartId) {
            return (int)$cartId;
        }

        // Migrate any session cart to user cart
        $stmt = $pdo->prepare("SELECT id FROM `carts` WHERE session_id = :sid AND user_id IS NULL LIMIT 1");
        $stmt->execute([':sid' => $sessionId]);
        $guestCartId = $stmt->fetchColumn();

        if ($guestCartId) {
            $upd = $pdo->prepare("UPDATE `carts` SET user_id = :uid WHERE id = :cid");
            $upd->execute([':uid' => $userId, ':cid' => $guestCartId]);
            return (int)$guestCartId;
        }

        $ins = $pdo->prepare("INSERT INTO `carts` (`user_id`, `session_id`) VALUES (:uid, :sid)");
        $ins->execute([':uid' => $userId, ':sid' => $sessionId]);
        return (int)$pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare("SELECT id FROM `carts` WHERE session_id = :sid AND user_id IS NULL LIMIT 1");
        $stmt->execute([':sid' => $sessionId]);
        $cartId = $stmt->fetchColumn();
        if ($cartId) {
            return (int)$cartId;
        }

        $ins = $pdo->prepare("INSERT INTO `carts` (`session_id`) VALUES (:sid)");
        $ins->execute([':sid' => $sessionId]);
        return (int)$pdo->lastInsertId();
    }
}

$method = $_SERVER['REQUEST_METHOD'];

// Handle GET: Retrieve Cart
if ($method === 'GET') {
    try {
        $cartId = getOrCreateCart($pdo, $userId ? (int)$userId : null, $sessionId);

        $stmt = $pdo->prepare("
            SELECT 
                ci.id AS item_id,
                ci.product_id,
                ci.colour_id,
                ci.size_id,
                ci.quantity,
                p.name AS product_name,
                p.slug AS product_slug,
                p.price,
                c.colour_name,
                c.colour_code,
                s.size_name,
                COALESCE(img.image_url, 'https://images.unsplash.com/photo-1542272604-780c96856592?auto=format&fit=crop&w=600&q=80') AS image_url
            FROM `cart_items` ci
            JOIN `products` p ON ci.product_id = p.id
            LEFT JOIN `product_colours` c ON ci.colour_id = c.id
            LEFT JOIN `product_sizes` s ON ci.size_id = s.id
            LEFT JOIN (
                SELECT product_id, image_url 
                FROM `product_images` 
                WHERE is_primary = 1
            ) img ON p.id = img.product_id
            WHERE ci.cart_id = :cid
            ORDER BY ci.id DESC
        ");
        $stmt->execute([':cid' => $cartId]);
        $rawItems = $stmt->fetchAll();

        $items = [];
        $subtotal = 0.0;
        $totalQuantity = 0;

        foreach ($rawItems as $row) {
            $qty = (int)$row['quantity'];
            $price = (float)$row['price'];
            $lineTotal = round($price * $qty, 2);
            $subtotal += $lineTotal;
            $totalQuantity += $qty;

            $items[] = [
                'item_id'      => (int)$row['item_id'],
                'product_id'   => (int)$row['product_id'],
                'product_name' => $row['product_name'],
                'product_slug' => $row['product_slug'],
                'price'        => $price,
                'colour_name'  => $row['colour_name'] ?: 'Standard',
                'colour_code'  => $row['colour_code'] ?: '#000000',
                'size_name'    => $row['size_name'] ?: 'One Size',
                'quantity'     => $qty,
                'line_total'   => $lineTotal,
                'image_url'    => $row['image_url']
            ];
        }

        // Complimentary shipping over $150, otherwise $15 flat rate
        $shipping = $subtotal >= 150.0 || $subtotal === 0.0 ? 0.0 : 15.0;
        $grandTotal = round($subtotal + $shipping, 2);

        ApiResponse::success('Cart retrieved', [
            'cart_id'        => $cartId,
            'item_count'     => $totalQuantity,
            'subtotal'       => $subtotal,
            'shipping'       => $shipping,
            'free_shipping'  => $subtotal >= 150.0 && $subtotal > 0,
            'grand_total'    => $grandTotal,
            'items'          => $items
        ]);

    } catch (Exception $e) {
        error_log("Cart Get Error: " . $e->getMessage());
        ApiResponse::error('Failed to retrieve cart', 500);
    }
}

// Handle State-Changing POST Actions
if ($method === 'POST') {
    CSRF::enforce();

    $rawInput = file_get_contents('php://input');
    $data = [];
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) $data = $decoded;
    }
    if (empty($data)) $data = $_POST;

    $action    = trim((string)($data['action'] ?? 'add'));
    $productId = isset($data['product_id']) ? (int)$data['product_id'] : 0;
    $colourId  = isset($data['colour_id']) && is_numeric($data['colour_id']) ? (int)$data['colour_id'] : null;
    $sizeId    = isset($data['size_id']) && is_numeric($data['size_id']) ? (int)$data['size_id'] : null;
    $quantity  = isset($data['quantity']) ? max(1, (int)$data['quantity']) : 1;
    $itemId    = isset($data['item_id']) ? (int)$data['item_id'] : 0;

    try {
        $cartId = getOrCreateCart($pdo, $userId ? (int)$userId : null, $sessionId);

        switch ($action) {
            case 'add':
                if ($productId <= 0) {
                    ApiResponse::error('Valid product ID required.', 422);
                }

                // Default colour and size if omitted
                if ($colourId === null) {
                    $cStmt = $pdo->prepare("SELECT id FROM `product_colours` WHERE product_id = :pid ORDER BY id ASC LIMIT 1");
                    $cStmt->execute([':pid' => $productId]);
                    $colourId = $cStmt->fetchColumn() ?: null;
                }
                if ($sizeId === null) {
                    $sStmt = $pdo->prepare("SELECT id FROM `product_sizes` WHERE product_id = :pid ORDER BY id ASC LIMIT 1");
                    $sStmt->execute([':pid' => $productId]);
                    $sizeId = $sStmt->fetchColumn() ?: null;
                }

                // Check if already in cart
                $chk = $pdo->prepare("
                    SELECT id, quantity 
                    FROM `cart_items` 
                    WHERE cart_id = :cid AND product_id = :pid 
                      AND (colour_id = :col OR (colour_id IS NULL AND :col2 IS NULL))
                      AND (size_id = :sz OR (size_id IS NULL AND :sz2 IS NULL))
                    LIMIT 1
                ");
                $chk->execute([
                    ':cid'  => $cartId,
                    ':pid'  => $productId,
                    ':col'  => $colourId,
                    ':col2' => $colourId,
                    ':sz'   => $sizeId,
                    ':sz2'  => $sizeId
                ]);
                $existing = $chk->fetch();

                if ($existing) {
                    $newQty = (int)$existing['quantity'] + $quantity;
                    $upd = $pdo->prepare("UPDATE `cart_items` SET quantity = :q WHERE id = :id");
                    $upd->execute([':q' => $newQty, ':id' => $existing['id']]);
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO `cart_items` (`cart_id`, `product_id`, `colour_id`, `size_id`, `quantity`)
                        VALUES (:cid, :pid, :col, :sz, :q)
                    ");
                    $ins->execute([
                        ':cid' => $cartId,
                        ':pid' => $productId,
                        ':col' => $colourId,
                        ':sz'  => $sizeId,
                        ':q'   => $quantity
                    ]);
                }

                // Notify admin of product added to cart
                $pStmt = $pdo->prepare("SELECT name FROM `products` WHERE id = :pid LIMIT 1");
                $pStmt->execute([':pid' => $productId]);
                $prodName = $pStmt->fetchColumn() ?: "Product #{$productId}";

                $custName = 'Guest Customer';
                if ($userId !== null) {
                    $uStmt = $pdo->prepare("SELECT name FROM `users` WHERE id = :uid LIMIT 1");
                    $uStmt->execute([':uid' => $userId]);
                    $custName = $uStmt->fetchColumn() ?: 'Customer';
                }

                AdminNotification::create([
                    'notification_type'  => 'cart_add',
                    'title'              => 'Product Added to Bag',
                    'message'            => "Customer {$custName} added '{$prodName}' to shopping bag (Qty: {$quantity}).",
                    'related_user_id'    => $userId ? (int)$userId : null,
                    'related_product_id' => $productId
                ], $pdo);

                ApiResponse::success('Item added to shopping bag.');
                break;

            case 'update':
                if ($itemId <= 0) {
                    ApiResponse::error('Item ID required.', 422);
                }
                $upd = $pdo->prepare("UPDATE `cart_items` SET quantity = :q WHERE id = :id AND cart_id = :cid");
                $upd->execute([':q' => $quantity, ':id' => $itemId, ':cid' => $cartId]);
                ApiResponse::success('Quantity updated.');
                break;

            case 'remove':
                if ($itemId <= 0) {
                    ApiResponse::error('Item ID required.', 422);
                }

                // Fetch item info before removal for notification
                $itStmt = $pdo->prepare("
                    SELECT ci.product_id, p.name 
                    FROM `cart_items` ci 
                    JOIN `products` p ON ci.product_id = p.id 
                    WHERE ci.id = :id AND ci.cart_id = :cid 
                    LIMIT 1
                ");
                $itStmt->execute([':id' => $itemId, ':cid' => $cartId]);
                $itemInfo = $itStmt->fetch();
                $prodName = $itemInfo['name'] ?? 'Product';
                $remProdId = isset($itemInfo['product_id']) ? (int)$itemInfo['product_id'] : null;

                $custName = 'Guest Customer';
                if ($userId !== null) {
                    $uStmt = $pdo->prepare("SELECT name FROM `users` WHERE id = :uid LIMIT 1");
                    $uStmt->execute([':uid' => $userId]);
                    $custName = $uStmt->fetchColumn() ?: 'Customer';
                }

                $del = $pdo->prepare("DELETE FROM `cart_items` WHERE id = :id AND cart_id = :cid");
                $del->execute([':id' => $itemId, ':cid' => $cartId]);

                AdminNotification::create([
                    'notification_type'  => 'cart_remove',
                    'title'              => 'Product Removed from Bag',
                    'message'            => "Customer {$custName} removed '{$prodName}' from shopping bag.",
                    'related_user_id'    => $userId ? (int)$userId : null,
                    'related_product_id' => $remProdId
                ], $pdo);

                ApiResponse::success('Item removed from shopping bag.');
                break;

            case 'clear':
                $del = $pdo->prepare("DELETE FROM `cart_items` WHERE cart_id = :cid");
                $del->execute([':cid' => $cartId]);
                ApiResponse::success('Cart cleared.');
                break;

            default:
                ApiResponse::error('Unknown action.', 400);
        }

    } catch (Exception $e) {
        error_log("Cart Action Error: " . $e->getMessage());
        ApiResponse::error('Failed to process cart action', 500);
    }
}

