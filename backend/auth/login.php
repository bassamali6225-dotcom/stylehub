<?php
/**
 * StyleHub - Customer Login Endpoint
 * Verifies credentials, enforces CSRF, manages customer session
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/notifications.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method Not Allowed', 405);
}

// Enforce CSRF protection
CSRF::enforce();

// Parse input
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

$email    = trim(strtolower($data['email'] ?? ''));
$password = (string)($data['password'] ?? '');

if (empty($email) || empty($password)) {
    ApiResponse::error('Please enter both your email address and password.', 422);
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT id, name, email, password, status FROM `users` WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !Auth::verifyPassword($password, $user['password'])) {
        ApiResponse::error('Invalid email or password. Please verify your credentials and try again.', 401);
    }

    if ($user['status'] !== 'active') {
        ApiResponse::error('Your account has been deactivated. Please contact customer support.', 403);
    }

    // Set customer session
    Auth::loginCustomer($user);

    // Notify admin of customer sign-in
    AdminNotification::create([
        'notification_type' => 'customer_login',
        'title'             => 'Customer Signed In',
        'message'           => "Customer {$user['name']} ({$user['email']}) signed in.",
        'related_user_id'   => (int)$user['id']
    ], $pdo);

    ApiResponse::success('Welcome back, ' . htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') . '!', [
        'user' => [
            'id'    => $user['id'],
            'name'  => $user['name'],
            'email' => $user['email']
        ],
        'redirect' => '/frontend/index.html'
    ]);

} catch (Exception $e) {
    error_log("Login Error: " . $e->getMessage());
    ApiResponse::error('An unexpected error occurred during login. Please try again.', 500);
}

