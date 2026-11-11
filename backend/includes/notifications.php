<?php
/**
 * StyleHub - Admin Notification Center Service
 * Handles notification creation, deduplication, stock threshold checking, and retrieval.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class AdminNotification {
    /**
     * Create an admin notification with deduplication safeguard.
     * Supports either an associative array or individual parameters.
     */
    public static function create(
        array|string $dataOrType,
        string|PDO|null $titleOrPdo = null,
        ?string $message = null,
        ?int $relatedUserId = null,
        ?int $relatedProductId = null,
        ?int $relatedOrderId = null,
        ?int $relatedReturnId = null,
        ?string $recipientRole = 'admin',
        ?int $adminId = null,
        ?PDO $pdo = null
    ): ?int {
        try {
            if (is_array($dataOrType)) {
                $data = $dataOrType;
                $pdo = ($titleOrPdo instanceof PDO) ? $titleOrPdo : ($pdo ?? Database::getConnection());
            } else {
                $data = [
                    'notification_type'  => $dataOrType,
                    'title'              => is_string($titleOrPdo) ? $titleOrPdo : '',
                    'message'            => $message,
                    'related_user_id'    => $relatedUserId,
                    'related_product_id' => $relatedProductId,
                    'related_order_id'   => $relatedOrderId,
                    'related_return_id'  => $relatedReturnId,
                    'recipient_role'     => $recipientRole ?? 'admin',
                    'admin_id'           => $adminId
                ];
                $pdo = $pdo ?? Database::getConnection();
            }

            $adminId         = isset($data['admin_id']) && is_numeric($data['admin_id']) ? (int)$data['admin_id'] : null;
            $recipientRole   = trim((string)($data['recipient_role'] ?? 'admin'));
            $type            = trim((string)($data['notification_type'] ?? 'general'));
            $title           = trim((string)($data['title'] ?? ''));
            $message         = trim((string)($data['message'] ?? ''));
            $relatedUserId   = isset($data['related_user_id']) && is_numeric($data['related_user_id']) ? (int)$data['related_user_id'] : null;
            $relatedProdId   = isset($data['related_product_id']) && is_numeric($data['related_product_id']) ? (int)$data['related_product_id'] : null;
            $relatedOrderId  = isset($data['related_order_id']) && is_numeric($data['related_order_id']) ? (int)$data['related_order_id'] : null;
            $relatedReturnId = isset($data['related_return_id']) && is_numeric($data['related_return_id']) ? (int)$data['related_return_id'] : null;

            if (empty($title) || empty($message)) {
                return null;
            }

            // Deduplication safeguard: Prevent exact duplicate events within a 15-second window
            $chkSql = "
                SELECT id FROM `admin_notifications`
                WHERE `notification_type` = :ntype
                  AND `title` = :title
                  AND (:ruid IS NULL OR `related_user_id` = :ruid2)
                  AND (:rpid IS NULL OR `related_product_id` = :rpid2)
                  AND (:roid IS NULL OR `related_order_id` = :roid2)
                  AND (:rret IS NULL OR `related_return_id` = :rret2)
                  AND `created_at` >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
                LIMIT 1
            ";
            $chkStmt = $pdo->prepare($chkSql);
            $chkStmt->execute([
                ':ntype' => $type,
                ':title' => $title,
                ':ruid'  => $relatedUserId,
                ':ruid2' => $relatedUserId,
                ':rpid'  => $relatedProdId,
                ':rpid2' => $relatedProdId,
                ':roid'  => $relatedOrderId,
                ':roid2' => $relatedOrderId,
                ':rret'  => $relatedReturnId,
                ':rret2' => $relatedReturnId
            ]);
            $existingId = $chkStmt->fetchColumn();
            if ($existingId) {
                return (int)$existingId;
            }

            // Insert notification
            $stmt = $pdo->prepare("
                INSERT INTO `admin_notifications` 
                (`admin_id`, `recipient_role`, `notification_type`, `title`, `message`, 
                 `related_user_id`, `related_product_id`, `related_order_id`, `related_return_id`, `is_read`, `created_at`)
                VALUES 
                (:aid, :role, :type, :title, :msg, :uid, :pid, :oid, :retid, 0, NOW())
            ");
            $stmt->execute([
                ':aid'   => $adminId,
                ':role'  => $recipientRole,
                ':type'  => $type,
                ':title' => $title,
                ':msg'   => $message,
                ':uid'   => $relatedUserId,
                ':pid'   => $relatedProdId,
                ':oid'   => $relatedOrderId,
                ':retid' => $relatedReturnId
            ]);

            return (int)$pdo->lastInsertId();
        } catch (Exception $e) {
            error_log("AdminNotification::create error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check product inventory level and trigger low stock / out of stock notification if applicable.
     */
    public static function checkProductStock(int $productId, ?PDO $pdo = null): void {
        try {
            $pdo = $pdo ?? Database::getConnection();

            $stmt = $pdo->prepare("
                SELECT p.id, p.name, p.low_stock_threshold, COALESCE(SUM(i.stock_quantity), 0) AS total_stock
                FROM `products` p
                LEFT JOIN `product_inventory` i ON p.id = i.product_id
                WHERE p.id = :pid
                GROUP BY p.id
                LIMIT 1
            ");
            $stmt->execute([':pid' => $productId]);
            $prod = $stmt->fetch();

            if (!$prod) return;

            $totalStock = (int)$prod['total_stock'];
            $threshold  = (int)$prod['low_stock_threshold'];
            $prodName   = $prod['name'];

            if ($totalStock <= 0) {
                // Out of stock warning: check if unread out-of-stock notification already exists
                $chk = $pdo->prepare("
                    SELECT id FROM `admin_notifications` 
                    WHERE `notification_type` = 'stock_out' 
                      AND `related_product_id` = :pid 
                      AND `is_read` = 0 
                    LIMIT 1
                ");
                $chk->execute([':pid' => $productId]);
                if (!$chk->fetchColumn()) {
                    self::create([
                        'notification_type'  => 'stock_out',
                        'title'              => 'Product Out of Stock',
                        'message'            => "Product '{$prodName}' is now out of stock.",
                        'related_product_id' => $productId
                    ], $pdo);
                }
            } elseif ($totalStock <= $threshold) {
                // Low stock warning: check if unread low-stock notification already exists
                $chk = $pdo->prepare("
                    SELECT id FROM `admin_notifications` 
                    WHERE `notification_type` = 'stock_low' 
                      AND `related_product_id` = :pid 
                      AND `is_read` = 0 
                    LIMIT 1
                ");
                $chk->execute([':pid' => $productId]);
                if (!$chk->fetchColumn()) {
                    self::create([
                        'notification_type'  => 'stock_low',
                        'title'              => 'Low Stock Warning',
                        'message'            => "Product '{$prodName}' is low in stock: {$totalStock} units remaining.",
                        'related_product_id' => $productId
                    ], $pdo);
                }
            }
        } catch (Exception $e) {
            error_log("AdminNotification::checkProductStock error: " . $e->getMessage());
        }
    }

    /**
     * Get count of unread notifications.
     */
    public static function getUnreadCount(?PDO $pdo = null): int {
        try {
            $pdo = $pdo ?? Database::getConnection();
            $stmt = $pdo->query("SELECT COUNT(*) FROM `admin_notifications` WHERE `is_read` = 0");
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("AdminNotification::getUnreadCount error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Mark a single notification as read.
     */
    public static function markAsRead(int $id, ?PDO $pdo = null): bool {
        try {
            $pdo = $pdo ?? Database::getConnection();
            $stmt = $pdo->prepare("UPDATE `admin_notifications` SET `is_read` = 1 WHERE `id` = :id");
            return $stmt->execute([':id' => $id]);
        } catch (Exception $e) {
            error_log("AdminNotification::markAsRead error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark all notifications as read.
     */
    public static function markAllAsRead(?PDO $pdo = null): int {
        try {
            $pdo = $pdo ?? Database::getConnection();
            $stmt = $pdo->query("UPDATE `admin_notifications` SET `is_read` = 1 WHERE `is_read` = 0");
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log("AdminNotification::markAllAsRead error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Retrieve notifications with filtering, searching, and pagination.
     */
    public static function getList(array $filters = [], ?PDO $pdo = null): array {
        try {
            $pdo = $pdo ?? Database::getConnection();

            $status     = trim((string)($filters['status'] ?? 'all')); // all, unread, read
            $type       = trim((string)($filters['type'] ?? 'all'));
            $datePreset = trim((string)($filters['date_preset'] ?? 'all')); // all, today, yesterday, week, month
            $dateFrom   = trim((string)($filters['date_from'] ?? ''));
            $dateTo     = trim((string)($filters['date_to'] ?? ''));
            $search     = trim((string)($filters['search'] ?? ''));
            $limit      = isset($filters['limit']) ? max(1, min(100, (int)$filters['limit'])) : 20;
            $offset     = isset($filters['offset']) ? max(0, (int)$filters['offset']) : 0;

            $where = ['1=1'];
            $params = [];

            // Read / Unread status
            if ($status === 'unread') {
                $where[] = 'n.is_read = 0';
            } elseif ($status === 'read') {
                $where[] = 'n.is_read = 1';
            }

            // Notification Type
            if ($type !== '' && $type !== 'all') {
                if ($type === 'order') {
                    $where[] = "(n.notification_type LIKE 'order_%')";
                } elseif ($type === 'stock') {
                    $where[] = "(n.notification_type LIKE 'stock_%')";
                } elseif ($type === 'return') {
                    $where[] = "(n.notification_type LIKE 'return_%' OR n.notification_type LIKE 'refund_%' OR n.notification_type LIKE 'replacement_%')";
                } elseif ($type === 'customer') {
                    $where[] = "(n.notification_type LIKE 'customer_%' OR n.notification_type LIKE 'review_%' OR n.notification_type LIKE 'address_%' OR n.notification_type LIKE 'cart_%' OR n.notification_type LIKE 'wishlist_%')";
                } elseif ($type === 'catalog') {
                    $where[] = "(n.notification_type LIKE 'product_%' OR n.notification_type LIKE 'category_%')";
                } else {
                    $where[] = 'n.notification_type = :ntype';
                    $params[':ntype'] = $type;
                }
            }

            // Date filtering
            if ($datePreset === 'today') {
                $where[] = 'n.created_at >= CURDATE()';
            } elseif ($datePreset === 'yesterday') {
                $where[] = 'n.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND n.created_at < CURDATE()';
            } elseif ($datePreset === 'week') {
                $where[] = 'n.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
            } elseif ($datePreset === 'month') {
                $where[] = 'n.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
            } elseif (!empty($dateFrom)) {
                $where[] = 'n.created_at >= :date_from';
                $params[':date_from'] = $dateFrom . ' 00:00:00';
                if (!empty($dateTo)) {
                    $where[] = 'n.created_at <= :date_to';
                    $params[':date_to'] = $dateTo . ' 23:59:59';
                }
            }

            // Keyword Search
            if (!empty($search)) {
                $where[] = '(n.title LIKE :s1 OR n.message LIKE :s2)';
                $params[':s1'] = "%{$search}%";
                $params[':s2'] = "%{$search}%";
            }

            $whereSql = implode(' AND ', $where);

            // Total matching count
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `admin_notifications` n WHERE {$whereSql}");
            $countStmt->execute($params);
            $totalCount = (int)$countStmt->fetchColumn();

            // Query items with related metadata
            $sql = "
                SELECT 
                    n.*,
                    u.name AS user_name,
                    u.email AS user_email,
                    p.name AS product_name,
                    p.slug AS product_slug,
                    o.order_number,
                    o.total_amount AS order_total,
                    o.status AS order_status,
                    r.status AS return_status
                FROM `admin_notifications` n
                LEFT JOIN `users` u ON n.related_user_id = u.id
                LEFT JOIN `products` p ON n.related_product_id = p.id
                LEFT JOIN `orders` o ON n.related_order_id = o.id
                LEFT JOIN `returns` r ON n.related_return_id = r.id
                WHERE {$whereSql}
                ORDER BY n.id DESC
                LIMIT :limit OFFSET :offset
            ";
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $notifications = $stmt->fetchAll();

            return [
                'total'         => $totalCount,
                'unread_count'  => self::getUnreadCount($pdo),
                'limit'         => $limit,
                'offset'        => $offset,
                'notifications' => $notifications
            ];
        } catch (Exception $e) {
            error_log("AdminNotification::getList error: " . $e->getMessage());
            return [
                'total'         => 0,
                'unread_count'  => 0,
                'limit'         => 20,
                'offset'        => 0,
                'notifications' => []
            ];
        }
    }
}
