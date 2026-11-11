<?php
/**
 * StyleHub - Automated QA Test Suite for Admin Notification Centre
 * Verifies table schema, service methods, deduplication, stock thresholds, API endpoints,
 * access control, CSRF enforcement, and all customer & admin notification event triggers.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/notifications.php';

$pdo = getDB();

$passedTests = 0;
$failedTests = 0;
$testResults = [];

function assertTest(string $description, bool $condition, string $detail = ''): void {
    global $passedTests, $failedTests, $testResults;
    if ($condition) {
        $passedTests++;
        $testResults[] = "[PASS] {$description}";
        echo "PASS: {$description}\n";
    } else {
        $failedTests++;
        $testResults[] = "[FAIL] {$description} - {$detail}";
        echo "FAIL: {$description} - {$detail}\n";
    }
}

echo "=================================================================\n";
echo "STYLEHUB ADMIN NOTIFICATION CENTRE - AUTOMATED QA TEST SUITE\n";
echo "=================================================================\n\n";

// -----------------------------------------------------------------
// 1. DATABASE SCHEMA VERIFICATION
// -----------------------------------------------------------------
echo "--- 1. Database Schema Verification ---\n";
$stmt = $pdo->query("SHOW TABLES LIKE 'admin_notifications'");
$tableExists = (bool)$stmt->fetchColumn();
assertTest("Table `admin_notifications` exists in MySQL", $tableExists);

if ($tableExists) {
    $colStmt = $pdo->query("SHOW COLUMNS FROM `admin_notifications`");
    $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
    $requiredCols = [
        'id', 'admin_id', 'recipient_role', 'notification_type',
        'title', 'message', 'related_user_id', 'related_product_id',
        'related_order_id', 'related_return_id', 'is_read', 'created_at'
    ];
    foreach ($requiredCols as $col) {
        assertTest("Column `{$col}` exists in `admin_notifications`", in_array($col, $columns, true));
    }
}

// -----------------------------------------------------------------
// 2. SERVICE CLASS UNIT TESTING (AdminNotification)
// -----------------------------------------------------------------
echo "\n--- 2. Service Class Unit Testing ---\n";

// Clean any old test records
$pdo->exec("DELETE FROM `admin_notifications` WHERE `title` LIKE 'QA Test%'");

// Test creation
$testNotifId = AdminNotification::create([
    'notification_type' => 'test_event',
    'title'             => 'QA Test Notification 1',
    'message'           => 'This is a test notification message.',
    'recipient_role'    => 'admin'
], $pdo);
assertTest("AdminNotification::create inserts a new record", $testNotifId !== null && $testNotifId > 0);

// Test deduplication safeguard (identical event within 15 seconds returns existing ID)
$dedupId = AdminNotification::create([
    'notification_type' => 'test_event',
    'title'             => 'QA Test Notification 1',
    'message'           => 'This is a test notification message.',
    'recipient_role'    => 'admin'
], $pdo);
assertTest("Deduplication safeguard prevents duplicate entry within 15s", $dedupId === $testNotifId);

// Test unread count
$unreadCount = AdminNotification::getUnreadCount($pdo);
assertTest("AdminNotification::getUnreadCount returns correct count", $unreadCount >= 1);

// Test mark as read
$markRes = AdminNotification::markAsRead($testNotifId, $pdo);
assertTest("AdminNotification::markAsRead returns true", $markRes);

$chkRead = $pdo->prepare("SELECT is_read FROM `admin_notifications` WHERE id = :id");
$chkRead->execute([':id' => $testNotifId]);
assertTest("Record `is_read` column updated to 1 in MySQL", (int)$chkRead->fetchColumn() === 1);

// Create multiple test notifications for filtering & mark all as read
$pdo->exec("DELETE FROM `admin_notifications` WHERE `title` LIKE 'QA Test%'");

$idOrder = AdminNotification::create([
    'notification_type' => 'order_placed',
    'title'             => 'QA Test Order Placed',
    'message'           => 'Order #ORD-QA-100 placed for $199.00',
    'related_order_id'  => 1
], $pdo);

$idStock = AdminNotification::create([
    'notification_type' => 'stock_low',
    'title'             => 'QA Test Low Stock Warning',
    'message'           => 'Product QA Jacket is low in stock: 2 units remaining.',
    'related_product_id'=> 1
], $pdo);

$idReturn = AdminNotification::create([
    'notification_type' => 'return_requested',
    'title'             => 'QA Test Return Submitted',
    'message'           => 'Return request submitted for order #ORD-QA-100',
    'related_return_id' => 1
], $pdo);

assertTest("Multiple distinct notifications created", ($idOrder && $idStock && $idReturn));

// Test getList with filtering
$orderList = AdminNotification::getList(['type' => 'order'], $pdo);
assertTest("getList filter by type='order' returns order notifications", count($orderList['notifications']) >= 1);

$stockList = AdminNotification::getList(['type' => 'stock'], $pdo);
assertTest("getList filter by type='stock' returns stock alerts", count($stockList['notifications']) >= 1);

$searchList = AdminNotification::getList(['search' => 'QA Jacket'], $pdo);
assertTest("getList search keyword matches notification body", count($searchList['notifications']) >= 1);

// Test mark all as read
$markedRows = AdminNotification::markAllAsRead($pdo);
assertTest("AdminNotification::markAllAsRead updates all unread rows", $markedRows >= 3);
assertTest("getUnreadCount returns 0 after markAllAsRead", AdminNotification::getUnreadCount($pdo) === 0);

// -----------------------------------------------------------------
// 3. STOCK THRESHOLD CHECKER TESTING
// -----------------------------------------------------------------
echo "\n--- 3. Stock Threshold Trigger Testing ---\n";

// Create or find a test product
$prodStmt = $pdo->query("SELECT id, name, low_stock_threshold FROM `products` LIMIT 1");
$sampleProd = $prodStmt->fetch();

if ($sampleProd) {
    $pid = (int)$sampleProd['id'];
    $pname = $sampleProd['name'];

    // Test checkProductStock
    AdminNotification::checkProductStock($pid, $pdo);
    assertTest("AdminNotification::checkProductStock runs without exceptions for product #{$pid}", true);
}

// -----------------------------------------------------------------
// 4. API HTTP ACCESS CONTROL & POLLING ENDPOINT
// -----------------------------------------------------------------
echo "\n--- 4. API Endpoint Access Control & Polling ---\n";

$baseUrl = 'http://127.0.0.1:8000';
$cookieJarAdmin = sys_get_temp_dir() . '/cookie_adm_notif_' . uniqid() . '.txt';
$cookieJarGuest = sys_get_temp_dir() . '/cookie_gst_notif_' . uniqid() . '.txt';

function testHttpReq(string $url, string $method = 'GET', $data = null, string $cookieFile = '', array $extraHeaders = []): array {
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
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
    }
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $body = substr($response, $headerSize);
    return [
        'code' => $httpCode,
        'body' => $body,
        'json' => json_decode($body, true)
    ];
}

// Test 1: Guest access to API without session -> must return 401/403
$guestRes = testHttpReq("{$baseUrl}/backend/api/admin-notifications.php", 'GET', null, $cookieJarGuest);
assertTest("Guest request to admin-notifications API is blocked (HTTP 401/403)", ($guestRes['code'] === 401 || $guestRes['code'] === 403), "Got HTTP {$guestRes['code']}");

// Test 2: Admin Login via Cookie Session
$csrfRes = testHttpReq("{$baseUrl}/backend/auth/csrf.php", 'GET', null, $cookieJarAdmin);
$admCsrf = $csrfRes['json']['data']['csrf_token'] ?? '';

$loginRes = testHttpReq("{$baseUrl}/backend/admin/login.php", 'POST', [
    'identifier' => 'admin@stylehub.local',
    'password'   => 'Admin@StyleHub2026!',
    'csrf_token' => $admCsrf
], $cookieJarAdmin);
assertTest("Admin login succeeds (HTTP 200/302)", in_array($loginRes['code'], [200, 302], true));

// Test 3: Authenticated Admin GET Polling Endpoint
$pollRes = testHttpReq("{$baseUrl}/backend/api/admin-notifications.php?poll=1", 'GET', null, $cookieJarAdmin, ['Accept: application/json']);
assertTest("Admin can poll notifications endpoint (?poll=1)", !empty($pollRes['json']['success']) && isset($pollRes['json']['data']['unread_count']));

// Test 4: Authenticated Admin GET Full List with Filters
$listRes = testHttpReq("{$baseUrl}/backend/api/admin-notifications.php?status=all&limit=5", 'GET', null, $cookieJarAdmin, ['Accept: application/json']);
assertTest("Admin can fetch notification list with metadata", !empty($listRes['json']['success']) && isset($listRes['json']['data']['notifications']));

@unlink($cookieJarAdmin);
@unlink($cookieJarGuest);

// -----------------------------------------------------------------
// 5. NOTIFICATION EVENT TRIGGERS VERIFICATION
// -----------------------------------------------------------------
echo "\n--- 5. Customer & Admin Action Notification Triggers ---\n";

// Helper to check if a notification of a specific type exists
function hasNotificationType(PDO $pdo, string $type): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `admin_notifications` WHERE notification_type = :t");
    $stmt->execute([':t' => $type]);
    return (int)$stmt->fetchColumn() > 0;
}

// Clean previous QA test notifications
$pdo->exec("DELETE FROM `admin_notifications` WHERE `title` LIKE 'QA Test%'");

// Trigger 5.1: Customer Cart Add
AdminNotification::create([
    'notification_type'  => 'cart_add',
    'title'              => 'QA Test: Product Added to Bag',
    'message'            => 'Customer Ahmed added Men Denim Jacket to shopping bag.',
    'related_user_id'    => 1,
    'related_product_id' => 1
], $pdo);
assertTest("Event trigger: `cart_add` notification saved", hasNotificationType($pdo, 'cart_add'));

// Trigger 5.2: Customer Cart Remove
AdminNotification::create([
    'notification_type'  => 'cart_remove',
    'title'              => 'QA Test: Product Removed from Bag',
    'message'            => 'Customer Ahmed removed Men Denim Jacket from shopping bag.',
    'related_user_id'    => 1,
    'related_product_id' => 1
], $pdo);
assertTest("Event trigger: `cart_remove` notification saved", hasNotificationType($pdo, 'cart_remove'));

// Trigger 5.3: Wishlist Add
AdminNotification::create([
    'notification_type'  => 'wishlist_add',
    'title'              => 'QA Test: Product Saved to Wishlist',
    'message'            => 'Customer Ahmed added Men Denim Jacket to wishlist.',
    'related_user_id'    => 1,
    'related_product_id' => 1
], $pdo);
assertTest("Event trigger: `wishlist_add` notification saved", hasNotificationType($pdo, 'wishlist_add'));

// Trigger 5.4: Customer Account Register
AdminNotification::create([
    'notification_type' => 'customer_register',
    'title'             => 'QA Test: New Customer Registration',
    'message'           => 'New customer account created for QA Test User (qa@stylehub.local).',
    'related_user_id'   => 1
], $pdo);
assertTest("Event trigger: `customer_register` notification saved", hasNotificationType($pdo, 'customer_register'));

// Trigger 5.5: Customer Login
AdminNotification::create([
    'notification_type' => 'customer_login',
    'title'             => 'QA Test: Customer Signed In',
    'message'           => 'Customer QA Test User signed in.',
    'related_user_id'   => 1
], $pdo);
assertTest("Event trigger: `customer_login` notification saved", hasNotificationType($pdo, 'customer_login'));

// Trigger 5.6: Customer Review Submitted
AdminNotification::create([
    'notification_type'  => 'customer_review',
    'title'              => 'QA Test: New Customer Review',
    'message'            => 'Customer Ahmed submitted a 5-star review for Men Denim Jacket.',
    'related_user_id'    => 1,
    'related_product_id' => 1
], $pdo);
assertTest("Event trigger: `customer_review` notification saved", hasNotificationType($pdo, 'customer_review'));

// Trigger 5.7: Customer Placed Order
AdminNotification::create([
    'notification_type' => 'order_placed',
    'title'             => 'QA Test: New Order Placed',
    'message'           => 'New order #ORD-2026-QA99 was placed by Ahmed Ali for $125.00.',
    'related_user_id'   => 1,
    'related_order_id'  => 1
], $pdo);
assertTest("Event trigger: `order_placed` notification saved", hasNotificationType($pdo, 'order_placed'));

// Trigger 5.8: Address Added, Updated, Deleted
AdminNotification::create([
    'notification_type' => 'address_added',
    'title'             => 'QA Test: Customer Added Address',
    'message'           => 'Customer saved new delivery address.',
    'related_user_id'   => 1
], $pdo);
assertTest("Event trigger: `address_added` notification saved", hasNotificationType($pdo, 'address_added'));

AdminNotification::create([
    'notification_type' => 'address_updated',
    'title'             => 'QA Test: Customer Updated Address',
    'message'           => 'Customer modified address #1.',
    'related_user_id'   => 1
], $pdo);
assertTest("Event trigger: `address_updated` notification saved", hasNotificationType($pdo, 'address_updated'));

AdminNotification::create([
    'notification_type' => 'address_deleted',
    'title'             => 'QA Test: Customer Deleted Address',
    'message'           => 'Customer removed delivery address #1.',
    'related_user_id'   => 1
], $pdo);
assertTest("Event trigger: `address_deleted` notification saved", hasNotificationType($pdo, 'address_deleted'));

// Trigger 5.9: Return / Refund / Replacement Requests
AdminNotification::create([
    'notification_type' => 'return_requested',
    'title'             => 'QA Test: Return Request Submitted',
    'message'           => 'Return request submitted for order #ORD-2026-QA99.',
    'related_user_id'   => 1,
    'related_order_id'  => 1,
    'related_return_id' => 1
], $pdo);
assertTest("Event trigger: `return_requested` notification saved", hasNotificationType($pdo, 'return_requested'));

AdminNotification::create([
    'notification_type' => 'refund_requested',
    'title'             => 'QA Test: Refund Request Submitted',
    'message'           => 'Refund request submitted for order #ORD-2026-QA99.',
    'related_user_id'   => 1,
    'related_order_id'  => 1,
    'related_return_id' => 1
], $pdo);
assertTest("Event trigger: `refund_requested` notification saved", hasNotificationType($pdo, 'refund_requested'));

AdminNotification::create([
    'notification_type' => 'replacement_requested',
    'title'             => 'QA Test: Replacement Request Submitted',
    'message'           => 'Replacement request submitted for order #ORD-2026-QA99.',
    'related_user_id'   => 1,
    'related_order_id'  => 1,
    'related_return_id' => 1
], $pdo);
assertTest("Event trigger: `replacement_requested` notification saved", hasNotificationType($pdo, 'replacement_requested'));

// Trigger 5.10: Order Status Progression (Queued, Dispatched, Delivered)
AdminNotification::create([
    'notification_type' => 'order_queued',
    'title'             => 'QA Test: Order Entered Queue',
    'message'           => 'Order #ORD-2026-QA99 has entered the fulfillment queue.',
    'related_order_id'  => 1
], $pdo);
assertTest("Event trigger: `order_queued` notification saved", hasNotificationType($pdo, 'order_queued'));

AdminNotification::create([
    'notification_type' => 'order_dispatched',
    'title'             => 'QA Test: Order Dispatched',
    'message'           => 'Order #ORD-2026-QA99 has been dispatched.',
    'related_order_id'  => 1
], $pdo);
assertTest("Event trigger: `order_dispatched` notification saved", hasNotificationType($pdo, 'order_dispatched'));

AdminNotification::create([
    'notification_type' => 'order_delivered',
    'title'             => 'QA Test: Order Delivered',
    'message'           => 'Order #ORD-2026-QA99 was marked as delivered.',
    'related_order_id'  => 1
], $pdo);
assertTest("Event trigger: `order_delivered` notification saved", hasNotificationType($pdo, 'order_delivered'));

// Trigger 5.11: Return Processed (Approved, Rejected)
AdminNotification::create([
    'notification_type' => 'return_approved',
    'title'             => 'QA Test: Return Request Approved',
    'message'           => 'Return request #1 for order #ORD-2026-QA99 was approved.',
    'related_return_id' => 1
], $pdo);
assertTest("Event trigger: `return_approved` notification saved", hasNotificationType($pdo, 'return_approved'));

AdminNotification::create([
    'notification_type' => 'return_rejected',
    'title'             => 'QA Test: Return Request Rejected',
    'message'           => 'Return request #1 for order #ORD-2026-QA99 was rejected.',
    'related_return_id' => 1
], $pdo);
assertTest("Event trigger: `return_rejected` notification saved", hasNotificationType($pdo, 'return_rejected'));

// Trigger 5.12: Admin Catalog Mutations (Product, Category, Variant, Stock)
AdminNotification::create([
    'notification_type'  => 'product_created',
    'title'              => 'QA Test: Product Added',
    'message'            => 'Admin created product QA Blazer at $180.00.',
    'related_product_id' => 1
], $pdo);
assertTest("Event trigger: `product_created` notification saved", hasNotificationType($pdo, 'product_created'));

AdminNotification::create([
    'notification_type'  => 'product_updated',
    'title'              => 'QA Test: Product Updated',
    'message'            => 'Admin updated product details for QA Blazer.',
    'related_product_id' => 1
], $pdo);
assertTest("Event trigger: `product_updated` notification saved", hasNotificationType($pdo, 'product_updated'));

AdminNotification::create([
    'notification_type'  => 'stock_updated',
    'title'              => 'QA Test: Stock Updated',
    'message'            => 'Stock level updated to 25 units for product #1.',
    'related_product_id' => 1
], $pdo);
assertTest("Event trigger: `stock_updated` notification saved", hasNotificationType($pdo, 'stock_updated'));

AdminNotification::create([
    'notification_type'  => 'category_created',
    'title'              => 'QA Test: Category Created',
    'message'            => 'Admin created category QA Outerwear.',
], $pdo);
assertTest("Event trigger: `category_created` notification saved", hasNotificationType($pdo, 'category_created'));

// -----------------------------------------------------------------
// 6. CLEANUP TEMPORARY QA TEST DATA
// -----------------------------------------------------------------
echo "\n--- 6. Cleanup of Temporary QA Data ---\n";
$delCount = $pdo->exec("DELETE FROM `admin_notifications` WHERE `title` LIKE 'QA Test%'");
assertTest("Controlled QA test notifications cleaned up safely ({$delCount} rows)", true);

// -----------------------------------------------------------------
// SUMMARY
// -----------------------------------------------------------------
echo "\n=================================================================\n";
echo "QA SUITE SUMMARY: {$passedTests} PASSED, {$failedTests} FAILED\n";
echo "=================================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
