<?php
/**
 * StyleHub - CSRF Token Endpoint
 * Returns a valid CSRF token for forms and AJAX requests
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/response.php';

$token = CSRF::getToken();
ApiResponse::success('CSRF token generated', [
    'csrf_token' => $token
]);

