<?php
/**
 * StyleHub - Admin Logout
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

Auth::logoutAdmin();

header('Location: /backend/admin/login.php?logged_out=1');
exit;

