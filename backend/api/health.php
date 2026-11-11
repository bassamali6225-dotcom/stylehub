<?php
/**
 * StyleHub - System Health & Diagnostics Endpoint
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Method Not Allowed', 405);
}

try {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT VERSION() AS db_version, DATABASE() AS current_db");
    $dbInfo = $stmt->fetch();

    ApiResponse::success('StyleHub System Healthy', [
        'php_version' => PHP_VERSION,
        'database'    => $dbInfo,
        'status'      => 'operational',
        'phase'       => 1
    ]);
} catch (Exception $e) {
    ApiResponse::error('System unhealthy: ' . $e->getMessage(), 500);
}

