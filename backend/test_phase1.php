<?php
/**
 * StyleHub - Phase 1 Automated Integration Test Suite
 */

declare(strict_types=1);

$baseUrl = 'http://127.0.0.1:8000';
$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name} - {$detail}\n";
        $failed++;
    }
}

function httpReq(string $url, string $method = 'GET', ?array $data = null, ?string $cookieFile = null, array $extraHeaders = [], bool $isJson = true): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    $headers = $extraHeaders;

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            if ($isJson) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $headers[] = 'Content-Type: application/json';
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
        }
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    return [
        'code'    => $httpCode,
        'headers' => $headerStr,
        'body'    => $body,
        'json'    => json_decode($body, true)
    ];
}

function getCsrf(string $baseUrl, string $cookieFile): string {
    $res = httpReq("{$baseUrl}/backend/auth/csrf.php", 'GET', null, $cookieFile);
    return $res['json']['data']['csrf_token'] ?? '';
}

echo "========================================================\n";
echo "   STYLEHUB PHASE 1 AUTOMATED INTEGRATION TEST SUITE     \n";
echo "========================================================\n\n";

$cookieJarCustomer = sys_get_temp_dir() . '/cookie_customer_' . uniqid() . '.txt';
$cookieJarRegister = sys_get_temp_dir() . '/cookie_reg_' . uniqid() . '.txt';
$cookieJarAdmin    = sys_get_temp_dir() . '/cookie_admin_' . uniqid() . '.txt';
$cookieJarGuest    = sys_get_temp_dir() . '/cookie_guest_' . uniqid() . '.txt';

// TEST 1: Health & Database Connectivity
echo "[1] Testing API Health & MySQL Database Connection...\n";
$res = httpReq("{$baseUrl}/backend/api/health.php");
assertTest("Health Check returns HTTP 200", $res['code'] === 200);
assertTest("Database is operational", ($res['json']['data']['status'] ?? '') === 'operational');
assertTest("Database name is stylehub_db", ($res['json']['data']['database']['current_db'] ?? '') === 'stylehub_db');

// TEST 2: CSRF Token Generation
echo "\n[2] Testing CSRF Protection & Token Generation...\n";
$csrfToken = getCsrf($baseUrl, $cookieJarCustomer);
assertTest("CSRF Token generated", !empty($csrfToken) && strlen($csrfToken) === 64);

// TEST 3: CSRF Rejection on State-Changing Request Without Token
echo "\n[3] Testing CSRF Defense on State-Changing Requests...\n";
$resNoCsrf = httpReq("{$baseUrl}/backend/auth/login.php", 'POST', [
    'email' => 'customer@stylehub.local',
    'password' => 'Customer@StyleHub2026!'
], $cookieJarCustomer, ['Accept: application/json']);
assertTest("POST without CSRF rejected with 403 Forbidden", $resNoCsrf['code'] === 403);

// TEST 4: Customer Login with Valid Credentials and CSRF
echo "\n[4] Testing Customer Authentication...\n";
$resLogin = httpReq("{$baseUrl}/backend/auth/login.php", 'POST', [
    'email' => 'customer@stylehub.local',
    'password' => 'Customer@StyleHub2026!',
    'csrf_token' => $csrfToken
], $cookieJarCustomer, ['Accept: application/json', "X-CSRF-Token: {$csrfToken}"]);
assertTest("Customer login succeeded with 200", $resLogin['code'] === 200);
assertTest("User name returned as Jane Doe", ($resLogin['json']['data']['user']['name'] ?? '') === 'Jane Doe');

// TEST 5: Verify Session Persistence for Customer
echo "\n[5] Testing Customer Session State...\n";
$resMe = httpReq("{$baseUrl}/backend/auth/me.php", 'GET', null, $cookieJarCustomer);
assertTest("Session correctly identifies logged-in customer", ($resMe['json']['data']['logged_in'] ?? false) === true);
assertTest("Session role is customer", ($resMe['json']['data']['role'] ?? '') === 'customer');

// TEST 6: Customer Registration
echo "\n[6] Testing Customer Registration...\n";
$regCsrf = getCsrf($baseUrl, $cookieJarRegister);
$newEmail = 'testuser_' . time() . '_' . rand(100, 999) . '@example.com';
$resReg = httpReq("{$baseUrl}/backend/auth/register.php", 'POST', [
    'name' => 'Michael Scott',
    'email' => $newEmail,
    'password' => 'ScrantonPaper2026!',
    'phone' => '+1 555-010-9999',
    'csrf_token' => $regCsrf
], $cookieJarRegister, ['Accept: application/json', "X-CSRF-Token: {$regCsrf}"]);
assertTest("New customer registered with 201 Created", $resReg['code'] === 201);
assertTest("Registered user name matches", ($resReg['json']['data']['user']['name'] ?? '') === 'Michael Scott');

// TEST 7: Role Checking & Admin Page Protection
echo "\n[7] Testing Admin Security & Role Checking...\n";
// Guest access to dashboard
$resGuestAdmin = httpReq("{$baseUrl}/backend/admin/dashboard.php", 'GET', null, $cookieJarGuest);
assertTest("Guest access to admin dashboard redirects (302)", $resGuestAdmin['code'] === 302);
assertTest("Redirects to admin login", strpos($resGuestAdmin['headers'], '/backend/admin/login.php') !== false);

// Customer access to admin dashboard
$resCustAdmin = httpReq("{$baseUrl}/backend/admin/dashboard.php", 'GET', null, $cookieJarCustomer);
assertTest("Customer access to admin dashboard blocked (302)", $resCustAdmin['code'] === 302);
assertTest("Customer redirected to 403 Access Denied", strpos($resCustAdmin['headers'], '/frontend/403.html') !== false);

// TEST 8: Admin Authentication & Protected Dashboard Access
echo "\n[8] Testing Admin Login & Dashboard...\n";
$cookieJarAdmin = sys_get_temp_dir() . '/cookie_admin_' . uniqid() . '.txt';
$adminCsrf = getCsrf($baseUrl, $cookieJarAdmin);

$resAdminLogin = httpReq("{$baseUrl}/backend/admin/login.php", 'POST', [
    'identifier' => 'admin',
    'password'   => 'Admin@StyleHub2026!',
    'csrf_token' => $adminCsrf
], $cookieJarAdmin, [], false);

assertTest("Admin login succeeds and redirects (302)", $resAdminLogin['code'] === 302);
assertTest("Admin login redirects to dashboard", strpos($resAdminLogin['headers'], 'dashboard.php') !== false);

// Now access dashboard as authenticated admin
$resAdminDash = httpReq("{$baseUrl}/backend/admin/dashboard.php", 'GET', null, $cookieJarAdmin);
assertTest("Authenticated admin accesses dashboard with HTTP 200", $resAdminDash['code'] === 200);
assertTest("Dashboard contains StyleHub Control Center", strpos($resAdminDash['body'], 'StyleHub Control Center') !== false);

// Admin Stats API
$resStats = httpReq("{$baseUrl}/backend/admin/stats.php", 'GET', null, $cookieJarAdmin);
assertTest("Admin stats API returns HTTP 200", $resStats['code'] === 200);
assertTest("Total products reported in database (> 0)", ($resStats['json']['data']['total_products'] ?? 0) === 16);
assertTest("Low stock products detected", ($resStats['json']['data']['low_stock_products'] ?? 0) > 0);

// TEST 9: Public Pages & Error Pages
echo "\n[9] Testing Public Storefront & Error Pages...\n";
$pages = [
    '/'                           => 302,
    '/frontend/index.html'        => 200,
    '/frontend/products.html'     => 200,
    '/frontend/product-details.html' => 200,
    '/frontend/cart.html'         => 200,
    '/frontend/wishlist.html'     => 200,
    '/frontend/login.html'        => 200,
    '/frontend/register.html'     => 200,
    '/frontend/profile.html'      => 200,
    '/frontend/order-history.html'=> 200,
    '/frontend/order-tracking.html'=> 200,
    '/frontend/addresses.html'    => 200,
    '/frontend/checkout.html'     => 200,
    '/frontend/403.html'          => 200,
    '/frontend/404.html'          => 200,
    '/frontend/500.html'          => 200,
    '/frontend/css/style.css'     => 200,
    '/frontend/js/main.js'        => 200
];

foreach ($pages as $p => $expectedCode) {
    $res = httpReq("{$baseUrl}{$p}");
    assertTest("Page {$p} returns {$expectedCode}", $res['code'] === $expectedCode);
}

// TEST 10: Public Navigation Verification (No admin link)
echo "\n[10] Testing Public Navigation Integrity...\n";
$resIndex = httpReq("{$baseUrl}/frontend/index.html");
$hasAdminLinkInNav = (
    strpos($resIndex['body'], 'href="/backend/admin') !== false ||
    strpos($resIndex['body'], 'Admin Dashboard') !== false ||
    strpos($resIndex['body'], 'admin/login') !== false
);
assertTest("Public homepage has NO Admin Dashboard button or Admin link", !$hasAdminLinkInNav);

// Cleanup cookie files and debug file
@unlink($cookieJarCustomer);
@unlink($cookieJarRegister);
@unlink($cookieJarAdmin);
@unlink($cookieJarGuest);
@unlink(__DIR__ . '/debug_test.php');

echo "\n========================================================\n";
echo "TEST RESULTS: {$passed} Passed, {$failed} Failed\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
