<?php
/**
 * StyleHub - Public Products API
 * Supports catalog search, category filtering, subcategories (Men/Women/Children),
 * colour options, and approved sort orders.
 *
 * Strict Visibility Rules Enforced:
 * 1. Exact stock quantities are NEVER exposed to customers.
 * 2. If stock is <= threshold, returns `is_low_stock: true` only.
 * 3. Out-of-stock products are hidden when `hide_when_out_of_stock = 1`.
 * 4. Filters do NOT expose stock status or rating filters.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';

try {
    $pdo = getDB();

    // Query parameters
    $search      = trim($_GET['search'] ?? '');
    $category    = trim($_GET['category'] ?? '');
    $targetGroup = trim($_GET['target_group'] ?? ''); // Men, Women, Children
    $subcategory = trim($_GET['subcategory'] ?? '');
    $colour      = trim($_GET['colour'] ?? '');
    $minPrice    = isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? (float)$_GET['min_price'] : null;
    $maxPrice    = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? (float)$_GET['max_price'] : null;
    $featured    = isset($_GET['featured']) && $_GET['featured'] === '1';
    $sort        = trim($_GET['sort'] ?? 'newest');
    $limit       = isset($_GET['limit']) ? max(1, min(60, (int)$_GET['limit'])) : 40;
    $offset      = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    // Base query with aggregates
    $whereConditions = [];
    $params = [];

    // Out-of-stock filter:
    // If a product has hide_when_out_of_stock = 1 and total stock <= 0, hide it from public listing
    // We compute total stock in a subquery or HAVING clause

    // Search by product name
    if ($search !== '') {
        $whereConditions[] = "(p.name LIKE :search_name OR p.description LIKE :search_desc)";
        $params[':search_name'] = "%{$search}%";
        $params[':search_desc'] = "%{$search}%";
    }

    // Category filter (slug or id)
    if ($category !== '') {
        if (is_numeric($category)) {
            $whereConditions[] = "p.category_id = :cat_id";
            $params[':cat_id'] = (int)$category;
        } else {
            $whereConditions[] = "c.slug = :cat_slug";
            $params[':cat_slug'] = strtolower($category);
        }
    }

    // Subcategory / Target Group filter (Men, Women, Children)
    if ($targetGroup !== '' && in_array(ucfirst(strtolower($targetGroup)), ['Men', 'Women', 'Children'], true)) {
        $whereConditions[] = "s.target_group = :target_group";
        $params[':target_group'] = ucfirst(strtolower($targetGroup));
    } elseif ($subcategory !== '') {
        if (is_numeric($subcategory)) {
            $whereConditions[] = "p.subcategory_id = :sub_id";
            $params[':sub_id'] = (int)$subcategory;
        } else {
            $whereConditions[] = "s.slug = :sub_slug";
            $params[':sub_slug'] = strtolower($subcategory);
        }
    }

    // Price range
    if ($minPrice !== null) {
        $whereConditions[] = "p.price >= :min_price";
        $params[':min_price'] = $minPrice;
    }
    if ($maxPrice !== null) {
        $whereConditions[] = "p.price <= :max_price";
        $params[':max_price'] = $maxPrice;
    }

    // Featured items
    if ($featured) {
        $whereConditions[] = "p.is_featured = 1";
    }

    // Colour filter
    if ($colour !== '') {
        $whereConditions[] = "EXISTS (
            SELECT 1 FROM `product_colours` pc_filter 
            WHERE pc_filter.product_id = p.id 
            AND (pc_filter.colour_name LIKE :colour_name OR pc_filter.colour_code = :colour_code)
        )";
        $params[':colour_name'] = "%{$colour}%";
        $params[':colour_code'] = $colour;
    }

    // Sorting
    $orderBy = "p.created_at DESC";
    switch ($sort) {
        case 'price_asc':
            $orderBy = "p.price ASC, p.id DESC";
            break;
        case 'price_desc':
            $orderBy = "p.price DESC, p.id DESC";
            break;
        case 'most_popular':
            $orderBy = "COALESCE(act.recent_views_count, 0) DESC, review_count DESC, p.id DESC";
            break;
        case 'highest_rated':
            $orderBy = "avg_rating DESC, review_count DESC, p.id DESC";
            break;
        case 'newest':
        default:
            $orderBy = "p.created_at DESC";
            break;
    }

    $whereSql = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    $sql = "
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
            COALESCE(act.active_viewers, 1) AS active_viewers,
            img.image_url AS primary_image,
            img.alt_text AS primary_image_alt
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
        LEFT JOIN (
            SELECT product_id, image_url, alt_text
            FROM `product_images`
            WHERE is_primary = 1
        ) img ON p.id = img.product_id
        {$whereSql}
        HAVING (p.hide_when_out_of_stock = 0 OR total_stock > 0)
        ORDER BY {$orderBy}
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rawProducts = $stmt->fetchAll();

    // Fetch colours and sizes for returned products
    $productIds = array_column($rawProducts, 'id');
    $coloursByProduct = [];
    $sizesByProduct = [];

    if (!empty($productIds)) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        // Colours
        $colourStmt = $pdo->prepare("
            SELECT id, product_id, colour_name, colour_code 
            FROM `product_colours` 
            WHERE product_id IN ({$placeholders})
            ORDER BY id ASC
        ");
        $colourStmt->execute($productIds);
        while ($c = $colourStmt->fetch()) {
            $coloursByProduct[$c['product_id']][] = [
                'id'   => (int)$c['id'],
                'name' => $c['colour_name'],
                'code' => $c['colour_code']
            ];
        }

        // Sizes
        $sizeStmt = $pdo->prepare("
            SELECT id, product_id, size_name 
            FROM `product_sizes` 
            WHERE product_id IN ({$placeholders})
            ORDER BY id ASC
        ");
        $sizeStmt->execute($productIds);
        while ($s = $sizeStmt->fetch()) {
            $sizesByProduct[$s['product_id']][] = [
                'id'   => (int)$s['id'],
                'name' => $s['size_name']
            ];
        }
    }

    // Transform products and sanitize for public visibility
    // CRITICAL: NEVER include total_stock in the public response!
    $products = [];
    foreach ($rawProducts as $p) {
        $totalStock = (int)$p['total_stock'];
        $threshold  = (int)$p['low_stock_threshold'];
        $isLowStock = ($totalStock <= $threshold && $totalStock > 0);

        $products[] = [
            'id'                   => (int)$p['id'],
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
            'is_low_stock'         => $isLowStock, // Public "Low in Stock" indicator
            'avg_rating'           => (float)$p['avg_rating'],
            'review_count'         => (int)$p['review_count'],
            'primary_image'        => $p['primary_image'] ?: 'https://images.unsplash.com/photo-1542272604-780c96856592?auto=format&fit=crop&w=800&q=80',
            'primary_image_alt'    => $p['primary_image_alt'] ?: $p['name'],
            'colours'              => $coloursByProduct[$p['id']] ?? [],
            'sizes'                => $sizesByProduct[$p['id']] ?? []
        ];
    }

    ApiResponse::success('Products retrieved', [
        'count'    => count($products),
        'products' => $products
    ]);

} catch (Exception $e) {
    error_log("Products API Error: " . $e->getMessage());
    ApiResponse::error('Failed to retrieve products', 500);
}

