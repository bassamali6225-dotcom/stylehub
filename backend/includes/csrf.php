<?php
/**
 * StyleHub - CSRF Protection Service
 * Provides cryptographic tokens stored in session and MySQL table
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class CSRF {
    private const TOKEN_KEY = '_stylehub_csrf_token';
    private const TOKEN_LIFETIME = 7200; // 2 hours

    /**
     * Start secure session if not started
     */
    public static function initSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $cookieParams = [
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'httponly' => true,
                'samesite' => 'Lax'
            ];
            session_set_cookie_params($cookieParams);
            session_start();
        }
    }

    /**
     * Generate or retrieve existing CSRF token
     * @return string
     */
    public static function getToken(): string {
        self::initSession();

        if (empty($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
            self::persistToken($_SESSION[self::TOKEN_KEY]);
        }

        return $_SESSION[self::TOKEN_KEY];
    }

    /**
     * Regenerate CSRF token (after login/logout)
     * @return string
     */
    public static function regenerateToken(): string {
        self::initSession();
        $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        self::persistToken($_SESSION[self::TOKEN_KEY]);
        return $_SESSION[self::TOKEN_KEY];
    }

    /**
     * Save token to database for audit & verification
     */
    private static function persistToken(string $token): void {
        try {
            $pdo = getDB();
            $sessionId = session_id();
            $userId = $_SESSION['user_id'] ?? null;
            $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_LIFETIME);

            $stmt = $pdo->prepare("
                INSERT INTO `csrf_tokens` (`session_id`, `user_id`, `token`, `expires_at`)
                VALUES (:session_id, :user_id, :token, :expires_at)
            ");
            $stmt->execute([
                ':session_id' => $sessionId,
                ':user_id'    => $userId,
                ':token'      => $token,
                ':expires_at' => $expiresAt
            ]);
        } catch (Exception $e) {
            // Non-blocking fallback to session only if DB is temporarily unreachable
            error_log("CSRF persist warning: " . $e->getMessage());
        }
    }

    /**
     * Validate CSRF token from Request Headers, POST body, or JSON payload
     * @return bool
     */
    public static function validate(): bool {
        self::initSession();

        // Safe methods don't need CSRF validation
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $sessionToken = $_SESSION[self::TOKEN_KEY] ?? '';
        if (empty($sessionToken)) {
            return false;
        }

        $submittedToken = '';

        // 1. Check HTTP Header X-CSRF-Token or X-XSRF-Token
        if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'];
        } elseif (!empty($_SERVER['HTTP_X_XSRF_TOKEN'])) {
            $submittedToken = $_SERVER['HTTP_X_XSRF_TOKEN'];
        }
        // 2. Check standard POST field
        elseif (!empty($_POST['csrf_token'])) {
            $submittedToken = (string)$_POST['csrf_token'];
        }
        // 3. Check JSON request body
        else {
            $rawInput = file_get_contents('php://input');
            if (!empty($rawInput)) {
                $decoded = json_decode($rawInput, true);
                if (is_array($decoded) && !empty($decoded['csrf_token'])) {
                    $submittedToken = (string)$decoded['csrf_token'];
                }
            }
        }

        if (empty($submittedToken)) {
            return false;
        }

        return hash_equals($sessionToken, $submittedToken);
    }

    /**
     * Enforce CSRF validation, halts with 403 response on failure
     */
    public static function enforce(): void {
        if (!self::validate()) {
            if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false ||
                strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
                require_once __DIR__ . '/response.php';
                ApiResponse::error('CSRF validation failed or token expired. Please refresh the page.', 403);
            } else {
                http_response_code(403);
                header('Location: /frontend/403.html');
                exit;
            }
        }
    }
}

