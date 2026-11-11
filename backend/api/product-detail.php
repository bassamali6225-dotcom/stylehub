<?php
/**
 * StyleHub - Product Detail Public API
 * Returns product specifications, image gallery, available colours, sizes,
 * customer reviews, and live viewer activity count.
 *
 * Enforces Visibility Rules:
 * 1. Exact stock quantity is NEVER exposed.
 * 2. Only `is_low_stock: true` is returned if stock is at or below threshold.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';

$id   = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
$slug = trim($_GET['slug'] ?? '');

if ($id === null && $slug === '') {
    ApiResponse::error('Product identifier or slug is required.', 400);
}

try {
    $pdo = getDB();

    $where = $id !== null ? 'p.id = :ident' : 'p.slug = :ident';
    $identVal = $id !== null ? $id : $slug;

    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.category_id,
            p.subcategory_id,
            p.name,
            p.slug,
            p.description,
            p.price,
            p.low_stock_threshold,
            p.is_featured,
            p.hide_when_out_of_stock,
            p.manual_badge_message,
            p.created_at,
            c.name AS category_name,
            c.slug AS category_slug,
            s.name AS subcategory_name,
            s.target_group,
            COALESCE(inv.total_stock, 0) AS total_stock,
            COALESCE(rev.avg_rating, 5.0) AS avg_rating,
            COALESCE(rev.review_count, 0) AS review_count,
            COALESCE(act.active_viewers, 12) AS active_viewers,
            COALESCE(act.recent_views_count, 180) AS recent_views_count
        FROM `products` p
        JOIN `categories` c ON p.category_id = c.id
        LEFT JOIN `subcategories` s ON p.subcategory_id = s.id
        LEFT JOIN (
            SELECT product_id, SUM(stock_quantity) AS total_stock
            FROM `product_inventory`
            GROUP BY product_id
        ) inv ON p.id = inv.product_id
        LEFT JOIN (
            SELECT 
                product_id, 
                ROUND(AVG(rating), 1) AS avg_rating, 
                COUNT(*) AS review_count
            FROM `reviews`
            WHERE is_approved = 1
            GROUP BY product_id
        ) rev ON p.id = rev.product_id
        LEFT JOIN `live_review_activity` act ON p.id = act.product_id
        WHERE {$where}
        LIMIT 1
    ");
    $stmt->execute([':ident' => $identVal]);
    $p = $stmt->fetch();

    if (!$p) {
        ApiResponse::error('Product not found.', 404);
    }

    $prodId = (int)$p['id'];

    // 1. Image gallery
    $imgStmt = $pdo->prepare("
        SELECT id, image_url, is_primary, alt_text 
        FROM `product_images` 
        WHERE product_id = :pid 
        ORDER BY is_primary DESC, id ASC
    ");
    $imgStmt->execute([':pid' => $prodId]);
    $images = $imgStmt->fetchAll();

    // 2. Colours
    $colStmt = $pdo->prepare("
        SELECT id, colour_name, colour_code 
        FROM `product_colours` 
        WHERE product_id = :pid 
        ORDER BY id ASC
    ");
    $colStmt->execute([':pid' => $prodId]);
    $colours = $colStmt->fetchAll();

    // 3. Sizes
    $sizeStmt = $pdo->prepare("
        SELECT id, size_name 
        FROM `product_sizes` 
        WHERE product_id = :pid 
        ORDER BY id ASC
    ");
    $sizeStmt->execute([':pid' => $prodId]);
    $sizes = $sizeStmt->fetchAll();

    // 4. Approved Customer Reviews
    $revStmt = $pdo->prepare("
        SELECT 
            r.id,
            r.rating,
            r.title,
            r.comment,
            r.created_at,
            u.name AS author_name
        FROM `reviews` r
        JOIN `users` u ON r.user_id = u.id
        WHERE r.product_id = :pid AND r.is_approved = 1
        ORDER BY r.created_at DESC
    ");
    $revStmt->execute([':pid' => $prodId]);
    $reviews = $revStmt->fetchAll();

    // Stock rule: never expose exact quantity
    $totalStock = (int)$p['total_stock'];
    $threshold  = (int)$p['low_stock_threshold'];
    $isLowStock = ($totalStock <= $threshold && $totalStock > 0);
    $inStock    = $totalStock > 0;

    $productData = [
        'id'                   => $prodId,
        'name'                 => $p['name'],
        'slug'                 => $p['slug'],
        'description'          => $p['description'],
        'price'                => (float)$p['price'],
        'category_name'        => $p['category_name'],
        'category_slug'        => $p['category_slug'],
        'subcategory_name'     => $p['subcategory_name'],
        'target_group'         => $p['target_group'],
        'is_featured'          => (bool)$p['is_featured'],
        'manual_badge_message' => $p['manual_badge_message'],
        'in_stock'             => $inStock,
        'is_low_stock'         => $isLowStock, // "Low in Stock" badge condition
        'avg_rating'           => (float)$p['avg_rating'],
        'review_count'         => (int)$p['review_count'],
        'active_viewers'       => (int)$p['active_viewers'],
        'recent_views'         => (int)$p['recent_views_count'],
        'images'               => $images,
        'colours'              => $colours,
        'sizes'                => $sizes,
        'reviews'              => $reviews
    ];

    ApiResponse::success('Product details retrieved', [
        'product' => $productData
    ]);

} catch (Exception $e) {
    error_log("Product Detail API Error: " . $e->getMessage());
    ApiResponse::error('Failed to load product details.', 500);
}

