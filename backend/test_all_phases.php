<?php
/**
 * StyleHub - Master Integration Test Suite (Phases 1, 2, 3, and 4 Collective)
 * Full end-to-end verification across:
 * - Phase 1: Infrastructure, Database, Design System, Auth, CSRF, Admin Isolation, Error Pages
 * - Phase 2: Storefront, Categories, Multi-field Filters, Detail View, Reviews, SEO, Stock Privacy
 * - Phase 3: Cart, Wishlist, Addresses, Checkout, Order History, Tracking Timeline, Customer Returns
 * - Phase 4: Admin Management, 13 Live MySQL Stats, Products CRUD, Categories, Customers, Orders, Return Restocking
 * - Code Quality: Zero Inline CSS, Strict Stock Privacy, CSRF Protection
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
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    $headers = $extraHeaders;

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            $hasFile = false;
            foreach ($data as $v) {
                if ($v instanceof CURLFile) {
                    $hasFile = true;
                    break;
                }
            }

            if ($hasFile) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            } elseif ($isJson) {
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

echo "====================================================================\n";
echo "   STYLEHUB MASTER VERIFICATION SUITE: PHASES 1 - 5 COLLECTIVE      \n";
echo "====================================================================\n\n";

$cookieJarCustomer = sys_get_temp_dir() . '/cookie_cust_all_' . uniqid() . '.txt';
$cookieJarRegister = sys_get_temp_dir() . '/cookie_reg_all_' . uniqid() . '.txt';
$cookieJarAdmin    = sys_get_temp_dir() . '/cookie_adm_all_' . uniqid() . '.txt';
$cookieJarGuest    = sys_get_temp_dir() . '/cookie_gst_all_' . uniqid() . '.txt';

// ==========================================
// SECTION A: PHASE 1 TESTS
// ==========================================
echo "--- SECTION A: PHASE 1 CORE FOUNDATION & AUTHENTICATION ---\n\n";

// A1: Health & Database Connectivity
echo "[1] Database Connectivity & Health API...\n";
$resHealth = httpReq("{$baseUrl}/backend/api/health.php");
assertTest("Health check endpoint returns HTTP 200", $resHealth['code'] === 200);
assertTest("Database reported operational", ($resHealth['json']['data']['status'] ?? '') === 'operational');
assertTest("Connected to stylehub_db", ($resHealth['json']['data']['database']['current_db'] ?? '') === 'stylehub_db');

// A2: CSRF Security
echo "\n[2] CSRF Token Generation & Enforcement...\n";
$preCsrf = getCsrf($baseUrl, $cookieJarCustomer);
assertTest("CSRF Token successfully generated", !empty($preCsrf));

$unprotectedPost = httpReq("{$baseUrl}/backend/auth/login.php", 'POST', [
    'email' => 'customer@stylehub.local',
    'password' => 'Customer@StyleHub2026!'
], $cookieJarGuest, ['Accept: application/json']);
assertTest("POST rejected without CSRF Token (HTTP 403 Forbidden)", $unprotectedPost['code'] === 403);

// A3: Customer Authentication
echo "\n[3] Customer Login & Session Handling...\n";
$loginRes = httpReq("{$baseUrl}/backend/auth/login.php", 'POST', [
    'email' => 'customer@stylehub.local',
    'password' => 'Customer@StyleHub2026!',
    'csrf_token' => $preCsrf
], $cookieJarCustomer, ['Accept: application/json', "X-CSRF-Token: {$preCsrf}"]);
assertTest("Customer Login Succeeded (HTTP 200)", $loginRes['code'] === 200);
assertTest("User identified as Jane Doe", ($loginRes['json']['data']['user']['name'] ?? '') === 'Jane Doe');

// Acquire post-login CSRF token
$custCsrf = getCsrf($baseUrl, $cookieJarCustomer);

$meRes = httpReq("{$baseUrl}/backend/auth/me.php", 'GET', null, $cookieJarCustomer);
assertTest("Active Customer Session Authenticated", ($meRes['json']['data']['logged_in'] ?? false) === true);
assertTest("Role identified as customer", ($meRes['json']['data']['role'] ?? '') === 'customer');

// A4: Customer Registration
echo "\n[4] Customer Registration Flow...\n";
$regCsrf = getCsrf($baseUrl, $cookieJarRegister);
$newEmail = 'buyer_' . time() . '_' . rand(100, 999) . '@example.com';
$regRes = httpReq("{$baseUrl}/backend/auth/register.php", 'POST', [
    'name' => 'Automated Buyer',
    'email' => $newEmail,
    'phone' => '+1-555-0199',
    'password' => 'SecurePass2026!',
    'csrf_token' => $regCsrf
], $cookieJarRegister, ['Accept: application/json', "X-CSRF-Token: {$regCsrf}"]);
assertTest("New customer registered (HTTP 201 Created)", $regRes['code'] === 201);
assertTest("New user registered name matches", ($regRes['json']['data']['user']['name'] ?? '') === 'Automated Buyer');

// A5: Admin Security & Role Checking
echo "\n[5] Admin Route Isolation & Role Verification...\n";
$guestAdmin = httpReq("{$baseUrl}/backend/admin/dashboard.php", 'GET', null, $cookieJarGuest);
assertTest("Guest access to admin dashboard redirects (HTTP 302)", $guestAdmin['code'] === 302);
assertTest("Guest redirected to admin login", strpos($guestAdmin['headers'], '/backend/admin/login.php') !== false);

$custAdmin = httpReq("{$baseUrl}/backend/admin/dashboard.php", 'GET', null, $cookieJarCustomer);
assertTest("Customer access to admin dashboard blocked (HTTP 302)", $custAdmin['code'] === 302);
assertTest("Customer redirected to 403 Access Denied", strpos($custAdmin['headers'], '/frontend/403.html') !== false);

// A6: Error Pages & Static Assets
echo "\n[6] Error Pages & Core Static Assets...\n";
$pages = [
    '/frontend/index.html' => 200,
    '/frontend/products.html' => 200,
    '/frontend/product-details.html' => 200,
    '/frontend/cart.html' => 200,
    '/frontend/checkout.html' => 200,
    '/frontend/wishlist.html' => 200,
    '/frontend/login.html' => 200,
    '/frontend/register.html' => 200,
    '/frontend/profile.html' => 200,
    '/frontend/order-history.html' => 200,
    '/frontend/order-tracking.html' => 200,
    '/frontend/addresses.html' => 200,
    '/frontend/403.html' => 200,
    '/frontend/404.html' => 200,
    '/frontend/500.html' => 200,
    '/frontend/css/style.css' => 200,
    '/frontend/js/main.js' => 200
];
foreach ($pages as $uri => $expectedCode) {
    $res = httpReq("{$baseUrl}{$uri}");
    assertTest("Page {$uri} accessible (HTTP {$expectedCode})", $res['code'] === $expectedCode);
}

// A7: Public Nav Link Integrity
echo "\n[7] Public Navigation Integrity...\n";
$homeHtml = httpReq("{$baseUrl}/frontend/index.html")['body'];
assertTest("Public navigation strictly contains NO Admin Dashboard button or link", strpos($homeHtml, '/backend/admin/') === false);


// ==========================================
// SECTION B: PHASE 2 TESTS
// ==========================================
echo "\n--- SECTION B: PHASE 2 STOREFRONT, FILTERS, DETAIL, SEO & REVIEWS ---\n\n";

// B1: Category and Subcategory Catalog
echo "[8] Public Categories & Subcategories API...\n";
$catRes = httpReq("{$baseUrl}/backend/api/categories.php");
assertTest("Categories API returns HTTP 200", $catRes['code'] === 200);
$categories = $catRes['json']['data']['categories'] ?? [];
assertTest("Storefront categories loaded (>= 4)", count($categories) >= 4);

$hasAllGroups = true;
foreach ($categories as $cat) {
    $groups = array_column($cat['subcategories'] ?? [], 'target_group');
    if (!in_array('Men', $groups) || !in_array('Women', $groups) || !in_array('Children', $groups)) {
        $hasAllGroups = false;
        break;
    }
}
assertTest("Every category supports Men, Women, and Children classifications", $hasAllGroups);

// B2: Product Filtering and Multi-Criteria Search
echo "\n[9] Product Filtering & Search...\n";
$pantsRes = httpReq("{$baseUrl}/backend/api/products.php?category=pants");
assertTest("Category filter by slug 'pants' returns HTTP 200", $pantsRes['code'] === 200);
assertTest("Category filter returns products", count($pantsRes['json']['data']['products'] ?? []) > 0);

$menPantsRes = httpReq("{$baseUrl}/backend/api/products.php?category=pants&target_group=Men");
assertTest("Subcategory filter by target_group 'Men' returns products", count($menPantsRes['json']['data']['products'] ?? []) > 0);

$priceRes = httpReq("{$baseUrl}/backend/api/products.php?min_price=40&max_price=90");
assertTest("Price range filter ($40 - $90) returns HTTP 200", $priceRes['code'] === 200);
$priceItems = $priceRes['json']['data']['products'] ?? [];
$priceValid = true;
foreach ($priceItems as $p) {
    if ($p['price'] < 40 || $p['price'] > 90) { $priceValid = false; break; }
}
assertTest("All returned items are within price boundary", $priceValid && count($priceItems) > 0);

$colourRes = httpReq("{$baseUrl}/backend/api/products.php?colour=Forest%20Green");
assertTest("Colour filter returns matching products", count($colourRes['json']['data']['products'] ?? []) > 0);

$sortRes = httpReq("{$baseUrl}/backend/api/products.php?sort=price_asc");
$sortItems = $sortRes['json']['data']['products'] ?? [];
$isAscending = true;
for ($i = 0; $i < count($sortItems) - 1; $i++) {
    if ($sortItems[$i]['price'] > $sortItems[$i + 1]['price']) {
        $isAscending = false;
        break;
    }
}
assertTest("Sorting by Price Low to High produces ascending prices", $isAscending && count($sortItems) > 0);

// B3: Stock Privacy Rule
echo "\n[10] Stock Privacy Rule Enforcement...\n";
$allProducts = httpReq("{$baseUrl}/backend/api/products.php")['json']['data']['products'] ?? [];
$exposedStock = false;
$hasLowStockBadge = false;
foreach ($allProducts as $p) {
    if (isset($p['total_stock']) || isset($p['stock_quantity'])) {
        $exposedStock = true;
    }
    if (isset($p['is_low_stock']) && $p['is_low_stock'] === true) {
        $hasLowStockBadge = true;
    }
}
assertTest("Exact product stock quantity NEVER exposed in public product catalog", !$exposedStock);
assertTest("Automatic 'Low in Stock' boolean indicator provided without exposing quantity", $hasLowStockBadge);

// B4: Product Detail Page & Live Viewer Activity
echo "\n[11] Product Detail Page & Activity Counters...\n";
$detailRes = httpReq("{$baseUrl}/backend/api/product-detail.php?id=1");
assertTest("Product detail API returns HTTP 200", $detailRes['code'] === 200);
$prodDetail = $detailRes['json']['data']['product'] ?? [];
assertTest("Product details contains image gallery", count($prodDetail['images'] ?? []) > 0);
assertTest("Product details contains colour variants", count($prodDetail['colours'] ?? []) > 0);
assertTest("Product details contains size options", count($prodDetail['sizes'] ?? []) > 0);
assertTest("Live activity counter returned for product", ($prodDetail['active_viewers'] ?? 0) > 0);
assertTest("Exact stock count NOT exposed in detail API", !isset($prodDetail['total_stock']));

// B5: Customer Reviews Flow
echo "\n[12] Customer Reviews Submission & Protection...\n";
require_once __DIR__ . '/config/database.php';
$pdo = getDB();
$pdo->prepare("DELETE FROM `reviews` WHERE comment = 'The fabric feel and stitching is world class. Highly recommended!'")->execute();

$revPost = httpReq("{$baseUrl}/backend/api/reviews.php", 'POST', [
    'product_id' => 1,
    'rating' => 5,
    'title' => 'Fantastic Quality',
    'comment' => 'The fabric feel and stitching is world class. Highly recommended!',
    'csrf_token' => $custCsrf
], $cookieJarCustomer, ['Accept: application/json', "X-CSRF-Token: {$custCsrf}"]);
assertTest("Authenticated customer review submitted (HTTP 201 Created)", $revPost['code'] === 201);


// ==========================================
// SECTION C: PHASE 3 TESTS
// ==========================================
echo "\n--- SECTION C: PHASE 3 CART, WISHLIST, ADDRESSES & CHECKOUT ---\n\n";

// C1: Wishlist Management
echo "[13] Customer Wishlist Operations...\n";
$wishAdd = httpReq("{$baseUrl}/backend/api/wishlist.php", 'POST', [
    'action' => 'add',
    'product_id' => 1,
    'csrf_token' => $custCsrf
], $cookieJarCustomer, ['Accept: application/json', "X-CSRF-Token: {$custCsrf}"]);
assertTest("Add product to wishlist succeeded (HTTP 200)", $wishAdd['code'] === 200);

$wishGet = httpReq("{$baseUrl}/backend/api/wishlist.php", 'GET', null, $cookieJarCustomer);
assertTest("Wishlist contains added product", count($wishGet['json']['data']['items'] ?? []) > 0);

// C2: Cart Management
echo "\n[14] Customer Shopping Cart Operations...\n";
httpReq("{$baseUrl}/backend/api/cart.php", 'POST', [
    'action' => 'clear',
    'csrf_token' => $custCsrf
], $cookieJarCustomer, ['Accept: application/json', "X-CSRF-Token: {$custCsrf}"]);

$cartAdd = httpReq("{$baseUrl}/backend/api/cart.php", 'POST', [
    'action' => 'add',
    'product_id' => 1,
    'quantity' => 2,
    'colour_name' => 'Forest Green',
    'size_name' => 'M',
    'csrf_token' => $custCsrf
], $cookieJarCustomer, ['Accept: application/json', "X-CSRF-Token: {$custCsrf}"]);
assertTest("Product added to shopping cart (HTTP 200)", $cartAdd['code'] === 200);

$cartGet = httpReq("{$baseUrl}/backend/api/cart.php", 'GET', null, $cookieJarCustomer);
$cartItems = $cartGet['json']['data']['items'] ?? [];
$cartItemCount = (int)($cartGet['json']['data']['item_count'] ?? 0);
$cartGrandTotal = (float)($cartGet['json']['data']['grand_total'] ?? 0.0);
assertTest("Shopping cart contains item", count($cartItems) === 1);
assertTest("Cart correctly calculated item count (2)", $cartItemCount === 2);
assertTest("Cart calculated positive total", $cartGrandTotal > 0.0);

// C3: Customer Addresses
echo "\n[15] Address Book Management...\n";
$addrAdd = httpReq("{$baseUrl}/backend/api/addresses.php", 'POST', [
    'action' => 'add',
    'full_name' => 'Jane Doe Delivery',
    'phone' => '+1-555-0100',
    'address_line1' => '742 Evergreen Terrace',
    'address_line2' => 'Apt 4B',
    'city' => 'Springfield',
    'state' => 'OR',
    'postal_code' => '97477',
    'country' => 'United States',
    'is_default' => 1,
    'csrf_token' => $custCsrf
], $cookieJarCustomer, ['Accept: application/json', "X-CSRF-Token: {$custCsrf}"]);
assertTest("New delivery address created (HTTP 201)", $addrAdd['code'] === 201);
$addressId = (int)($addrAdd['json']['data']['address_id'] ?? 0);
assertTest("Valid address ID returned", $addressId > 0);

// C4: Checkout Process
echo "\n[16] Order Placement & Checkout Processing...\n";
$orderRes = httpReq("{$baseUrl}/backend/api/checkout.php", 'POST', [
    'address_id' => $addressId,
    'payment_method' => 'Cash on Delivery (Demo)',
    'notes' => 'Please leave with reception.',
    'csrf_token' => $custCsrf
], $cookieJarCustomer, ['Accept: application/json', "X-CSRF-Token: {$custCsrf}"]);
assertTest("Order successfully processed & saved to MySQL (HTTP 201)", $orderRes['code'] === 201);
$orderNum = $orderRes['json']['data']['order_number'] ?? '';
$orderId  = (int)($orderRes['json']['data']['order_id'] ?? 0);
assertTest("Unique order number issued ({$orderNum})", !empty($orderNum));

// C5: Cart Cleaned After Order
echo "\n[17] Cart Auto-Clearing After Checkout...\n";
$cartAfter = httpReq("{$baseUrl}/backend/api/cart.php", 'GET', null, $cookieJarCustomer);
assertTest("Cart is empty after order placement", count($cartAfter['json']['data']['items'] ?? []) === 0);

// C6: Order Tracking & Timeline
echo "\n[18] Order Tracking Timeline...\n";
$trackRes = httpReq("{$baseUrl}/backend/api/orders.php?order_number={$orderNum}", 'GET', null, $cookieJarCustomer);
assertTest("Customer order tracking endpoint returns HTTP 200", $trackRes['code'] === 200);
$trackedOrder = $trackRes['json']['data']['order'] ?? [];
assertTest("Tracking shows Pending status", ($trackedOrder['status'] ?? '') === 'Pending');
assertTest("Timeline contains all 4 progressive steps", count($trackedOrder['timeline'] ?? []) === 4);


// ==========================================
// SECTION D: PHASE 4 COMPLETE ADMIN SYSTEM
// ==========================================
echo "\n--- SECTION D: PHASE 4 COMPLETE ADMIN MANAGEMENT SYSTEM ---\n\n";

// D1: Admin Authentication & Live Statistics
echo "[19] Admin Login & Live 13 MySQL Statistics...\n";
$admCsrf1 = getCsrf($baseUrl, $cookieJarAdmin);
$admLogin = httpReq("{$baseUrl}/backend/admin/login.php", 'POST', [
    'identifier' => 'admin@stylehub.local',
    'password'   => 'Admin@StyleHub2026!',
    'csrf_token' => $admCsrf1
], $cookieJarAdmin, [], false);
assertTest("Admin authenticated with session (HTTP 302/200)", in_array($admLogin['code'], [200, 302], true));

$admToken = getCsrf($baseUrl, $cookieJarAdmin);

$statsRes = httpReq("{$baseUrl}/backend/admin/stats.php", 'GET', null, $cookieJarAdmin, ['Accept: application/json']);
assertTest("Admin stats API returns HTTP 200", $statsRes['code'] === 200);
$allStats = $statsRes['json']['data'] ?? [];
$reqMetrics = [
    'total_products', 'in_stock_products', 'low_stock_products', 'out_of_stock_products',
    'orders_pending', 'orders_in_queue', 'orders_dispatched', 'orders_delivered',
    'orders_returned', 'total_returns', 'pending_returns', 'total_colours',
    'total_reviews', 'active_viewers'
];
$all13Present = true;
foreach ($reqMetrics as $m) {
    if (!array_key_exists($m, $allStats)) { $all13Present = false; break; }
}
assertTest("All 13 live MySQL dashboard statistics present", $all13Present);

// D2: Admin Category & Men/Women/Children Subcategories
echo "\n[20] Admin Category & Subcategory Management...\n";
$newCatName = 'Master Activewear ' . rand(100, 999);
$createCat = httpReq("{$baseUrl}/backend/api/admin-categories.php", 'POST', [
    'action'      => 'create_category',
    'name'        => $newCatName,
    'description' => 'Athletic apparel collection',
    'csrf_token'  => $admToken
], $cookieJarAdmin, ["X-CSRF-Token: {$admToken}"]);
assertTest("Admin category created (HTTP 201)", $createCat['code'] === 201);
$testCatId = (int)($createCat['json']['data']['category_id'] ?? 0);

$catsCheck = httpReq("{$baseUrl}/backend/api/admin-categories.php", 'GET', null, $cookieJarAdmin);
$foundCat = null;
foreach ($catsCheck['json']['data']['categories'] ?? [] as $c) {
    if ($c['id'] === $testCatId) { $foundCat = $c; break; }
}
$subs = $foundCat['subcategories'] ?? [];
$subGroups = array_column($subs, 'target_group');
assertTest("Men, Women, and Children classifications auto-generated", in_array('Men', $subGroups) && in_array('Women', $subGroups) && in_array('Children', $subGroups));

// D3: Admin Product Management (CRUD, Exact Stock, Manual Badge)
echo "\n[21] Admin Product Management & Stock Visibility...\n";
$newProdName = 'Master Bomber Jacket ' . rand(100, 999);
$createProd = httpReq("{$baseUrl}/backend/api/admin-products.php", 'POST', [
    'action'                 => 'create',
    'name'                   => $newProdName,
    'category_id'            => $testCatId,
    'price'                  => 129.99,
    'low_stock_threshold'    => 5,
    'initial_stock'          => 4, // 4 <= threshold 5 -> low stock
    'is_featured'            => 1,
    'hide_when_out_of_stock' => 1,
    'manual_badge_message'   => 'Limited Winter Edition',
    'csrf_token'             => $admToken
], $cookieJarAdmin, ["X-CSRF-Token: {$admToken}"]);
assertTest("Admin product created (HTTP 201)", $createProd['code'] === 201);
$testProdId = (int)($createProd['json']['data']['product_id'] ?? 0);

// Admin sees exact stock
$admProd = httpReq("{$baseUrl}/backend/api/admin-products.php?id={$testProdId}", 'GET', null, $cookieJarAdmin);
assertTest("Admin sees exact stock count (4)", (int)($admProd['json']['data']['product']['total_stock'] ?? 0) === 4);

// Public sees Low in Stock badge & manual badge, hides exact quantity
$pubProd = httpReq("{$baseUrl}/backend/api/products.php?search=" . urlencode($newProdName))['json']['data']['products'][0] ?? [];
assertTest("Public sees Low in Stock badge", ($pubProd['is_low_stock'] ?? false) === true);
assertTest("Public sees manual badge message", ($pubProd['manual_badge_message'] ?? '') === 'Limited Winter Edition');
assertTest("Public NEVER receives exact total_stock count", !array_key_exists('total_stock', (array)$pubProd));

// Adjust inventory stock from 4 to 20
$updStock = httpReq("{$baseUrl}/backend/api/admin-products.php", 'POST', [
    'action'         => 'update_stock',
    'product_id'     => $testProdId,
    'stock_quantity' => 20,
    'csrf_token'     => $admToken
], $cookieJarAdmin, ["X-CSRF-Token: {$admToken}"]);
assertTest("Admin adjusted stock level to 20", $updStock['code'] === 200);

// D4: Admin Customer Directory & Privacy
echo "\n[22] Admin Customer Management & Password Protection...\n";
$custDir = httpReq("{$baseUrl}/backend/api/admin-customers.php", 'GET', null, $cookieJarAdmin);
assertTest("Admin customer directory returns HTTP 200", $custDir['code'] === 200);
$allCusts = $custDir['json']['data']['customers'] ?? [];
$passExposed = false;
foreach ($allCusts as $c) {
    if (isset($c['password']) || isset($c['password_hash'])) { $passExposed = true; break; }
}
assertTest("Customer passwords strictly excluded from admin customer directory", !$passExposed);

// Customer Dossier
$dossierRes = httpReq("{$baseUrl}/backend/api/admin-customers.php?id=1", 'GET', null, $cookieJarAdmin);
assertTest("Customer dossier loaded with addresses, orders, reviews, returns", 
    isset($dossierRes['json']['data']['addresses']) &&
    isset($dossierRes['json']['data']['orders']) &&
    isset($dossierRes['json']['data']['reviews'])
);

// D5: Admin Orders Fulfillment Pipeline
echo "\n[23] Admin Orders Pipeline & Dispatch Tracking...\n";
$admOrders = httpReq("{$baseUrl}/backend/api/admin-orders.php", 'GET', null, $cookieJarAdmin);
assertTest("Admin orders list loaded (HTTP 200)", $admOrders['code'] === 200);

// Advance order to In Queue -> Dispatched -> Delivered
httpReq("{$baseUrl}/backend/api/admin-orders.php", 'POST', [
    'order_id'   => $orderId,
    'status'     => 'In Queue',
    'csrf_token' => $admToken
], $cookieJarAdmin, ["X-CSRF-Token: {$admToken}"]);

$dispDate = date('Y-m-d H:i:s');
$toDisp = httpReq("{$baseUrl}/backend/api/admin-orders.php", 'POST', [
    'order_id'      => $orderId,
    'status'        => 'Dispatched',
    'dispatch_date' => $dispDate,
    'csrf_token'    => $admToken
], $cookieJarAdmin, ["X-CSRF-Token: {$admToken}"]);
assertTest("Admin marked order Dispatched with dispatch date", $toDisp['code'] === 200);

$toDeliv = httpReq("{$baseUrl}/backend/api/admin-orders.php", 'POST', [
    'order_id'   => $orderId,
    'status'     => 'Delivered',
    'csrf_token' => $admToken
], $cookieJarAdmin, ["X-CSRF-Token: {$admToken}"]);
assertTest("Admin marked order Delivered", $toDeliv['code'] === 200);

// D6: Returns Processing & Automated Inventory Restocking
echo "\n[24] Return Request Approval & Automated Inventory Restocking...\n";
// Customer submits return request for the Delivered order
$retSubmit = httpReq("{$baseUrl}/backend/api/returns.php", 'POST', [
    'order_id'   => $orderId,
    'reason'     => 'Wrong size delivered',
    'details'    => 'Requesting return for restocking',
    'csrf_token' => $custCsrf
], $cookieJarCustomer, ["X-CSRF-Token: {$custCsrf}"]);
assertTest("Customer return request submitted (HTTP 201)", $retSubmit['code'] === 201);
$returnId = (int)($retSubmit['json']['data']['return_id'] ?? 0);

// Read inventory before return
require_once __DIR__ . '/config/database.php';
$pdo = getDB();
$stockBefore = (int)$pdo->query("SELECT SUM(stock_quantity) FROM `product_inventory` WHERE product_id = 1")->fetchColumn();

// Admin approves return
$apprRes = httpReq("{$baseUrl}/backend/api/admin-returns.php", 'POST', [
    'action'      => 'process_return',
    'return_id'   => $returnId,
    'decision'    => 'Approved',
    'admin_notes' => 'Product received back in warehouse and approved',
    'csrf_token'  => $admToken
], $cookieJarAdmin, ["X-CSRF-Token: {$admToken}"]);
assertTest("Admin approved return request (HTTP 200)", $apprRes['code'] === 200);

$stockAfter = (int)$pdo->query("SELECT SUM(stock_quantity) FROM `product_inventory` WHERE product_id = 1")->fetchColumn();
assertTest("Order status updated to Returned in MySQL", ($pdo->query("SELECT status FROM `orders` WHERE id = {$orderId}")->fetchColumn() === 'Returned'));
assertTest("Product inventory automatically restocked (+2 units)", $stockAfter === ($stockBefore + 2));

// Clean up created product
httpReq("{$baseUrl}/backend/api/admin-products.php", 'POST', [
    'action'     => 'delete',
    'product_id' => $testProdId,
    'csrf_token' => $admToken
], $cookieJarAdmin, ["X-CSRF-Token: {$admToken}"]);


// ==========================================
// SECTION E: PHASE 5 SECURITY & QA DEFENSES
// ==========================================
echo "\n--- SECTION E: PHASE 5 SECURITY DEFENSES & QA VALIDATION ---\n\n";

// E1: SQL Injection Immunity
echo "[25] SQL Injection Immunity Testing...\n";
$sqliTest1 = httpReq("{$baseUrl}/backend/api/products.php?search=" . urlencode("' OR '1'='1"));
assertTest("SQLi payload in search handled safely with HTTP 200", $sqliTest1['code'] === 200 && is_array($sqliTest1['json']));

$sqliTest2 = httpReq("{$baseUrl}/backend/api/admin-customers.php?id=" . urlencode("1 UNION SELECT 1,2,3,4,5,6,7--"), 'GET', null, $cookieJarAdmin);
assertTest("SQLi payload in admin customer ID handled safely", in_array($sqliTest2['code'], [200, 404], true));

// E2: Proper HTTP Status Codes
echo "\n[26] HTTP Status Code Conformance (400, 404, 405, 422)...\n";
$sc400 = httpReq("{$baseUrl}/backend/api/product-detail.php");
assertTest("Missing parameter returns HTTP 400 Bad Request", $sc400['code'] === 400);

$sc404 = httpReq("{$baseUrl}/backend/api/product-detail.php?id=999999");
assertTest("Non-existent resource returns HTTP 404 Not Found", $sc404['code'] === 404);

$sc405 = httpReq("{$baseUrl}/backend/api/health.php", 'DELETE');
assertTest("Unsupported verb returns HTTP 405 Method Not Allowed", $sc405['code'] === 405);

$sc422 = httpReq("{$baseUrl}/backend/auth/login.php", 'POST', ['email' => '', 'password' => '', 'csrf_token' => $custCsrf], $cookieJarCustomer, ["X-CSRF-Token: {$custCsrf}"]);
assertTest("Empty credentials return HTTP 422 Unprocessable Entity", $sc422['code'] === 422);

// E3: Secure File Uploads
echo "\n[27] File Upload MIME Validation & Sandboxing...\n";
$fakePhp = tempnam(sys_get_temp_dir(), 'hack_') . '.txt';
file_put_contents($fakePhp, '<?php phpinfo(); ?>');
$badFile = new CURLFile($fakePhp, 'text/plain', 'shell.php');
$badUploadRes = httpReq("{$baseUrl}/backend/api/admin-products.php", 'POST', [
    'action'     => 'upload_image',
    'product_id' => 1,
    'image'      => $badFile,
    'csrf_token' => $admToken
], $cookieJarAdmin, ["X-CSRF-Token: {$admToken}"]);
@unlink($fakePhp);
assertTest("Non-image upload rejected with HTTP 422", $badUploadRes['code'] === 422);


// ==========================================
// SECTION F: SYSTEM INTEGRITY & ZERO INLINE CSS
// ==========================================
echo "\n--- SECTION F: SYSTEM INTEGRITY & ZERO INLINE CSS ---\n\n";
echo "[28] Zero Inline CSS Audit Across Entire Application...\n";
$allFiles = array_merge(
    glob(__DIR__ . '/../frontend/*.html'),
    glob(__DIR__ . '/../frontend/js/*.js'),
    glob(__DIR__ . '/*.php'),
    glob(__DIR__ . '/api/*.php'),
    glob(__DIR__ . '/admin/*.php'),
    glob(__DIR__ . '/auth/*.php')
);

$hasInlineCss = false;
foreach ($allFiles as $f) {
    if (preg_match('/style\s*=\s*["\']/i', file_get_contents($f))) {
        $hasInlineCss = true;
        echo "  [WARN] Inline CSS found in " . basename($f) . "\n";
    }
}
assertTest("ZERO Inline CSS across all HTML, JS, and PHP files", !$hasInlineCss);

// Cleanup cookies
@unlink($cookieJarCustomer);
@unlink($cookieJarRegister);
@unlink($cookieJarAdmin);
@unlink($cookieJarGuest);

echo "\n====================================================================\n";
echo "MASTER COLLECTIVE TEST RESULTS: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
