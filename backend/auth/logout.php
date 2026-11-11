<?php
/**
 * StyleHub - Customer Logout Endpoint
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

Auth::logoutCustomer();

if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false ||
    strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    ApiResponse::success('You have been logged out successfully.', [
        'redirect' => '/frontend/login.html'
    ]);
} else {
    header('Location: /frontend/login.html');
    exit;
}

