<?php
/**
 * StyleHub - API Response Helper
 */

declare(strict_types=1);

class ApiResponse {
    /**
     * Send JSON response
     * @param bool $success
     * @param string $message
     * @param mixed $data
     * @param int $statusCode
     */
    public static function json(bool $success, string $message = '', mixed $data = null, int $statusCode = 200): void {
        // Clear any previous output buffering
        if (ob_get_length()) {
            ob_clean();
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');

        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data'    => $data,
            'timestamp' => time()
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success(string $message = 'Success', mixed $data = null, int $statusCode = 200): void {
        self::json(true, $message, $data, $statusCode);
    }

    public static function error(string $message = 'An error occurred', int $statusCode = 400, mixed $data = null): void {
        self::json(false, $message, $data, $statusCode);
    }
}

