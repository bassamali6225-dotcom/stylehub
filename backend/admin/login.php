<?php
/**
 * StyleHub - Admin Secure Login
 * Authentication portal for store administrators
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

// If already authenticated as admin, redirect directly to dashboard
if (Auth::isAdminLoggedIn()) {
    header('Location: /backend/admin/dashboard.php');
    exit;
}

$errorMessage = '';
$csrfToken = CSRF::getToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!CSRF::validate()) {
        $errorMessage = 'Security validation failed (CSRF token invalid). Please try again.';
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password   = (string)($_POST['password'] ?? '');

        if (empty($identifier) || empty($password)) {
            $errorMessage = 'Please provide both admin username/email and password.';
        } else {
            try {
                $pdo = getDB();
                $stmt = $pdo->prepare("
                    SELECT id, username, email, password, role 
                    FROM `admins` 
                    WHERE (username = :ident1 OR email = :ident2) 
                    LIMIT 1
                ");
                $stmt->execute([
                    ':ident1' => $identifier,
                    ':ident2' => $identifier
                ]);
                $admin = $stmt->fetch();

                if ($admin && Auth::verifyPassword($password, $admin['password'])) {
                    Auth::loginAdmin($admin);
                    header('Location: /backend/admin/dashboard.php');
                    exit;
                } else {
                    $errorMessage = 'Invalid administrator credentials. Access logged and denied.';
                }
            } catch (Exception $e) {
                error_log("Admin Login Error: " . $e->getMessage());
                $errorMessage = 'An error occurred during authentication. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal Login | StyleHub</title>
    <link rel="stylesheet" href="/frontend/css/style.css">
    <meta name="robots" content="noindex, nofollow">
</head>
<body class="admin-auth-body">
    <div class="admin-auth-wrapper">
        <div class="admin-auth-card">
            <div class="admin-auth-header">
                <div class="admin-brand-badge">STYLEHUB ADMIN</div>
                <h1 class="admin-auth-title">Administrative Portal</h1>
                <p class="admin-auth-subtitle">Restricted access for authorized management personnel only</p>
            </div>

            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-error" role="alert">
                    <span class="alert-icon">⚠️</span>
                    <span class="alert-text"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <form action="/backend/admin/login.php" method="POST" class="auth-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-group">
                    <label for="admin-identifier" class="form-label">Username or Email</label>
                    <input 
                        type="text" 
                        id="admin-identifier" 
                        name="identifier" 
                        class="form-input" 
                        required 
                        autocomplete="username" 
                        placeholder="admin or admin@stylehub.local"
                        value="<?php echo htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="admin-password" class="form-label">Admin Password</label>
                    <input 
                        type="password" 
                        id="admin-password" 
                        name="password" 
                        class="form-input" 
                        required 
                        autocomplete="current-password" 
                        placeholder="••••••••••••"
                    >
                </div>

                <button type="submit" class="btn btn-primary btn-block">Authenticate to Admin Portal</button>
            </form>

            <div class="admin-auth-footer">
                <a href="/frontend/index.html" class="link-subtle">← Return to Public Storefront</a>
            </div>
        </div>
    </div>
</body>
</html>
