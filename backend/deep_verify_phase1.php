<?php
/**
 * StyleHub - In-depth Deep Verification Suite for Phase 1
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

$pdo = getDB();
$errors = [];
$checks = 0;

function check(string $label, bool $condition, string $failMsg = ''): void {
    global $errors, $checks;
    $checks++;
    if ($condition) {
        echo "  [OK] {$label}\n";
    } else {
        echo "  [FAIL] {$label}: {$failMsg}\n";
        $errors[] = "{$label}: {$failMsg}";
    }
}

echo "========================================================\n";
echo "   STYLEHUB PHASE 1 DEEP VERIFICATION SUITE              \n";
echo "========================================================\n\n";

// 1. DATABASE TABLES VERIFICATION
echo "[1] Checking Database Tables (All 21 Required)...\n";
$expectedTables = [
    'users', 'admins', 'categories', 'subcategories', 'products',
    'product_colours', 'product_sizes', 'product_inventory', 'product_images',
    'carts', 'cart_items', 'wishlists', 'wishlist_items', 'addresses',
    'orders', 'order_items', 'reviews', 'returns', 'live_review_activity',
    'product_admin_notes', 'csrf_tokens'
];

$existingTables = $pdo->query("SHOW TABLES FROM stylehub_db")->fetchAll(PDO::FETCH_COLUMN);

foreach ($expectedTables as $tbl) {
    check("Table `{$tbl}` exists", in_array($tbl, $existingTables, true), "Missing table in database");
}

// 2. PASSWORD HASHING AND AUDIT
echo "\n[2] Checking Password Hashing Security...\n";
$admin = $pdo->query("SELECT * FROM admins WHERE username = 'admin'")->fetch();
check("Admin account exists", !empty($admin));
check("Admin password uses Bcrypt ($2y$)", str_starts_with($admin['password'] ?? '', '$2y$'));
check("Admin password verifies correctly", password_verify('Admin@StyleHub2026!', $admin['password'] ?? ''));
check("Admin rejects invalid password", !password_verify('WrongAdminPassword', $admin['password'] ?? ''));

$customer = $pdo->query("SELECT * FROM users WHERE email = 'customer@stylehub.local'")->fetch();
check("Customer account exists", !empty($customer));
check("Customer password uses Bcrypt ($2y$)", str_starts_with($customer['password'] ?? '', '$2y$'));
check("Customer password verifies correctly", password_verify('Customer@StyleHub2026!', $customer['password'] ?? ''));
check("Customer rejects invalid password", !password_verify('WrongCustomerPassword', $customer['password'] ?? ''));

// 3. ZERO INLINE CSS AUDIT
echo "\n[3] Auditing Codebase for ZERO Inline CSS Rule...\n";
$codeFiles = array_merge(
    glob(__DIR__ . '/../frontend/*.html'),
    glob(__DIR__ . '/*.php'),
    glob(__DIR__ . '/admin/*.php'),
    glob(__DIR__ . '/auth/*.php'),
    glob(__DIR__ . '/includes/*.php'),
    glob(__DIR__ . '/config/*.php')
);

$inlineCssViolations = 0;
foreach ($codeFiles as $file) {
    $content = file_get_contents($file);
    if (preg_match('/style\s*=\s*["\']/i', $content)) {
        $inlineCssViolations++;
        echo "  [WARN] Found inline CSS in " . basename($file) . "\n";
    }
}
check("Zero inline CSS attributes across all HTML & PHP files", $inlineCssViolations === 0, "Found {$inlineCssViolations} files with inline CSS");

// 4. RESPONSIVE DESIGN SYSTEM BREAKPOINTS
echo "\n[4] Checking Responsive CSS Design System Breakpoints...\n";
$css = file_get_contents(__DIR__ . '/../frontend/css/style.css');
check("Mobile Portrait (375px) breakpoint defined", str_contains($css, '375px'));
check("Tablet Portrait (768px) breakpoint defined", str_contains($css, '768px'));
check("Laptop / Tablet Landscape (1024px) breakpoint defined", str_contains($css, '1024px'));
check("Desktop (1440px) breakpoint defined", str_contains($css, '1440px'));
check("CSS Variables for Green, White, Grey, Black defined", 
    str_contains($css, '--color-green') && 
    str_contains($css, '--color-white') && 
    str_contains($css, '--color-grey') && 
    str_contains($css, '--color-black')
);

// 5. PUBLIC STOREFRONT VISIBILITY RULES
echo "\n[5] Verifying Public Navigation & Visibility Rules...\n";
$indexHtml = file_get_contents(__DIR__ . '/../frontend/index.html');
check("Public navigation includes Logo", str_contains($indexHtml, 'brand-logo'));
check("Public navigation includes Home", str_contains($indexHtml, 'Home'));
check("Public navigation includes Products", str_contains($indexHtml, 'Products'));
check("Public navigation includes Categories", str_contains($indexHtml, 'Categories'));
check("Public navigation includes Wishlist", str_contains($indexHtml, 'Wishlist'));
check("Public navigation includes Cart", str_contains($indexHtml, 'Cart'));
check("Public navigation includes Login", str_contains($indexHtml, 'Login'));
check("NO Admin link or Admin Dashboard button in public homepage", 
    !str_contains($indexHtml, 'admin/dashboard') && 
    !str_contains($indexHtml, 'admin/login') && 
    !str_contains($indexHtml, 'Admin Dashboard')
);

// 6. SKELETON LOADERS & EMPTY STATES
echo "\n[6] Checking UI Skeletons & Empty State Systems...\n";
check("Skeleton loading markup exists in index.html", str_contains($indexHtml, 'skeleton-card'));
check("Skeleton pulse animation defined in style.css", str_contains($css, 'skeleton-pulse'));

$cartHtml = file_get_contents(__DIR__ . '/../frontend/cart.html');
check("Empty Cart state exists in cart.html", str_contains($cartHtml, 'Your cart is empty'));

$wishlistHtml = file_get_contents(__DIR__ . '/../frontend/wishlist.html');
check("Empty Wishlist state exists in wishlist.html", str_contains($wishlistHtml, 'Your wishlist is empty'));

// 7. ERROR PAGES
echo "\n[7] Checking Error Pages...\n";
$e403 = file_get_contents(__DIR__ . '/../frontend/403.html');
$e404 = file_get_contents(__DIR__ . '/../frontend/404.html');
$e500 = file_get_contents(__DIR__ . '/../frontend/500.html');
check("403 Access Denied page contains proper status code", str_contains($e403, '403'));
check("404 Page Not Found contains proper status code", str_contains($e404, '404'));
check("500 Server Error page contains proper status code", str_contains($e500, '500'));

// 8. LIVE DATABASE METRICS VS ADMIN STATS CALCULATION
echo "\n[8] Checking Inventory & Statistics Calculation...\n";
$prodCount = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
check("Products exist in database", $prodCount > 0, "Found {$prodCount} products");

$lowStockThresholdSample = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM products p 
    LEFT JOIN (SELECT product_id, SUM(stock_quantity) as total_qty FROM product_inventory GROUP BY product_id) i ON p.id = i.product_id 
    WHERE COALESCE(i.total_qty, 0) <= p.low_stock_threshold AND COALESCE(i.total_qty, 0) > 0
")->fetchColumn();
check("Low-stock products identified accurately in sample data", $lowStockThresholdSample > 0);

echo "\n========================================================\n";
echo "DEEP VERIFICATION RESULT: {$checks} Checks Run, " . count($errors) . " Errors Found\n";
echo "========================================================\n";

if (count($errors) > 0) {
    exit(1);
}

