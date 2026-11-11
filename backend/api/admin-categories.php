<?php
/**
 * StyleHub - Admin Category & Subcategory Management API
 * Create, rename, delete categories & subcategories; manage Men, Women, Children classifications.
 * Protected by Admin Authentication & CSRF.
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

// 1. GET: Retrieve Categories with Subcategories & Product Counts
if ($method === 'GET') {
    try {
        $stmt = $pdo->query("
            SELECT 
                c.id, c.name, c.slug, c.description, c.image_url,
                COUNT(DISTINCT p.id) AS product_count
            FROM `categories` c
            LEFT JOIN `products` p ON c.id = p.category_id
            GROUP BY c.id
            ORDER BY c.name ASC
        ");
        $categories = $stmt->fetchAll();

        // Fetch subcategories
        $subStmt = $pdo->query("
            SELECT 
                s.id, s.category_id, s.name, s.slug, s.target_group,
                COUNT(DISTINCT p.id) AS product_count
            FROM `subcategories` s
            LEFT JOIN `products` p ON s.id = p.subcategory_id
            GROUP BY s.id
            ORDER BY s.category_id ASC, s.name ASC
        ");
        $allSubs = $subStmt->fetchAll();

        $subsByCat = [];
        foreach ($allSubs as $s) {
            $subsByCat[$s['category_id']][] = $s;
        }

        foreach ($categories as &$c) {
            $c['subcategories'] = $subsByCat[$c['id']] ?? [];
        }

        ApiResponse::success('Categories and subcategories retrieved for admin', [
            'count'      => count($categories),
            'categories' => $categories
        ]);

    } catch (Exception $e) {
        error_log("Admin Categories GET Error: " . $e->getMessage());
        ApiResponse::error('Failed to retrieve categories.', 500);
    }
}

// 2. POST: Create, Rename, Delete Categories & Subcategories
if ($method === 'POST') {
    CSRF::enforce();

    $rawInput = file_get_contents('php://input');
    $data = [];
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) $data = $decoded;
    }
    if (empty($data)) $data = $_POST;

    $action = trim((string)($data['action'] ?? 'create_category'));

    try {
        switch ($action) {
            case 'create_category':
                $name        = trim((string)($data['name'] ?? ''));
                $description = trim((string)($data['description'] ?? ''));
                $imageUrl    = trim((string)($data['image_url'] ?? ''));

                if (empty($name)) {
                    ApiResponse::error('Category name is required.', 422);
                }

                $slugBase = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
                $slug = $slugBase;
                $c = 1;
                $chkCatSlug = $pdo->prepare("SELECT id FROM `categories` WHERE slug = :slug LIMIT 1");
                while (true) {
                    $chkCatSlug->execute([':slug' => $slug]);
                    if (!$chkCatSlug->fetchColumn()) break;
                    $slug = $slugBase . '-' . (++$c);
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("INSERT INTO `categories` (`name`, `slug`, `description`, `image_url`) VALUES (:name, :slug, :desc, :img)");
                $stmt->execute([
                    ':name' => $name,
                    ':slug' => $slug,
                    ':desc' => $description ?: null,
                    ':img'  => $imageUrl ?: null
                ]);
                $catId = (int)$pdo->lastInsertId();

                // Automatically generate Men, Women, Children subcategories
                $subIns = $pdo->prepare("INSERT INTO `subcategories` (`category_id`, `name`, `slug`, `target_group`) VALUES (:cid, :name, :slug, :target)");
                foreach (['Men', 'Women', 'Children'] as $group) {
                    $subName = "{$name} for {$group}";
                    $subSlug = "{$slug}-" . strtolower($group);
                    $subIns->execute([
                        ':cid'    => $catId,
                        ':name'   => $subName,
                        ':slug'   => $subSlug,
                        ':target' => $group
                    ]);
                }

                $pdo->commit();

                AdminNotification::create([
                    'notification_type' => 'category_created',
                    'title'             => 'Category Created',
                    'message'           => "Admin created category '{$name}' with default classifications.",
                ], $pdo);

                ApiResponse::success('Category and classifications created successfully.', [
                    'category_id' => $catId,
                    'slug'        => $slug
                ], 201);
                break;

            case 'update_category':
                $id          = (int)($data['category_id'] ?? 0);
                $name        = trim((string)($data['name'] ?? ''));
                $description = trim((string)($data['description'] ?? ''));

                if ($id <= 0 || empty($name)) {
                    ApiResponse::error('Category ID and name are required.', 422);
                }

                $upd = $pdo->prepare("UPDATE `categories` SET name = :name, description = :desc WHERE id = :id");
                $upd->execute([':name' => $name, ':desc' => $description ?: null, ':id' => $id]);

                AdminNotification::create([
                    'notification_type' => 'category_updated',
                    'title'             => 'Category Updated',
                    'message'           => "Admin renamed category #{$id} to '{$name}'.",
                ], $pdo);

                ApiResponse::success('Category renamed successfully.');
                break;

            case 'delete_category':
                $id = (int)($data['category_id'] ?? 0);
                if ($id <= 0) ApiResponse::error('Category ID is required.', 422);

                // Check if products exist in category
                $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM `products` WHERE category_id = :id");
                $cntStmt->execute([':id' => $id]);
                $prodCount = (int)$cntStmt->fetchColumn();
                if ($prodCount > 0) {
                    ApiResponse::error("Cannot delete category. {$prodCount} active product(s) currently belong to it. Please reassign products first.", 409);
                }

                $pdo->prepare("DELETE FROM `categories` WHERE id = :id")->execute([':id' => $id]);

                AdminNotification::create([
                    'notification_type' => 'category_deleted',
                    'title'             => 'Category Deleted',
                    'message'           => "Admin deleted category #{$id}.",
                ], $pdo);

                ApiResponse::success('Category removed safely.');
                break;

            case 'create_subcategory':
                $catId       = (int)($data['category_id'] ?? 0);
                $name        = trim((string)($data['name'] ?? ''));
                $targetGroup = trim((string)($data['target_group'] ?? 'Men'));

                if ($catId <= 0 || empty($name)) {
                    ApiResponse::error('Valid Category ID and classification name required.', 422);
                }
                if (!in_array($targetGroup, ['Men', 'Women', 'Children'], true)) {
                    $targetGroup = 'Men';
                }

                $slugBase = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
                $slug = $slugBase;
                $counter = 1;
                $chkSubSlug = $pdo->prepare("SELECT id FROM `subcategories` WHERE slug = :slug LIMIT 1");
                while (true) {
                    $chkSubSlug->execute([':slug' => $slug]);
                    if (!$chkSubSlug->fetchColumn()) break;
                    $slug = $slugBase . '-' . (++$counter);
                }

                $stmt = $pdo->prepare("
                    INSERT INTO `subcategories` (`category_id`, `name`, `slug`, `target_group`)
                    VALUES (:cid, :name, :slug, :tg)
                ");
                $stmt->execute([
                    ':cid'  => $catId,
                    ':name' => $name,
                    ':slug' => $slug,
                    ':tg'   => $targetGroup
                ]);
                $newSubId = (int)$pdo->lastInsertId();

                AdminNotification::create([
                    'notification_type' => 'category_created',
                    'title'             => 'Classification Created',
                    'message'           => "Admin created classification '{$name}' for category #{$catId}.",
                ], $pdo);

                ApiResponse::success('Classification created successfully.', [
                    'subcategory_id' => $newSubId
                ], 201);
                break;

            case 'update_subcategory':
                $id          = (int)($data['subcategory_id'] ?? 0);
                $name        = trim((string)($data['name'] ?? ''));
                $targetGroup = trim((string)($data['target_group'] ?? 'Men'));

                if ($id <= 0 || empty($name)) {
                    ApiResponse::error('Subcategory ID and name required.', 422);
                }
                if (!in_array($targetGroup, ['Men', 'Women', 'Children'], true)) {
                    $targetGroup = 'Men';
                }

                $upd = $pdo->prepare("UPDATE `subcategories` SET name = :name, target_group = :tg WHERE id = :id");
                $upd->execute([':name' => $name, ':tg' => $targetGroup, ':id' => $id]);

                ApiResponse::success('Classification updated successfully.');
                break;

            case 'delete_subcategory':
                $id = (int)($data['subcategory_id'] ?? 0);
                if ($id <= 0) ApiResponse::error('Subcategory ID required.', 422);

                // Set subcategory_id to NULL on products before deleting
                $pdo->prepare("UPDATE `products` SET subcategory_id = NULL WHERE subcategory_id = :id")->execute([':id' => $id]);
                $pdo->prepare("DELETE FROM `subcategories` WHERE id = :id")->execute([':id' => $id]);

                ApiResponse::success('Classification removed safely.');
                break;

            default:
                ApiResponse::error('Unknown action.', 400);
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Admin Categories Error: " . $e->getMessage());
        ApiResponse::error('Failed to process category action: ' . $e->getMessage(), 500);
    }
}

