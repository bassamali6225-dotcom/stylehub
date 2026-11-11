<?php
/**
 * StyleHub - Complete Protected Admin Management Dashboard (Phase 4)
 * Real-time statistics, products CRUD, inventory adjustment, low-stock threshold,
 * category & subcategory classification management, customer directory & account control,
 * order status tracking & dispatch dates, returns approval with automated inventory restocking.
 * Requires Admin Authentication via PHP Sessions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';

// Strict Admin Gate: if not authenticated as administrator, redirects to admin login
Auth::requireAdmin();

$admin = Auth::getCurrentAdmin();
$csrfToken = CSRF::getToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | StyleHub Management</title>
    <link rel="stylesheet" href="/frontend/css/style.css">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="robots" content="noindex, nofollow">
</head>
<body class="admin-body">
    <!-- Admin Top Header -->
    <header class="admin-header">
        <div class="admin-header-container">
            <div class="admin-brand">
                <span class="admin-logo-mark">SH</span>
                <span class="admin-title">StyleHub Control Center</span>
            </div>
            <div class="admin-user-nav">
                <!-- Notification Bell & Dropdown -->
                <div class="admin-notif-wrapper" id="admin-notif-wrapper">
                    <button type="button" class="admin-notif-bell-btn" id="admin-notif-bell-btn" aria-label="Notifications" aria-expanded="false" title="Notification Alerts">
                        <span class="bell-icon" aria-hidden="true">🔔</span>
                        <span class="admin-notif-badge is-hidden" id="admin-notif-badge">0</span>
                    </button>
                    <div class="admin-notif-dropdown is-hidden" id="admin-notif-dropdown" role="region" aria-label="Recent Notifications">
                        <div class="admin-notif-dropdown-header">
                            <div class="d-flex align-items-center justify-content-between">
                                <h4 class="admin-notif-dropdown-title">Notifications</h4>
                                <span class="badge badge-sm badge-primary" id="dropdown-unread-pill">0 new</span>
                            </div>
                            <div class="admin-notif-dropdown-actions">
                                <button type="button" class="btn-text btn-text-sm" id="dropdown-mark-all-read-btn">Mark all read</button>
                            </div>
                        </div>
                        <div class="admin-notif-dropdown-body" id="admin-notif-dropdown-list">
                            <div class="admin-notif-empty">No unread notifications</div>
                        </div>
                        <div class="admin-notif-dropdown-footer">
                            <a href="#tab-notifications" class="admin-notif-view-all" id="dropdown-view-all-btn">View All Notifications →</a>
                        </div>
                    </div>
                </div>

                <span class="admin-role-tag">Admin</span>
                <a href="/frontend/index.html" class="btn btn-sm btn-outline" target="_blank" rel="noopener">Storefront ↗</a>
                <a href="/backend/admin/logout.php" class="btn btn-sm btn-secondary">Sign Out</a>
            </div>
        </div>
    </header>

    <div class="admin-layout">
        <!-- Admin Navigation Sidebar -->
        <aside class="admin-sidebar" aria-label="Admin Navigation">
            <nav class="admin-nav" id="admin-sidebar-nav">
                <button type="button" class="admin-nav-item active" data-tab="overview">
                    <span class="nav-icon">📊</span>
                    <span>Overview</span>
                </button>
                <button type="button" class="admin-nav-item" data-tab="notifications" id="sidebar-notif-nav-btn">
                    <span class="nav-icon">🔔</span>
                    <span>Notifications</span>
                    <span class="sidebar-badge is-hidden" id="sidebar-notif-badge">0</span>
                </button>
                <button type="button" class="admin-nav-item" data-tab="products">
                    <span class="nav-icon">📦</span>
                    <span>Products</span>
                </button>
                <button type="button" class="admin-nav-item" data-tab="categories">
                    <span class="nav-icon">🏷️</span>
                    <span>Categories</span>
                </button>
                <button type="button" class="admin-nav-item" data-tab="orders">
                    <span class="nav-icon">🛒</span>
                    <span>Orders</span>
                </button>
                <button type="button" class="admin-nav-item" data-tab="customers">
                    <span class="nav-icon">👥</span>
                    <span>Customers</span>
                </button>
                <button type="button" class="admin-nav-item" data-tab="returns">
                    <span class="nav-icon">↩️</span>
                    <span>Returns</span>
                </button>
            </nav>

            <div class="admin-sidebar-footer">
                <div class="system-status-indicator">
                    <span class="status-dot status-online"></span>
                    <span class="status-label">MySQL Database Live</span>
                </div>
            </div>
        </aside>

        <!-- Admin Content Area -->
        <main class="admin-main">
            <!-- Global Flash / Toast Message Area -->
            <div id="admin-flash-message" class="is-hidden mb-4"></div>

            <!-- ============================================================== -->
            <!-- TAB 1: OVERVIEW & LIVE 13 MYSQL STATISTICS -->
            <!-- ============================================================== -->
            <section id="pane-overview" class="admin-tab-pane active" aria-labelledby="overview-heading">
                <div class="admin-page-header">
                    <div>
                        <h1 id="overview-heading" class="page-title">Management Overview</h1>
                        <p class="page-subtitle">Real-time store metrics, fulfillment pipeline, and inventory status</p>
                    </div>
                    <div class="admin-quick-actions">
                        <button type="button" id="refresh-dashboard-btn" class="btn btn-primary btn-sm">Refresh Stats</button>
                    </div>
                </div>

                <!-- Skeleton Loader for Stats -->
                <div id="dashboard-skeleton" class="admin-dashboard-clusters">
                    <div class="admin-cluster-card mb-6">
                        <div class="admin-cluster-header">
                            <span class="cluster-badge">Catalog Health</span>
                            <h2 class="admin-cluster-title">Inventory & Stock Tracking</h2>
                        </div>
                        <div class="admin-stats-grid-4">
                            <div class="skeleton-card skeleton-stat"><div class="skeleton-line skeleton-title"></div><div class="skeleton-line skeleton-metric"></div></div>
                            <div class="skeleton-card skeleton-stat"><div class="skeleton-line skeleton-title"></div><div class="skeleton-line skeleton-metric"></div></div>
                            <div class="skeleton-card skeleton-stat"><div class="skeleton-line skeleton-title"></div><div class="skeleton-line skeleton-metric"></div></div>
                            <div class="skeleton-card skeleton-stat"><div class="skeleton-line skeleton-title"></div><div class="skeleton-line skeleton-metric"></div></div>
                        </div>
                    </div>
                    <div class="admin-cluster-card mb-6">
                        <div class="admin-cluster-header">
                            <span class="cluster-badge">Fulfillment Pipeline</span>
                            <h2 class="admin-cluster-title">Order Processing & Logistics</h2>
                        </div>
                        <div class="admin-stats-grid-5">
                            <div class="skeleton-card skeleton-stat"><div class="skeleton-line skeleton-title"></div><div class="skeleton-line skeleton-metric"></div></div>
                            <div class="skeleton-card skeleton-stat"><div class="skeleton-line skeleton-title"></div><div class="skeleton-line skeleton-metric"></div></div>
                            <div class="skeleton-card skeleton-stat"><div class="skeleton-line skeleton-title"></div><div class="skeleton-line skeleton-metric"></div></div>
                            <div class="skeleton-card skeleton-stat"><div class="skeleton-line skeleton-title"></div><div class="skeleton-line skeleton-metric"></div></div>
                            <div class="skeleton-card skeleton-stat"><div class="skeleton-line skeleton-title"></div><div class="skeleton-line skeleton-metric"></div></div>
                        </div>
                    </div>
                </div>

                <!-- Live 13 MySQL Statistics Logically Grouped -->
                <div id="dashboard-content" class="admin-dashboard-clusters is-hidden">
                    <!-- Cluster 1: Catalog & Inventory Tracking -->
                    <div class="admin-cluster-card mb-6">
                        <div class="admin-cluster-header">
                            <span class="cluster-badge">Catalog Health</span>
                            <h2 class="admin-cluster-title">Inventory & Stock Tracking</h2>
                        </div>
                        <div class="admin-stats-grid-4">
                            <!-- Metric 1: Total Products -->
                            <div class="stat-card">
                                <div class="stat-card-header">
                                    <span class="stat-label">Total Products</span>
                                    <span class="stat-badge">Catalog</span>
                                </div>
                                <div class="stat-value" id="stat-total-products">0</div>
                                <div class="stat-meta">Active catalog items</div>
                            </div>

                            <!-- Metric 2: In-Stock Products -->
                            <div class="stat-card stat-card-success">
                                <div class="stat-card-header">
                                    <span class="stat-label">In-Stock Products</span>
                                    <span class="stat-badge badge-success">Healthy</span>
                                </div>
                                <div class="stat-value" id="stat-in-stock">0</div>
                                <div class="stat-meta">Above reorder threshold</div>
                            </div>

                            <!-- Metric 3: Low-Stock Products -->
                            <div class="stat-card stat-card-warning">
                                <div class="stat-card-header">
                                    <span class="stat-label">Low-Stock Warnings</span>
                                    <span class="stat-badge badge-warning">Action Needed</span>
                                </div>
                                <div class="stat-value" id="stat-low-stock">0</div>
                                <div class="stat-meta">At or below stock threshold</div>
                            </div>

                            <!-- Metric 4: Out-of-Stock Products -->
                            <div class="stat-card stat-card-danger">
                                <div class="stat-card-header">
                                    <span class="stat-label">Out-of-Stock</span>
                                    <span class="stat-badge badge-danger">Depleted</span>
                                </div>
                                <div class="stat-value" id="stat-out-of-stock">0</div>
                                <div class="stat-meta">Zero inventory remaining</div>
                            </div>
                        </div>
                    </div>

                    <!-- Cluster 2: Order Fulfillment Pipeline -->
                    <div class="admin-cluster-card mb-6">
                        <div class="admin-cluster-header">
                            <span class="cluster-badge">Fulfillment Pipeline</span>
                            <h2 class="admin-cluster-title">Order Processing & Logistics</h2>
                        </div>
                        <div class="admin-stats-grid-5">
                            <!-- Metric 5: Pending Orders -->
                            <div class="stat-card">
                                <div class="stat-card-header">
                                    <span class="stat-label">Pending Orders</span>
                                    <span class="stat-badge badge-warning">Review</span>
                                </div>
                                <div class="stat-value" id="stat-orders-pending">0</div>
                                <div class="stat-meta">Awaiting processing</div>
                            </div>

                            <!-- Metric 6: Orders In Queue -->
                            <div class="stat-card stat-card-info">
                                <div class="stat-card-header">
                                    <span class="stat-label">Orders In Queue</span>
                                    <span class="stat-badge badge-info">Packaging</span>
                                </div>
                                <div class="stat-value" id="stat-orders-in-queue">0</div>
                                <div class="stat-meta">Being prepared for dispatch</div>
                            </div>

                            <!-- Metric 7: Dispatched Orders -->
                            <div class="stat-card">
                                <div class="stat-card-header">
                                    <span class="stat-label">Dispatched Orders</span>
                                    <span class="stat-badge badge-info">In Transit</span>
                                </div>
                                <div class="stat-value" id="stat-orders-dispatched">0</div>
                                <div class="stat-meta">With courier partners</div>
                            </div>

                            <!-- Metric 8: Delivered Orders -->
                            <div class="stat-card stat-card-success">
                                <div class="stat-card-header">
                                    <span class="stat-label">Delivered Orders</span>
                                    <span class="stat-badge badge-success">Completed</span>
                                </div>
                                <div class="stat-value" id="stat-orders-delivered">0</div>
                                <div class="stat-meta">Successfully fulfilled</div>
                            </div>

                            <!-- Metric 9: Total Returned Products -->
                            <div class="stat-card">
                                <div class="stat-card-header">
                                    <span class="stat-label">Returned Orders</span>
                                    <span class="stat-badge badge-danger">Returned</span>
                                </div>
                                <div class="stat-value" id="stat-orders-returned">0</div>
                                <div class="stat-meta">Total orders returned</div>
                            </div>
                        </div>
                    </div>

                    <!-- Cluster 3: Operations & Engagement -->
                    <div class="admin-cluster-card mb-6">
                        <div class="admin-cluster-header">
                            <span class="cluster-badge">Operations & Engagement</span>
                            <h2 class="admin-cluster-title">Restocking, Variants & Engagement</h2>
                        </div>
                        <div class="admin-stats-grid-4">
                            <!-- Metric 10: Pending Return Requests -->
                            <div class="stat-card stat-card-warning">
                                <div class="stat-card-header">
                                    <span class="stat-label">Pending Returns</span>
                                    <span class="stat-badge badge-warning">Awaiting Approval</span>
                                </div>
                                <div class="stat-value" id="stat-pending-returns">0</div>
                                <div class="stat-meta">Restocking review required</div>
                            </div>

                            <!-- Metric 11: Total Colours -->
                            <div class="stat-card">
                                <div class="stat-card-header">
                                    <span class="stat-label">Product Colours</span>
                                    <span class="stat-badge">Variants</span>
                                </div>
                                <div class="stat-value" id="stat-total-colours">0</div>
                                <div class="stat-meta">Distinct colour choices</div>
                            </div>

                            <!-- Metric 12: Customer Reviews -->
                            <div class="stat-card">
                                <div class="stat-card-header">
                                    <span class="stat-label">Customer Reviews</span>
                                    <span class="stat-badge">Feedback</span>
                                </div>
                                <div class="stat-value" id="stat-total-reviews">0</div>
                                <div class="stat-meta">Ratings & feedback logged</div>
                            </div>

                            <!-- Metric 13: Live Product Viewers -->
                            <div class="stat-card stat-card-info">
                                <div class="stat-card-header">
                                    <span class="stat-label">Active Viewers</span>
                                    <span class="stat-badge badge-info">Real-time</span>
                                </div>
                                <div class="stat-value" id="stat-active-viewers">0</div>
                                <div class="stat-meta">Currently browsing products</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Security Architecture & Verification Notice -->
                <div class="card mt-6">
                    <div class="card-header">
                        <h2 class="card-title">Security & Role Architecture Active</h2>
                    </div>
                    <div class="card-body">
                        <div class="security-checklist">
                            <div class="security-check-item">
                                <span class="check-icon">✓</span>
                                <div>
                                    <strong>Stock Privacy Enforcement:</strong>
                                    <span>Public customers never see raw stock quantities. Customers only receive an automatic "Low in Stock" indicator when stock reaches the admin-defined threshold. Admins have complete visibility of exact counts.</span>
                                </div>
                            </div>
                            <div class="security-check-item">
                                <span class="check-icon">✓</span>
                                <div>
                                    <strong>Role-Based Access Control:</strong>
                                    <span>Public navigation has zero admin links. Non-authenticated visitors are redirected to admin login, and logged-in customers attempting to open admin routes receive a 403 Access Denied.</span>
                                </div>
                            </div>
                            <div class="security-check-item">
                                <span class="check-icon">✓</span>
                                <div>
                                    <strong>Automated Inventory Restocking on Return:</strong>
                                    <span>Approving a return request automatically recalculates and increments the exact product quantities back into MySQL inventory.</span>
                                </div>
                            </div>
                            <div class="security-check-item">
                                <span class="check-icon">✓</span>
                                <div>
                                    <strong>Cryptographic CSRF Defense:</strong>
                                    <span>Every admin create, update, delete, status change, and restocking approval enforces CSRF token verification.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================================================== -->
            <!-- TAB 2: PRODUCT MANAGEMENT -->
            <!-- ============================================================== -->
            <section id="pane-products" class="admin-tab-pane" aria-labelledby="products-heading">
                <div class="admin-page-header">
                    <div>
                        <h1 id="products-heading" class="page-title">Product Catalog Management</h1>
                        <p class="page-subtitle">Add, edit, adjust stock, upload images, manage colours & sizes, set manual badges</p>
                    </div>
                    <div class="admin-quick-actions">
                        <button type="button" id="btn-open-add-product" class="btn btn-primary btn-sm">+ Add New Product</button>
                    </div>
                </div>

                <!-- Product Filters Toolbar -->
                <div class="admin-toolbar">
                    <div class="admin-toolbar-group">
                        <input type="text" id="admin-product-search" class="admin-search-input" placeholder="Search products by name or slug...">
                        <select id="admin-product-category-filter" class="admin-select" aria-label="Filter by category">
                            <option value="">All Categories</option>
                        </select>
                        <button type="button" id="btn-reset-product-filters" class="btn btn-outline btn-sm">Reset</button>
                    </div>
                    <span id="admin-product-count" class="text-sm text-muted">Loading products...</span>
                </div>

                <!-- Products Table -->
                <div class="admin-table-container">
                    <table class="admin-table" id="admin-products-table">
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Product Details</th>
                                <th>Category / Group</th>
                                <th>Price</th>
                                <th>Stock (Admin Only)</th>
                                <th>Status</th>
                                <th>Manual Badge</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="admin-products-tbody">
                            <tr>
                                <td colspan="8" class="text-center py-6 text-muted">Loading product catalog...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ============================================================== -->
            <!-- TAB 3: CATEGORY & CLASSIFICATION MANAGEMENT -->
            <!-- ============================================================== -->
            <section id="pane-categories" class="admin-tab-pane" aria-labelledby="categories-heading">
                <div class="admin-page-header">
                    <div>
                        <h1 id="categories-heading" class="page-title">Categories & Classifications</h1>
                        <p class="page-subtitle">Manage store departments, subcategories, and Men / Women / Children groups</p>
                    </div>
                    <div class="admin-quick-actions">
                        <button type="button" id="btn-open-add-category" class="btn btn-primary btn-sm">+ Add New Category</button>
                    </div>
                </div>

                <div class="admin-table-container">
                    <table class="admin-table" id="admin-categories-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Slug</th>
                                <th>Subcategories / Target Groups</th>
                                <th>Products</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="admin-categories-tbody">
                            <tr>
                                <td colspan="5" class="text-center py-6 text-muted">Loading categories...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ============================================================== -->
            <!-- TAB 4: ORDER MANAGEMENT & DISPATCH TRACKING -->
            <!-- ============================================================== -->
            <section id="pane-orders" class="admin-tab-pane" aria-labelledby="orders-heading">
                <div class="admin-page-header">
                    <div>
                        <h1 id="orders-heading" class="page-title">Customer Orders Management</h1>
                        <p class="page-subtitle">Inspect orders, advance fulfillment status, and set dispatch dates</p>
                    </div>
                </div>

                <!-- Orders Filter Toolbar -->
                <div class="admin-toolbar">
                    <div class="admin-toolbar-group">
                        <div class="admin-filter-pills" id="admin-order-status-pills">
                            <button type="button" class="filter-pill active" data-status="">All</button>
                            <button type="button" class="filter-pill" data-status="Pending">Pending</button>
                            <button type="button" class="filter-pill" data-status="In Queue">In Queue</button>
                            <button type="button" class="filter-pill" data-status="Dispatched">Dispatched</button>
                            <button type="button" class="filter-pill" data-status="Delivered">Delivered</button>
                            <button type="button" class="filter-pill" data-status="Returned">Returned</button>
                        </div>
                        <input type="text" id="admin-order-search" class="admin-search-input" placeholder="Search order #, customer name, email...">
                    </div>
                    <span id="admin-orders-count" class="text-sm text-muted">Loading orders...</span>
                </div>

                <div class="admin-table-container">
                    <table class="admin-table" id="admin-orders-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Dispatch Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="admin-orders-tbody">
                            <tr>
                                <td colspan="7" class="text-center py-6 text-muted">Loading orders...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ============================================================== -->
            <!-- TAB 5: CUSTOMER DIRECTORY & ACCOUNT CONTROL -->
            <!-- ============================================================== -->
            <section id="pane-customers" class="admin-tab-pane" aria-labelledby="customers-heading">
                <div class="admin-page-header">
                    <div>
                        <h1 id="customers-heading" class="page-title">Customer Directory</h1>
                        <p class="page-subtitle">View profiles, order history, reviews, returns, activate or deactivate accounts</p>
                    </div>
                </div>

                <!-- Customer Search Toolbar -->
                <div class="admin-toolbar">
                    <div class="admin-toolbar-group">
                        <input type="text" id="admin-customer-search" class="admin-search-input" placeholder="Search customer by name, email, or phone...">
                        <button type="button" id="btn-reset-customer-search" class="btn btn-outline btn-sm">Clear</button>
                    </div>
                    <span id="admin-customers-count" class="text-sm text-muted">Loading customers...</span>
                </div>

                <div class="admin-table-container">
                    <table class="admin-table" id="admin-customers-table">
                        <thead>
                            <tr>
                                <th>Customer ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Orders</th>
                                <th>Total Spent</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="admin-customers-tbody">
                            <tr>
                                <td colspan="9" class="text-center py-6 text-muted">Loading customer directory...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ============================================================== -->
            <!-- TAB 6: RETURNS MANAGEMENT & RESTOCKING -->
            <!-- ============================================================== -->
            <section id="pane-returns" class="admin-tab-pane" aria-labelledby="returns-heading">
                <div class="admin-page-header">
                    <div>
                        <h1 id="returns-heading" class="page-title">Return Requests & Restocking</h1>
                        <p class="page-subtitle">Review customer returns, record admin notes, and restock inventory</p>
                    </div>
                </div>

                <!-- Return Filters Toolbar -->
                <div class="admin-toolbar">
                    <div class="admin-toolbar-group">
                        <div class="admin-filter-pills" id="admin-return-status-pills">
                            <button type="button" class="filter-pill active" data-status="">All</button>
                            <button type="button" class="filter-pill" data-status="Pending">Pending</button>
                            <button type="button" class="filter-pill" data-status="Approved">Approved</button>
                            <button type="button" class="filter-pill" data-status="Rejected">Rejected</button>
                        </div>
                        <input type="text" id="admin-return-search" class="admin-search-input" placeholder="Search return by order #, customer, reason...">
                    </div>
                    <span id="admin-returns-count" class="text-sm text-muted">Loading return requests...</span>
                </div>

                <div class="admin-table-container">
                    <table class="admin-table" id="admin-returns-table">
                        <thead>
                            <tr>
                                <th>Return ID</th>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Reason</th>
                                <th>Customer Notes</th>
                                <th>Status</th>
                                <th>Admin Notes</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="admin-returns-tbody">
                            <tr>
                                <td colspan="9" class="text-center py-6 text-muted">Loading returns...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ============================================================== -->
            <!-- TAB 7: ADMIN NOTIFICATION CENTRE -->
            <!-- ============================================================== -->
            <section id="pane-notifications" class="admin-tab-pane" aria-labelledby="notifications-heading">
                <div class="admin-page-header">
                    <div>
                        <h1 id="notifications-heading" class="page-title">Admin Notification Centre</h1>
                        <p class="page-subtitle">Real-time alerts for customer orders, inventory thresholds, returns, and store activity</p>
                    </div>
                    <div class="admin-quick-actions">
                        <button type="button" id="notif-mark-all-btn" class="btn btn-outline btn-sm">Mark All As Read</button>
                        <button type="button" id="notif-refresh-btn" class="btn btn-primary btn-sm">Refresh Alerts</button>
                    </div>
                </div>

                <!-- Filter & Search Bar -->
                <div class="admin-filter-bar mb-4">
                    <div class="admin-filter-group">
                        <label for="notif-type-filter" class="form-label-inline">Type:</label>
                        <select id="notif-type-filter" class="form-control form-control-sm">
                            <option value="all">All Event Types</option>
                            <option value="order">Orders & Fulfillment</option>
                            <option value="stock">Inventory & Stock Alerts</option>
                            <option value="return">Returns, Refunds & Exchanges</option>
                            <option value="customer">Customers, Logins & Reviews</option>
                            <option value="catalog">Products & Categories</option>
                        </select>
                    </div>
                    <div class="admin-filter-group">
                        <label for="notif-status-filter" class="form-label-inline">Status:</label>
                        <select id="notif-status-filter" class="form-control form-control-sm">
                            <option value="all">All Alerts</option>
                            <option value="unread" selected>Unread Only</option>
                            <option value="read">Read Only</option>
                        </select>
                    </div>
                    <div class="admin-filter-group">
                        <label for="notif-date-filter" class="form-label-inline">Date:</label>
                        <select id="notif-date-filter" class="form-control form-control-sm">
                            <option value="all">All Time</option>
                            <option value="today">Today</option>
                            <option value="yesterday">Yesterday</option>
                            <option value="week">Past 7 Days</option>
                            <option value="month">Past 30 Days</option>
                        </select>
                    </div>
                    <div class="admin-search-wrapper">
                        <input type="search" id="notif-search-input" class="form-control form-control-sm" placeholder="Search notification details...">
                    </div>
                </div>

                <!-- Notification Feed -->
                <div class="admin-notif-centre-card">
                    <div id="notif-skeleton" class="admin-notif-skeleton is-hidden">
                        <div class="skeleton-card skeleton-stat mb-2"><div class="skeleton-line skeleton-title"></div><div class="skeleton-line skeleton-metric"></div></div>
                        <div class="skeleton-card skeleton-stat mb-2"><div class="skeleton-line skeleton-title"></div><div class="skeleton-line skeleton-metric"></div></div>
                        <div class="skeleton-card skeleton-stat mb-2"><div class="skeleton-line skeleton-title"></div><div class="skeleton-line skeleton-metric"></div></div>
                    </div>

                    <div id="notif-feed-container" class="admin-notif-feed">
                        <!-- Rendered dynamically by JS -->
                    </div>

                    <div id="notif-empty-state" class="admin-empty-state is-hidden">
                        <span class="empty-icon">🔔</span>
                        <h3 class="empty-title">No notifications match your filter</h3>
                        <p class="empty-subtitle">All alerts are up-to-date. New store events will appear here in real-time.</p>
                    </div>

                    <div class="admin-notif-pagination mt-4 d-flex justify-content-between align-items-center">
                        <span class="text-muted text-sm" id="notif-count-summary">Showing 0 notifications</span>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline" id="notif-prev-page-btn" disabled>Previous</button>
                            <button type="button" class="btn btn-sm btn-outline" id="notif-next-page-btn" disabled>Next</button>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- ====================================================================== -->
    <!-- MODAL 1: ADD / EDIT PRODUCT -->
    <!-- ====================================================================== -->
    <div id="modal-product" class="admin-modal-overlay is-hidden" role="dialog" aria-modal="true" aria-labelledby="modal-product-title">
        <div class="admin-modal-container modal-lg">
            <div class="admin-modal-header">
                <h3 id="modal-product-title" class="admin-modal-title">Product Details</h3>
                <button type="button" class="admin-modal-close" data-close-modal="modal-product" aria-label="Close modal">&times;</button>
            </div>
            <form id="form-save-product">
                <input type="hidden" id="prod-id" name="product_id" value="">
                <input type="hidden" id="prod-action" name="action" value="create">
                <div class="admin-modal-body">
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="prod-name" class="form-label">Product Name *</label>
                            <input type="text" id="prod-name" name="name" class="form-control" required placeholder="e.g. Vintage Denim Jacket">
                        </div>
                        <div class="form-group">
                            <label for="prod-price" class="form-label">Price (USD $) *</label>
                            <input type="number" id="prod-price" name="price" class="form-control" step="0.01" min="0.01" required placeholder="59.99">
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="prod-category" class="form-label">Category *</label>
                            <select id="prod-category" name="category_id" class="form-control" required>
                                <option value="">Select Category</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="prod-subcategory" class="form-label">Classification / Subcategory</label>
                            <select id="prod-subcategory" name="subcategory_id" class="form-control">
                                <option value="">General / None</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="prod-description" class="form-label">Description</label>
                        <textarea id="prod-description" name="description" class="form-control" rows="3" placeholder="Enter product details, fabric, and styling notes..."></textarea>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="prod-threshold" class="form-label">Low Stock Threshold</label>
                            <input type="number" id="prod-threshold" name="low_stock_threshold" class="form-control" min="1" value="5">
                            <p class="form-help">When stock reaches this level, the public store displays "Low in Stock" (exact count hidden).</p>
                        </div>
                        <div class="form-group" id="prod-initial-stock-group">
                            <label for="prod-initial-stock" class="form-label">Initial Stock Quantity</label>
                            <input type="number" id="prod-initial-stock" name="initial_stock" class="form-control" min="0" value="10">
                            <p class="form-help">Starting inventory for this product.</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="prod-manual-badge" class="form-label">Custom Manual Message</label>
                        <input type="text" id="prod-manual-badge" name="manual_badge_message" class="form-control" placeholder="e.g. New winter collection, Limited edition, Bestseller">
                        <p class="form-help">Controlled by admin: displayed beneath this product on public cards and detail page.</p>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="prod-is-featured" name="is_featured" value="1">
                                <span>Mark as Featured Product</span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="prod-hide-oos" name="hide_when_out_of_stock" value="1" checked>
                                <span>Hide from public listings when out of stock</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="admin-modal-footer">
                    <button type="button" class="btn btn-outline" data-close-modal="modal-product">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btn-save-product-submit">Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- MODAL 2: ADJUST INVENTORY STOCK -->
    <!-- ====================================================================== -->
    <div id="modal-stock" class="admin-modal-overlay is-hidden" role="dialog" aria-modal="true" aria-labelledby="modal-stock-title">
        <div class="admin-modal-container">
            <div class="admin-modal-header">
                <h3 id="modal-stock-title" class="admin-modal-title">Adjust Inventory Stock</h3>
                <button type="button" class="admin-modal-close" data-close-modal="modal-stock" aria-label="Close modal">&times;</button>
            </div>
            <form id="form-adjust-stock">
                <input type="hidden" id="stock-product-id" name="product_id" value="">
                <div class="admin-modal-body">
                    <p class="mb-4 text-sm" id="stock-product-name-display">Product: <strong>--</strong></p>
                    <div class="form-group">
                        <label for="stock-qty-input" class="form-label">Current Stock Count *</label>
                        <input type="number" id="stock-qty-input" name="stock_quantity" class="form-control" min="0" required>
                        <p class="form-help">Exact stock count is only visible to admins. Customers will only see "Low in Stock" or "In Stock".</p>
                    </div>
                </div>
                <div class="admin-modal-footer">
                    <button type="button" class="btn btn-outline" data-close-modal="modal-stock">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Stock</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- MODAL 3: UPLOAD PRODUCT PHOTO -->
    <!-- ====================================================================== -->
    <div id="modal-image" class="admin-modal-overlay is-hidden" role="dialog" aria-modal="true" aria-labelledby="modal-image-title">
        <div class="admin-modal-container">
            <div class="admin-modal-header">
                <h3 id="modal-image-title" class="admin-modal-title">Upload Product Photo</h3>
                <button type="button" class="admin-modal-close" data-close-modal="modal-image" aria-label="Close modal">&times;</button>
            </div>
            <form id="form-upload-image" enctype="multipart/form-data">
                <input type="hidden" id="img-product-id" name="product_id" value="">
                <div class="admin-modal-body">
                    <p class="mb-4 text-sm" id="img-product-name-display">Product: <strong>--</strong></p>
                    <div class="form-group">
                        <label for="img-file-input" class="form-label">Choose Image File (JPEG, PNG, WEBP, max 5MB) *</label>
                        <input type="file" id="img-file-input" name="image" class="form-control" accept="image/jpeg,image/png,image/webp" required>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="img-is-primary" name="is_primary" value="1" checked>
                            <span>Set as primary storefront image</span>
                        </label>
                    </div>
                </div>
                <div class="admin-modal-footer">
                    <button type="button" class="btn btn-outline" data-close-modal="modal-image">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btn-upload-image-submit">Upload Photo</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- MODAL 4: MANAGE COLOURS & SIZES -->
    <!-- ====================================================================== -->
    <div id="modal-variants" class="admin-modal-overlay is-hidden" role="dialog" aria-modal="true" aria-labelledby="modal-variants-title">
        <div class="admin-modal-container modal-lg">
            <div class="admin-modal-header">
                <h3 id="modal-variants-title" class="admin-modal-title">Manage Colours & Sizes</h3>
                <button type="button" class="admin-modal-close" data-close-modal="modal-variants" aria-label="Close modal">&times;</button>
            </div>
            <div class="admin-modal-body">
                <input type="hidden" id="variants-product-id" value="">
                <p class="mb-4 text-sm" id="variants-product-name-display">Product: <strong>--</strong></p>

                <!-- Colours Section -->
                <div class="admin-dossier-card">
                    <h4 class="admin-dossier-title">Available Colours</h4>
                    <div id="variants-colours-list" class="mb-3"></div>
                    <form id="form-add-colour" class="form-grid-2">
                        <input type="text" id="new-colour-name" class="form-control" placeholder="Colour name (e.g. Olive Green)" required>
                        <div class="d-flex gap-2">
                            <input type="color" id="new-colour-code" value="#15803d" class="admin-select" title="Choose colour shade">
                            <button type="submit" class="btn btn-sm btn-primary">Add Colour</button>
                        </div>
                    </form>
                </div>

                <!-- Sizes Section -->
                <div class="admin-dossier-card">
                    <h4 class="admin-dossier-title">Available Sizes</h4>
                    <div id="variants-sizes-list" class="mb-3"></div>
                    <form id="form-add-size" class="form-grid-2">
                        <input type="text" id="new-size-name" class="form-control" placeholder="Size (e.g. S, M, L, XL, 32)" required>
                        <div class="d-flex gap-2">
                            <input type="number" id="new-size-order" class="form-control" placeholder="Sort order" value="1" min="1">
                            <button type="submit" class="btn btn-sm btn-primary">Add Size</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal="modal-variants">Close</button>
            </div>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- MODAL 5: ADD / EDIT CATEGORY -->
    <!-- ====================================================================== -->
    <div id="modal-category" class="admin-modal-overlay is-hidden" role="dialog" aria-modal="true" aria-labelledby="modal-category-title">
        <div class="admin-modal-container">
            <div class="admin-modal-header">
                <h3 id="modal-category-title" class="admin-modal-title">Category</h3>
                <button type="button" class="admin-modal-close" data-close-modal="modal-category" aria-label="Close modal">&times;</button>
            </div>
            <form id="form-save-category">
                <input type="hidden" id="cat-id" name="category_id" value="">
                <input type="hidden" id="cat-action" name="action" value="create_category">
                <div class="admin-modal-body">
                    <div class="form-group">
                        <label for="cat-name" class="form-label">Category Name *</label>
                        <input type="text" id="cat-name" name="name" class="form-control" required placeholder="e.g. Jackets, Shoes, Pants">
                    </div>
                    <div class="form-group">
                        <label for="cat-description" class="form-label">Description</label>
                        <textarea id="cat-description" name="description" class="form-control" rows="2" placeholder="Brief category description..."></textarea>
                    </div>
                    <div class="form-group" id="cat-image-group">
                        <label for="cat-image-url" class="form-label">Image URL (Optional)</label>
                        <input type="url" id="cat-image-url" name="image_url" class="form-control" placeholder="https://...">
                        <p class="form-help">Creating a new category automatically generates Men, Women, and Children classifications.</p>
                    </div>
                </div>
                <div class="admin-modal-footer">
                    <button type="button" class="btn btn-outline" data-close-modal="modal-category">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btn-save-category-submit">Save Category</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- MODAL 6: ADD SUBCATEGORY CLASSIFICATION -->
    <!-- ====================================================================== -->
    <div id="modal-subcategory" class="admin-modal-overlay is-hidden" role="dialog" aria-modal="true" aria-labelledby="modal-subcategory-title">
        <div class="admin-modal-container">
            <div class="admin-modal-header">
                <h3 id="modal-subcategory-title" class="admin-modal-title">Add Classification</h3>
                <button type="button" class="admin-modal-close" data-close-modal="modal-subcategory" aria-label="Close modal">&times;</button>
            </div>
            <form id="form-save-subcategory">
                <input type="hidden" id="subcat-cat-id" name="category_id" value="">
                <div class="admin-modal-body">
                    <div class="form-group">
                        <label for="subcat-name" class="form-label">Classification Name *</label>
                        <input type="text" id="subcat-name" name="name" class="form-control" required placeholder="e.g. Slim-fit Shirts">
                    </div>
                    <div class="form-group">
                        <label for="subcat-target" class="form-label">Target Group *</label>
                        <select id="subcat-target" name="target_group" class="form-control" required>
                            <option value="Men">Men</option>
                            <option value="Women">Women</option>
                            <option value="Children">Children</option>
                        </select>
                    </div>
                </div>
                <div class="admin-modal-footer">
                    <button type="button" class="btn btn-outline" data-close-modal="modal-subcategory">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Classification</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- MODAL 7: VIEW ORDER & ADVANCE STATUS -->
    <!-- ====================================================================== -->
    <div id="modal-order" class="admin-modal-overlay is-hidden" role="dialog" aria-modal="true" aria-labelledby="modal-order-title">
        <div class="admin-modal-container modal-lg">
            <div class="admin-modal-header">
                <h3 id="modal-order-title" class="admin-modal-title">Order Management</h3>
                <button type="button" class="admin-modal-close" data-close-modal="modal-order" aria-label="Close modal">&times;</button>
            </div>
            <div class="admin-modal-body">
                <div id="order-modal-details" class="mb-4"></div>

                <!-- Status Update Form -->
                <form id="form-update-order-status" class="admin-dossier-card">
                    <input type="hidden" id="order-update-id" name="order_id" value="">
                    <h4 class="admin-dossier-title">Update Fulfillment Status</h4>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="order-status-select" class="form-label">New Status *</label>
                            <select id="order-status-select" name="status" class="form-control" required>
                                <option value="Pending">Pending</option>
                                <option value="In Queue">In Queue</option>
                                <option value="Dispatched">Dispatched</option>
                                <option value="Delivered">Delivered</option>
                                <option value="Returned">Returned</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="order-dispatch-input" class="form-label">Dispatch Date & Time</label>
                            <input type="datetime-local" id="order-dispatch-input" name="dispatch_date" class="form-control">
                            <p class="form-help">Automatically sets to current time when marking as Dispatched.</p>
                        </div>
                    </div>
                    <div class="text-right mt-3">
                        <button type="submit" class="btn btn-primary btn-sm">Save Order Status</button>
                    </div>
                </form>
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal="modal-order">Close</button>
            </div>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- MODAL 8: CUSTOMER DOSSIER -->
    <!-- ====================================================================== -->
    <div id="modal-customer-dossier" class="admin-modal-overlay is-hidden" role="dialog" aria-modal="true" aria-labelledby="modal-customer-dossier-title">
        <div class="admin-modal-container modal-lg">
            <div class="admin-modal-header">
                <h3 id="modal-customer-dossier-title" class="admin-modal-title">Customer Dossier</h3>
                <button type="button" class="admin-modal-close" data-close-modal="modal-customer-dossier" aria-label="Close modal">&times;</button>
            </div>
            <div class="admin-modal-body" id="customer-dossier-content">
                <p class="text-muted text-center py-6">Loading customer dossier...</p>
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal="modal-customer-dossier">Close</button>
            </div>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- MODAL 9: EDIT CUSTOMER DETAILS -->
    <!-- ====================================================================== -->
    <div id="modal-customer-edit" class="admin-modal-overlay is-hidden" role="dialog" aria-modal="true" aria-labelledby="modal-customer-edit-title">
        <div class="admin-modal-container">
            <div class="admin-modal-header">
                <h3 id="modal-customer-edit-title" class="admin-modal-title">Edit Customer Details</h3>
                <button type="button" class="admin-modal-close" data-close-modal="modal-customer-edit" aria-label="Close modal">&times;</button>
            </div>
            <form id="form-edit-customer">
                <input type="hidden" id="cust-edit-id" name="customer_id" value="">
                <div class="admin-modal-body">
                    <div class="form-group">
                        <label for="cust-edit-name" class="form-label">Full Name *</label>
                        <input type="text" id="cust-edit-name" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="cust-edit-email" class="form-label">Email Address *</label>
                        <input type="email" id="cust-edit-email" name="email" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="cust-edit-phone" class="form-label">Phone Number</label>
                        <input type="text" id="cust-edit-phone" name="phone" class="form-control">
                    </div>
                    <p class="form-help">Passwords are cryptographically secured and strictly omitted for customer privacy.</p>
                </div>
                <div class="admin-modal-footer">
                    <button type="button" class="btn btn-outline" data-close-modal="modal-customer-edit">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- MODAL 10: PROCESS RETURN REQUEST & RESTOCK -->
    <!-- ====================================================================== -->
    <div id="modal-return" class="admin-modal-overlay is-hidden" role="dialog" aria-modal="true" aria-labelledby="modal-return-title">
        <div class="admin-modal-container">
            <div class="admin-modal-header">
                <h3 id="modal-return-title" class="admin-modal-title">Process Return Request</h3>
                <button type="button" class="admin-modal-close" data-close-modal="modal-return" aria-label="Close modal">&times;</button>
            </div>
            <form id="form-process-return">
                <input type="hidden" id="ret-process-id" name="return_id" value="">
                <div class="admin-modal-body">
                    <div id="return-modal-summary" class="admin-dossier-card mb-3"></div>

                    <div class="form-group">
                        <label class="form-label">Decision *</label>
                        <div class="d-flex gap-4">
                            <label class="radio-label">
                                <input type="radio" name="decision" value="Approved" required checked>
                                <span><strong>Approve & Restock Inventory</strong></span>
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="decision" value="Rejected" required>
                                <span><strong>Reject Return</strong></span>
                            </label>
                        </div>
                        <p class="form-help">Approving marks the order as Returned and automatically increments inventory stock counts in MySQL.</p>
                    </div>

                    <div class="form-group">
                        <label for="ret-admin-notes" class="form-label">Admin Notes</label>
                        <textarea id="ret-admin-notes" name="admin_notes" class="form-control" rows="3" placeholder="Enter notes regarding return verification or customer feedback..."></textarea>
                    </div>
                </div>
                <div class="admin-modal-footer">
                    <button type="button" class="btn btn-outline" data-close-modal="modal-return">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btn-submit-return-decision">Confirm Decision</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Core Frontend Scripts -->
    <script src="/frontend/js/main.js"></script>
</body>
</html>
