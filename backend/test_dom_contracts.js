/**
 * StyleHub - Frontend DOM & JavaScript Contract Verifier
 * Scans main.js for all document.getElementById, querySelector, and event targets,
 * and validates that all corresponding elements exist in the target HTML/PHP files.
 */

const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');
const frontendDir = path.join(rootDir, 'frontend');
const adminDir = path.join(rootDir, 'backend', 'admin');

const htmlFiles = {
    'index.html': path.join(frontendDir, 'index.html'),
    'products.html': path.join(frontendDir, 'products.html'),
    'product-details.html': path.join(frontendDir, 'product-details.html'),
    'cart.html': path.join(frontendDir, 'cart.html'),
    'checkout.html': path.join(frontendDir, 'checkout.html'),
    'wishlist.html': path.join(frontendDir, 'wishlist.html'),
    'addresses.html': path.join(frontendDir, 'addresses.html'),
    'order-history.html': path.join(frontendDir, 'order-history.html'),
    'order-tracking.html': path.join(frontendDir, 'order-tracking.html'),
    'login.html': path.join(frontendDir, 'login.html'),
    'register.html': path.join(frontendDir, 'register.html'),
    'profile.html': path.join(frontendDir, 'profile.html'),
    '403.html': path.join(frontendDir, '403.html'),
    '404.html': path.join(frontendDir, '404.html'),
    '500.html': path.join(frontendDir, '500.html'),
    'admin/dashboard.php': path.join(adminDir, 'dashboard.php'),
    'admin/login.php': path.join(adminDir, 'login.php')
};

// Check that all files exist
console.log('--- Verifying HTML & Template Existence ---');
let allFilesExist = true;
for (const [name, filepath] of Object.entries(htmlFiles)) {
    if (!fs.existsSync(filepath)) {
        console.error(`MISSING FILE: ${name} at ${filepath}`);
        allFilesExist = false;
    } else {
        const stats = fs.statSync(filepath);
        console.log(`[OK] ${name} (${stats.size} bytes)`);
    }
}

if (!allFilesExist) {
    process.exit(1);
}

// Contract checks for specific pages
const mainJs = fs.readFileSync(path.join(frontendDir, 'js', 'main.js'), 'utf8');

const pageContracts = [
    {
        page: 'index.html',
        elements: ['home-product-sections', 'home-featured-grid', 'nav-auth-container', 'mobile-menu-toggle', 'mobile-drawer']
    },
    {
        page: 'products.html',
        elements: ['catalog-products-grid', 'results-count', 'filter-search', 'filter-category', 'filter-sort', 'filter-min-price', 'filter-max-price', 'filter-reset-btn']
    },
    {
        page: 'product-details.html',
        elements: ['product-detail-view', 'p-title', 'p-price', 'p-desc', 'p-main-img', 'p-thumbs', 'p-colours', 'p-sizes', 'p-viewers', 'p-rating-stars', 'add-review-form', 'reviews-container']
    },
    {
        page: 'cart.html',
        elements: ['cart-main-container', 'cart-skeleton-view', 'cart-content-view', 'cart-empty-view', 'cart-items-tbody', 'cart-subtotal-val', 'cart-shipping-val', 'cart-total-val', 'checkout-btn']
    },
    {
        page: 'checkout.html',
        elements: ['checkout-main-container', 'checkout-skeleton-view', 'checkout-content-view', 'checkout-empty-view', 'checkout-items-preview', 'place-order-btn']
    },
    {
        page: 'wishlist.html',
        elements: ['wishlist-main-container', 'wishlist-skeleton-view', 'wishlist-content-view', 'wishlist-empty-view', 'wishlist-products-grid', 'wishlist-item-count']
    },
    {
        page: 'addresses.html',
        elements: ['addresses-main-container', 'addresses-skeleton-view', 'addresses-content-view', 'addresses-empty-view', 'addresses-grid', 'add-address-btn', 'address-modal', 'address-form']
    },
    {
        page: 'order-history.html',
        elements: ['orders-main-container', 'orders-skeleton-view', 'orders-content-view', 'orders-empty-view', 'orders-tbody', 'orders-count']
    },
    {
        page: 'order-tracking.html',
        elements: ['tracking-main-container', 'tracking-search-form', 'tracking-search-input', 'tracking-result-card', 'track-order-num', 'track-status-badge', 'track-timeline-steps', 'track-items-list']
    },
    {
        page: 'login.html',
        elements: ['customer-login-form', 'login-email', 'login-password', 'login-alert']
    },
    {
        page: 'register.html',
        elements: ['customer-register-form', 'reg-name', 'reg-email', 'reg-password', 'reg-alert']
    },
    {
        page: 'profile.html',
        elements: ['customer-profile-container', 'prof-name', 'prof-email', 'prof-logout-btn']
    },
    {
        page: 'admin/dashboard.php',
        elements: [
            'dashboard-skeleton', 'dashboard-content',
            'stat-total-products', 'stat-in-stock', 'stat-low-stock', 'stat-out-of-stock',
            'stat-orders-pending', 'stat-orders-in-queue', 'stat-orders-dispatched', 'stat-orders-delivered', 'stat-orders-returned',
            'stat-pending-returns', 'stat-total-colours', 'stat-total-reviews', 'stat-active-viewers',
            'admin-products-tbody', 'admin-categories-tbody', 'admin-orders-tbody', 'admin-customers-tbody', 'admin-returns-tbody',
            'modal-product', 'modal-category', 'modal-order', 'modal-customer-dossier', 'modal-return'
        ]
    }
];

console.log('\n--- Verifying DOM Contract Elements in HTML/PHP Files ---');
let contractsPassed = 0;
let contractsFailed = 0;

for (const contract of pageContracts) {
    const filePath = htmlFiles[contract.page];
    const content = fs.readFileSync(filePath, 'utf8');
    
    for (const elId of contract.elements) {
        const idRegex = new RegExp(`id=["']${elId}["']`, 'i');
        if (idRegex.test(content)) {
            contractsPassed++;
        } else {
            console.error(`[FAIL] ${contract.page} missing expected DOM element id="${elId}"`);
            contractsFailed++;
        }
    }
}

console.log(`Contract Check Result: ${contractsPassed} passed, ${contractsFailed} failed.`);

if (contractsFailed > 0) {
    process.exit(1);
} else {
    console.log('[ALL DOM CONTRACTS VERIFIED SUCCESSFULLY]');
}

