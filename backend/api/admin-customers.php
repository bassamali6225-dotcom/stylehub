<?php
/**
 * StyleHub - Admin Customer Management API
 * View customer directory, profile, order history, addresses, reviews, and returns.
 * Activate / deactivate customer accounts. Edit customer profile.
 * Security: NEVER exposes password hashes!
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/response.php';

Auth::requireAdmin(true);

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// 1. GET: Customer Directory or Specific Customer Dossier
if ($method === 'GET') {
    $customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $search = trim((string)($_GET['search'] ?? ''));

    try {
        if ($customerId > 0) {
            // Detailed Customer Dossier (NO PASSWORD IN SELECT)
            $stmt = $pdo->prepare("
                SELECT id, name, email, phone, status, created_at, updated_at
                FROM `users`
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $customerId]);
            $user = $stmt->fetch();

            if (!$user) {
                ApiResponse::error('Customer not found.', 404);
            }

            // Customer Addresses
            $addrStmt = $pdo->prepare("SELECT * FROM `addresses` WHERE user_id = :uid ORDER BY is_default DESC, id DESC");
            $addrStmt->execute([':uid' => $customerId]);
            $addresses = $addrStmt->fetchAll();

            // Customer Order History
            $ordStmt = $pdo->prepare("
                SELECT id, order_number, total_amount, status, payment_method, dispatch_date, created_at
                FROM `orders`
                WHERE user_id = :uid
                ORDER BY created_at DESC
            ");
            $ordStmt->execute([':uid' => $customerId]);
            $orders = $ordStmt->fetchAll();

            // Customer Reviews
            $revStmt = $pdo->prepare("
                SELECT r.id, r.product_id, r.rating, r.title, r.comment, r.created_at, p.name AS product_name
                FROM `reviews` r
                JOIN `products` p ON r.product_id = p.id
                WHERE r.user_id = :uid
                ORDER BY r.created_at DESC
            ");
            $revStmt->execute([':uid' => $customerId]);
            $reviews = $revStmt->fetchAll();

            // Customer Return Requests
            $retStmt = $pdo->prepare("
                SELECT r.id, r.order_id, r.reason, r.details, r.status, r.created_at, o.order_number
                FROM `returns` r
                JOIN `orders` o ON r.order_id = o.id
                WHERE r.user_id = :uid
                ORDER BY r.created_at DESC
            ");
            $retStmt->execute([':uid' => $customerId]);
            $returns = $retStmt->fetchAll();

            ApiResponse::success('Customer profile dossier loaded', [
                'customer'  => $user,
                'addresses' => $addresses,
                'orders'    => $orders,
                'reviews'   => $reviews,
                'returns'   => $returns
            ]);
        }

        // List Customers with Aggregate Metrics (NO PASSWORDS)
        $sql = "
            SELECT 
                u.id, u.name, u.email, u.phone, u.status, u.created_at,
                COUNT(DISTINCT o.id) AS total_orders,
                COALESCE(SUM(o.total_amount), 0.00) AS total_spent
            FROM `users` u
            LEFT JOIN `orders` o ON u.id = o.user_id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (u.name LIKE :s1 OR u.email LIKE :s2 OR u.phone LIKE :s3)";
            $params[':s1'] = "%{$search}%";
            $params[':s2'] = "%{$search}%";
            $params[':s3'] = "%{$search}%";
        }

        $sql .= " GROUP BY u.id ORDER BY u.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $customers = $stmt->fetchAll();

        ApiResponse::success('Customer directory loaded', [
            'count'     => count($customers),
            'customers' => $customers
        ]);

    } catch (Exception $e) {
        error_log("Admin Customers GET Error: " . $e->getMessage());
        ApiResponse::error('Failed to load customers.', 500);
    }
}

// 2. POST: Status Toggle & Details Edit
if ($method === 'POST') {
    CSRF::enforce();

    $rawInput = file_get_contents('php://input');
    $data = [];
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) $data = $decoded;
    }
    if (empty($data)) $data = $_POST;

    $action = trim((string)($data['action'] ?? 'toggle_status'));

    try {
        switch ($action) {
            case 'toggle_status':
                $id = (int)($data['customer_id'] ?? 0);
                $status = trim((string)($data['status'] ?? ''));

                if ($id <= 0 || !in_array($status, ['active', 'inactive'], true)) {
                    ApiResponse::error('Valid Customer ID and status (active/inactive) required.', 422);
                }

                $stmt = $pdo->prepare("UPDATE `users` SET status = :st WHERE id = :id");
                $stmt->execute([':st' => $status, ':id' => $id]);

                ApiResponse::success("Customer account set to {$status}.");
                break;

            case 'update_customer':
                $id    = (int)($data['customer_id'] ?? 0);
                $name  = trim((string)($data['name'] ?? ''));
                $email = trim((string)($data['email'] ?? ''));
                $phone = trim((string)($data['phone'] ?? ''));

                if ($id <= 0 || empty($name) || empty($email)) {
                    ApiResponse::error('Customer ID, name, and email are required.', 422);
                }

                // Check email uniqueness
                $chk = $pdo->prepare("SELECT id FROM `users` WHERE email = :email AND id != :id LIMIT 1");
                $chk->execute([':email' => $email, ':id' => $id]);
                if ($chk->fetch()) {
                    ApiResponse::error('Email is already registered by another customer.', 409);
                }

                $stmt = $pdo->prepare("
                    UPDATE `users` SET
                        name = :name,
                        email = :email,
                        phone = :phone
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':name'  => $name,
                    ':email' => $email,
                    ':phone' => $phone ?: null,
                    ':id'    => $id
                ]);

                ApiResponse::success('Customer contact details updated.');
                break;

            default:
                ApiResponse::error('Unknown action.', 400);
        }

    } catch (Exception $e) {
        error_log("Admin Customers Error: " . $e->getMessage());
        ApiResponse::error('Failed to update customer: ' . $e->getMessage(), 500);
    }
}

