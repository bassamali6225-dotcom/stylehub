<?php
/**
 * StyleHub - Admin Notifications API Endpoint
 * Handles retrieval, safe polling, marking read, and bulk mark as read for admin notifications.
 * Protected by Admin Authentication and CSRF.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/notifications.php';

// Strict Admin Session Protection
Auth::requireAdmin(true);

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// 1. GET: Fetch list of notifications or lightweight poll
if ($method === 'GET') {
    try {
        $poll = isset($_GET['poll']) && $_GET['poll'] === '1';

        if ($poll) {
            $unreadCount = AdminNotification::getUnreadCount($pdo);
            $latestIdStmt = $pdo->query("SELECT MAX(id) FROM `admin_notifications`");
            $latestId = (int)$latestIdStmt->fetchColumn();

            ApiResponse::success('Notification poll count', [
                'unread_count' => $unreadCount,
                'latest_id'    => $latestId
            ]);
        }

        // Full list with filters
        $filters = [
            'status'      => $_GET['status'] ?? 'all',
            'type'        => $_GET['type'] ?? 'all',
            'date_preset' => $_GET['date_preset'] ?? 'all',
            'date_from'   => $_GET['date_from'] ?? '',
            'date_to'     => $_GET['date_to'] ?? '',
            'search'      => $_GET['search'] ?? '',
            'limit'       => isset($_GET['limit']) ? (int)$_GET['limit'] : 20,
            'offset'      => isset($_GET['offset']) ? (int)$_GET['offset'] : 0,
        ];

        $data = AdminNotification::getList($filters, $pdo);
        ApiResponse::success('Admin notifications retrieved', $data);

    } catch (Exception $e) {
        error_log("Admin Notifications GET Error: " . $e->getMessage());
        ApiResponse::error('Failed to retrieve notifications.', 500);
    }
}

// 2. POST: State-changing actions (mark_read, mark_all_read, delete)
if ($method === 'POST') {
    CSRF::enforce();

    $rawInput = file_get_contents('php://input');
    $data = [];
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) $data = $decoded;
    }
    if (empty($data)) $data = $_POST;

    $action = trim((string)($data['action'] ?? 'mark_read'));

    try {
        switch ($action) {
            case 'mark_read':
                $notificationId = (int)($data['notification_id'] ?? 0);
                if ($notificationId <= 0) {
                    ApiResponse::error('Valid notification ID is required.', 422);
                }

                AdminNotification::markAsRead($notificationId, $pdo);
                $unread = AdminNotification::getUnreadCount($pdo);

                ApiResponse::success("Notification #{$notificationId} marked as read.", [
                    'notification_id' => $notificationId,
                    'unread_count'    => $unread
                ]);
                break;

            case 'mark_all_read':
                $affected = AdminNotification::markAllAsRead($pdo);
                $unread = AdminNotification::getUnreadCount($pdo);

                ApiResponse::success("All notifications marked as read ({$affected} updated).", [
                    'updated_count' => $affected,
                    'unread_count'  => $unread
                ]);
                break;

            case 'delete':
                $notificationId = (int)($data['notification_id'] ?? 0);
                if ($notificationId <= 0) {
                    ApiResponse::error('Valid notification ID is required.', 422);
                }

                $del = $pdo->prepare("DELETE FROM `admin_notifications` WHERE `id` = :id");
                $del->execute([':id' => $notificationId]);
                $unread = AdminNotification::getUnreadCount($pdo);

                ApiResponse::success("Notification #{$notificationId} removed.", [
                    'notification_id' => $notificationId,
                    'unread_count'    => $unread
                ]);
                break;

            default:
                ApiResponse::error('Unknown notification action.', 400);
        }

    } catch (Exception $e) {
        error_log("Admin Notifications POST Error: " . $e->getMessage());
        ApiResponse::error('Failed to update notifications.', 500);
    }
}

