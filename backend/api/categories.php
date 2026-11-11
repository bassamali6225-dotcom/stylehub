<?php
/**
 * StyleHub - Categories & Subcategories Public API
 * Provides structured categories with Men, Women, and Children target groups
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';

try {
    $pdo = getDB();

    // Fetch all categories
    $stmtCats = $pdo->query("
        SELECT id, name, slug, description, image_url 
        FROM `categories` 
        ORDER BY id ASC
    ");
    $categories = $stmtCats->fetchAll();

    // Fetch all subcategories
    $stmtSubs = $pdo->query("
        SELECT id, category_id, name, slug, target_group 
        FROM `subcategories` 
        ORDER BY category_id ASC, target_group ASC
    ");
    $allSubs = $stmtSubs->fetchAll();

    // Group subcategories under their respective categories
    $subsByCategory = [];
    foreach ($allSubs as $sub) {
        $catId = (int)$sub['category_id'];
        if (!isset($subsByCategory[$catId])) {
            $subsByCategory[$catId] = [];
        }
        $subsByCategory[$catId][] = [
            'id'           => (int)$sub['id'],
            'name'         => $sub['name'],
            'slug'         => $sub['slug'],
            'target_group' => $sub['target_group']
        ];
    }

    $result = [];
    foreach ($categories as $cat) {
        $catId = (int)$cat['id'];
        $result[] = [
            'id'            => $catId,
            'name'          => $cat['name'],
            'slug'          => $cat['slug'],
            'description'   => $cat['description'],
            'image_url'     => $cat['image_url'],
            'subcategories' => $subsByCategory[$catId] ?? []
        ];
    }

    ApiResponse::success('Categories retrieved successfully', [
        'categories' => $result
    ]);

} catch (Exception $e) {
    error_log("Categories API Error: " . $e->getMessage());
    ApiResponse::error('Failed to load categories', 500);
}

