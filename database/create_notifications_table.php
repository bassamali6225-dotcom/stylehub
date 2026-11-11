<?php
require_once dirname(__DIR__) . '/backend/config/database.php';

try {
    $pdo = Database::getConnection();

    $sql = "
    CREATE TABLE IF NOT EXISTS `admin_notifications` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `admin_id` INT UNSIGNED NULL,
        `recipient_role` VARCHAR(50) NOT NULL DEFAULT 'admin',
        `notification_type` VARCHAR(100) NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `message` TEXT NOT NULL,
        `related_user_id` INT UNSIGNED NULL,
        `related_product_id` INT UNSIGNED NULL,
        `related_order_id` INT UNSIGNED NULL,
        `related_return_id` INT UNSIGNED NULL,
        `is_read` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_admin_notif_is_read` (`is_read`),
        INDEX `idx_admin_notif_type` (`notification_type`),
        INDEX `idx_admin_notif_created_at` (`created_at`),
        INDEX `idx_admin_notif_user` (`related_user_id`),
        INDEX `idx_admin_notif_order` (`related_order_id`),
        INDEX `idx_admin_notif_product` (`related_product_id`),
        INDEX `idx_admin_notif_return` (`related_return_id`),
        CONSTRAINT `fk_admin_notif_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
        CONSTRAINT `fk_admin_notif_user` FOREIGN KEY (`related_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
        CONSTRAINT `fk_admin_notif_product` FOREIGN KEY (`related_product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
        CONSTRAINT `fk_admin_notif_order` FOREIGN KEY (`related_order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
        CONSTRAINT `fk_admin_notif_return` FOREIGN KEY (`related_return_id`) REFERENCES `returns` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $pdo->exec($sql);
    echo "admin_notifications table created or verified successfully.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
