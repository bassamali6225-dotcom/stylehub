<?php
/**
 * StyleHub - Admin Product Management API
 * Full CRUD for products, images, stock, threshold, colours, sizes, and manual badges.
 * Protected by Admin Authentication & CSRF.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/notifications.php';

// Strict Admin Gate
Auth::requireAdmin(true);

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// 1. GET: Retrieve Products or Single Product Details
if ($method === 'GET') {
    $productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $search = trim((string)($_GET['search'] ?? ''));
    $categoryFilter = trim((string)($_GET['category'] ?? ''));

    try {
        if ($productId > 0) {
            // Detailed Single Product View for Admin
            $stmt = $pdo->prepare("
                SELECT 
                    p.*,
                    c.name AS category_name,
                    c.slug AS category_slug,
                    sub.name AS subcategory_name,
                    sub.target_group,
                    COALESCE(inv.total_stock, 0) AS total_stock
                FROM `products` p
                JOIN `categories` c ON p.category_id = c.id
                LEFT JOIN `subcategories` sub ON p.subcategory_id = sub.id
                LEFT JOIN (
                    SELECT product_id, SUM(stock_quantity) AS total_stock
                    FROM `product_inventory`
                    GROUP BY product_id
                ) inv ON p.id = inv.product_id
                WHERE p.id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $productId]);
            $product = $stmt->fetch();

            if (!$product) {
                ApiResponse::error('Product not found.', 404);
            }

            // Fetch Images
            $imgStmt = $pdo->prepare("SELECT id, image_url, alt_text, is_primary FROM `product_images` WHERE product_id = :pid ORDER BY is_primary DESC, id ASC");
            $imgStmt->execute([':pid' => $productId]);
            $images = $imgStmt->fetchAll();

            // Fetch Colours
            $colStmt = $pdo->prepare("SELECT id, colour_name, colour_code FROM `product_colours` WHERE product_id = :pid ORDER BY id ASC");
            $colStmt->execute([':pid' => $productId]);
            $colours = $colStmt->fetchAll();

            // Fetch Sizes
            $szStmt = $pdo->prepare("SELECT id, size_name FROM `product_sizes` WHERE product_id = :pid ORDER BY id ASC");
            $szStmt->execute([':pid' => $productId]);
            $sizes = $szStmt->fetchAll();

            // Fetch Detailed Inventory Breakdown
            $invStmt = $pdo->prepare("
                SELECT 
                    i.id, i.colour_id, i.size_id, i.stock_quantity,
                    c.colour_name, s.size_name
                FROM `product_inventory` i
                LEFT JOIN `product_colours` c ON i.colour_id = c.id
                LEFT JOIN `product_sizes` s ON i.size_id = s.id
                WHERE i.product_id = :pid
            ");
            $invStmt->execute([':pid' => $productId]);
            $inventory = $invStmt->fetchAll();

            ApiResponse::success('Product details loaded for admin', [
                'product'   => $product,
                'images'    => $images,
                'colours'   => $colours,
                'sizes'     => $sizes,
                'inventory' => $inventory
            ]);
        }

        // List all products with Admin metrics (exact stock, low stock boolean, out of stock)
        $sql = "
            SELECT 
                p.id, p.name, p.slug, p.price, p.low_stock_threshold,
                p.is_featured, p.hide_when_out_of_stock, p.manual_badge_message, p.created_at,
                c.name AS category_name,
                c.slug AS category_slug,
                sub.name AS subcategory_name,
                sub.target_group,
                COALESCE(inv.total_stock, 0) AS total_stock,
                img.image_url AS primary_image
            FROM `products` p
            JOIN `categories` c ON p.category_id = c.id
            LEFT JOIN `subcategories` sub ON p.subcategory_id = sub.id
            LEFT JOIN (
                SELECT product_id, SUM(stock_quantity) AS total_stock
                FROM `product_inventory`
                GROUP BY product_id
            ) inv ON p.id = inv.product_id
            LEFT JOIN (
                SELECT product_id, image_url
                FROM `product_images`
                WHERE is_primary = 1
            ) img ON p.id = img.product_id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (p.name LIKE :search1 OR p.description LIKE :search2 OR p.slug LIKE :search3)";
            $params[':search1'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
            $params[':search3'] = "%{$search}%";
        }

        if (!empty($categoryFilter)) {
            $sql .= " AND (c.slug = :catslug OR c.name = :catname)";
            $params[':catslug'] = $categoryFilter;
            $params[':catname'] = $categoryFilter;
        }

        $sql .= " ORDER BY p.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        // Annotate stock states for admin visibility
        foreach ($products as &$p) {
            $stk = (int)$p['total_stock'];
            $th = (int)$p['low_stock_threshold'];
            $p['is_out_of_stock'] = ($stk <= 0);
            $p['is_low_stock']    = ($stk > 0 && $stk <= $th);
        }

        ApiResponse::success('Admin product list retrieved', [
            'count'    => count($products),
            'products' => $products
        ]);

    } catch (Exception $e) {
        error_log("Admin Products GET Error: " . $e->getMessage());
        ApiResponse::error('Failed to retrieve products.', 500);
    }
}

// 2. POST: State-changing management actions
if ($method === 'POST') {
    CSRF::enforce();

    $rawInput = file_get_contents('php://input');
    $data = [];
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) $data = $decoded;
    }
    if (empty($data)) $data = $_POST;

    $action = trim((string)($data['action'] ?? 'create'));

    try {
        switch ($action) {
            case 'create':
                $name            = trim((string)($data['name'] ?? ''));
                $categoryId      = (int)($data['category_id'] ?? 0);
                $subcategoryId   = !empty($data['subcategory_id']) ? (int)$data['subcategory_id'] : null;
                $description     = trim((string)($data['description'] ?? ''));
                $price           = (float)($data['price'] ?? 0.0);
                $lowThreshold    = isset($data['low_stock_threshold']) ? max(1, (int)$data['low_stock_threshold']) : 5;
                $isFeatured      = !empty($data['is_featured']) ? 1 : 0;
                $hideWhenOos     = isset($data['hide_when_out_of_stock']) ? (int)!empty($data['hide_when_out_of_stock']) : 1;
                $manualBadge     = trim((string)($data['manual_badge_message'] ?? ''));
                $initialStock    = isset($data['initial_stock']) ? max(0, (int)$data['initial_stock']) : 10;
                $imageUrl        = trim((string)($data['image_url'] ?? ''));

                if (empty($name) || $categoryId <= 0 || $price <= 0.0) {
                    ApiResponse::error('Please provide a valid product name, category, and positive price.', 422);
                }

                // Generate slug
                $slugBase = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
                $slug = $slugBase;
                $counter = 1;
                $chkProdSlug = $pdo->prepare("SELECT id FROM `products` WHERE slug = :slug LIMIT 1");
                while (true) {
                    $chkProdSlug->execute([':slug' => $slug]);
                    if (!$chkProdSlug->fetchColumn()) break;
                    $slug = $slugBase . '-' . (++$counter);
                }

                $pdo->beginTransaction();

                $insStmt = $pdo->prepare("
                    INSERT INTO `products` 
                    (`category_id`, `subcategory_id`, `name`, `slug`, `description`, `price`, `low_stock_threshold`, `is_featured`, `hide_when_out_of_stock`, `manual_badge_message`)
                    VALUES (:cid, :subid, :name, :slug, :desc, :price, :thresh, :feat, :hide, :badge)
                ");
                $insStmt->execute([
                    ':cid'    => $categoryId,
                    ':subid'  => $subcategoryId,
                    ':name'   => $name,
                    ':slug'   => $slug,
                    ':desc'   => $description,
                    ':price'  => $price,
                    ':thresh' => $lowThreshold,
                    ':feat'   => $isFeatured,
                    ':hide'   => $hideWhenOos,
                    ':badge'  => !empty($manualBadge) ? $manualBadge : null
                ]);
                $newProdId = (int)$pdo->lastInsertId();

                // Standard Default Colour & Size
                $colName = trim((string)($data['default_colour'] ?? 'Forest Green'));
                $insCol = $pdo->prepare("INSERT INTO `product_colours` (`product_id`, `colour_name`, `colour_code`) VALUES (:pid, :cname, '#15803d')");
                $insCol->execute([':pid' => $newProdId, ':cname' => $colName]);
                $newColId = (int)$pdo->lastInsertId();

                $szName = trim((string)($data['default_size'] ?? 'Standard'));
                $insSz = $pdo->prepare("INSERT INTO `product_sizes` (`product_id`, `size_name`) VALUES (:pid, :sname)");
                $insSz->execute([':pid' => $newProdId, ':sname' => $szName]);
                $newSzId = (int)$pdo->lastInsertId();

                // Initial inventory
                $insInv = $pdo->prepare("
                    INSERT INTO `product_inventory` (`product_id`, `colour_id`, `size_id`, `stock_quantity`)
                    VALUES (:pid, :cid, :sid, :qty)
                ");
                $insInv->execute([
                    ':pid' => $newProdId,
                    ':cid' => $newColId,
                    ':sid' => $newSzId,
                    ':qty' => $initialStock
                ]);

                // Primary Image
                if (!empty($imageUrl)) {
                    $insImg = $pdo->prepare("INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`) VALUES (:pid, :url, :alt, 1)");
                    $insImg->execute([':pid' => $newProdId, ':url' => $imageUrl, ':alt' => $name]);
                } else {
                    $defaultImg = 'https://images.unsplash.com/photo-1542272604-780c96856592?auto=format&fit=crop&w=800&q=80';
                    $insImg = $pdo->prepare("INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`) VALUES (:pid, :url, :alt, 1)");
                    $insImg->execute([':pid' => $newProdId, ':url' => $defaultImg, ':alt' => $name]);
                }

                $pdo->commit();

                AdminNotification::create([
                    'notification_type'  => 'product_created',
                    'title'              => 'Product Added',
                    'message'            => "Admin created product '{$name}' at \${$price}.",
                    'related_product_id' => $newProdId
                ], $pdo);

                AdminNotification::checkProductStock($newProdId, $pdo);

                ApiResponse::success('Product created successfully.', [
                    'product_id' => $newProdId,
                    'slug'       => $slug
                ], 201);
                break;

            case 'update':
                $id            = (int)($data['product_id'] ?? 0);
                $name          = trim((string)($data['name'] ?? ''));
                $categoryId    = (int)($data['category_id'] ?? 0);
                $subcategoryId = !empty($data['subcategory_id']) ? (int)$data['subcategory_id'] : null;
                $description   = trim((string)($data['description'] ?? ''));
                $price         = (float)($data['price'] ?? 0.0);
                $lowThreshold  = isset($data['low_stock_threshold']) ? max(1, (int)$data['low_stock_threshold']) : 5;
                $isFeatured    = !empty($data['is_featured']) ? 1 : 0;
                $hideWhenOos   = isset($data['hide_when_out_of_stock']) ? (int)!empty($data['hide_when_out_of_stock']) : 1;
                $manualBadge   = trim((string)($data['manual_badge_message'] ?? ''));

                if ($id <= 0 || empty($name) || $categoryId <= 0 || $price <= 0.0) {
                    ApiResponse::error('Valid product ID, name, category, and positive price are required.', 422);
                }

                $updStmt = $pdo->prepare("
                    UPDATE `products` SET
                        name                   = :name,
                        category_id            = :cid,
                        subcategory_id         = :subid,
                        description            = :desc,
                        price                  = :price,
                        low_stock_threshold    = :thresh,
                        is_featured            = :feat,
                        hide_when_out_of_stock = :hide,
                        manual_badge_message   = :badge
                    WHERE id = :id
                ");
                $updStmt->execute([
                    ':name'   => $name,
                    ':cid'    => $categoryId,
                    ':subid'  => $subcategoryId,
                    ':desc'   => $description,
                    ':price'  => $price,
                    ':thresh' => $lowThreshold,
                    ':feat'   => $isFeatured,
                    ':hide'   => $hideWhenOos,
                    ':badge'  => !empty($manualBadge) ? $manualBadge : null,
                    ':id'     => $id
                ]);

                AdminNotification::create([
                    'notification_type'  => 'product_updated',
                    'title'              => 'Product Updated',
                    'message'            => "Admin updated product details for '{$name}'.",
                    'related_product_id' => $id
                ], $pdo);

                AdminNotification::checkProductStock($id, $pdo);

                ApiResponse::success('Product details updated successfully.');
                break;

            case 'update_stock':
                $id       = (int)($data['product_id'] ?? 0);
                $quantity = isset($data['stock_quantity']) ? max(0, (int)$data['stock_quantity']) : 0;
                $colourId = !empty($data['colour_id']) ? (int)$data['colour_id'] : null;
                $sizeId   = !empty($data['size_id']) ? (int)$data['size_id'] : null;

                if ($id <= 0) {
                    ApiResponse::error('Product ID is required.', 422);
                }

                // Check if row exists in product_inventory
                $chkInv = $pdo->prepare("SELECT id FROM `product_inventory` WHERE product_id = :pid AND (colour_id = :col OR (colour_id IS NULL AND :col2 IS NULL)) LIMIT 1");
                $chkInv->execute([':pid' => $id, ':col' => $colourId, ':col2' => $colourId]);
                $invId = $chkInv->fetchColumn();

                if ($invId) {
                    $upd = $pdo->prepare("UPDATE `product_inventory` SET stock_quantity = :qty WHERE id = :id");
                    $upd->execute([':qty' => $quantity, ':id' => $invId]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO `product_inventory` (`product_id`, `colour_id`, `size_id`, `stock_quantity`) VALUES (:pid, :col, :sz, :qty)");
                    $ins->execute([':pid' => $id, ':col' => $colourId, ':sz' => $sizeId, ':qty' => $quantity]);
                }

                AdminNotification::create([
                    'notification_type'  => 'stock_updated',
                    'title'              => 'Stock Updated',
                    'message'            => "Stock level updated to {$quantity} units for product #{$id}.",
                    'related_product_id' => $id
                ], $pdo);

                AdminNotification::checkProductStock($id, $pdo);

                ApiResponse::success('Inventory stock level updated successfully.');
                break;

            case 'delete':
                $id = (int)($data['product_id'] ?? 0);
                if ($id <= 0) {
                    ApiResponse::error('Product ID is required.', 422);
                }

                // Delete product; cascading constraints clean up images, colours, sizes, and inventory.
                // Foreign keys on order_items have ON DELETE SET NULL to preserve customer receipts.
                $delStmt = $pdo->prepare("DELETE FROM `products` WHERE id = :id");
                $delStmt->execute([':id' => $id]);

                AdminNotification::create([
                    'notification_type'  => 'product_deleted',
                    'title'              => 'Product Deleted',
                    'message'            => "Admin removed product #{$id} from catalog.",
                    'related_product_id' => $id
                ], $pdo);

                ApiResponse::success('Product removed safely from catalog.');
                break;

            case 'add_colour':
                $pid   = (int)($data['product_id'] ?? 0);
                $cname = trim((string)($data['colour_name'] ?? ''));
                $ccode = trim((string)($data['colour_code'] ?? '#15803d'));

                if ($pid <= 0 || empty($cname)) {
                    ApiResponse::error('Product ID and Colour Name required.', 422);
                }

                $insC = $pdo->prepare("INSERT INTO `product_colours` (`product_id`, `colour_name`, `colour_code`) VALUES (:pid, :name, :code)");
                $insC->execute([':pid' => $pid, ':name' => $cname, ':code' => $ccode]);
                $newColId = (int)$pdo->lastInsertId();

                AdminNotification::create([
                    'notification_type'  => 'colour_added',
                    'title'              => 'Product Colour Added',
                    'message'            => "Colour '{$cname}' added to product #{$pid}.",
                    'related_product_id' => $pid
                ], $pdo);

                ApiResponse::success('Colour option added.', ['colour_id' => $newColId], 201);
                break;

            case 'delete_colour':
                $cid = (int)($data['colour_id'] ?? 0);
                if ($cid <= 0) ApiResponse::error('Colour ID required.', 422);

                $pdo->prepare("DELETE FROM `product_colours` WHERE id = :id")->execute([':id' => $cid]);
                ApiResponse::success('Colour option removed.');
                break;

            case 'add_size':
                $pid   = (int)($data['product_id'] ?? 0);
                $sname = trim((string)($data['size_name'] ?? ''));
                $order = isset($data['sort_order']) ? (int)$data['sort_order'] : 0;

                if ($pid <= 0 || empty($sname)) {
                    ApiResponse::error('Product ID and Size Name required.', 422);
                }

                $insS = $pdo->prepare("INSERT INTO `product_sizes` (`product_id`, `size_name`) VALUES (:pid, :name)");
                $insS->execute([':pid' => $pid, ':name' => $sname]);
                $newSzId = (int)$pdo->lastInsertId();

                AdminNotification::create([
                    'notification_type'  => 'size_added',
                    'title'              => 'Product Size Added',
                    'message'            => "Size '{$sname}' added to product #{$pid}.",
                    'related_product_id' => $pid
                ], $pdo);

                ApiResponse::success('Size option added.', ['size_id' => $newSzId], 201);
                break;

            case 'delete_size':
                $sid = (int)($data['size_id'] ?? 0);
                if ($sid <= 0) ApiResponse::error('Size ID required.', 422);

                $pdo->prepare("DELETE FROM `product_sizes` WHERE id = :id")->execute([':id' => $sid]);
                ApiResponse::success('Size option removed.');
                break;

            case 'upload_image':
                $pid = (int)($data['product_id'] ?? ($_POST['product_id'] ?? 0));
                if ($pid <= 0) {
                    ApiResponse::error('Valid Product ID is required for image upload.', 422);
                }

                if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                    ApiResponse::error('Please select a valid image file to upload.', 422);
                }

                $file = $_FILES['image'];
                $maxSize = 5 * 1024 * 1024; // 5MB
                if ($file['size'] > $maxSize) {
                    ApiResponse::error('Image file exceeds the 5MB size limit.', 422);
                }

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($file['tmp_name']);
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp'
                ];

                if (!isset($allowed[$mimeType])) {
                    ApiResponse::error('Invalid image format. Allowed formats: JPEG, PNG, WEBP.', 422);
                }

                $ext = $allowed[$mimeType];
                $uploadDir = __DIR__ . '/../uploads';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $safeName = 'prod_' . $pid . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $destination = $uploadDir . '/' . $safeName;

                if (!move_uploaded_file($file['tmp_name'], $destination)) {
                    ApiResponse::error('Failed to save uploaded image.', 500);
                }

                $webUrl = '/backend/uploads/' . $safeName;
                $isPrimary = !empty($_POST['is_primary']) ? 1 : 0;

                if ($isPrimary) {
                    $pdo->prepare("UPDATE `product_images` SET is_primary = 0 WHERE product_id = :pid")->execute([':pid' => $pid]);
                }

                $insImg = $pdo->prepare("INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`) VALUES (:pid, :url, :alt, :pri)");
                $insImg->execute([':pid' => $pid, ':url' => $webUrl, ':alt' => 'Product Photo', ':pri' => $isPrimary]);
                $newImgId = (int)$pdo->lastInsertId();

                AdminNotification::create([
                    'notification_type'  => 'image_uploaded',
                    'title'              => 'Product Image Uploaded',
                    'message'            => "New photo uploaded for product #{$pid}.",
                    'related_product_id' => $pid
                ], $pdo);

                ApiResponse::success('Product image uploaded successfully.', [
                    'image_id'  => $newImgId,
                    'image_url' => $webUrl
                ], 201);
                break;

            default:
                ApiResponse::error('Unknown action.', 400);
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Admin Products Error: " . $e->getMessage());
        ApiResponse::error('Failed to process product management action: ' . $e->getMessage(), 500);
    }
}
