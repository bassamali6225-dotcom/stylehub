<?php
/**
 * StyleHub - Phase 4 Automated Integration Test Suite
 * Rigorously tests:
 * 1. Admin authentication & role protection
 * 2. Live 13 MySQL dashboard metrics
 * 3. Admin product CRUD, threshold, manual badge, exact stock visibility
 * 4. Image upload validation and file storage
 * 5. Admin category & Men/Women/Children classification management
 * 6. Admin customer directory, dossier, active/inactive toggle, password protection
 * 7. Admin orders fulfillment pipeline (In Queue, Dispatched + date, Delivered)
 * 8. Admin return processing and automated inventory restocking
 * 9. CSRF defense on state-changing admin actions
 * 10. Zero inline CSS and stock privacy verification
 */

declare(strict_types=1);

$baseUrl = 'http://127.0.0.1:8000';
$passed = 0;
$failed = 0;

function report(string $name, bool $ok, string $details = ''): void {
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  [PASS] {$name}" . PHP_EOL;
    } else {
        $failed++;
        echo "  [FAIL] {$name} - {$details}" . PHP_EOL;
    }
}

function httpReq(string $method, string $url, $data = null, array $headers = [], ?string $cookieJar = null): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }

    if ($data !== null) {
        $hasFile = false;
        if (is_array($data)) {
            foreach ($data as $v) {
                if ($v instanceof CURLFile) {
                    $hasFile = true;
                    break;
                }
            }
        }

        if ($hasFile) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } elseif (is_array($data) && !isset($headers['Content-Type'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? json_encode($data) : $data);
        }
    }

    $flatHeaders = [];
    foreach ($headers as $k => $v) {
        $flatHeaders[] = "{$k}: {$v}";
    }
    if (!empty($flatHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $flatHeaders);
    }

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $json = null;
    if ($response !== false) {
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $json = $decoded;
        }
    }

    return [
        'status'   => $status,
        'body'     => $response,
        'json'     => $json,
        'error'    => $error
    ];
}

$adminCookie = tempnam(sys_get_temp_dir(), 'sh_adm_');
$custCookie  = tempnam(sys_get_temp_dir(), 'sh_cst_');

echo PHP_EOL;
echo "========================================================" . PHP_EOL;
echo "   STYLEHUB PHASE 4 AUTOMATED INTEGRATION TEST SUITE    " . PHP_EOL;
echo "========================================================" . PHP_EOL . PHP_EOL;

// -----------------------------------------------------------------------------
// 1. Admin Authentication & Role Enforcement
// -----------------------------------------------------------------------------
echo "[1] Testing Admin Access Control & Authentication..." . PHP_EOL;

// Unauthenticated request to stats
$unauth = httpReq('GET', "{$baseUrl}/backend/admin/stats.php", null, ['Accept' => 'application/json']);
report('Guest access to admin stats rejected (401/403/302)', in_array($unauth['status'], [401, 403, 302], true));

// Customer login
$custCsrf = httpReq('GET', "{$baseUrl}/backend/auth/csrf.php", null, ['Accept' => 'application/json'], $custCookie);
$cToken = $custCsrf['json']['data']['csrf_token'] ?? '';
httpReq('POST', "{$baseUrl}/backend/auth/login.php", [
    'email'      => 'customer@stylehub.local',
    'password'   => 'Customer@StyleHub2026!',
    'csrf_token' => $cToken
], [], $custCookie);

// Customer trying to open admin stats
$custAdminReq = httpReq('GET', "{$baseUrl}/backend/admin/stats.php", null, ['Accept' => 'application/json'], $custCookie);
report('Customer blocked from admin stats (403)', $custAdminReq['status'] === 403);

// Admin Login
$admCsrf = httpReq('GET', "{$baseUrl}/backend/auth/csrf.php", null, ['Accept' => 'application/json'], $adminCookie);
$aToken = $admCsrf['json']['data']['csrf_token'] ?? '';
$admLogin = httpReq('POST', "{$baseUrl}/backend/admin/login.php", [
    'identifier' => 'admin@stylehub.local',
    'password'   => 'Admin@StyleHub2026!',
    'csrf_token' => $aToken
], [], $adminCookie);

// Refresh CSRF for post-login session
$admCsrf2 = httpReq('GET', "{$baseUrl}/backend/auth/csrf.php", null, ['Accept' => 'application/json'], $adminCookie);
$admToken = $admCsrf2['json']['data']['csrf_token'] ?? '';

report('Admin authenticated successfully', $admLogin['status'] === 302 || $admLogin['status'] === 200);

// Verify admin dashboard renders
$dash = httpReq('GET', "{$baseUrl}/backend/admin/dashboard.php", null, [], $adminCookie);
report('Admin dashboard HTTP 200', $dash['status'] === 200);
report('Dashboard contains Control Center', strpos($dash['body'], 'StyleHub Control Center') !== false);

// -----------------------------------------------------------------------------
// 2. Live 13 MySQL Statistics API
// -----------------------------------------------------------------------------
echo PHP_EOL . "[2] Testing Live 13 MySQL Dashboard Metrics..." . PHP_EOL;

$statsRes = httpReq('GET', "{$baseUrl}/backend/admin/stats.php", null, ['Accept' => 'application/json'], $adminCookie);
report('Stats API HTTP 200', $statsRes['status'] === 200);

$metrics = $statsRes['json']['data'] ?? [];
$requiredKeys = [
    'total_products', 'in_stock_products', 'low_stock_products', 'out_of_stock_products',
    'orders_pending', 'orders_in_queue', 'orders_dispatched', 'orders_delivered',
    'orders_returned', 'total_returns', 'pending_returns', 'total_colours',
    'total_reviews', 'active_viewers'
];

$allKeysPresent = true;
foreach ($requiredKeys as $k) {
    if (!array_key_exists($k, $metrics)) {
        $allKeysPresent = false;
        break;
    }
}
report('All 13 MySQL store metrics returned in live stats', $allKeysPresent);
report('Total products count > 0', isset($metrics['total_products']) && $metrics['total_products'] > 0);
report('Active product viewers metric present', isset($metrics['active_viewers']));

// -----------------------------------------------------------------------------
// 3. Category & Men/Women/Children Classification Management
// -----------------------------------------------------------------------------
echo PHP_EOL . "[3] Testing Category & Classification Management..." . PHP_EOL;

$catName = 'Phase4 Activewear ' . rand(100, 999);
$createCat = httpReq('POST', "{$baseUrl}/backend/api/admin-categories.php", json_encode([
    'action'      => 'create_category',
    'name'        => $catName,
    'description' => 'High performance athletic apparel',
    'csrf_token'  => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);

report('Create category HTTP 201', $createCat['status'] === 201);
$testCatId = (int)($createCat['json']['data']['category_id'] ?? 0);
report('New category ID generated', $testCatId > 0);

// Verify auto-generated Men, Women, Children subcategories
$catsList = httpReq('GET', "{$baseUrl}/backend/api/admin-categories.php", null, [], $adminCookie);
$createdCatObj = null;
foreach ($catsList['json']['data']['categories'] ?? [] as $c) {
    if ($c['id'] === $testCatId) {
        $createdCatObj = $c;
        break;
    }
}
$subs = $createdCatObj['subcategories'] ?? [];
$groups = array_column($subs, 'target_group');
report('Automatic Men, Women, Children classifications created', in_array('Men', $groups) && in_array('Women', $groups) && in_array('Children', $groups));

// Rename category
$renameCat = httpReq('POST', "{$baseUrl}/backend/api/admin-categories.php", json_encode([
    'action'      => 'update_category',
    'category_id' => $testCatId,
    'name'        => $catName . ' Updated',
    'csrf_token'  => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);
report('Rename category succeeded', $renameCat['status'] === 200);

// Add custom subcategory
$addCustomSub = httpReq('POST', "{$baseUrl}/backend/api/admin-categories.php", json_encode([
    'action'       => 'create_subcategory',
    'category_id'  => $testCatId,
    'name'         => 'Performance Windbreakers',
    'target_group' => 'Men',
    'csrf_token'   => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);
report('Add custom subcategory succeeded', $addCustomSub['status'] === 201);
$customSubId = (int)($addCustomSub['json']['data']['subcategory_id'] ?? 0);

// -----------------------------------------------------------------------------
// 4. Product Management (CRUD, Stock, Threshold, Manual Badge, Variants, Upload)
// -----------------------------------------------------------------------------
echo PHP_EOL . "[4] Testing Admin Product Management..." . PHP_EOL;

$prodName = 'Phase4 Tactical Parka ' . rand(100, 999);
$createProd = httpReq('POST', "{$baseUrl}/backend/api/admin-products.php", json_encode([
    'action'                  => 'create',
    'name'                    => $prodName,
    'category_id'             => $testCatId,
    'subcategory_id'          => $customSubId,
    'description'             => 'All-weather waterproof tactical parka with thermal lining',
    'price'                   => 149.99,
    'low_stock_threshold'     => 5,
    'initial_stock'           => 3, // Initial stock 3 is <= threshold 5 -> Low in Stock
    'is_featured'             => 1,
    'hide_when_out_of_stock'  => 1,
    'manual_badge_message'    => 'New Winter Release',
    'csrf_token'              => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);

report('Create product HTTP 201', $createProd['status'] === 201);
$testProdId = (int)($createProd['json']['data']['product_id'] ?? 0);
report('New product ID generated', $testProdId > 0);

// Verify Admin product GET shows exact stock (3) and is_low_stock
$admProdGet = httpReq('GET', "{$baseUrl}/backend/api/admin-products.php?id={$testProdId}", null, [], $adminCookie);
$prodData = $admProdGet['json']['data']['product'] ?? [];
report('Admin sees exact stock count (3)', isset($prodData['total_stock']) && (int)$prodData['total_stock'] === 3);

// Verify Public API hides exact stock and shows Low in Stock badge + manual badge
$pubProdGet = httpReq('GET', "{$baseUrl}/backend/api/products.php?search=" . urlencode($prodName));
$pubProd = $pubProdGet['json']['data']['products'][0] ?? null;
report('Public API displays created product', $pubProd !== null);
report('Public API hides total_stock field', !array_key_exists('total_stock', (array)$pubProd));
report('Public API sets is_low_stock: true', ($pubProd['is_low_stock'] ?? false) === true);
report('Public API displays manual badge message', ($pubProd['manual_badge_message'] ?? '') === 'New Winter Release');

// Adjust inventory stock from 3 to 15
$updStock = httpReq('POST', "{$baseUrl}/backend/api/admin-products.php", json_encode([
    'action'         => 'update_stock',
    'product_id'     => $testProdId,
    'stock_quantity' => 15,
    'csrf_token'     => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);
report('Adjust stock level succeeded', $updStock['status'] === 200);

// Verify stock updated and low_stock turned false (15 > 5)
$pubProdGet2 = httpReq('GET', "{$baseUrl}/backend/api/products.php?search=" . urlencode($prodName));
$pubProd2 = $pubProdGet2['json']['data']['products'][0] ?? null;
report('Public is_low_stock turns false after stock increase', ($pubProd2['is_low_stock'] ?? true) === false);

// Add variant colour & size
$addCol = httpReq('POST', "{$baseUrl}/backend/api/admin-products.php", json_encode([
    'action'       => 'add_colour',
    'product_id'   => $testProdId,
    'colour_name'  => 'Matte Olive',
    'colour_code'  => '#3d5c3a',
    'csrf_token'   => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);
report('Add product colour succeeded', $addCol['status'] === 201);

$addSz = httpReq('POST', "{$baseUrl}/backend/api/admin-products.php", json_encode([
    'action'       => 'add_size',
    'product_id'   => $testProdId,
    'size_name'    => 'XL',
    'sort_order'   => 4,
    'csrf_token'   => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);
report('Add product size succeeded', $addSz['status'] === 201);

// Test Image Upload Validation & Execution
$tmpImg = tempnam(sys_get_temp_dir(), 'img_') . '.jpg';
// Create a small 1x1 valid JPEG image
$im = imagecreatetruecolor(10, 10);
imagejpeg($im, $tmpImg);
imagedestroy($im);

$cFile = new CURLFile($tmpImg, 'image/jpeg', 'test_photo.jpg');
$uploadRes = httpReq('POST', "{$baseUrl}/backend/api/admin-products.php", [
    'action'     => 'upload_image',
    'product_id' => $testProdId,
    'image'      => $cFile,
    'is_primary' => 1,
    'csrf_token' => $admToken
], ['X-CSRF-Token' => $admToken], $adminCookie);
@unlink($tmpImg);

report('Product image upload HTTP 201', $uploadRes['status'] === 201);
$uploadedUrl = $uploadRes['json']['data']['image_url'] ?? '';
report('Image URL saved in uploads directory', strpos($uploadedUrl, '/backend/uploads/') !== false);

// -----------------------------------------------------------------------------
// 5. Customer Directory & Account Control
// -----------------------------------------------------------------------------
echo PHP_EOL . "[5] Testing Admin Customer Management..." . PHP_EOL;

$custList = httpReq('GET', "{$baseUrl}/backend/api/admin-customers.php", null, [], $adminCookie);
report('Customer directory loaded HTTP 200', $custList['status'] === 200);
$customers = $custList['json']['data']['customers'] ?? [];
report('Customer list contains users', count($customers) > 0);

// Verify password hashes are NEVER exposed
$passwordsExposed = false;
foreach ($customers as $c) {
    if (isset($c['password']) || isset($c['password_hash'])) {
        $passwordsExposed = true;
        break;
    }
}
report('Customer directory strictly excludes password hashes', !$passwordsExposed);

// Customer Dossier
$firstCustId = (int)($customers[0]['id'] ?? 1);
$dossier = httpReq('GET', "{$baseUrl}/backend/api/admin-customers.php?id={$firstCustId}", null, [], $adminCookie);
report('Customer dossier loaded HTTP 200', $dossier['status'] === 200);
report('Dossier includes addresses, orders, reviews, returns', 
    isset($dossier['json']['data']['addresses']) &&
    isset($dossier['json']['data']['orders']) &&
    isset($dossier['json']['data']['reviews']) &&
    isset($dossier['json']['data']['returns'])
);

// Toggle Account Status (active -> inactive -> active)
$toggleInact = httpReq('POST', "{$baseUrl}/backend/api/admin-customers.php", json_encode([
    'action'      => 'toggle_status',
    'customer_id' => $firstCustId,
    'status'      => 'inactive',
    'csrf_token'  => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);
report('Deactivate customer account succeeded', $toggleInact['status'] === 200);

$toggleAct = httpReq('POST', "{$baseUrl}/backend/api/admin-customers.php", json_encode([
    'action'      => 'toggle_status',
    'customer_id' => $firstCustId,
    'status'      => 'active',
    'csrf_token'  => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);
report('Reactivate customer account succeeded', $toggleAct['status'] === 200);

// -----------------------------------------------------------------------------
// 6. Orders Fulfillment Pipeline & Tracking Dates
// -----------------------------------------------------------------------------
echo PHP_EOL . "[6] Testing Orders Management & Status Progression..." . PHP_EOL;

$ordersList = httpReq('GET', "{$baseUrl}/backend/api/admin-orders.php", null, [], $adminCookie);
report('Admin orders list loaded HTTP 200', $ordersList['status'] === 200);
$orders = $ordersList['json']['data']['orders'] ?? [];
report('Orders found in database', count($orders) > 0);

$testOrderId = (int)($orders[0]['id'] ?? 1);

// Advance to In Queue
$toQueue = httpReq('POST', "{$baseUrl}/backend/api/admin-orders.php", json_encode([
    'order_id'   => $testOrderId,
    'status'     => 'In Queue',
    'csrf_token' => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);
report('Update order to In Queue succeeded', $toQueue['status'] === 200);

// Advance to Dispatched with dispatch date
$dispatchDate = date('Y-m-d H:i:s');
$toDispatched = httpReq('POST', "{$baseUrl}/backend/api/admin-orders.php", json_encode([
    'order_id'      => $testOrderId,
    'status'        => 'Dispatched',
    'dispatch_date' => $dispatchDate,
    'csrf_token'    => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);
report('Update order to Dispatched with dispatch date succeeded', $toDispatched['status'] === 200);

// Advance to Delivered
$toDelivered = httpReq('POST', "{$baseUrl}/backend/api/admin-orders.php", json_encode([
    'order_id'   => $testOrderId,
    'status'     => 'Delivered',
    'csrf_token' => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);
report('Update order to Delivered succeeded', $toDelivered['status'] === 200);

// -----------------------------------------------------------------------------
// 7. Returns Processing & Automated Inventory Restocking
// -----------------------------------------------------------------------------
echo PHP_EOL . "[7] Testing Return Processing & Automated Restocking..." . PHP_EOL;

// Customer places an order for our newly created product to test return restocking cycle
// 1. Add product to cart
$cCsrf = httpReq('GET', "{$baseUrl}/backend/auth/csrf.php", null, [], $custCookie)['json']['data']['csrf_token'] ?? '';
httpReq('POST', "{$baseUrl}/backend/api/cart.php", json_encode([
    'action'     => 'add',
    'product_id' => $testProdId,
    'quantity'   => 2,
    'csrf_token' => $cCsrf
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $cCsrf], $custCookie);

// 2. Checkout
$checkoutRes = httpReq('POST', "{$baseUrl}/backend/api/checkout.php", json_encode([
    'address_id'     => 1,
    'payment_method' => 'Cash on Delivery (Demo)',
    'notes'          => 'Phase 4 Return Restock Test',
    'csrf_token'     => $cCsrf
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $cCsrf], $custCookie);
$createdOrderNum = $checkoutRes['json']['data']['order_number'] ?? '';
$newOrderId = (int)($checkoutRes['json']['data']['order_id'] ?? 0);
report('Placed test order for restocking cycle', $newOrderId > 0);

// Admin advances order through In Queue -> Dispatched -> Delivered so it becomes eligible for return
httpReq('POST', "{$baseUrl}/backend/api/admin-orders.php", json_encode([
    'order_id'   => $newOrderId,
    'status'     => 'In Queue',
    'csrf_token' => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);

httpReq('POST', "{$baseUrl}/backend/api/admin-orders.php", json_encode([
    'order_id'      => $newOrderId,
    'status'        => 'Dispatched',
    'dispatch_date' => date('Y-m-d H:i:s'),
    'csrf_token'    => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);

httpReq('POST', "{$baseUrl}/backend/api/admin-orders.php", json_encode([
    'order_id'   => $newOrderId,
    'status'     => 'Delivered',
    'csrf_token' => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);

// Customer submits return request
$retSubmit = httpReq('POST', "{$baseUrl}/backend/api/returns.php", json_encode([
    'order_id'   => $newOrderId,
    'reason'     => 'Size too large',
    'details'    => 'Tried on once, would like to return',
    'csrf_token' => $cCsrf
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $cCsrf], $custCookie);
report('Customer submitted return request HTTP 201', $retSubmit['status'] === 201);
$retId = (int)($retSubmit['json']['data']['return_id'] ?? 0);

// Check current inventory before return approval
$prodBefore = httpReq('GET', "{$baseUrl}/backend/api/admin-products.php?id={$testProdId}", null, [], $adminCookie);
$stockBefore = (int)($prodBefore['json']['data']['product']['total_stock'] ?? 0);

// Admin processes return request: APPROVE & RESTOCK
$approveRet = httpReq('POST', "{$baseUrl}/backend/api/admin-returns.php", json_encode([
    'action'      => 'process_return',
    'return_id'   => $retId,
    'decision'    => 'Approved',
    'admin_notes' => 'Product inspected and approved for restocking',
    'csrf_token'  => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);
report('Admin approved return request HTTP 200', $approveRet['status'] === 200);

// Check order status is now 'Returned'
$orderAfterRet = httpReq('GET', "{$baseUrl}/backend/api/admin-orders.php?id={$newOrderId}", null, [], $adminCookie);
$orderStatusAfter = $orderAfterRet['json']['data']['order']['status'] ?? '';
report('Order status automatically transitioned to Returned', $orderStatusAfter === 'Returned');

// Check stock has been incremented by 2
$prodAfter = httpReq('GET', "{$baseUrl}/backend/api/admin-products.php?id={$testProdId}", null, [], $adminCookie);
$stockAfter = (int)($prodAfter['json']['data']['product']['total_stock'] ?? 0);
report("Automated restocking confirmed: Stock increased from {$stockBefore} to {$stockAfter} (+2)", $stockAfter === ($stockBefore + 2));

// -----------------------------------------------------------------------------
// 8. CSRF Protection on Admin State-Changing Actions
// -----------------------------------------------------------------------------
echo PHP_EOL . "[8] Testing CSRF Protection on Admin Actions..." . PHP_EOL;

$noCsrf = httpReq('POST', "{$baseUrl}/backend/api/admin-products.php", json_encode([
    'action'     => 'delete',
    'product_id' => $testProdId
]), ['Content-Type' => 'application/json'], $adminCookie);
report('Admin POST without CSRF token rejected (403)', $noCsrf['status'] === 403);

// -----------------------------------------------------------------------------
// 9. Zero Inline CSS Verification
// -----------------------------------------------------------------------------
echo PHP_EOL . "[9] Testing Zero Inline CSS & Privacy Integrity..." . PHP_EOL;

$dashHtml = file_get_contents(__DIR__ . '/admin/dashboard.php');
$hasInlineStyle = (bool)preg_match('/style\s*=\s*["\']/i', $dashHtml);
report('Admin dashboard has ZERO inline CSS', !$hasInlineStyle);

// Clean up test product
httpReq('POST', "{$baseUrl}/backend/api/admin-products.php", json_encode([
    'action'     => 'delete',
    'product_id' => $testProdId,
    'csrf_token' => $admToken
]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admToken], $adminCookie);

// Clean up temporary cookies
@unlink($adminCookie);
@unlink($custCookie);

echo PHP_EOL;
echo "========================================================" . PHP_EOL;
echo "TEST RESULTS: {$passed} Passed, {$failed} Failed" . PHP_EOL;
echo "========================================================" . PHP_EOL;

exit($failed > 0 ? 1 : 0);
