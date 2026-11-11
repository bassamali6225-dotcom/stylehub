<?php
/**
 * StyleHub - Product Reviews API
 * Handles review submissions protected by CSRF and fetches approved reviews
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/notifications.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // 1. Enforce CSRF token protection
    CSRF::enforce();

    // 2. Ensure customer is authenticated
    if (!Auth::isCustomerLoggedIn()) {
        ApiResponse::error('You must be signed in to submit a review.', 401);
    }

    $rawInput = file_get_contents('php://input');
    $data = [];
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
    if (empty($data)) {
        $data = $_POST;
    }

    $productId = isset($data['product_id']) ? (int)$data['product_id'] : 0;
    $rating    = isset($data['rating']) ? (int)$data['rating'] : 0;
    $title     = trim((string)($data['title'] ?? ''));
    $comment   = trim((string)($data['comment'] ?? ''));

    if ($productId <= 0) {
        ApiResponse::error('Valid product ID is required.', 422);
    }
    if ($rating < 1 || $rating > 5) {
        ApiResponse::error('Please select a star rating between 1 and 5.', 422);
    }
    if (empty($comment) || mb_strlen($comment) < 5) {
        ApiResponse::error('Please share your thoughts (minimum 5 characters).', 422);
    }

    try {
        $pdo = getDB();
        $userId = (int)$_SESSION['user_id'];

        // Verify product exists
        $chk = $pdo->prepare("SELECT id FROM `products` WHERE id = :id LIMIT 1");
        $chk->execute([':id' => $productId]);
        if (!$chk->fetch()) {
            ApiResponse::error('Target product does not exist.', 404);
        }

        // Prevent duplicate review submission (same customer, same product, and identical review)
        $chkDup = $pdo->prepare("SELECT id FROM `reviews` WHERE product_id = :pid AND user_id = :uid AND comment = :comment LIMIT 1");
        $chkDup->execute([':pid' => $productId, ':uid' => $userId, ':comment' => $comment]);
        if ($chkDup->fetch()) {
            ApiResponse::error('Duplicate review detected. You have already posted this review.', 409);
        }

        // Insert review
        $stmt = $pdo->prepare("
            INSERT INTO `reviews` (`product_id`, `user_id`, `rating`, `title`, `comment`, `is_approved`)
            VALUES (:pid, :uid, :rating, :title, :comment, 1)
        ");
        $stmt->execute([
            ':pid'     => $productId,
            ':uid'     => $userId,
            ':rating'  => $rating,
            ':title'   => $title ?: null,
            ':comment' => $comment
        ]);

        $reviewId = (int)$pdo->lastInsertId();

        // Notify admin of submitted review
        $uName = $_SESSION['user_name'] ?? 'Customer';
        $pNameStmt = $pdo->prepare("SELECT name FROM `products` WHERE id = :id LIMIT 1");
        $pNameStmt->execute([':id' => $productId]);
        $prodName = $pNameStmt->fetchColumn() ?: "Product #{$productId}";

        AdminNotification::create([
            'notification_type'  => 'customer_review',
            'title'              => 'New Customer Review',
            'message'            => "Customer {$uName} submitted a {$rating}-star review for '{$prodName}'.",
            'related_user_id'    => $userId,
            'related_product_id' => $productId
        ], $pdo);

        ApiResponse::success('Thank you! Your review has been published.', [
            'review_id' => $reviewId
        ], 201);

    } catch (Exception $e) {
        error_log("Review Submit Error: " . $e->getMessage());
        ApiResponse::error('Failed to submit review.', 500);
    }
} elseif ($method === 'GET') {
    $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
    if ($productId <= 0) {
        ApiResponse::error('Product ID required.', 400);
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            SELECT 
                r.id, r.rating, r.title, r.comment, r.created_at,
                u.name AS author_name
            FROM `reviews` r
            JOIN `users` u ON r.user_id = u.id
            WHERE r.product_id = :pid AND r.is_approved = 1
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([':pid' => $productId]);
        $reviews = $stmt->fetchAll();

        ApiResponse::success('Reviews loaded', [
            'count'   => count($reviews),
            'reviews' => $reviews
        ]);
    } catch (Exception $e) {
        ApiResponse::error('Failed to fetch reviews.', 500);
    }
} else {
    ApiResponse::error('Method Not Allowed', 405);
}

