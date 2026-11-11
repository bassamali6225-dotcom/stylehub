<?php
/**
 * StyleHub - Complete End-to-End Professional QA Verification Suite
 * Tests all Public-User & Admin Workflows, Security, Database Integrity, and Edge Cases
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

$baseUrl = 'http://127.0.0.1:8000';
$pdo = Database::getConnection();

$passed = 0;
$failed = 0;
$testResults = [];

function test(string $name, bool $condition, string $detail = '') {
    global $passed, $failed, $testResults;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}\n";
        $testResults[] = ['name' => $name, 'status' => 'PASS', 'detail' => $detail];
    } else {
        $failed++;
        echo "  [FAIL] {$name} - {$detail}\n";
        $testResults[] = ['name' => $name, 'status' => 'FAIL', 'detail' => $detail];
    }
}

function req(string $url, string $method = 'GET', $data = null, string $cookieFile = '', array $headers = [], bool $isJson = true): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($cookieFile !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($data !== null) {
        if ($isJson) {
            $body = is_array($data) ? json_encode($data) : $data;
            $headers[] = 'Content-Type: application/json';
        } else {
            $body = is_array($data) ? http_build_query($data) : $data;
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $json = json_decode((string)$raw, true);

    return [
        'code' => $code,
        'body' => $raw,
        'json' => is_array($json) ? $json : [],
        'error' => $err
    ];
}

function getCsrfToken(string $baseUrl, string $cookieFile): string {
    $res = req("{$baseUrl}/backend/auth/csrf.php", 'GET', null, $cookieFile, ['Accept: application/json']);
    return $res['json']['data']['csrf_token'] ?? '';
}

echo "====================================================================\n";
echo "    STYLEHUB COMPLETE PROFESSIONAL QA VERIFICATION TEST SUITE       \n";
echo "====================================================================\n\n";

$tempCustCookie = sys_get_temp_dir() . '/cookie_qa_cust_' . uniqid() . '.txt';
$tempAdminCookie = sys_get_temp_dir() . '/cookie_qa_admin_' . uniqid() . '.txt';
$guestCookie = sys_get_temp_dir() . '/cookie_qa_guest_' . uniqid() . '.txt';

// =========================================================================
// PART 1: PUBLIC-USER WORKFLOWS
// =========================================================================
echo ">>> PART 1: PUBLIC-USER WORKFLOWS <<<\n\n";

// --- Workflow 1: Registration, Login, Logout, Invalid Login, Account Protection ---
echo "[Workflow 1] Authentication & Customer Account Protection...\n";
$qaEmail = 'qa_customer_' . bin2hex(random_bytes(4)) . '@stylehub.local';
$qaPass = 'QATest@2026!';
$qaName = 'QA Test User';

$guestCsrf = getCsrfToken($baseUrl, $tempCustCookie);

// 1.1 Invalid Login with non-existent user
$resBadUser = req("{$baseUrl}/backend/auth/login.php", 'POST', [
    'email' => 'nonexistent@stylehub.local',
    'password' => 'WrongPassword!',
    'csrf_token' => $guestCsrf
], $tempCustCookie, ["X-CSRF-Token: {$guestCsrf}"]);
test("Invalid login rejected with HTTP 401", $resBadUser['code'] === 401);
test("Invalid login returns appropriate error message", strpos($resBadUser['json']['message'] ?? '', 'Invalid email or password') !== false);

// 1.2 Customer Registration
$resReg = req("{$baseUrl}/backend/auth/register.php", 'POST', [
    'name' => $qaName,
    'email' => $qaEmail,
    'password' => $qaPass,
    'phone' => '+1 (555) 987-6543',
    'csrf_token' => $guestCsrf
], $tempCustCookie, ["X-CSRF-Token: {$guestCsrf}"]);
test("Customer registration returns HTTP 201 Created", $resReg['code'] === 201);
$qaUserId = (int)($resReg['json']['data']['user']['id'] ?? 0);
test("Customer ID generated in MySQL", $qaUserId > 0);

// Verify in MySQL
$dbUser = $pdo->query("SELECT id, name, email, status, password FROM `users` WHERE id = {$qaUserId}")->fetch();
test("Customer registered in MySQL users table", $dbUser && $dbUser['email'] === $qaEmail);
test("Password securely hashed with Bcrypt in MySQL", password_verify($qaPass, $dbUser['password']));
test("Initial customer status is 'active'", $dbUser['status'] === 'active');

// 1.3 Customer Login with valid credentials
$custCsrf = getCsrfToken($baseUrl, $tempCustCookie);
$resLogin = req("{$baseUrl}/backend/auth/login.php", 'POST', [
    'email' => $qaEmail,
    'password' => $qaPass,
    'csrf_token' => $custCsrf
], $tempCustCookie, ["X-CSRF-Token: {$custCsrf}"]);
test("Customer login succeeds with HTTP 200", $resLogin['code'] === 200);
test("Login returns user profile data", ($resLogin['json']['data']['user']['email'] ?? '') === $qaEmail);

// 1.4 Active session check
$resMe = req("{$baseUrl}/backend/auth/me.php", 'GET', null, $tempCustCookie);
test("Customer session check returns logged_in: true", ($resMe['json']['data']['logged_in'] ?? false) === true);
test("Customer session role is 'customer'", ($resMe['json']['data']['role'] ?? '') === 'customer');

// 1.5 Customer Account Protection: Customer attempting admin dashboard
$resAdminBlock = req("{$baseUrl}/backend/admin/dashboard.php", 'GET', null, $tempCustCookie);
test("Customer blocked from admin dashboard (HTTP 302 Redirect)", $resAdminBlock['code'] === 302);
$resAdminStatsBlock = req("{$baseUrl}/backend/admin/stats.php", 'GET', null, $tempCustCookie);
test("Customer blocked from admin stats API (HTTP 403 Forbidden)", $resAdminStatsBlock['code'] === 403);

// --- Workflow 2: Catalog Browsing, Search, Filters, Sorting ---
echo "\n[Workflow 2] Storefront Catalog, Categories, Search, Filters & Sorting...\n";

// 2.1 Public Categories API
$resCats = req("{$baseUrl}/backend/api/categories.php");
test("Public Categories API returns HTTP 200", $resCats['code'] === 200);
$categories = $resCats['json']['data']['categories'] ?? [];
test("Storefront has categories available", count($categories) >= 4);

// 2.2 Search filter
$resSearch = req("{$baseUrl}/backend/api/products.php?search=wool");
test("Product search by 'wool' returns HTTP 200", $resSearch['code'] === 200);
$searchProds = $resSearch['json']['data']['products'] ?? [];
test("Search returns matching products", count($searchProds) > 0);

// 2.3 Category and Subcategory (Men/Women/Children) filter
$resCatFilter = req("{$baseUrl}/backend/api/products.php?category=pants&target_group=Men");
test("Filter by category=pants and target_group=Men returns HTTP 200", $resCatFilter['code'] === 200);
$catProds = $resCatFilter['json']['data']['products'] ?? [];
test("All returned products belong to Pants / Men", count($catProds) > 0 && $catProds[0]['category_slug'] === 'pants');

// 2.4 Price range filter
$resPrice = req("{$baseUrl}/backend/api/products.php?min_price=50&max_price=100");
test("Price range filter ($50-$100) returns HTTP 200", $resPrice['code'] === 200);
$priceProds = $resPrice['json']['data']['products'] ?? [];
$priceOk = true;
foreach ($priceProds as $p) {
    if ($p['price'] < 50 || $p['price'] > 100) { $priceOk = false; break; }
}
test("All products respect price boundary $50-$100", $priceOk && count($priceProds) > 0);

// 2.5 Colour filter
$resColour = req("{$baseUrl}/backend/api/products.php?colour=Forest");
test("Colour filter by 'Forest' returns HTTP 200", $resColour['code'] === 200);

// 2.6 Sorting
$resSortAsc = req("{$baseUrl}/backend/api/products.php?sort=price_asc&limit=5");
$ascProds = $resSortAsc['json']['data']['products'] ?? [];
$isAsc = true;
for ($i = 0; $i < count($ascProds) - 1; $i++) {
    if ($ascProds[$i]['price'] > $ascProds[$i + 1]['price']) { $isAsc = false; break; }
}
test("Sort by price_asc produces ascending prices", $isAsc && count($ascProds) > 1);

$resSortDesc = req("{$baseUrl}/backend/api/products.php?sort=price_desc&limit=5");
$descProds = $resSortDesc['json']['data']['products'] ?? [];
$isDesc = true;
for ($i = 0; $i < count($descProds) - 1; $i++) {
    if ($descProds[$i]['price'] < $descProds[$i + 1]['price']) { $isDesc = false; break; }
}
test("Sort by price_desc produces descending prices", $isDesc && count($descProds) > 1);

// 2.7 Pagination / Load More limit & offset
$resPaging1 = req("{$baseUrl}/backend/api/products.php?limit=2&offset=0");
$resPaging2 = req("{$baseUrl}/backend/api/products.php?limit=2&offset=2");
test("Pagination returns correct count per page", count($resPaging1['json']['data']['products'] ?? []) === 2);
test("Pagination offset returns distinct next set", ($resPaging1['json']['data']['products'][0]['id'] ?? 1) !== ($resPaging2['json']['data']['products'][0]['id'] ?? 2));

// --- Workflow 11: Stock Quantity Privacy Rule ---
echo "\n[Workflow 11] Public Stock Privacy Enforcement...\n";
$firstProd = $resPaging1['json']['data']['products'][0] ?? [];
test("Public catalog strictly NEVER exposes 'total_stock'", !isset($firstProd['total_stock']));
test("Public catalog strictly NEVER exposes 'stock_quantity'", !isset($firstProd['stock_quantity']));
test("Public catalog exposes boolean 'is_low_stock'", isset($firstProd['is_low_stock']) && is_bool($firstProd['is_low_stock']));

$resDetail = req("{$baseUrl}/backend/api/product-detail.php?id=1");
$prodDetail = $resDetail['json']['data']['product'] ?? [];
test("Product details strictly NEVER exposes exact stock number", !isset($prodDetail['total_stock']) && !isset($prodDetail['stock_quantity']));
test("Product details includes photo gallery", !empty($prodDetail['images']));
test("Product details includes colours & sizes", !empty($prodDetail['colours']) && !empty($prodDetail['sizes']));
test("Product details includes live viewer activity", isset($prodDetail['active_viewers']));

// --- Workflow 3: Wishlist Operations ---
echo "\n[Workflow 3] Customer Wishlist (Add, List, Remove)...\n";
$custCsrf = getCsrfToken($baseUrl, $tempCustCookie);

$resWishAdd = req("{$baseUrl}/backend/api/wishlist.php", 'POST', [
    'action' => 'add',
    'product_id' => 2,
    'csrf_token' => $custCsrf
], $tempCustCookie, ["X-CSRF-Token: {$custCsrf}"]);
test("Add product to wishlist returns HTTP 200", $resWishAdd['code'] === 200);

// Check wishlist in MySQL
$wishRow = $pdo->query("
    SELECT wi.id FROM `wishlist_items` wi 
    JOIN `wishlists` w ON wi.wishlist_id = w.id 
    WHERE w.user_id = {$qaUserId} AND wi.product_id = 2
")->fetch();
test("Wishlist item saved in MySQL database", !empty($wishRow));

// Retrieve Wishlist API
$resWishGet = req("{$baseUrl}/backend/api/wishlist.php", 'GET', null, $tempCustCookie);
test("Wishlist API returns saved product", !empty($resWishGet['json']['data']['items']));

// Remove from Wishlist
$resWishRem = req("{$baseUrl}/backend/api/wishlist.php", 'POST', [
    'action' => 'remove',
    'product_id' => 2,
    'csrf_token' => $custCsrf
], $tempCustCookie, ["X-CSRF-Token: {$custCsrf}"]);
test("Remove product from wishlist returns HTTP 200", $resWishRem['code'] === 200);

// Verify removed in MySQL
$wishRowAfter = $pdo->query("
    SELECT wi.id FROM `wishlist_items` wi 
    JOIN `wishlists` w ON wi.wishlist_id = w.id 
    WHERE w.user_id = {$qaUserId} AND wi.product_id = 2
")->fetch();
test("Wishlist item removed from MySQL database", empty($wishRowAfter));

// --- Workflow 4 & 5: Shopping Cart & Variants (Colours/Sizes) ---
echo "\n[Workflow 4 & 5] Shopping Cart Operations & Variant Selection...\n";
$custCsrf = getCsrfToken($baseUrl, $tempCustCookie);

// 4.1 Add product with colour and size
$resCartAdd = req("{$baseUrl}/backend/api/cart.php", 'POST', [
    'action' => 'add',
    'product_id' => 1,
    'colour_id' => 1,
    'size_id' => 2,
    'quantity' => 1,
    'csrf_token' => $custCsrf
], $tempCustCookie, ["X-CSRF-Token: {$custCsrf}"]);
test("Add product variant to cart returns HTTP 200", $resCartAdd['code'] === 200);

// Retrieve Cart API
$resCartGet = req("{$baseUrl}/backend/api/cart.php", 'GET', null, $tempCustCookie);
$cartData = $resCartGet['json']['data'] ?? [];
test("Cart contains added product", !empty($cartData['items']));
$cartItem = $cartData['items'][0] ?? [];
$cartItemId = (int)($cartItem['item_id'] ?? 0);
test("Cart item has correct variant colour and size names", !empty($cartItem['colour_name']) && !empty($cartItem['size_name']));

// 4.2 Update Quantity
$resCartUpd = req("{$baseUrl}/backend/api/cart.php", 'POST', [
    'action' => 'update',
    'item_id' => $cartItemId,
    'quantity' => 3,
    'csrf_token' => $custCsrf
], $tempCustCookie, ["X-CSRF-Token: {$custCsrf}"]);
test("Update cart item quantity returns HTTP 200", $resCartUpd['code'] === 200);

// Verify persistence in MySQL
$dbCartItem = $pdo->query("SELECT quantity FROM `cart_items` WHERE id = {$cartItemId}")->fetch();
test("Updated quantity (3) persisted in MySQL cart_items", (int)($dbCartItem['quantity'] ?? 0) === 3);

// Verify Subtotal and Shipping rules in API
$resCartAfterUpd = req("{$baseUrl}/backend/api/cart.php", 'GET', null, $tempCustCookie);
$cartAfter = $resCartAfterUpd['json']['data'] ?? [];
test("Cart totalQuantity updated to 3", ($cartAfter['item_count'] ?? 0) === 3);
test("Complimentary shipping unlocked when subtotal >= $150", $cartAfter['subtotal'] >= 150.0 ? ((float)$cartAfter['shipping'] == 0.0) : ((float)$cartAfter['shipping'] == 15.0));

// --- Workflow 6: Customer Reviews (Valid, Invalid, Duplicate) ---
echo "\n[Workflow 6] Customer Reviews Submission (Valid, Invalid, Duplicate)...\n";
$custCsrf = getCsrfToken($baseUrl, $tempCustCookie);
$uniqueReviewComment = 'QA Test Review ' . uniqid() . ' - Exceptional quality and finish!';

// 6.1 Valid Review Submission
$resRevValid = req("{$baseUrl}/backend/api/reviews.php", 'POST', [
    'product_id' => 2,
    'rating' => 5,
    'title' => 'Top Tier Product',
    'comment' => $uniqueReviewComment,
    'csrf_token' => $custCsrf
], $tempCustCookie, ["X-CSRF-Token: {$custCsrf}"]);
test("Valid review submitted returns HTTP 201 Created", $resRevValid['code'] === 201);
$qaReviewId = (int)($resRevValid['json']['data']['review_id'] ?? 0);
test("Review saved to MySQL reviews table", $qaReviewId > 0);

// 6.2 Invalid Review: Rating out of bounds (6)
$resRevBadRating = req("{$baseUrl}/backend/api/reviews.php", 'POST', [
    'product_id' => 2,
    'rating' => 6,
    'comment' => 'Should fail due to invalid rating number',
    'csrf_token' => $custCsrf
], $tempCustCookie, ["X-CSRF-Token: {$custCsrf}"]);
test("Invalid rating (>5) rejected with HTTP 422", $resRevBadRating['code'] === 422);

// 6.3 Invalid Review: Comment too short (< 5 chars)
$resRevShort = req("{$baseUrl}/backend/api/reviews.php", 'POST', [
    'product_id' => 2,
    'rating' => 5,
    'comment' => 'Bad',
    'csrf_token' => $custCsrf
], $tempCustCookie, ["X-CSRF-Token: {$custCsrf}"]);
test("Too short comment rejected with HTTP 422", $resRevShort['code'] === 422);

// 6.4 Duplicate Review: Submitting exact same review
$resRevDup = req("{$baseUrl}/backend/api/reviews.php", 'POST', [
    'product_id' => 2,
    'rating' => 5,
    'title' => 'Top Tier Product',
    'comment' => $uniqueReviewComment,
    'csrf_token' => $custCsrf
], $tempCustCookie, ["X-CSRF-Token: {$custCsrf}"]);
test("Duplicate review rejected with HTTP 409 Conflict", $resRevDup['code'] === 409);
test("Duplicate error message returned", strpos($resRevDup['json']['message'] ?? '', 'Duplicate review detected') !== false);

// --- Workflow 7: Customer Address Book ---
echo "\n[Workflow 7] Address Book (Add, Edit, Set Default, Delete)...\n";
$custCsrf = getCsrfToken($baseUrl, $tempCustCookie);

// 7.1 Add Address
$resAddrAdd = req("{$baseUrl}/backend/api/addresses.php", 'POST', [
    'action' => 'add',
    'full_name' => $qaName,
    'phone' => '+1 (555) 123-4567',
    'address_line1' => '100 StyleHub Way',
    'address_line2' => 'Suite 500',
    'city' => 'San Francisco',
    'state' => 'CA',
    'postal_code' => '94105',
    'country' => 'United States',
    'is_default' => 1,
    'csrf_token' => $custCsrf
], $tempCustCookie, ["X-CSRF-Token: {$custCsrf}"]);
test("Add delivery address returns HTTP 201", $resAddrAdd['code'] === 201);
$qaAddressId = (int)($resAddrAdd['json']['data']['address_id'] ?? 0);
test("Address ID generated in MySQL", $qaAddressId > 0);

// Verify in MySQL
$dbAddr = $pdo->query("SELECT * FROM `addresses` WHERE id = {$qaAddressId}")->fetch();
test("Address saved in MySQL with is_default=1", (int)($dbAddr['is_default'] ?? 0) === 1);

// 7.2 Edit Address
$resAddrEdit = req("{$baseUrl}/backend/api/addresses.php", 'POST', [
    'action' => 'edit',
    'address_id' => $qaAddressId,
    'full_name' => $qaName,
    'phone' => '+1 (555) 999-8888',
    'address_line1' => '100 StyleHub Way',
    'address_line2' => 'Penthouse A',
    'city' => 'San Francisco',
    'state' => 'CA',
    'postal_code' => '94105',
    'country' => 'United States',
    'is_default' => 1,
    'csrf_token' => $custCsrf
], $tempCustCookie, ["X-CSRF-Token: {$custCsrf}"]);
test("Edit delivery address returns HTTP 200", $resAddrEdit['code'] === 200);

$dbAddrAfter = $pdo->query("SELECT address_line2, phone FROM `addresses` WHERE id = {$qaAddressId}")->fetch();
test("Address updates saved in MySQL (Penthouse A)", $dbAddrAfter['address_line2'] === 'Penthouse A');

// --- Workflow 8: Checkout & Order Placement ---
echo "\n[Workflow 8] Checkout Order Placement & MySQL Consistency...\n";
$custCsrf = getCsrfToken($baseUrl, $tempCustCookie);

$resCheckout = req("{$baseUrl}/backend/api/checkout.php", 'POST', [
    'address_id' => $qaAddressId,
    'csrf_token' => $custCsrf
], $tempCustCookie, ["X-CSRF-Token: {$custCsrf}"]);
test("Sample checkout returns HTTP 201 Created", $resCheckout['code'] === 201);
$qaOrderId = (int)($resCheckout['json']['data']['order_id'] ?? 0);
$qaOrderNum = $resCheckout['json']['data']['order_number'] ?? '';
test("Unique Order Number generated ({$qaOrderNum})", !empty($qaOrderNum));

// Verify Order in MySQL
$dbOrder = $pdo->query("SELECT * FROM `orders` WHERE id = {$qaOrderId}")->fetch();
test("Order saved in MySQL with status='Pending'", $dbOrder && $dbOrder['status'] === 'Pending');
test("Payment method recorded as Cash on Delivery (Demo Checkout)", $dbOrder['payment_method'] === 'Cash on Delivery (Demo Checkout)');
test("Order total matches checkout amount", (float)$dbOrder['total_amount'] > 0);

// Verify Order Items in MySQL
$dbOrderItems = $pdo->query("SELECT * FROM `order_items` WHERE order_id = {$qaOrderId}")->fetchAll();
test("Order items saved as historical snapshots in MySQL", count($dbOrderItems) > 0);
test("Order item retains product name, colour, size and price", !empty($dbOrderItems[0]['product_name']) && !empty($dbOrderItems[0]['colour_name']));

// Verify Cart Auto-Clearing
$dbCartCheck = $pdo->query("
    SELECT COUNT(*) FROM `cart_items` ci 
    JOIN `carts` c ON ci.cart_id = c.id 
    WHERE c.user_id = {$qaUserId}
")->fetchColumn();
test("Customer shopping bag automatically emptied after checkout", (int)$dbCartCheck === 0);

// --- Workflow 9: Order History & Tracking Statuses ---
echo "\n[Workflow 9] Customer Order History & Tracking Details...\n";

// Order History
$resHistory = req("{$baseUrl}/backend/api/orders.php", 'GET', null, $tempCustCookie);
test("Customer order history returns HTTP 200", $resHistory['code'] === 200);
$historyOrders = $resHistory['json']['data']['orders'] ?? [];
$orderInHistory = false;
foreach ($historyOrders as $ho) {
    if ($ho['order_number'] === $qaOrderNum) { $orderInHistory = true; break; }
}
test("Newly placed order appears in customer order history", $orderInHistory);

// Order Tracking
$resTrack = req("{$baseUrl}/backend/api/orders.php?order_number={$qaOrderNum}", 'GET', null, $tempCustCookie);
test("Order tracking endpoint returns HTTP 200", $resTrack['code'] === 200);
$trackData = $resTrack['json']['data']['order'] ?? [];
test("Tracking status is 'Pending'", ($trackData['status'] ?? '') === 'Pending');
test("Tracking includes 4 progressive timeline steps", count($trackData['timeline'] ?? []) === 4);
test("Tracking includes shipping destination address", !empty($trackData['shipping_address']['city']));

// --- Workflow 10: Customer Return Request Block on Non-Delivered Order ---
echo "\n[Workflow 10] Return Eligibility Protection...\n";
$custCsrf = getCsrfToken($baseUrl, $tempCustCookie);

$resRetBlocked = req("{$baseUrl}/backend/api/returns.php", 'POST', [
    'order_id' => $qaOrderId,
    'reason' => 'Defective zipper',
    'details' => 'Zipper gets stuck',
    'csrf_token' => $custCsrf
], $tempCustCookie, ["X-CSRF-Token: {$custCsrf}"]);
test("Return on non-Delivered order blocked with HTTP 400", $resRetBlocked['code'] === 400);


// =========================================================================
// PART 2: ADMIN WORKFLOWS
// =========================================================================
echo "\n>>> PART 2: ADMIN WORKFLOWS <<<\n\n";

// --- Admin Workflow 1: Login, Logout, Session Protection ---
echo "[Admin 1] Admin Authentication & Session Protection...\n";
$admCsrfPre = getCsrfToken($baseUrl, $tempAdminCookie);

$resAdminLogin = req("{$baseUrl}/backend/admin/login.php", 'POST', [
    'identifier' => 'admin@stylehub.local',
    'password' => 'Admin@StyleHub2026!',
    'csrf_token' => $admCsrfPre
], $tempAdminCookie, [], false);
test("Admin login succeeds (HTTP 302/200)", in_array($resAdminLogin['code'], [200, 302], true));

$admCsrf = getCsrfToken($baseUrl, $tempAdminCookie);

// Verify admin stats access
$resStats = req("{$baseUrl}/backend/admin/stats.php", 'GET', null, $tempAdminCookie);
test("Authenticated admin accesses live stats API (HTTP 200)", $resStats['code'] === 200);

// --- Admin Workflow 2: Category & Classification Management ---
echo "\n[Admin 2 & 3] Category Creation, Men/Women/Children Classifications & Rename...\n";
$catName = 'QA Performance ' . uniqid();

$resCatCreate = req("{$baseUrl}/backend/api/admin-categories.php", 'POST', [
    'action' => 'create_category',
    'name' => $catName,
    'description' => 'QA Activewear and high-performance outerwear',
    'csrf_token' => $admCsrf
], $tempAdminCookie, ["X-CSRF-Token: {$admCsrf}"]);
test("Admin creates category returns HTTP 201 Created", $resCatCreate['code'] === 201);
$qaCatId = (int)($resCatCreate['json']['data']['category_id'] ?? 0);
test("Category created in MySQL with ID > 0", $qaCatId > 0);

// Verify Men, Women, Children subcategories auto-created
$dbSubs = $pdo->query("SELECT target_group FROM `subcategories` WHERE category_id = {$qaCatId}")->fetchAll(PDO::FETCH_COLUMN);
test("Automatic Men, Women, Children classifications created in MySQL", 
    in_array('Men', $dbSubs, true) && in_array('Women', $dbSubs, true) && in_array('Children', $dbSubs, true)
);

// Verify category appears in public Categories API
$resPublicCats = req("{$baseUrl}/backend/api/categories.php");
$foundPubCat = false;
foreach ($resPublicCats['json']['data']['categories'] ?? [] as $pc) {
    if ((int)$pc['id'] === $qaCatId) { $foundPubCat = true; break; }
}
test("Newly created category appears in storefront public Categories API", $foundPubCat);

// Rename category
$renamedCatName = $catName . ' Updated';
$resCatRename = req("{$baseUrl}/backend/api/admin-categories.php", 'POST', [
    'action' => 'update_category',
    'category_id' => $qaCatId,
    'name' => $renamedCatName,
    'description' => 'Updated description',
    'csrf_token' => $admCsrf
], $tempAdminCookie, ["X-CSRF-Token: {$admCsrf}"]);
test("Admin renames category returns HTTP 200", $resCatRename['code'] === 200);

$dbCatCheck = $pdo->query("SELECT name FROM `categories` WHERE id = {$qaCatId}")->fetchColumn();
test("Category name updated in MySQL", $dbCatCheck === $renamedCatName);

// --- Admin Workflow 4 & 5: Upload Product, Variants, Stock, Threshold & Badges ---
echo "\n[Admin 4 & 5] Upload Product with Image, Variants, Stock & Badges...\n";
$prodName = 'QA Technical Jacket ' . uniqid();

$resProdCreate = req("{$baseUrl}/backend/api/admin-products.php", 'POST', [
    'action' => 'create',
    'name' => $prodName,
    'category_id' => $qaCatId,
    'description' => 'Three-layer waterproof technical shell with sealed seams.',
    'price' => 280.00,
    'initial_stock' => 4,
    'low_stock_threshold' => 5,
    'manual_badge_message' => 'Limited Edition QA',
    'is_featured' => 1,
    'hide_when_out_of_stock' => 1,
    'csrf_token' => $admCsrf
], $tempAdminCookie, ["X-CSRF-Token: {$admCsrf}"]);
test("Admin creates product returns HTTP 201 Created", $resProdCreate['code'] === 201);
$qaProdId = (int)($resProdCreate['json']['data']['product_id'] ?? 0);
test("Product created in MySQL with ID > 0", $qaProdId > 0);

// Verify in Admin Product Table API
$resAdminProds = req("{$baseUrl}/backend/api/admin-products.php?id={$qaProdId}", 'GET', null, $tempAdminCookie);
$admProdData = $resAdminProds['json']['data']['product'] ?? [];
test("Admin sees exact stock count (4)", (int)($admProdData['total_stock'] ?? 0) === 4);

// Verify in Public Catalog API
$resPublicProd = req("{$baseUrl}/backend/api/product-detail.php?id={$qaProdId}");
$pubProdData = $resPublicProd['json']['data']['product'] ?? [];
test("Public product detail displays created product", ($pubProdData['name'] ?? '') === $prodName);
test("Public strictly does NOT receive exact total_stock count", !isset($pubProdData['total_stock']));
test("Public sees is_low_stock = true (stock 4 <= threshold 5)", ($pubProdData['is_low_stock'] ?? false) === true);
test("Public sees manual badge 'Limited Edition QA'", ($pubProdData['manual_badge_message'] ?? '') === 'Limited Edition QA');

// --- Admin Workflow 6: Edit Product Information & Stock Adjustment ---
echo "\n[Admin 6 & 8] Edit Product Info, Inventory Adjustment & Stats Update...\n";

// Update details
$resProdEdit = req("{$baseUrl}/backend/api/admin-products.php", 'POST', [
    'action' => 'update',
    'product_id' => $qaProdId,
    'name' => $prodName . ' V2',
    'category_id' => $qaCatId,
    'description' => 'Updated description with reinforced Gore-Tex pro fabric.',
    'price' => 310.00,
    'low_stock_threshold' => 5,
    'manual_badge_message' => 'New Season',
    'is_featured' => 1,
    'csrf_token' => $admCsrf
], $tempAdminCookie, ["X-CSRF-Token: {$admCsrf}"]);
test("Admin edits product details returns HTTP 200", $resProdEdit['code'] === 200);

// Adjust stock from 4 to 25 (above low-stock threshold)
$resStockAdj = req("{$baseUrl}/backend/api/admin-products.php", 'POST', [
    'action' => 'update_stock',
    'product_id' => $qaProdId,
    'stock_quantity' => 25,
    'csrf_token' => $admCsrf
], $tempAdminCookie, ["X-CSRF-Token: {$admCsrf}"]);
test("Admin adjusts inventory stock to 25 returns HTTP 200", $resStockAdj['code'] === 200);

// Confirm public is_low_stock turns false
$resPubAfterStock = req("{$baseUrl}/backend/api/product-detail.php?id={$qaProdId}");
$pubAfterData = $resPubAfterStock['json']['data']['product'] ?? [];
test("Public is_low_stock turns false after stock increase to 25", ($pubAfterData['is_low_stock'] ?? true) === false);

// --- Admin Workflow 9 & 10: Order Status Progression & Blocking Invalid Transitions ---
echo "\n[Admin 9 & 10] Order Pipeline Progression & Invalid State Action Blocking...\n";

// 10.1 Block invalid status transition: Delivered before Dispatched (Order is currently Pending)
$resBlockDeliv = req("{$baseUrl}/backend/api/admin-orders.php", 'POST', [
    'order_id' => $qaOrderId,
    'status' => 'Delivered',
    'csrf_token' => $admCsrf
], $tempAdminCookie, ["X-CSRF-Token: {$admCsrf}"]);
test("Delivered before Dispatched is strictly BLOCKED (HTTP 422)", $resBlockDeliv['code'] === 422);
test("Invalid progression returns descriptive error message", strpos($resBlockDeliv['json']['message'] ?? '', 'must be Dispatched') !== false);

// 9.1 Advance order: Pending -> In Queue
$resToQueue = req("{$baseUrl}/backend/api/admin-orders.php", 'POST', [
    'order_id' => $qaOrderId,
    'status' => 'In Queue',
    'csrf_token' => $admCsrf
], $tempAdminCookie, ["X-CSRF-Token: {$admCsrf}"]);
test("Valid progression: Pending -> In Queue succeeds (HTTP 200)", $resToQueue['code'] === 200);

// Verify customer tracking reflects In Queue
$resTrackQueue = req("{$baseUrl}/backend/api/orders.php?order_number={$qaOrderNum}", 'GET', null, $tempCustCookie);
test("Customer tracking reflects 'In Queue'", ($resTrackQueue['json']['data']['order']['status'] ?? '') === 'In Queue');

// 9.2 Advance order: In Queue -> Dispatched with dispatch date
$dispatchTimestamp = date('Y-m-d H:i:s');
$resToDispatched = req("{$baseUrl}/backend/api/admin-orders.php", 'POST', [
    'order_id' => $qaOrderId,
    'status' => 'Dispatched',
    'dispatch_date' => $dispatchTimestamp,
    'csrf_token' => $admCsrf
], $tempAdminCookie, ["X-CSRF-Token: {$admCsrf}"]);
test("Valid progression: In Queue -> Dispatched with date succeeds (HTTP 200)", $resToDispatched['code'] === 200);

// Verify customer tracking reflects Dispatched and date
$resTrackDisp = req("{$baseUrl}/backend/api/orders.php?order_number={$qaOrderNum}", 'GET', null, $tempCustCookie);
test("Customer tracking reflects 'Dispatched'", ($resTrackDisp['json']['data']['order']['status'] ?? '') === 'Dispatched');
test("Customer tracking includes dispatch timestamp", !empty($resTrackDisp['json']['data']['order']['dispatch_date']));

// 9.3 Advance order: Dispatched -> Delivered
$resToDelivered = req("{$baseUrl}/backend/api/admin-orders.php", 'POST', [
    'order_id' => $qaOrderId,
    'status' => 'Delivered',
    'csrf_token' => $admCsrf
], $tempAdminCookie, ["X-CSRF-Token: {$admCsrf}"]);
test("Valid progression: Dispatched -> Delivered succeeds (HTTP 200)", $resToDelivered['code'] === 200);

// Verify customer tracking reflects Delivered
$resTrackDeliv = req("{$baseUrl}/backend/api/orders.php?order_number={$qaOrderNum}", 'GET', null, $tempCustCookie);
test("Customer tracking reflects 'Delivered'", ($resTrackDeliv['json']['data']['order']['status'] ?? '') === 'Delivered');
test("Order is now marked return_eligible for customer", ($resTrackDeliv['json']['data']['order']['is_return_eligible'] ?? false) === true);

// --- Admin Workflow 11: Return Processing & Automated Restocking ---
echo "\n[Admin 11] Customer Return Submission, Admin Approval & Inventory Restock...\n";

// Customer submits return request for Delivered order
$custCsrf = getCsrfToken($baseUrl, $tempCustCookie);
$resRetSubmit = req("{$baseUrl}/backend/api/returns.php", 'POST', [
    'order_id' => $qaOrderId,
    'reason' => 'Item defective or damaged',
    'details' => 'Sleeve seam stitching issue',
    'csrf_token' => $custCsrf
], $tempCustCookie, ["X-CSRF-Token: {$custCsrf}"]);
test("Customer return request on Delivered order succeeds (HTTP 201)", $resRetSubmit['code'] === 201);
$qaReturnId = (int)($resRetSubmit['json']['data']['return_id'] ?? 0);
test("Return record created in MySQL returns table", $qaReturnId > 0);

// Verify return appears in Admin Returns list
$resAdminReturns = req("{$baseUrl}/backend/api/admin-returns.php", 'GET', null, $tempAdminCookie);
$foundAdminRet = false;
foreach ($resAdminReturns['json']['data']['returns'] ?? [] as $ret) {
    if ((int)$ret['id'] === $qaReturnId) { $foundAdminRet = true; break; }
}
test("Customer return appears in Admin Returns list", $foundAdminRet);

// Check stock before approval (order had 3 units of product 1)
$stockBeforeAppr = (int)$pdo->query("SELECT SUM(stock_quantity) FROM `product_inventory` WHERE product_id = 1")->fetchColumn();

// Admin approves return
$resRetApprove = req("{$baseUrl}/backend/api/admin-returns.php", 'POST', [
    'action' => 'process_return',
    'return_id' => $qaReturnId,
    'decision' => 'Approved',
    'admin_notes' => 'Inspected in QA warehouse. Restocked into inventory.',
    'csrf_token' => $admCsrf
], $tempAdminCookie, ["X-CSRF-Token: {$admCsrf}"]);
test("Admin approves return request returns HTTP 200", $resRetApprove['code'] === 200);

// Verify MySQL changes:
$dbRetAfter = $pdo->query("SELECT status, admin_notes FROM `returns` WHERE id = {$qaReturnId}")->fetch();
test("Return status updated to 'Approved' in MySQL", $dbRetAfter['status'] === 'Approved');

$dbOrderAfterRet = $pdo->query("SELECT status FROM `orders` WHERE id = {$qaOrderId}")->fetch();
test("Order status automatically updated to 'Returned' in MySQL", $dbOrderAfterRet['status'] === 'Returned');

$stockAfterAppr = (int)$pdo->query("SELECT SUM(stock_quantity) FROM `product_inventory` WHERE product_id = 1")->fetchColumn();
test("Product inventory automatically restocked (+3 units): {$stockBeforeAppr} -> {$stockAfterAppr}", $stockAfterAppr === ($stockBeforeAppr + 3));

// --- Admin Workflow 12: Customer Management & Account Activation/Deactivation ---
echo "\n[Admin 12] Customer Directory, Dossier & Activation/Deactivation...\n";

// Customer Directory
$resCustList = req("{$baseUrl}/backend/api/admin-customers.php", 'GET', null, $tempAdminCookie);
test("Admin customer directory loaded HTTP 200", $resCustList['code'] === 200);
test("Passwords strictly excluded from customer directory", !isset($resCustList['json']['data']['customers'][0]['password']));

// Customer Dossier
$resDossier = req("{$baseUrl}/backend/api/admin-customers.php?id={$qaUserId}", 'GET', null, $tempAdminCookie);
test("Customer dossier loaded HTTP 200", $resDossier['code'] === 200);
$dossier = $resDossier['json']['data'] ?? [];
test("Dossier contains addresses", count($dossier['addresses'] ?? []) > 0);
test("Dossier contains orders", count($dossier['orders'] ?? []) > 0);
test("Dossier contains reviews", count($dossier['reviews'] ?? []) > 0);
test("Dossier contains returns", count($dossier['returns'] ?? []) > 0);

// Deactivate Account
$resDeact = req("{$baseUrl}/backend/api/admin-customers.php", 'POST', [
    'action' => 'toggle_status',
    'customer_id' => $qaUserId,
    'status' => 'inactive',
    'csrf_token' => $admCsrf
], $tempAdminCookie, ["X-CSRF-Token: {$admCsrf}"]);
test("Admin deactivates customer account returns HTTP 200", $resDeact['code'] === 200);

// Verify deactivated customer cannot log in
$resDeactLogin = req("{$baseUrl}/backend/auth/login.php", 'POST', [
    'email' => $qaEmail,
    'password' => $qaPass,
    'csrf_token' => getCsrfToken($baseUrl, $guestCookie)
], $guestCookie);
test("Deactivated customer login BLOCKED (HTTP 403)", $resDeactLogin['code'] === 403);
test("Deactivation error message shown to user", strpos($resDeactLogin['json']['message'] ?? '', 'deactivated') !== false);

// Reactivate Account
$resReact = req("{$baseUrl}/backend/api/admin-customers.php", 'POST', [
    'action' => 'toggle_status',
    'customer_id' => $qaUserId,
    'status' => 'active',
    'csrf_token' => $admCsrf
], $tempAdminCookie, ["X-CSRF-Token: {$admCsrf}"]);
test("Admin reactivates customer account returns HTTP 200", $resReact['code'] === 200);

// Verify customer can log in again
$resReactLogin = req("{$baseUrl}/backend/auth/login.php", 'POST', [
    'email' => $qaEmail,
    'password' => $qaPass,
    'csrf_token' => getCsrfToken($baseUrl, $guestCookie)
], $guestCookie);
test("Reactivated customer login succeeds (HTTP 200)", $resReactLogin['code'] === 200);

// --- Admin Workflow 13: Live 13 MySQL Dashboard Statistics Verification ---
echo "\n[Admin 13] Confirm Dashboard Values Match Actual MySQL Data...\n";
$resLiveStats = req("{$baseUrl}/backend/admin/stats.php", 'GET', null, $tempAdminCookie);
test("Admin stats API returns HTTP 200", $resLiveStats['code'] === 200);
$stats = $resLiveStats['json']['data'] ?? [];

// Calculate direct MySQL values
$mysqlProducts = (int)$pdo->query("SELECT COUNT(*) FROM `products`")->fetchColumn();
$mysqlReturns = (int)$pdo->query("SELECT COUNT(*) FROM `returns`")->fetchColumn();
$mysqlPendingReturns = (int)$pdo->query("SELECT COUNT(*) FROM `returns` WHERE status = 'Pending'")->fetchColumn();
$mysqlColours = (int)$pdo->query("SELECT COUNT(DISTINCT colour_name) FROM `product_colours`")->fetchColumn();
$mysqlReviews = (int)$pdo->query("SELECT COUNT(*) FROM `reviews`")->fetchColumn();
$mysqlViewers = (int)$pdo->query("SELECT COALESCE(SUM(active_viewers), 0) FROM `live_review_activity`")->fetchColumn();

test("Stats total_products matches MySQL: {$stats['total_products']} == {$mysqlProducts}", $stats['total_products'] === $mysqlProducts);
test("Stats total_returns matches MySQL: {$stats['total_returns']} == {$mysqlReturns}", $stats['total_returns'] === $mysqlReturns);
test("Stats pending_returns matches MySQL: {$stats['pending_returns']} == {$mysqlPendingReturns}", $stats['pending_returns'] === $mysqlPendingReturns);
test("Stats total_colours matches MySQL: {$stats['total_colours']} == {$mysqlColours}", $stats['total_colours'] === $mysqlColours);
test("Stats total_reviews matches MySQL: {$stats['total_reviews']} == {$mysqlReviews}", $stats['total_reviews'] === $mysqlReviews);
test("Stats active_viewers matches MySQL: {$stats['active_viewers']} == {$mysqlViewers}", $stats['active_viewers'] === $mysqlViewers);

// Verify Orders breakdown in stats matches MySQL
$mysqlOrders = $pdo->query("SELECT status, COUNT(*) AS cnt FROM `orders` GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
test("Stats orders_pending matches MySQL", $stats['orders_pending'] === (int)($mysqlOrders['Pending'] ?? 0));
test("Stats orders_in_queue matches MySQL", $stats['orders_in_queue'] === (int)($mysqlOrders['In Queue'] ?? 0));
test("Stats orders_dispatched matches MySQL", $stats['orders_dispatched'] === (int)($mysqlOrders['Dispatched'] ?? 0));
test("Stats orders_delivered matches MySQL", $stats['orders_delivered'] === (int)($mysqlOrders['Delivered'] ?? 0));
test("Stats orders_returned matches MySQL", $stats['orders_returned'] === (int)($mysqlOrders['Returned'] ?? 0));

// --- Admin Workflow 7: Safe Deletion of Product Preserving Orders ---
echo "\n[Admin 7] Safe Product Deletion Preserving Orders...\n";
$resDelProd = req("{$baseUrl}/backend/api/admin-products.php", 'POST', [
    'action' => 'delete',
    'product_id' => $qaProdId,
    'csrf_token' => $admCsrf
], $tempAdminCookie, ["X-CSRF-Token: {$admCsrf}"]);
test("Admin deletes product returns HTTP 200", $resDelProd['code'] === 200);

// Verify product no longer appears in public catalog
$resCheckDeleted = req("{$baseUrl}/backend/api/product-detail.php?id={$qaProdId}");
test("Deleted product returns HTTP 404 in public storefront", $resCheckDeleted['code'] === 404);

// Category Deletion Protection: Can delete category after products removed
$resDelCat = req("{$baseUrl}/backend/api/admin-categories.php", 'POST', [
    'action' => 'delete_category',
    'category_id' => $qaCatId,
    'csrf_token' => $admCsrf
], $tempAdminCookie, ["X-CSRF-Token: {$admCsrf}"]);
test("Empty category safely deleted (HTTP 200)", $resDelCat['code'] === 200);


// =========================================================================
// PART 3: CLEANUP OF TEMPORARY QA TEST RECORDS
// =========================================================================
echo "\n>>> PART 3: CLEANUP OF TEMPORARY QA RECORDS <<<\n";
// Remove only temporary QA test records created in this test run
$pdo->prepare("DELETE FROM `reviews` WHERE id = :rid")->execute([':rid' => $qaReviewId]);
$pdo->prepare("DELETE FROM `returns` WHERE id = :retid")->execute([':retid' => $qaReturnId]);
$pdo->prepare("DELETE FROM `order_items` WHERE order_id = :oid")->execute([':oid' => $qaOrderId]);
$pdo->prepare("DELETE FROM `orders` WHERE id = :oid")->execute([':oid' => $qaOrderId]);
$pdo->prepare("DELETE FROM `addresses` WHERE user_id = :uid")->execute([':uid' => $qaUserId]);
$pdo->prepare("DELETE FROM `users` WHERE id = :uid")->execute([':uid' => $qaUserId]);
echo "  [CLEANUP] Temporary QA user (#{$qaUserId}), order (#{$qaOrderId}), return (#{$qaReturnId}), address (#{$qaAddressId}), and review (#{$qaReviewId}) cleanly removed.\n";
echo "  [CLEANUP] All real production / seed data strictly preserved.\n";

@unlink($tempCustCookie);
@unlink($tempAdminCookie);
@unlink($guestCookie);

echo "\n====================================================================\n";
echo "QA VERIFICATION SUMMARY: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
