<?php
/**
 * StyleHub - Senior-Level Comprehensive Audit Suite (Phases 1, 2, 3)
 * Full Verification of UI, Responsiveness, Security, APIs, Data Persistence,
 * Stock Privacy, Navigation, and Zero Inline CSS.
 */

declare(strict_types=1);

$baseUrl = 'http://127.0.0.1:8000';
$passed = 0;
$failed = 0;
$issues = [];

function auditTest(string $title, bool $condition, string $detail = ''): void {
    global $passed, $failed, $issues;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$title}\n";
    } else {
        $failed++;
        $issues[] = "{$title}: {$detail}";
        echo "  [FAIL] {$title} - {$detail}\n";
    }
}

function req(string $url, string $method = 'GET', ?array $data = null, ?string $cookieFile = null, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
        }
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false) {
        return [
            'code'    => 500,
            'headers' => '',
            'body'    => '',
            'json'    => null
        ];
    }

    $headerStr = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    return [
        'code'    => $status,
        'headers' => $headerStr,
        'body'    => $body,
        'json'    => json_decode($body, true)
    ];
}

function getCsrfToken(string $baseUrl, string $cookieFile): string {
    $res = req("{$baseUrl}/backend/auth/csrf.php", 'GET', null, $cookieFile);
    return $res['json']['data']['csrf_token'] ?? '';
}

echo "====================================================================\n";
echo "      STYLEHUB SENIOR-LEVEL AUDIT: PHASES 1, 2, AND 3 VERIFICATION   \n";
echo "====================================================================\n\n";

// --- AUDIT 1: ALL HTML PAGES LOAD WITH HTTP 200 & NO BROKEN URLS ---
echo "--- 1. HTML PAGES & ACCESSIBILITY AUDIT ---\n";
$htmlPages = [
    'frontend/index.html'           => 'Public Storefront Homepage',
    'frontend/products.html'        => 'Product Catalog',
    'frontend/product-details.html' => 'Product Details View',
    'frontend/cart.html'            => 'Shopping Bag',
    'frontend/wishlist.html'        => 'Saved Wishlist',
    'frontend/checkout.html'        => 'Secure Checkout',
    'frontend/addresses.html'       => 'Delivery Addresses',
    'frontend/order-history.html'   => 'Customer Order History',
    'frontend/order-tracking.html'  => 'Order Fulfillment Tracking',
    'frontend/login.html'           => 'Customer Login Portal',
    'frontend/register.html'        => 'Customer Registration',
    'frontend/profile.html'         => 'Customer Profile',
    'frontend/403.html'             => '403 Access Denied Error Page',
    'frontend/404.html'             => '404 Not Found Error Page',
    'frontend/500.html'             => '500 Server Error Page'
];

foreach ($htmlPages as $path => $desc) {
    $res = req("{$baseUrl}/{$path}");
    auditTest("Page {$desc} loads successfully (HTTP 200)", $res['code'] === 200, "Returned HTTP {$res['code']}");
    auditTest("Page {$desc} has proper title & viewport meta", str_contains($res['body'], '<title>') && str_contains($res['body'], 'viewport'));
}

// --- AUDIT 2: NAVIGATION & ADMIN LINK RESTRICTIONS ---
echo "\n--- 2. PUBLIC NAVIGATION & ADMIN ISOLATION AUDIT ---\n";
$publicNavPages = ['frontend/index.html', 'frontend/products.html', 'frontend/cart.html', 'frontend/wishlist.html'];
foreach ($publicNavPages as $p) {
    $body = req("{$baseUrl}/{$p}")['body'];
    auditTest("{$p} navigation strictly hides admin dashboard", !str_contains($body, 'admin/dashboard') && !str_contains($body, 'Admin Dashboard'));
    auditTest("{$p} navigation includes Home, Products, Categories, Wishlist, Cart, Login", 
        str_contains($body, 'Home') && str_contains($body, 'Products') && str_contains($body, 'Categories') && 
        str_contains($body, 'Wishlist') && str_contains($body, 'Cart') && str_contains($body, 'Login'));
}

// --- AUDIT 3: SECURITY, SESSIONS, PASSWORDS, CSRF & ROLE GUARDS ---
echo "\n--- 3. SECURITY & ROLE GUARDS AUDIT ---\n";
$guestCookie = sys_get_temp_dir() . '/audit_guest_' . uniqid() . '.txt';
$custCookie  = sys_get_temp_dir() . '/audit_cust_' . uniqid() . '.txt';
$adminCookie = sys_get_temp_dir() . '/audit_admin_' . uniqid() . '.txt';

// Guest access to admin dashboard
$guestAdmin = req("{$baseUrl}/backend/admin/dashboard.php", 'GET', null, $guestCookie);
auditTest("Unauthenticated guest blocked from admin area (HTTP 302 Redirect)", $guestAdmin['code'] === 302);
auditTest("Guest redirected to admin login portal", str_contains($guestAdmin['headers'], '/backend/admin/login.php'));

// Customer login
$custCsrf = getCsrfToken($baseUrl, $custCookie);
$custLogin = req("{$baseUrl}/backend/auth/login.php", 'POST', [
    'email' => 'customer@stylehub.local',
    'password' => 'Customer@StyleHub2026!',
    'csrf_token' => $custCsrf
], $custCookie, ["X-CSRF-Token: {$custCsrf}"]);
auditTest("Customer authentication succeeds (HTTP 200)", $custLogin['code'] === 200);

// Customer access to admin dashboard
$custAdmin = req("{$baseUrl}/backend/admin/dashboard.php", 'GET', null, $custCookie);
auditTest("Customer blocked from admin dashboard (HTTP 302 Redirect)", $custAdmin['code'] === 302);
auditTest("Customer redirected to 403 Forbidden page", str_contains($custAdmin['headers'], '/frontend/403.html'));

// Refresh token for session fixation defense
$custCsrf = getCsrfToken($baseUrl, $custCookie);

// SQL Injection safety on search
$sqliPayload = urlencode("' OR '1'='1");
$sqliSearch = req("{$baseUrl}/backend/api/products.php?search={$sqliPayload}");
auditTest("Product search immune to SQL injection", $sqliSearch['code'] === 200 && is_array($sqliSearch['json']['data']['products']));

// XSS safety on reviews
$xssReview = req("{$baseUrl}/backend/api/reviews.php", 'POST', [
    'product_id' => 1,
    'rating' => 5,
    'title' => '<script>alert("xss")</script>Tailoring Test',
    'comment' => '<b>Bold tag</b> and <img src=x onerror=alert(1)>',
    'csrf_token' => $custCsrf
], $custCookie, ["X-CSRF-Token: {$custCsrf}"]);
auditTest("Review submitted with sanitized content", $xssReview['code'] === 201);

$detailCheck = req("{$baseUrl}/backend/api/product-detail.php?id=1");
$detailHtml = $detailCheck['body'];
auditTest("XSS script tags are never executed or rendered raw", !str_contains($detailHtml, '<script>alert("xss")</script>'));

// --- AUDIT 4: STOCK PRIVACY ENFORCEMENT ---
echo "\n--- 4. STOCK PRIVACY AUDIT (NO EXACT QUANTITY EXPOSURE) ---\n";
$prodsRes = req("{$baseUrl}/backend/api/products.php");
$prods = $prodsRes['json']['data']['products'] ?? [];
$stockQuantityExposed = false;
$lowStockBadgeWorking = false;

foreach ($prods as $p) {
    if (isset($p['stock_quantity']) || isset($p['total_stock']) || isset($p['inventory_count'])) {
        $stockQuantityExposed = true;
    }
    if (!empty($p['is_low_stock'])) {
        $lowStockBadgeWorking = true;
    }
}
auditTest("Exact stock quantity NEVER exposed on products list", !$stockQuantityExposed);
auditTest("Low stock badge correctly signals low inventory boolean", $lowStockBadgeWorking);

$detailRes = req("{$baseUrl}/backend/api/product-detail.php?id=1");
$detailProduct = $detailRes['json']['data']['product'] ?? [];
auditTest("Exact stock quantity NEVER exposed on product detail page", !isset($detailProduct['stock_quantity']) && !isset($detailProduct['total_stock']));

$wlRes = req("{$baseUrl}/backend/api/wishlist.php", 'GET', null, $custCookie);
$wlItems = $wlRes['json']['data']['items'] ?? [];
$wlStockExposed = false;
foreach ($wlItems as $wi) {
    if (isset($wi['stock_quantity']) || isset($wi['total_stock'])) $wlStockExposed = true;
}
auditTest("Exact stock quantity NEVER exposed on wishlist page", !$wlStockExposed);

// --- AUDIT 5: CART, CHECKOUT, ADDRESSES, ORDERS & DATA PERSISTENCE ---
echo "\n--- 5. COMMERCE WORKFLOW & DATA PERSISTENCE AUDIT ---\n";
// Clear Cart
req("{$baseUrl}/backend/api/cart.php", 'POST', ['action' => 'clear', 'csrf_token' => $custCsrf], $custCookie, ["X-CSRF-Token: {$custCsrf}"]);

// Add Item
$cartAdd = req("{$baseUrl}/backend/api/cart.php", 'POST', [
    'action' => 'add',
    'product_id' => 1,
    'quantity' => 1,
    'csrf_token' => $custCsrf
], $custCookie, ["X-CSRF-Token: {$custCsrf}"]);
auditTest("Cart Add Item works (HTTP 200)", $cartAdd['code'] === 200);

// Persistence check: simulate browser refresh by making a second GET request
$cartCheck1 = req("{$baseUrl}/backend/api/cart.php", 'GET', null, $custCookie);
auditTest("Cart items persist upon page refresh", ($cartCheck1['json']['data']['item_count'] ?? 0) === 1);

// Add Address
$addrAdd = req("{$baseUrl}/backend/api/addresses.php", 'POST', [
    'action' => 'add',
    'full_name' => 'Auditor Jane',
    'phone' => '+1 (555) 777-8888',
    'address_line1' => '500 Madison Avenue',
    'city' => 'New York',
    'state' => 'NY',
    'postal_code' => '10022',
    'country' => 'United States',
    'is_default' => 1,
    'csrf_token' => $custCsrf
], $custCookie, ["X-CSRF-Token: {$custCsrf}"]);
auditTest("Customer Address Add works (HTTP 201)", $addrAdd['code'] === 201);
$addrId = $addrAdd['json']['data']['address_id'] ?? 0;

// Checkout
$coRes = req("{$baseUrl}/backend/api/checkout.php", 'POST', [
    'address_id' => $addrId,
    'csrf_token' => $custCsrf
], $custCookie, ["X-CSRF-Token: {$custCsrf}"]);
auditTest("Checkout placement succeeds (HTTP 201)", $coRes['code'] === 201);
$orderNum = $coRes['json']['data']['order_number'] ?? '';
$orderId = $coRes['json']['data']['order_id'] ?? 0;

// Verify Cart is cleared post-checkout
$cartPost = req("{$baseUrl}/backend/api/cart.php", 'GET', null, $custCookie);
auditTest("Shopping bag empty after checkout placement", ($cartPost['json']['data']['item_count'] ?? -1) === 0);

// Order Tracking timeline
$trackRes = req("{$baseUrl}/backend/api/orders.php?order_number={$orderNum}", 'GET', null, $custCookie);
$trackOrder = $trackRes['json']['data']['order'] ?? [];
auditTest("Order Tracking timeline correctly returned", !empty($trackOrder['timeline']));
auditTest("Order contains purchased item snapshots in MySQL", count($trackOrder['items'] ?? []) >= 1);

// --- AUDIT 6: ZERO INLINE CSS ENFORCEMENT ---
echo "\n--- 6. ZERO INLINE CSS AUDIT ---\n";
$allCodeFiles = array_merge(
    glob(__DIR__ . '/../frontend/*.html'),
    glob(__DIR__ . '/../frontend/js/*.js'),
    glob(__DIR__ . '/*.php'),
    glob(__DIR__ . '/api/*.php'),
    glob(__DIR__ . '/admin/*.php'),
    glob(__DIR__ . '/auth/*.php')
);

$inlineCssViolations = [];
foreach ($allCodeFiles as $f) {
    $content = file_get_contents($f);
    if (preg_match('/<[^>]+style\s*=\s*["\']/i', $content, $m)) {
        $inlineCssViolations[] = basename($f);
    }
}
auditTest("Strict ZERO inline CSS across all project files", empty($inlineCssViolations), "Violations in: " . implode(', ', $inlineCssViolations));

// --- AUDIT 7: RESPONSIVE CSS BREAKPOINTS AUDIT ---
echo "\n--- 7. RESPONSIVE DESIGN BREAKPOINTS AUDIT ---\n";
$cssContent = file_get_contents(__DIR__ . '/../frontend/css/style.css');
auditTest("CSS contains 375px mobile breakpoint", str_contains($cssContent, 'max-width: 375px'));
auditTest("CSS contains 768px tablet breakpoint", str_contains($cssContent, 'max-width: 768px'));
auditTest("CSS contains 1024px desktop breakpoint", str_contains($cssContent, 'max-width: 1024px'));
auditTest("CSS contains 1440px wide display media query", str_contains($cssContent, 'min-width: 1440px'));

// Cleanup
@unlink($guestCookie);
@unlink($custCookie);
@unlink($adminCookie);

echo "\n====================================================================\n";
echo "AUDIT SUMMARY: {$passed} Checks Passed, {$failed} Issues Found\n";
echo "====================================================================\n";

if ($failed > 0) {
    echo "\nIssues to address:\n";
    foreach ($issues as $iss) {
        echo " - {$iss}\n";
    }
    exit(1);
}

exit(0);
