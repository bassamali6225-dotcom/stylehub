<?php
/**
 * StyleHub - Current User Session Endpoint
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

$customer = Auth::getCurrentCustomer();

ApiResponse::success('Session status', [
    'logged_in' => $customer !== null,
    'user'      => $customer,
    'role'      => $customer ? 'customer' : 'guest'
]);

