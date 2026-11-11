<?php
/**
 * StyleHub - Dedicated Admin Notification Centre Route
 * Redirects authenticated administrators directly to the Notifications Tab in Dashboard.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

Auth::requireAdmin();

header('Location: /backend/admin/dashboard.php?tab=notifications');
exit;

