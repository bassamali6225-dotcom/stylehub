<?php
require_once __DIR__ . '/config/database.php';
$pdo = Database::getConnection();
$tables = ['users', 'admins', 'categories', 'subcategories', 'products', 'product_colours', 'product_sizes', 'product_images', 'product_inventory', 'orders', 'order_items', 'returns', 'reviews', 'addresses', 'carts', 'cart_items', 'wishlists', 'wishlist_items', 'live_review_activity'];
foreach ($tables as $t) {
    $count = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    echo str_pad($t, 22) . ": " . $count . PHP_EOL;
}

