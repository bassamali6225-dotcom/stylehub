<?php
/**
 * StyleHub - PHP Built-in Server Router
 * Usage: php -S 127.0.0.1:8000 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Redirect root to frontend/index.html
if ($uri === '/' || $uri === '') {
    header('Location: /frontend/index.html');
    exit;
}

// Security: Prevent directory traversal
if (strpos($uri, '..') !== false) {
    http_response_code(403);
    require __DIR__ . '/frontend/403.html';
    exit;
}

$file = __DIR__ . $uri;

// If the requested URI is an existing file, serve it directly
if (is_file($file)) {
    // If it's a PHP file, let the server execute it
    if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
        require $file;
        exit;
    }

    // Explicit mime types for assets
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $mimes = [
        'css'  => 'text/css; charset=UTF-8',
        'js'   => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'html' => 'text/html; charset=UTF-8',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'xml'  => 'application/xml; charset=UTF-8',
        'txt'  => 'text/plain; charset=UTF-8',
        'webp' => 'image/webp'
    ];

    if (isset($mimes[$ext])) {
        header("Content-Type: {$mimes[$ext]}");
    }
    readfile($file);
    exit;
}

// If file not found, serve 404
http_response_code(404);
if (file_exists(__DIR__ . '/frontend/404.html')) {
    require __DIR__ . '/frontend/404.html';
} else {
    echo "404 Not Found";
}
exit;

