<?php
/**
 * StyleHub - Phase 2 Automated Test & Verification Suite
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
echo "   STYLEHUB PHASE 2 AUTOMATED INTEGRATION TEST SUITE     \n";
echo "========================================================\n\n";

$cookieJarCustomer = sys_get_temp_dir() . '/cookie_cust_p2_' . uniqid() . '.txt';

// Log in customer first to test authenticated review submission later
$csrf = getCsrf($baseUrl, $cookieJarCustomer);
httpReq("{$baseUrl}/backend/auth/login.php", 'POST', [
    'email' => 'customer@stylehub.local',
    'password' => 'Customer@StyleHub2026!',
    'csrf_token' => $csrf
], $cookieJarCustomer, ['Accept: application/json', "X-CSRF-Token: {$csrf}"]);

// ==========================================
// 1. CATEGORIES API & MEN/WOMEN/CHILDREN
// ==========================================
echo "[1] Testing Categories & Subcategories API...\n";
$resCat = httpReq("{$baseUrl}/backend/api/categories.php");
assertTest("Categories API returns HTTP 200", $resCat['code'] === 200);
$cats = $resCat['json']['data']['categories'] ?? [];
$catNames = array_map('strtolower', array_column($cats, 'name'));
$hasAllFour = in_array('pants', $catNames) && in_array('shirts', $catNames) && in_array('jackets', $catNames) && in_array('shoes', $catNames);
assertTest("Contains all 4 primary categories (Pants, Shirts, Jackets, Shoes)", $hasAllFour);

$hasAllTargetGroups = true;
foreach ($cats as $c) {
    $groups = array_column($c['subcategories'], 'target_group');
    if (!in_array('Men', $groups) || !in_array('Women', $groups) || !in_array('Children', $groups)) {
        $hasAllTargetGroups = false;
        break;
    }
}
assertTest("Each category supports Men, Women, and Children subcategories", $hasAllTargetGroups);

// ==========================================
// 2. PRODUCTS API & STRICT VISIBILITY RULES
// ==========================================
echo "\n[2] Testing Products API & Stock Visibility Rules...\n";
$resProd = httpReq("{$baseUrl}/backend/api/products.php");
assertTest("Products API returns HTTP 200", $resProd['code'] === 200);
$products = $resProd['json']['data']['products'] ?? [];
assertTest("Returns catalog products (> 0)", count($products) > 0);

$stockExposed = false;
$lowStockDetected = false;
$manualBadgeFound = false;

foreach ($products as $p) {
    // Exact stock quantity must NEVER be exposed
    if (isset($p['total_stock']) || isset($p['stock_quantity']) || isset($p['exact_stock'])) {
        $stockExposed = true;
    }
    if ($p['is_low_stock'] === true) {
        $lowStockDetected = true;
    }
    if (!empty($p['manual_badge_message'])) {
        $manualBadgeFound = true;
    }
}

assertTest("Exact stock quantity is NEVER exposed to customers", !$stockExposed);
assertTest("Low stock badge ('Low in Stock') boolean correctly generated", $lowStockDetected);
assertTest("Admin manual product badge message supported ('New Winter Collection', etc.)", $manualBadgeFound);

// ==========================================
// 3. PRODUCT FILTERS & SORTING
// ==========================================
echo "\n[3] Testing Product Filters & Sorting...\n";

// Filter by search name
$resSearch = httpReq("{$baseUrl}/backend/api/products.php?search=Wool");
$searchCount = count($resSearch['json']['data']['products'] ?? []);
assertTest("Filter by search keyword ('Wool') works", $searchCount > 0);

// Filter by category
$resPants = httpReq("{$baseUrl}/backend/api/products.php?category=pants");
$pantsProds = $resPants['json']['data']['products'] ?? [];
$onlyPants = count($pantsProds) > 0 && array_reduce($pantsProds, fn($carry, $item) => $carry && $item['category_slug'] === 'pants', true);
assertTest("Filter by category ('pants') returns only pants", $onlyPants);

// Filter by subcategory (target_group: Men)
$resMen = httpReq("{$baseUrl}/backend/api/products.php?target_group=Men");
$menProds = $resMen['json']['data']['products'] ?? [];
$onlyMen = count($menProds) > 0 && array_reduce($menProds, fn($carry, $item) => $carry && $item['target_group'] === 'Men', true);
assertTest("Filter by target group ('Men') works", $onlyMen);

// Filter by price range
$resPrice = httpReq("{$baseUrl}/backend/api/products.php?min_price=100&max_price=150");
$priceProds = $resPrice['json']['data']['products'] ?? [];
$inRange = count($priceProds) > 0 && array_reduce($priceProds, fn($carry, $item) => $carry && ($item['price'] >= 100 && $item['price'] <= 150), true);
assertTest("Filter by price range ($100 - $150) works", $inRange);

// Filter by colour
$resColour = httpReq("{$baseUrl}/backend/api/products.php?colour=Green");
$colourProds = $resColour['json']['data']['products'] ?? [];
assertTest("Filter by colour ('Green') returns matches", count($colourProds) > 0);

// Sorting: Price Low to High
$resSortAsc = httpReq("{$baseUrl}/backend/api/products.php?sort=price_asc");
$ascProds = $resSortAsc['json']['data']['products'] ?? [];
$isAscending = true;
for ($i = 0; $i < count($ascProds) - 1; $i++) {
    if ($ascProds[$i]['price'] > $ascProds[$i + 1]['price']) {
        $isAscending = false;
        break;
    }
}
assertTest("Sort by Price Low to High works correctly", $isAscending && count($ascProds) > 1);

// Sorting: Price High to Low
$resSortDesc = httpReq("{$baseUrl}/backend/api/products.php?sort=price_desc");
$descProds = $resSortDesc['json']['data']['products'] ?? [];
$isDescending = true;
for ($i = 0; $i < count($descProds) - 1; $i++) {
    if ($descProds[$i]['price'] < $descProds[$i + 1]['price']) {
        $isDescending = false;
        break;
    }
}
assertTest("Sort by Price High to Low works correctly", $isDescending && count($descProds) > 1);

// Sorting: Highest Rated
$resSortRating = httpReq("{$baseUrl}/backend/api/products.php?sort=highest_rated");
assertTest("Sort by Highest Rated returns HTTP 200", $resSortRating['code'] === 200);

// ==========================================
// 4. PRODUCT DETAILS API & REVIEWS
// ==========================================
echo "\n[4] Testing Product Details API & CSRF-Protected Reviews...\n";
$resDetail = httpReq("{$baseUrl}/backend/api/product-detail.php?id=1");
assertTest("Product Detail API returns HTTP 200", $resDetail['code'] === 200);
$detail = $resDetail['json']['data']['product'] ?? [];
assertTest("Detail includes image gallery", !empty($detail['images']));
assertTest("Detail includes colour swatches", !empty($detail['colours']));
assertTest("Detail includes sizes", !empty($detail['sizes']));
assertTest("Detail includes live viewer activity counter", isset($detail['active_viewers']) && $detail['active_viewers'] > 0);
assertTest("Detail does NOT expose exact stock number", !isset($detail['stock_quantity']) && !isset($detail['exact_stock']));

// Review submission without CSRF token -> Expect 403 Forbidden
$resRevNoCsrf = httpReq("{$baseUrl}/backend/api/reviews.php", 'POST', [
    'product_id' => 1,
    'rating' => 5,
    'title' => 'Superb fit',
    'comment' => 'The wool drape is impeccable and comfortable.'
], $cookieJarCustomer, ['Accept: application/json']);
assertTest("Review submission without CSRF rejected with 403 Forbidden", $resRevNoCsrf['code'] === 403);

// Review submission with valid CSRF token -> Expect 201 Created
require_once __DIR__ . '/config/database.php';
$pdo = getDB();
$pdo->prepare("DELETE FROM `reviews` WHERE comment = 'Truly unmatched virgin wool texture. Fits like a glove.'")->execute();

$custCsrf = getCsrf($baseUrl, $cookieJarCustomer);
$resRevValid = httpReq("{$baseUrl}/backend/api/reviews.php", 'POST', [
    'product_id' => 1,
    'rating' => 5,
    'title' => 'Exceptional tailoring',
    'comment' => 'Truly unmatched virgin wool texture. Fits like a glove.',
    'csrf_token' => $custCsrf
], $cookieJarCustomer, ['Accept: application/json', "X-CSRF-Token: {$custCsrf}"]);
assertTest("Review submission with CSRF accepted with 201 Created", $resRevValid['code'] === 201);

// ==========================================
// 5. SEO & ACCESSIBILITY ASSETS
// ==========================================
echo "\n[5] Testing SEO Basics & Sitemap...\n";
$resRobots = httpReq("{$baseUrl}/robots.txt");
assertTest("robots.txt returns HTTP 200", $resRobots['code'] === 200);
assertTest("robots.txt disallows admin area and specifies sitemap", 
    str_contains($resRobots['body'], 'Disallow: /backend/admin/') && 
    str_contains($resRobots['body'], 'Sitemap:')
);

$resSitemap = httpReq("{$baseUrl}/sitemap.xml");
assertTest("sitemap.xml returns HTTP 200", $resSitemap['code'] === 200);
assertTest("sitemap.xml contains valid XML urlset", str_contains($resSitemap['body'], '<urlset'));

// Check canonical links and meta tags on HTML pages
$resIndex = httpReq("{$baseUrl}/frontend/index.html");
assertTest("Homepage includes unique title and meta description", 
    str_contains($resIndex['body'], '<title>') && 
    str_contains($resIndex['body'], '<meta name="description"')
);
assertTest("Homepage includes canonical URL tag", str_contains($resIndex['body'], 'rel="canonical"'));

$resCatalog = httpReq("{$baseUrl}/frontend/products.html");
assertTest("Catalog page includes canonical link and meta description", 
    str_contains($resCatalog['body'], 'rel="canonical"') && 
    str_contains($resCatalog['body'], '<meta name="description"')
);

// Zero Inline CSS Audit across all frontend/backend
echo "\n[6] Zero Inline CSS Verification...\n";
$codeFiles = array_merge(
    glob(__DIR__ . '/../frontend/*.html'),
    glob(__DIR__ . '/../frontend/js/*.js'),
    glob(__DIR__ . '/*.php'),
    glob(__DIR__ . '/api/*.php')
);
$hasInlineCss = false;
foreach ($codeFiles as $file) {
    $content = file_get_contents($file);
    if (preg_match('/style\s*=\s*["\']/i', $content)) {
        $hasInlineCss = true;
        echo "  [WARN] Found inline CSS in " . basename($file) . "\n";
    }
}
assertTest("ZERO inline CSS across all HTML, JS, and PHP files", !$hasInlineCss);

// Cleanup cookie
@unlink($cookieJarCustomer);

echo "\n========================================================\n";
echo "PHASE 2 TEST RESULTS: {$passed} Passed, {$failed} Failed\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}

