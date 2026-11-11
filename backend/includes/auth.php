<?php
/**
 * StyleHub - Authentication & Access Control Service
 * Handles customer and admin sessions, password hashing, and role checks
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/csrf.php';

class Auth {
    /**
     * Start secure session
     */
    public static function init(): void {
        CSRF::initSession();
    }

    // ================= CUSTOMER AUTHENTICATION ================= //

    /**
     * Hash plain password
     */
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Verify plain password against hash
     */
    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }

    /**
     * Check if customer is currently logged in
     */
    public static function isCustomerLoggedIn(): bool {
        self::init();
        return !empty($_SESSION['user_id']) && !empty($_SESSION['user_email']);
    }

    /**
     * Get current logged-in customer data
     */
    public static function getCurrentCustomer(): ?array {
        if (!self::isCustomerLoggedIn()) {
            return null;
        }

        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT id, name, email, phone, status, created_at FROM `users` WHERE id = :id AND status = 'active' LIMIT 1");
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $user = $stmt->fetch();
            return $user ?: null;
        } catch (Exception $e) {
            error_log("Error fetching customer: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Log in a customer
     */
    public static function loginCustomer(array $user): void {
        self::init();
        // Regenerate session ID to prevent session fixation attacks
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = 'customer';
        $_SESSION['logged_in_at'] = time();

        CSRF::regenerateToken();
    }

    /**
     * Require customer authentication; redirect to login if not authenticated
     */
    public static function requireCustomer(bool $isApi = false): void {
        self::init();
        if (!self::isCustomerLoggedIn()) {
            if ($isApi) {
                require_once __DIR__ . '/response.php';
                ApiResponse::error('Authentication required. Please log in as a customer.', 401);
            } else {
                header('Location: /frontend/login.html?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/frontend/profile.html'));
                exit;
            }
        }
    }

    /**
     * Logout customer
     */
    public static function logoutCustomer(): void {
        self::init();
        unset($_SESSION['user_id']);
        unset($_SESSION['user_name']);
        unset($_SESSION['user_email']);
        unset($_SESSION['user_role']);
        unset($_SESSION['logged_in_at']);
        CSRF::regenerateToken();
    }

    // ================= ADMIN AUTHENTICATION ================= //

    /**
     * Check if admin is currently logged in
     */
    public static function isAdminLoggedIn(): bool {
        self::init();
        return !empty($_SESSION['admin_id']) && !empty($_SESSION['admin_role']) && in_array($_SESSION['admin_role'], ['superadmin', 'admin'], true);
    }

    /**
     * Get current logged-in admin data
     */
    public static function getCurrentAdmin(): ?array {
        if (!self::isAdminLoggedIn()) {
            return null;
        }

        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT id, username, email, role, created_at FROM `admins` WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $_SESSION['admin_id']]);
            $admin = $stmt->fetch();
            return $admin ?: null;
        } catch (Exception $e) {
            error_log("Error fetching admin: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Log in an admin
     */
    public static function loginAdmin(array $admin): void {
        self::init();
        session_regenerate_id(true);

        $_SESSION['admin_id'] = (int)$admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_email'] = $admin['email'];
        $_SESSION['admin_role'] = $admin['role'];
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_logged_in_at'] = time();

        CSRF::regenerateToken();
    }

    /**
     * Require admin authentication; prevents public users from accessing admin pages
     */
    public static function requireAdmin(bool $isApi = false): void {
        self::init();
        if (!self::isAdminLoggedIn()) {
            if ($isApi) {
                require_once __DIR__ . '/response.php';
                ApiResponse::error('Access Denied. Administrator privileges required.', 403);
            } else {
                // If a customer is logged in or user has no admin credentials, send to 403 Access Denied or admin login
                if (self::isCustomerLoggedIn()) {
                    header('Location: /frontend/403.html');
                } else {
                    header('Location: /backend/admin/login.php');
                }
                exit;
            }
        }
    }

    /**
     * Logout admin
     */
    public static function logoutAdmin(): void {
        self::init();
        unset($_SESSION['admin_id']);
        unset($_SESSION['admin_username']);
        unset($_SESSION['admin_email']);
        unset($_SESSION['admin_role']);
        unset($_SESSION['admin_logged_in']);
        unset($_SESSION['admin_logged_in_at']);
        CSRF::regenerateToken();
    }
}

// Automatically ensure session is initialized
Auth::init();

