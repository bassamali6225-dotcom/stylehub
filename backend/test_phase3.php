<?php
/**
 * StyleHub - Phase 3 Automated Verification Test Suite
 * Tests: Cart, Wishlist, Address Management, Checkout, Order History,
 * Order Tracking Timeline, Customer Returns, Stock Privacy, and Zero Inline CSS.
 */

declare(strict_types=1);

$baseUrl = 'http://127.0.0.1:8000';
$passed = 0;
$failed = 0;

function runTest(string $title, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$title}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$title}" . ($details ? " - {$details}" : "") . "\n";
    }
}

function httpReq(string $method, string $url, $data = null, array $headers = [], ?string &$cookieJar = null): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);

    $formattedHeaders = [];
    foreach ($headers as $k => $v) {
        $formattedHeaders[] = "{$k}: {$v}";
    }

    if ($cookieJar !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookieJar);
    }

    if ($data !== null) {
        if (is_array($data)) {
            $formattedHeaders[] = 'Content-Type: application/json';
            $payload = json_encode($data);
        } else {
            $payload = $data;
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);

    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerStr = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    curl_close($ch);

    // Extract cookies
    preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $headerStr, $matches);
    if (!empty($matches[1])) {
        $cookieJar = implode('; ', $matches[1]);
    }

    $json = json_decode($body, true);
    return [
        'status'  => $status,
        'headers' => $headerStr,
        'body'    => $body,
        'json'    => $json
    ];
}

echo "====================================================================\n";
echo "       STYLEHUB AUTOMATED VERIFICATION: PHASE 3 COMMERCE SUITE      \n";
echo "====================================================================\n\n";

$cookieJar = '';

// Step 1: CSRF & Customer Authentication
echo "--- STEP 1: AUTHENTICATION & CSRF FOUNDATION ---\n";
$csrfRes = httpReq('GET', "{$baseUrl}/backend/auth/csrf.php", null, [], $cookieJar);
$csrfToken = $csrfRes['json']['data']['csrf_token'] ?? '';
runTest('CSRF Token Retrieved for Phase 3', !empty($csrfToken) && strlen($csrfToken) === 64);

$loginRes = httpReq('POST', "{$baseUrl}/backend/auth/login.php", [
    'email' => 'customer@stylehub.local',
    'password' => 'Customer@StyleHub2026!',
    'csrf_token' => $csrfToken
], ['X-CSRF-Token' => $csrfToken], $cookieJar);
runTest('Customer Login Succeeded', $loginRes['status'] === 200 && ($loginRes['json']['success'] ?? false));

// Acquire newly generated CSRF token for the authenticated customer session
$authCsrfRes = httpReq('GET', "{$baseUrl}/backend/auth/csrf.php", null, [], $cookieJar);
$csrfToken = $authCsrfRes['json']['data']['csrf_token'] ?? '';
runTest('Authenticated Customer Session CSRF Token Acquired', !empty($csrfToken) && strlen($csrfToken) === 64);

// Step 2: Shopping Bag / Cart API
echo "\n--- STEP 2: SHOPPING BAG / CART API ---\n";
// Clear any old cart items first
$clearRes = httpReq('POST', "{$baseUrl}/backend/api/cart.php", [
    'action' => 'clear',
    'csrf_token' => $csrfToken
], ['X-CSRF-Token' => $csrfToken], $cookieJar);
runTest('Cart Clear Endpoint Works', $clearRes['status'] === 200);

// Verify empty cart
$cartEmpty = httpReq('GET', "{$baseUrl}/backend/api/cart.php", null, [], $cookieJar);
runTest('Empty Cart Returns 0 Items', ($cartEmpty['json']['data']['item_count'] ?? -1) === 0);
runTest('Empty Cart Subtotal is $0.00', (float)($cartEmpty['json']['data']['subtotal'] ?? -1.0) === 0.0);

// Test CSRF protection on Cart Add
$badAdd = httpReq('POST', "{$baseUrl}/backend/api/cart.php", [
    'action' => 'add',
    'product_id' => 1,
    'quantity' => 1
], [], $cookieJar);
runTest('Cart Add Without CSRF Blocked (403 Forbidden)', $badAdd['status'] === 403);

// Add product #1 (Tailored Wool Trousers, price $145.00)
$addRes1 = httpReq('POST', "{$baseUrl}/backend/api/cart.php", [
    'action' => 'add',
    'product_id' => 1,
    'quantity' => 1,
    'csrf_token' => $csrfToken
], ['X-CSRF-Token' => $csrfToken], $cookieJar);
runTest('Add Product #1 to Cart (HTTP 200)', $addRes1['status'] === 200);

// Check cart subtotal & standard shipping (< $150 threshold)
$cartRes1 = httpReq('GET', "{$baseUrl}/backend/api/cart.php", null, [], $cookieJar);
$cartData1 = $cartRes1['json']['data'] ?? [];
runTest('Cart Contains 1 Item', ($cartData1['item_count'] ?? 0) === 1);
runTest('Standard $15.00 Shipping Applied Under $150', (float)($cartData1['shipping'] ?? 0.0) === 15.0);
runTest('Cart Grand Total Includes Standard Shipping ($160.00)', (float)($cartData1['grand_total'] ?? 0.0) === 160.0);

$cartItemId = $cartData1['items'][0]['item_id'] ?? 0;

// Update quantity to 2 ($145 * 2 = $290, over $150 threshold -> free shipping)
$updateRes = httpReq('POST', "{$baseUrl}/backend/api/cart.php", [
    'action' => 'update',
    'item_id' => $cartItemId,
    'quantity' => 2,
    'csrf_token' => $csrfToken
], ['X-CSRF-Token' => $csrfToken], $cookieJar);
runTest('Cart Item Quantity Updated to 2', $updateRes['status'] === 200);

$cartRes2 = httpReq('GET', "{$baseUrl}/backend/api/cart.php", null, [], $cookieJar);
$cartData2 = $cartRes2['json']['data'] ?? [];
runTest('Cart Item Count Reflects Updated Quantity (2)', ($cartData2['item_count'] ?? 0) === 2);
runTest('Cart Subtotal Recalculated ($290.00)', (float)($cartData2['subtotal'] ?? 0.0) === 290.0);
runTest('Complimentary Express Shipping Granted Over $150', (float)($cartData2['shipping'] ?? -1.0) === 0.0 && ($cartData2['free_shipping'] ?? false) === true);
runTest('Grand Total Equals Subtotal When Shipping Free ($290.00)', (float)($cartData2['grand_total'] ?? 0.0) === 290.0);

// Step 3: Wishlist / Favourites API
echo "\n--- STEP 3: WISHLIST & STOCK PRIVACY ---\n";
// Ensure product #2 is added into Wishlist
$wlAddRes = httpReq('POST', "{$baseUrl}/backend/api/wishlist.php", [
    'action' => 'add',
    'product_id' => 2,
    'csrf_token' => $csrfToken
], ['X-CSRF-Token' => $csrfToken], $cookieJar);
runTest('Save Product #2 to Wishlist', $wlAddRes['status'] === 200 && ($wlAddRes['json']['data']['in_wishlist'] ?? false) === true);

// Retrieve Wishlist and check stock privacy
$wlGetRes = httpReq('GET', "{$baseUrl}/backend/api/wishlist.php", null, [], $cookieJar);
$wlItems = $wlGetRes['json']['data']['items'] ?? [];
runTest('Wishlist Contains Saved Product', count($wlItems) >= 1);
$foundWlProd = false;
$exposedStock = false;
foreach ($wlItems as $wItem) {
    if ($wItem['product_id'] === 2) {
        $foundWlProd = true;
        // Verify stock privacy: exact quantity must NOT be present
        if (isset($wItem['stock_quantity']) || isset($wItem['total_stock'])) {
            $exposedStock = true;
        }
    }
}
runTest('Saved Product Identified in Wishlist', $foundWlProd);
runTest('Exact Stock Quantity NEVER Exposed in Wishlist (Stock Privacy)', !$exposedStock);

// Toggle Product #2 off to verify removal toggle
$wlToggleOff = httpReq('POST', "{$baseUrl}/backend/api/wishlist.php", [
    'action' => 'toggle',
    'product_id' => 2,
    'csrf_token' => $csrfToken
], ['X-CSRF-Token' => $csrfToken], $cookieJar);
runTest('Toggle Removes Product from Wishlist', $wlToggleOff['status'] === 200 && ($wlToggleOff['json']['data']['in_wishlist'] ?? true) === false);

// Step 4: Customer Address Management
echo "\n--- STEP 4: CUSTOMER ADDRESS MANAGEMENT ---\n";
// Add Delivery Address
$addAddrRes = httpReq('POST', "{$baseUrl}/backend/api/addresses.php", [
    'action' => 'add',
    'full_name' => 'Jane Style Enthusiast',
    'phone' => '+1 (555) 345-6789',
    'address_line1' => '742 Evergreen Avenue',
    'address_line2' => 'Penthouse 12B',
    'city' => 'Beverly Hills',
    'state' => 'CA',
    'postal_code' => '90210',
    'country' => 'United States',
    'is_default' => 1,
    'csrf_token' => $csrfToken
], ['X-CSRF-Token' => $csrfToken], $cookieJar);
runTest('Add New Customer Address (HTTP 201)', $addAddrRes['status'] === 201);
$createdAddrId = $addAddrRes['json']['data']['address_id'] ?? 0;

// Verify Address in GET list
$getAddrRes = httpReq('GET', "{$baseUrl}/backend/api/addresses.php", null, [], $cookieJar);
$addresses = $getAddrRes['json']['data']['addresses'] ?? [];
$foundAddr = false;
foreach ($addresses as $a) {
    if ($a['id'] === $createdAddrId) {
        $foundAddr = true;
        runTest('Saved Address Field Verification', $a['city'] === 'Beverly Hills' && $a['postal_code'] === '90210');
        runTest('Default Address Flag Set', (int)$a['is_default'] === 1);
    }
}
runTest('Address Retrieved in Address Directory', $foundAddr);

// Edit Address
$editAddrRes = httpReq('POST', "{$baseUrl}/backend/api/addresses.php", [
    'action' => 'edit',
    'address_id' => $createdAddrId,
    'full_name' => 'Jane Style Enthusiast',
    'phone' => '+1 (555) 345-6789',
    'address_line1' => '742 Evergreen Avenue West',
    'address_line2' => 'Penthouse 12B',
    'city' => 'Beverly Hills',
    'state' => 'CA',
    'postal_code' => '90210',
    'country' => 'United States',
    'is_default' => 1,
    'csrf_token' => $csrfToken
], ['X-CSRF-Token' => $csrfToken], $cookieJar);
runTest('Customer Address Edit Succeeded', $editAddrRes['status'] === 200);

// Step 5: Checkout & MySQL Order Creation
echo "\n--- STEP 5: CHECKOUT & MYSQL ORDER CREATION ---\n";
// Attempt checkout without CSRF token
$badCheckout = httpReq('POST', "{$baseUrl}/backend/api/checkout.php", [
    'address_id' => $createdAddrId
], [], $cookieJar);
runTest('Checkout Without CSRF Blocked (403 Forbidden)', $badCheckout['status'] === 403);

// Perform valid checkout
$checkoutRes = httpReq('POST', "{$baseUrl}/backend/api/checkout.php", [
    'address_id' => $createdAddrId,
    'csrf_token' => $csrfToken
], ['X-CSRF-Token' => $csrfToken], $cookieJar);
runTest('Checkout Placement Succeeded (HTTP 201)', $checkoutRes['status'] === 201);

$orderData = $checkoutRes['json']['data'] ?? [];
$orderId = $orderData['order_id'] ?? 0;
$orderNumber = $orderData['order_number'] ?? '';
runTest('Unique Order Reference Generated (e.g. ORD-2026-...)', str_starts_with($orderNumber, 'ORD-2026-'));
runTest('Payment Method Labeled Cash on Delivery (Demo Checkout)', str_contains($orderData['payment_method'] ?? '', 'Cash on Delivery (Demo Checkout)'));

// Verify Cart was Cleared After Checkout
$cartAfter = httpReq('GET', "{$baseUrl}/backend/api/cart.php", null, [], $cookieJar);
runTest('Cart Cleared Automatically After Successful Checkout', ($cartAfter['json']['data']['item_count'] ?? -1) === 0);

// Step 6: Customer Order History
echo "\n--- STEP 6: CUSTOMER ORDER HISTORY ---\n";
$historyRes = httpReq('GET', "{$baseUrl}/backend/api/orders.php", null, [], $cookieJar);
$historyOrders = $historyRes['json']['data']['orders'] ?? [];
runTest('Customer Order History Returns Placed Orders', count($historyOrders) >= 1);
$foundOrderInHistory = false;
foreach ($historyOrders as $hOrd) {
    if ($hOrd['order_number'] === $orderNumber) {
        $foundOrderInHistory = true;
        runTest('Order Found in Customer History with Correct Total', (float)$hOrd['total_amount'] === (float)$orderData['grand_total']);
        runTest('Initial Order Status is Pending', $hOrd['status'] === 'Pending');
    }
}
runTest('Order Located in Customer History List', $foundOrderInHistory);

// Step 7: Order Tracking Timeline
echo "\n--- STEP 7: ORDER TRACKING TIMELINE ---\n";
$trackRes = httpReq('GET', "{$baseUrl}/backend/api/orders.php?order_number={$orderNumber}", null, [], $cookieJar);
$trackOrder = $trackRes['json']['data']['order'] ?? [];
runTest('Tracking Reference Found by Order Number', ($trackOrder['order_number'] ?? '') === $orderNumber);
runTest('Tracking Information Includes Shipping Address', !empty($trackOrder['shipping_address']['city']));
runTest('Tracking Information Includes Purchased Items Breakdown', count($trackOrder['items'] ?? []) >= 1);

$timeline = $trackOrder['timeline'] ?? [];
runTest('Fulfillment Timeline Defined with 4 Progressive Steps', count($timeline) === 4);
$expectedStatuses = ['Pending', 'In Queue', 'Dispatched', 'Delivered'];
$timelineStatuses = array_column($timeline, 'status');
runTest('Timeline Contains: Pending, In Queue, Dispatched, Delivered', $timelineStatuses === $expectedStatuses);

// Step 8: Return Request Flow
echo "\n--- STEP 8: CUSTOMER RETURN FLOW ---\n";
// Attempt return on a Pending order (must fail because not Delivered)
$invalidReturn = httpReq('POST', "{$baseUrl}/backend/api/returns.php", [
    'order_id' => $orderId,
    'reason' => 'Incorrect Size / Fit',
    'csrf_token' => $csrfToken
], ['X-CSRF-Token' => $csrfToken], $cookieJar);
runTest('Return Blocked on Non-Delivered Order (HTTP 400)', $invalidReturn['status'] === 400);

// Directly update status to Delivered in DB to simulate courier delivery
require_once __DIR__ . '/config/database.php';
$pdo = getDB();
$pdo->prepare("UPDATE `orders` SET status = 'Delivered' WHERE id = :id")->execute([':id' => $orderId]);

// Verify order is now return-eligible in tracking API
$trackEligible = httpReq('GET', "{$baseUrl}/backend/api/orders.php?order_number={$orderNumber}", null, [], $cookieJar);
runTest('Delivered Order is Return Eligible', ($trackEligible['json']['data']['order']['is_return_eligible'] ?? false) === true);

// Submit Return Request with CSRF
$returnRes = httpReq('POST', "{$baseUrl}/backend/api/returns.php", [
    'order_id' => $orderId,
    'reason' => 'Damaged or Defective Item',
    'details' => 'Fabric hem slightly unstitched on left seam.',
    'csrf_token' => $csrfToken
], ['X-CSRF-Token' => $csrfToken], $cookieJar);
runTest('Customer Return Request Submitted (HTTP 201)', $returnRes['status'] === 201);

// Verify Duplicate Return is Rejected (409 Conflict)
$dupReturn = httpReq('POST', "{$baseUrl}/backend/api/returns.php", [
    'order_id' => $orderId,
    'reason' => 'Changed Mind',
    'csrf_token' => $csrfToken
], ['X-CSRF-Token' => $csrfToken], $cookieJar);
runTest('Duplicate Return Request Rejected (HTTP 409)', $dupReturn['status'] === 409);

// Step 9: Zero Inline CSS Verification
echo "\n--- STEP 9: ZERO INLINE CSS VERIFICATION ---\n";
$phase3Files = [
    'frontend/cart.html',
    'frontend/wishlist.html',
    'frontend/checkout.html',
    'frontend/addresses.html',
    'frontend/order-history.html',
    'frontend/order-tracking.html',
    'frontend/js/main.js'
];
$inlineCssFound = 0;
foreach ($phase3Files as $file) {
    $content = file_get_contents(__DIR__ . '/../' . $file);
    if (preg_match('/<[^>]+style\s*=/i', $content)) {
        $inlineCssFound++;
        echo "  [FAIL] Inline CSS detected in {$file}\n";
    }
}
runTest('ZERO Inline CSS in Phase 3 HTML and JS Files', $inlineCssFound === 0);

echo "\n====================================================================\n";
echo "PHASE 3 TEST RESULTS: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n";

exit($failed === 0 ? 0 : 1);
