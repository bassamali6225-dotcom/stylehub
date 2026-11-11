<?php
/**
 * StyleHub - Wishlist / Favourites API
 * Supports viewing, adding, and removing saved products.
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

function getOrCreateWishlist(PDO $pdo, ?int $userId, string $sessionId): int {
    if ($userId !== null) {
        $stmt = $pdo->prepare("SELECT id FROM `wishlists` WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $wid = $stmt->fetchColumn();
        if ($wid) return (int)$wid;

        $ins = $pdo->prepare("INSERT INTO `wishlists` (`user_id`, `session_id`) VALUES (:uid, :sid)");
        $ins->execute([':uid' => $userId, ':sid' => $sessionId]);
        return (int)$pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare("SELECT id FROM `wishlists` WHERE session_id = :sid AND user_id IS NULL LIMIT 1");
        $stmt->execute([':sid' => $sessionId]);
        $wid = $stmt->fetchColumn();
        if ($wid) return (int)$wid;

        $ins = $pdo->prepare("INSERT INTO `wishlists` (`session_id`) VALUES (:sid)");
        $ins->execute([':sid' => $sessionId]);
        return (int)$pdo->lastInsertId();
    }
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $wishlistId = getOrCreateWishlist($pdo, $userId ? (int)$userId : null, $sessionId);

        $stmt = $pdo->prepare("
            SELECT 
                wi.id AS item_id,
                p.id AS product_id,
                p.name,
                p.slug,
                p.price,
                p.low_stock_threshold,
                c.name AS category_name,
                COALESCE(inv.total_stock, 0) AS total_stock,
                COALESCE(rev.avg_rating, 5.0) AS avg_rating,
                COALESCE(rev.review_count, 0) AS review_count,
                img.image_url
            FROM `wishlist_items` wi
            JOIN `products` p ON wi.product_id = p.id
            JOIN `categories` c ON p.category_id = c.id
            LEFT JOIN (
                SELECT product_id, SUM(stock_quantity) AS total_stock
                FROM `product_inventory`
                GROUP BY product_id
            ) inv ON p.id = inv.product_id
            LEFT JOIN (
                SELECT product_id, ROUND(AVG(rating), 1) AS avg_rating, COUNT(*) AS review_count
                FROM `reviews`
                WHERE is_approved = 1
                GROUP BY product_id
            ) rev ON p.id = rev.product_id
            LEFT JOIN (
                SELECT product_id, image_url
                FROM `product_images`
                WHERE is_primary = 1
            ) img ON p.id = img.product_id
            WHERE wi.wishlist_id = :wid
            ORDER BY wi.id DESC
        ");
        $stmt->execute([':wid' => $wishlistId]);
        $rows = $stmt->fetchAll();

        $items = [];
        foreach ($rows as $r) {
            $stock = (int)$r['total_stock'];
            $threshold = (int)$r['low_stock_threshold'];
            $isLowStock = ($stock <= $threshold && $stock > 0);

            $items[] = [
                'item_id'       => (int)$r['item_id'],
                'product_id'    => (int)$r['product_id'],
                'name'          => $r['name'],
                'slug'          => $r['slug'],
                'price'         => (float)$r['price'],
                'category_name' => $r['category_name'],
                'in_stock'      => $stock > 0,
                'is_low_stock'  => $isLowStock, // Never exact quantity
                'avg_rating'    => (float)$r['avg_rating'],
                'review_count'  => (int)$r['review_count'],
                'image_url'     => $r['image_url'] ?: 'https://images.unsplash.com/photo-1542272604-780c96856592?auto=format&fit=crop&w=600&q=80'
            ];
        }

        ApiResponse::success('Wishlist loaded', [
            'count' => count($items),
            'items' => $items
        ]);

    } catch (Exception $e) {
        error_log("Wishlist Error: " . $e->getMessage());
        ApiResponse::error('Failed to load wishlist', 500);
    }
}

if ($method === 'POST') {
    CSRF::enforce();

    $rawInput = file_get_contents('php://input');
    $data = [];
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) $data = $decoded;
    }
    if (empty($data)) $data = $_POST;

    $action    = trim((string)($data['action'] ?? 'toggle'));
    $productId = isset($data['product_id']) ? (int)$data['product_id'] : 0;

    if ($productId <= 0) {
        ApiResponse::error('Product ID required.', 422);
    }

    try {
        $wishlistId = getOrCreateWishlist($pdo, $userId ? (int)$userId : null, $sessionId);

        // Check if exists
        $chk = $pdo->prepare("SELECT id FROM `wishlist_items` WHERE wishlist_id = :wid AND product_id = :pid LIMIT 1");
        $chk->execute([':wid' => $wishlistId, ':pid' => $productId]);
        $exists = $chk->fetchColumn();

        if ($action === 'remove' || ($action === 'toggle' && $exists)) {
            $del = $pdo->prepare("DELETE FROM `wishlist_items` WHERE wishlist_id = :wid AND product_id = :pid");
            $del->execute([':wid' => $wishlistId, ':pid' => $productId]);
            ApiResponse::success('Item removed from wishlist.', ['in_wishlist' => false]);
        } else {
            $ins = $pdo->prepare("INSERT IGNORE INTO `wishlist_items` (`wishlist_id`, `product_id`) VALUES (:wid, :pid)");
            $ins->execute([':wid' => $wishlistId, ':pid' => $productId]);

            // Notify admin of product saved to wishlist
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
                'notification_type'  => 'wishlist_add',
                'title'              => 'Product Saved to Wishlist',
                'message'            => "Customer {$custName} saved '{$prodName}' to wishlist.",
                'related_user_id'    => $userId ? (int)$userId : null,
                'related_product_id' => $productId
            ], $pdo);

            ApiResponse::success('Item saved to wishlist.', ['in_wishlist' => true]);
        }

    } catch (Exception $e) {
        error_log("Wishlist POST Error: " . $e->getMessage());
        ApiResponse::error('Failed to update wishlist', 500);
    }
}

