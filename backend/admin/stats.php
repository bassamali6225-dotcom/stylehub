<?php
/**
 * StyleHub - Admin Live Stats API
 * Provides live MySQL metrics for the admin dashboard
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../config/database.php';

// Strict Admin role check
Auth::requireAdmin(true);

try {
    $pdo = getDB();

    // 1. Total products
    $totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM `products`")->fetchColumn();

    // 2. Inventory status (in-stock, low-stock, out-of-stock)
    $stmtStock = $pdo->query("
        SELECT 
            p.id,
            p.name,
            p.low_stock_threshold,
            COALESCE(SUM(i.stock_quantity), 0) AS total_stock
        FROM `products` p
        LEFT JOIN `product_inventory` i ON p.id = i.product_id
        GROUP BY p.id
    ");
    $allProductStocks = $stmtStock->fetchAll();

    $inStockCount = 0;
    $lowStockCount = 0;
    $outOfStockCount = 0;

    foreach ($allProductStocks as $p) {
        $stock = (int)$p['total_stock'];
        $threshold = (int)$p['low_stock_threshold'];

        if ($stock <= 0) {
            $outOfStockCount++;
        } elseif ($stock <= $threshold) {
            $lowStockCount++;
        } else {
            $inStockCount++;
        }
    }

    // 3. Orders by status
    $stmtOrders = $pdo->query("
        SELECT status, COUNT(*) AS count
        FROM `orders`
        GROUP BY status
    ");
    $orderCounts = [
        'Pending' => 0,
        'In Queue' => 0,
        'Dispatched' => 0,
        'Delivered' => 0,
        'Returned' => 0
    ];
    while ($row = $stmtOrders->fetch()) {
        $orderCounts[$row['status']] = (int)$row['count'];
    }

    // 4. Returns
    $totalReturns = (int)$pdo->query("SELECT COUNT(*) FROM `returns`")->fetchColumn();
    $pendingReturns = (int)$pdo->query("SELECT COUNT(*) FROM `returns` WHERE status = 'Pending'")->fetchColumn();

    // 5. Total Colours
    $totalColours = (int)$pdo->query("SELECT COUNT(DISTINCT colour_name) FROM `product_colours`")->fetchColumn();

    // 6. Total Customer Reviews
    $totalReviews = (int)$pdo->query("SELECT COUNT(*) FROM `reviews`")->fetchColumn();

    // 7. Customers viewing products
    $activeViewers = (int)$pdo->query("SELECT COALESCE(SUM(active_viewers), 0) FROM `live_review_activity`")->fetchColumn();

    ApiResponse::success('Dashboard statistics retrieved', [
        'total_products'         => $totalProducts,
        'in_stock_products'      => $inStockCount,
        'low_stock_products'     => $lowStockCount,
        'out_of_stock_products'  => $outOfStockCount,
        'orders_pending'         => $orderCounts['Pending'],
        'orders_in_queue'        => $orderCounts['In Queue'],
        'orders_dispatched'      => $orderCounts['Dispatched'],
        'orders_delivered'       => $orderCounts['Delivered'],
        'orders_returned'        => $orderCounts['Returned'],
        'total_returns'          => $totalReturns,
        'pending_returns'        => $pendingReturns,
        'total_colours'          => $totalColours,
        'total_reviews'          => $totalReviews,
        'active_viewers'         => $activeViewers
    ]);

} catch (Exception $e) {
    error_log("Admin Stats API Error: " . $e->getMessage());
    ApiResponse::error('Failed to load dashboard metrics.', 500);
}

