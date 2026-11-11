# StyleHub — Full-Stack E-Commerce Web Application

**StyleHub** is a complete, secure, responsive full-stack clothing-store website built with modern HTML5, CSS3, JavaScript, PHP 8+ using PDO, and MySQL. It features an architectural green, white, grey, and black aesthetic, strict session-based authentication, prepared statements, CSRF protection, and role-based access control.

---

## 🚀 Technology Stack
- **Frontend**: HTML5, CSS3, JavaScript (Vanilla ES6+). Clean separation of concerns with **zero inline CSS**.
- **Backend**: PHP 8.3+ with PDO (Prepared Statements).
- **Database**: MySQL / MariaDB (`stylehub_db`).
- **Security**: 
  - Password hashing with Bcrypt (`password_hash()`, `password_verify()`).
  - Cryptographic CSRF tokens for all state-changing actions (`POST`, `PUT`, `DELETE`).
  - Strict role-based session isolation (public customers cannot access admin portals).
  - SQL injection immunity via parameterized PDO queries.
  - Stock quantity privacy (exact quantities are strictly hidden from customers; only “Low in Stock” badge exposed).
  - Admin return restocking automation (stock quantities are automatically credited back to MySQL inventory upon approval).

---

## 🛠️ Complete Admin Management System (Phase 4)
- **Live 13 MySQL Statistics**: Real-time store analytics tracking Total Products, In-Stock, Low-Stock Warnings, Out-of-Stock, Orders by Status (Pending, In Queue, Dispatched, Delivered, Returned), Total Returns, Pending Returns, Distinct Product Colours, Customer Reviews, and Active Live Product Viewers.
- **Product Catalog Control**: Add, edit, and delete products; adjust inventory counts; set low-stock reorder thresholds; toggle featured flags; control out-of-stock public visibility; assign custom manual marketing messages (e.g., *"New winter collection"* or *"Limited edition"*).
- **Media & Variants**: Direct product image uploads (JPEG, PNG, WEBP up to 5MB) with automated primary photo replacement; manage colour swatches and sizing specifications.
- **Categories & Classifications**: Create and rename categories with automated Men, Women, and Children classifications; create custom subcategories; safe cascade validation before category deletion.
- **Customer Directory & Security**: Customer directory with multi-field search; view detailed customer dossiers (addresses, order history, reviews, returns); one-click account activation / deactivation; edit contact details. Customer passwords are strictly excluded from all administrative views.
- **Order Fulfillment Pipeline**: View full order details, shipping destinations, and item breakdowns; advance order statuses (`Pending` ➔ `In Queue` ➔ `Dispatched` ➔ `Delivered` ➔ `Returned`); assign delivery dispatch timestamps.
- **Return Processing & Automated Inventory Restocking**: Review customer return requests with order details and customer reasoning; approve or reject returns with administrative notes; **approving a return automatically increments exact product quantities back into MySQL inventory**.

---

## 🔐 Sample Demo Accounts

### 👑 Administrator Account
- **Portal URL**: `http://127.0.0.1:8000/backend/admin/login.php`
- **Username**: `admin`
- **Email**: `admin@stylehub.local`
- **Password**: `Admin@StyleHub2026!`
- **Role**: Superadmin (Access to live statistics, catalog overview, role-gated admin portal)

### 🛍️ Customer Accounts
- **Storefront Login URL**: `http://127.0.0.1:8000/frontend/login.html`
- **Account 1**:
  - **Email**: `customer@stylehub.local`
  - **Password**: `Customer@StyleHub2026!`
  - **Name**: Jane Doe
  - **Phone**: +1 (555) 019-2834
- **Account 2**:
  - **Email**: `alex@stylehub.local`
  - **Password**: `Customer@StyleHub2026!`
  - **Name**: Alex Morgan

---

## 🌐 Local URLs

| Section | Local URL | Access Level | Description |
|---|---|---|---|
| **Public Storefront** | `http://127.0.0.1:8000/` or `http://127.0.0.1:8000/frontend/index.html` | Public | Hero banner, categories, product sections |
| **Product Catalog** | `http://127.0.0.1:8000/frontend/products.html` | Public | Multi-filter search, categories, sorting |
| **Product Details** | `http://127.0.0.1:8000/frontend/product-details.html?id=1` | Public | Photo gallery, colours, sizes, live viewers, reviews |
| **Shopping Bag (Cart)** | `http://127.0.0.1:8000/frontend/cart.html` | Public / Customer | Add, update quantity, remove, shipping rules, totals |
| **Saved Wishlist** | `http://127.0.0.1:8000/frontend/wishlist.html` | Public / Customer | Save favourites, move to bag, stock privacy |
| **Delivery Addresses** | `http://127.0.0.1:8000/frontend/addresses.html` | Customer Session | Add, edit, delete, set default address |
| **Secure Checkout** | `http://127.0.0.1:8000/frontend/checkout.html` | Public / Customer | Address selection, Cash on Delivery demo, order placement |
| **Order History** | `http://127.0.0.1:8000/frontend/order-history.html` | Customer Session | Past orders, totals, status badges, return requests |
| **Order Tracking** | `http://127.0.0.1:8000/frontend/order-tracking.html` | Public / Customer | 4-step progress timeline, dispatch date, returns |
| **Customer Login** | `http://127.0.0.1:8000/frontend/login.html` | Public | Customer session authentication |
| **Customer Register** | `http://127.0.0.1:8000/frontend/register.html` | Public | Customer account registration |
| **Customer Profile** | `http://127.0.0.1:8000/frontend/profile.html` | Customer Session | Customer details and session verification |
| **Admin Login** | `http://127.0.0.1:8000/backend/admin/login.php` | Admins Only | Dedicated admin portal (no public nav link) |
| **Admin Dashboard** | `http://127.0.0.1:8000/backend/admin/dashboard.php` | Admins Only | Protected live metrics & management portal |
| **Access Denied (403)** | `http://127.0.0.1:8000/frontend/403.html` | Public | Forbidden / role restriction error page |
| **Not Found (404)** | `http://127.0.0.1:8000/frontend/404.html` | Public | Standard 404 page |
| **Server Error (500)** | `http://127.0.0.1:8000/frontend/500.html` | Public | Standard 500 error page |
| **API Health Check** | `http://127.0.0.1:8000/backend/api/health.php` | Public Diagnostic | Operational status & MySQL health |

---

## 🧪 Automated Verification Test Suites

Run any of the automated test suites using the PHP CLI:

```bash
# 1. Collective Master Verification (All 5 Phases: 95 tests)
php backend/test_all_phases.php

# 2. Phase 5 Security, APIs & QA Suite (46 tests)
php backend/test_phase5.php

# 3. Phase 4 Admin Management Suite (46 tests)
php backend/test_phase4.php

# 4. Phase 3 Commerce, Cart & Checkout Suite (45 tests)
php backend/test_phase3.php

# 5. Phase 2 Storefront, Catalog & SEO Suite (32 tests)
php backend/test_phase2.php

# 6. Phase 1 Core & Authentication Suite (41 tests)
php backend/test_phase1.php
```

---

## ⚙️ Setup and Running Instructions

### 1. Database Setup
MySQL / MariaDB runs on `127.0.0.1:3306`. Run the automated migration script to create `stylehub_db` and seed initial tables:
```bash
php database/migrate.php
```

### 2. Start the Local Web Server
Start the PHP built-in server with the provided router:
```bash
php -S 127.0.0.1:8000 router.php
```

### 3. Open in Browser
- Storefront: `http://127.0.0.1:8000/frontend/index.html`
- Shopping Bag: `http://127.0.0.1:8000/frontend/cart.html`
- Customer Login: `http://127.0.0.1:8000/frontend/login.html`
- Admin Portal: `http://127.0.0.1:8000/backend/admin/login.php`
