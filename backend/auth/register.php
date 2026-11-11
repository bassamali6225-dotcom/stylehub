<?php
/**
 * StyleHub - Customer Registration Endpoint
 * Validates input, hashes password, prevents duplicates, enforces CSRF
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

// Parse input (supports JSON or Form POST)
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

$name     = trim($data['name'] ?? '');
$email    = trim(strtolower($data['email'] ?? ''));
$password = (string)($data['password'] ?? '');
$phone    = trim($data['phone'] ?? '');

// Validation
$errors = [];
if (empty($name) || mb_strlen($name) < 2) {
    $errors[] = 'Full name is required (minimum 2 characters).';
}
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email address is required.';
}
if (empty($password) || mb_strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters long.';
}

if (!empty($errors)) {
    ApiResponse::error(implode(' ', $errors), 422, ['errors' => $errors]);
}

try {
    $pdo = getDB();

    // Check if email already exists
    $checkStmt = $pdo->prepare("SELECT id FROM `users` WHERE email = :email LIMIT 1");
    $checkStmt->execute([':email' => $email]);
    if ($checkStmt->fetch()) {
        ApiResponse::error('An account with this email address already exists. Please log in instead.', 409);
    }

    // Secure password hashing
    $hashedPassword = Auth::hashPassword($password);

    // Insert user
    $insertStmt = $pdo->prepare("
        INSERT INTO `users` (`name`, `email`, `password`, `phone`, `status`)
        VALUES (:name, :email, :password, :phone, 'active')
    ");
    $insertStmt->execute([
        ':name'     => $name,
        ':email'    => $email,
        ':password' => $hashedPassword,
        ':phone'    => $phone ?: null
    ]);

    $newUserId = (int)$pdo->lastInsertId();

    $newUser = [
        'id'    => $newUserId,
        'name'  => $name,
        'email' => $email,
        'phone' => $phone
    ];

    // Automatically log in the user
    Auth::loginCustomer($newUser);

    ApiResponse::success('Registration successful. Welcome to StyleHub!', [
        'user' => [
            'id'    => $newUser['id'],
            'name'  => $newUser['name'],
            'email' => $newUser['email']
        ],
        'redirect' => '/frontend/index.html'
    ], 201);

} catch (Exception $e) {
    error_log("Registration Error: " . $e->getMessage());
    ApiResponse::error('Failed to create account. Please try again.', 500);
}

