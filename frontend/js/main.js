/**
 * StyleHub - Core Frontend JavaScript Application
 * Manages CSRF tokens, session state, navigation, auth forms, UI states,
 * dynamic product rendering, category navigation, live filters, and review submission.
 */

'use strict';

window.StyleHub = window.StyleHub || {};
(function (StyleHub) {
    let csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || null;
    let currentUser = null;

    /**
     * Fetch and cache CSRF token from backend
     */
    async function getCsrfToken() {
        if (csrfToken) return csrfToken;

        const metaTag = document.querySelector('meta[name="csrf-token"]');
        if (metaTag && metaTag.getAttribute('content')) {
            csrfToken = metaTag.getAttribute('content');
            return csrfToken;
        }

        try {
            const res = await fetch('/backend/auth/csrf.php', {
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();
            if (data.success && data.data && data.data.csrf_token) {
                csrfToken = data.data.csrf_token;
                document.querySelectorAll('input[name="csrf_token"]').forEach(input => {
                    input.value = csrfToken;
                });
            }
        } catch (err) {
            console.warn('Failed to retrieve CSRF token:', err);
        }
        return csrfToken || '';
    }

    /**
     * Check current customer session status and update public header
     */
    async function checkAuthStatus() {
        try {
            const res = await fetch('/backend/auth/me.php', {
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();

            if (data.success && data.data) {
                currentUser = data.data.user;
                renderNavAuth(currentUser);
            }
        } catch (err) {
            console.warn('Failed to check auth status:', err);
        }
    }

    /**
     * Update navigation depending on whether customer is logged in or guest
     */
    function renderNavAuth(user) {
        const authContainer = document.getElementById('nav-auth-container');
        if (!authContainer) return;

        if (user) {
            authContainer.innerHTML = `
                <a href="/frontend/profile.html" class="nav-action-btn" title="View Account">
                    <span>👤</span>
                    <span>${escapeHtml(user.name.split(' ')[0])}</span>
                </a>
                <button type="button" id="customer-logout-btn" class="nav-action-btn" title="Sign Out">
                    <span>🚪</span>
                    <span>Logout</span>
                </button>
            `;

            const logoutBtn = document.getElementById('customer-logout-btn');
            if (logoutBtn) {
                logoutBtn.addEventListener('click', handleLogout);
            }
        } else {
            authContainer.innerHTML = `
                <a href="/frontend/login.html" class="nav-action-btn">
                    <span>👤</span>
                    <span>Login</span>
                </a>
            `;
        }
    }

    /**
     * Customer Logout handler
     */
    async function handleLogout(e) {
        if (e) e.preventDefault();
        try {
            const token = await getCsrfToken();
            const res = await fetch('/backend/auth/logout.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token || ''
                },
                body: JSON.stringify({ csrf_token: token })
            });
            const data = await res.json();
            if (data.success) {
                window.location.href = data.data?.redirect || '/frontend/login.html';
            } else {
                window.location.href = '/frontend/login.html';
            }
        } catch (err) {
            window.location.href = '/frontend/login.html';
        }
    }

    /**
     * Render stars helper
     */
    function renderStars(rating) {
        const full = Math.round(rating);
        let stars = '';
        for (let i = 1; i <= 5; i++) {
            stars += i <= full ? '★' : '☆';
        }
        return stars;
    }

    /**
     * Product Card HTML Generator
     * Strictly enforces that exact stock quantity is NEVER displayed.
     * Shows "Low in Stock" badge only when is_low_stock is true.
     */
    function createProductCard(p) {
        const lowStockBadge = p.is_low_stock 
            ? `<span class="badge badge-low-stock">Low in Stock</span>` 
            : '';

        const manualBadge = p.manual_badge_message 
            ? `<span class="badge badge-dark">${escapeHtml(p.manual_badge_message)}</span>` 
            : '';

        const swatches = (p.colours || []).map(c => 
            `<span class="swatch-dot" data-colour="${escapeHtml(c.name)}" title="${escapeHtml(c.name)}"></span>`
        ).join('');

        const targetBadge = p.target_group 
            ? `<span class="badge badge-info">${escapeHtml(p.target_group)}</span>` 
            : '';

        return `
            <article class="product-card" data-product-id="${p.id}">
                <div class="product-media">
                    <img src="${escapeHtml(p.primary_image)}" alt="${escapeHtml(p.primary_image_alt || p.name)}" class="product-image" loading="lazy">
                    <div class="product-badge-group">
                        ${manualBadge}
                        ${lowStockBadge}
                    </div>
                    <button type="button" class="wishlist-btn" aria-label="Save to Wishlist" onclick="StyleHub.toggleWishlist(${p.id}, this)">
                        ♡
                    </button>
                </div>
                <div class="product-content">
                    <div class="product-meta-row">
                        <span class="product-category-tag">${escapeHtml(p.category_name)}</span>
                        ${targetBadge}
                    </div>
                    <h3 class="product-title">
                        <a href="/frontend/product-details.html?id=${p.id}">${escapeHtml(p.name)}</a>
                    </h3>
                    <div class="product-rating" aria-label="Rated ${p.avg_rating} out of 5 stars">
                        <span class="star-rating">${renderStars(p.avg_rating)}</span>
                        <span class="review-count">(${p.review_count})</span>
                    </div>
                    ${swatches ? `<div class="product-swatches" aria-label="Available Colours">${swatches}</div>` : ''}
                    <div class="product-bottom-row">
                        <span class="product-price">$${Number(p.price).toFixed(2)}</span>
                        <div class="product-actions-btn-group">
                            <a href="/frontend/product-details.html?id=${p.id}" class="btn btn-outline btn-sm">Details</a>
                            <button type="button" class="btn btn-primary btn-sm" onclick="StyleHub.addToCart(${p.id}, this)">Add</button>
                        </div>
                    </div>
                </div>
            </article>
        `;
    }

    /**
     * Helper: render skeleton cards
     */
    function getSkeletonCards(count = 4) {
        let html = '';
        for (let i = 0; i < count; i++) {
            html += `
                <div class="skeleton-card">
                    <div class="skeleton-media"></div>
                    <div class="skeleton-line skeleton-title"></div>
                    <div class="skeleton-line skeleton-meta"></div>
                    <div class="skeleton-line skeleton-price"></div>
                </div>
            `;
        }
        return html;
    }

    /**
     * Initialize Public Homepage Featured Products (Showing only 6–8 curated products)
     */
    async function initHomePage() {
        const sectionsContainer = document.getElementById('home-product-sections');
        if (!sectionsContainer) return;

        const featuredGrid = document.getElementById('home-featured-grid');

        try {
            // Fetch 8 curated / top products for homepage showcase
            const res = await fetch('/backend/api/products.php?limit=8&sort=most_popular');
            const json = await res.json();
            const products = json.data?.products || [];

            const targetGrid = featuredGrid || sectionsContainer;

            if (products.length === 0) {
                targetGrid.innerHTML = `
                    <div class="empty-state col-span-full">
                        <p class="empty-state-text">No featured products currently available.</p>
                        <a href="/frontend/products.html" class="btn btn-primary mt-4">View All Products</a>
                    </div>
                `;
            } else {
                targetGrid.innerHTML = products.slice(0, 8).map(createProductCard).join('');
            }
        } catch (err) {
            console.error('Failed to load featured homepage products:', err);
        }
    }

    async function loadSectionProducts(categorySlug, targetGroup = '') {
        const grid = document.getElementById(`grid-${categorySlug}`);
        if (!grid) return;

        try {
            const url = `/backend/api/products.php?category=${encodeURIComponent(categorySlug)}${targetGroup ? `&target_group=${encodeURIComponent(targetGroup)}` : ''}&limit=4`;
            const res = await fetch(url);
            const json = await res.json();
            const products = json.data?.products || [];

            if (products.length === 0) {
                grid.innerHTML = `
                    <div class="empty-state empty-state-compact">
                        <p class="empty-state-text">No items currently available in this subcategory.</p>
                    </div>
                `;
            } else {
                grid.innerHTML = products.map(createProductCard).join('');
            }
        } catch (err) {
            console.error('Error loading section products:', err);
        }
    }

    function filterSection(catSlug, targetGroup) {
        const tabsContainer = document.getElementById(`cat-tabs-${catSlug}`);
        if (tabsContainer) {
            tabsContainer.querySelectorAll('.filter-pill').forEach(btn => {
                const isMatch = (targetGroup === '' && btn.innerText.trim() === 'All') ||
                                (btn.innerText.trim() === targetGroup);
                btn.classList.toggle('active', isMatch);
            });
        }
        loadSectionProducts(catSlug, targetGroup);
    }

    /**
     * Initialize Products Catalog Page with Live Filters
     */
    async function initProductsPage() {
        const catalogContainer = document.getElementById('catalog-products-grid');
        if (!catalogContainer) return;

        const resultsCountEl = document.getElementById('results-count');
        const searchInput    = document.getElementById('filter-search');
        const minPriceInput  = document.getElementById('filter-min-price');
        const maxPriceInput  = document.getElementById('filter-max-price');
        const sortSelect     = document.getElementById('filter-sort');
        const resetFiltersBtn = document.getElementById('reset-filters-btn');

        // URL Parameters
        const urlParams = new URLSearchParams(window.location.search);
        let currentCategory = urlParams.get('category') || '';
        let currentTarget   = urlParams.get('target_group') || '';
        let currentColour   = urlParams.get('colour') || '';
        let currentSearch   = urlParams.get('search') || '';

        if (searchInput && currentSearch) searchInput.value = currentSearch;

        // Sync initial active state of filter pills from query parameters
        if (currentCategory) {
            document.querySelectorAll('.cat-filter-btn').forEach(b => {
                b.classList.toggle('active', (b.dataset.category || '') === currentCategory);
            });
        }
        if (currentTarget) {
            document.querySelectorAll('.target-filter-btn').forEach(b => {
                b.classList.toggle('active', (b.dataset.target || '') === currentTarget);
            });
        }
        if (currentColour) {
            document.querySelectorAll('.colour-filter-btn').forEach(b => {
                b.classList.toggle('active', (b.dataset.colour || '') === currentColour);
            });
        }

        async function fetchProducts() {
            catalogContainer.innerHTML = getSkeletonCards(8);

            const params = new URLSearchParams();
            if (searchInput && searchInput.value.trim()) params.set('search', searchInput.value.trim());
            if (currentCategory) params.set('category', currentCategory);
            if (currentTarget) params.set('target_group', currentTarget);
            if (currentColour) params.set('colour', currentColour);
            if (minPriceInput && minPriceInput.value) params.set('min_price', minPriceInput.value);
            if (maxPriceInput && maxPriceInput.value) params.set('max_price', maxPriceInput.value);
            if (sortSelect && sortSelect.value) params.set('sort', sortSelect.value);

            try {
                const res = await fetch(`/backend/api/products.php?${params.toString()}`);
                const json = await res.json();
                const products = json.data?.products || [];

                if (resultsCountEl) {
                    resultsCountEl.innerText = `${products.length} product${products.length === 1 ? '' : 's'} found`;
                }

                if (products.length === 0) {
                    catalogContainer.innerHTML = `
                        <div class="empty-state col-span-full">
                            <div class="empty-state-icon">🔍</div>
                            <h2 class="empty-state-title">No products found</h2>
                            <p class="empty-state-text">Try adjusting your filters, price range, or search keywords.</p>
                            <button type="button" class="btn btn-primary" onclick="StyleHub.resetFilters()">Clear All Filters</button>
                        </div>
                    `;
                } else {
                    catalogContainer.innerHTML = products.map(createProductCard).join('');
                }
            } catch (err) {
                catalogContainer.innerHTML = `
                    <div class="alert alert-error col-span-full">Failed to load catalog products.</div>
                `;
            }
        }

        // Debounced search
        let searchTimer = null;
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(fetchProducts, 350);
            });
        }

        // Price filters
        if (minPriceInput) minPriceInput.addEventListener('change', fetchProducts);
        if (maxPriceInput) maxPriceInput.addEventListener('change', fetchProducts);

        // Sorting
        const sortSelectMobile = document.getElementById('filter-sort-mobile');
        if (sortSelect) {
            sortSelect.addEventListener('change', () => {
                if (sortSelectMobile) sortSelectMobile.value = sortSelect.value;
                fetchProducts();
            });
        }
        if (sortSelectMobile) {
            sortSelectMobile.addEventListener('change', () => {
                if (sortSelect) sortSelect.value = sortSelectMobile.value;
                fetchProducts();
            });
        }

        // Mobile Filter Drawer Toggles
        const mobileToggleBtn = document.getElementById('mobile-filter-toggle');
        const mobileCloseBtn  = document.getElementById('mobile-filter-close');
        const filterSidebar   = document.querySelector('.filter-sidebar');

        if (mobileToggleBtn && filterSidebar) {
            mobileToggleBtn.addEventListener('click', () => {
                filterSidebar.classList.toggle('is-open');
            });
        }
        if (mobileCloseBtn && filterSidebar) {
            mobileCloseBtn.addEventListener('click', () => {
                filterSidebar.classList.remove('is-open');
            });
        }

        // Category pills
        document.querySelectorAll('.cat-filter-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.cat-filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentCategory = this.dataset.category || '';
                fetchProducts();
            });
        });

        // Target group subcategories (Men, Women, Children)
        document.querySelectorAll('.target-filter-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.target-filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentTarget = this.dataset.target || '';
                fetchProducts();
            });
        });

        // Colour options filter
        document.querySelectorAll('.colour-filter-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.colour-filter-btn').forEach(b => b.classList.remove('active'));
                if (currentColour === this.dataset.colour) {
                    currentColour = '';
                } else {
                    this.classList.add('active');
                    currentColour = this.dataset.colour || '';
                }
                fetchProducts();
            });
        });

        if (resetFiltersBtn) {
            resetFiltersBtn.addEventListener('click', resetFilters);
        }

        function resetFilters() {
            if (searchInput) searchInput.value = '';
            if (minPriceInput) minPriceInput.value = '';
            if (maxPriceInput) maxPriceInput.value = '';
            if (sortSelect) sortSelect.value = 'newest';
            currentCategory = '';
            currentTarget = '';
            currentColour = '';
            document.querySelectorAll('.cat-filter-btn, .target-filter-btn, .colour-filter-btn').forEach(b => b.classList.remove('active'));
            const allCat = document.querySelector('.cat-filter-btn[data-category=""]');
            if (allCat) allCat.classList.add('active');
            const allTarget = document.querySelector('.target-filter-btn[data-target=""]');
            if (allTarget) allTarget.classList.add('active');
            fetchProducts();
        }

        // Expose reset to window
        StyleHub.resetFilters = resetFilters;

        // Initial fetch
        await fetchProducts();
    }

    /**
     * Initialize Product Details Page
     */
    async function initProductDetailsPage() {
        const container = document.getElementById('product-detail-view');
        if (!container) return;

        const urlParams = new URLSearchParams(window.location.search);
        const prodId   = urlParams.get('id');
        const prodSlug = urlParams.get('slug');

        if (!prodId && !prodSlug) {
            window.location.href = '/frontend/products.html';
            return;
        }

        try {
            const query = prodId ? `id=${prodId}` : `slug=${encodeURIComponent(prodSlug)}`;
            const res = await fetch(`/backend/api/product-detail.php?${query}`);
            const json = await res.json();

            if (!res.ok || !json.success || !json.data?.product) {
                container.innerHTML = `
                    <div class="empty-state">
                        <h2 class="empty-state-title">Product Not Found</h2>
                        <p class="empty-state-text">The requested item could not be retrieved.</p>
                        <a href="/frontend/products.html" class="btn btn-primary">Return to Catalog</a>
                    </div>
                `;
                return;
            }

            const p = json.data.product;

            // Update Page Meta for SEO
            document.title = `${p.name} | StyleHub`;
            const metaDesc = document.querySelector('meta[name="description"]');
            if (metaDesc) metaDesc.setAttribute('content', `${p.name} - ${p.description.substring(0, 150)}...`);

            // Canonical Link
            let canonical = document.querySelector('link[rel="canonical"]');
            if (!canonical) {
                canonical = document.createElement('link');
                canonical.rel = 'canonical';
                document.head.appendChild(canonical);
            }
            canonical.href = `http://127.0.0.1:8000/frontend/product-details.html?id=${p.id}`;

            // Structured Data JSON-LD (SEO)
            const jsonLdScript = document.createElement('script');
            jsonLdScript.type = 'application/ld+json';
            jsonLdScript.textContent = JSON.stringify({
                "@context": "https://schema.org/",
                "@type": "Product",
                "name": p.name,
                "image": (p.images && p.images[0]) ? p.images[0].image_url : '',
                "description": p.description,
                "offers": {
                    "@type": "Offer",
                    "priceCurrency": "USD",
                    "price": p.price,
                    "availability": p.in_stock ? "https://schema.org/InStock" : "https://schema.org/OutOfStock"
                },
                "aggregateRating": {
                    "@type": "AggregateRating",
                    "ratingValue": p.avg_rating,
                    "reviewCount": Math.max(1, p.review_count)
                }
            });
            document.head.appendChild(jsonLdScript);

            // Badges
            const lowStockBadge = p.is_low_stock 
                ? `<span class="badge badge-low-stock">Low in Stock</span>` 
                : '';
            const manualBadge = p.manual_badge_message 
                ? `<span class="badge badge-dark">${escapeHtml(p.manual_badge_message)}</span>` 
                : '';

            // Gallery Thumbnails
            const primaryImg = (p.images && p.images.length > 0) ? p.images[0].image_url : 'https://images.unsplash.com/photo-1542272604-780c96856592?auto=format&fit=crop&w=800&q=80';
            const thumbsHtml = (p.images || []).map((img, idx) => `
                <button type="button" class="thumb-item ${idx === 0 ? 'active' : ''}" onclick="StyleHub.selectThumb('${escapeHtml(img.image_url)}', this)" aria-label="Product thumbnail ${idx + 1}">
                    <img src="${escapeHtml(img.image_url)}" alt="${escapeHtml(img.alt_text || p.name)}" class="thumb-img">
                </button>
            `).join('');

            // Colours
            const coloursHtml = (p.colours || []).map((c, idx) => `
                <button type="button" class="colour-pick-btn ${idx === 0 ? 'active' : ''}" data-colour-id="${c.id}" data-colour="${escapeHtml(c.colour_name)}" title="${escapeHtml(c.colour_name)}" onclick="StyleHub.selectColour(this)" aria-label="${escapeHtml(c.colour_name)}"></button>
            `).join('');

            // Sizes
            const sizesHtml = (p.sizes || []).map((s, idx) => `
                <button type="button" class="size-pick-btn ${idx === 0 ? 'active' : ''}" data-size-id="${s.id}" onclick="StyleHub.selectSize(this)">${escapeHtml(s.size_name)}</button>
            `).join('');

            // Reviews List
            const reviewsHtml = (p.reviews || []).map(r => `
                <div class="review-card">
                    <div class="review-header">
                        <span class="reviewer-name">${escapeHtml(r.author_name)}</span>
                        <span class="review-date">${escapeHtml(r.created_at.split(' ')[0])}</span>
                    </div>
                    <div class="star-rating" aria-label="Rated ${r.rating} stars">${renderStars(r.rating)}</div>
                    ${r.title ? `<strong>${escapeHtml(r.title)}</strong>` : ''}
                    <p class="review-body">${escapeHtml(r.comment)}</p>
                </div>
            `).join('');

            container.innerHTML = `
                <div class="product-detail-layout">
                    <!-- Left: Gallery -->
                    <div class="gallery-container">
                        <div class="gallery-main-view">
                            <img src="${escapeHtml(primaryImg)}" id="main-product-img" alt="${escapeHtml(p.name)}" class="gallery-main-img">
                        </div>
                        <div class="gallery-thumbs-row">
                            ${thumbsHtml}
                        </div>
                    </div>

                    <!-- Right: Info Pane -->
                    <div class="detail-info-pane">
                        <nav class="breadcrumb-nav" aria-label="Breadcrumb">
                            <a href="/frontend/index.html">Home</a>
                            <span>/</span>
                            <a href="/frontend/products.html?category=${p.category_slug}">${escapeHtml(p.category_name)}</a>
                            ${p.target_group ? `<span>/</span><a href="/frontend/products.html?category=${p.category_slug}&target_group=${p.target_group}">${escapeHtml(p.target_group)}</a>` : ''}
                        </nav>

                        <div class="flex gap-2 items-center">
                            ${manualBadge}
                            ${lowStockBadge}
                        </div>

                        <h1 class="detail-title">${escapeHtml(p.name)}</h1>

                        <div class="flex items-center gap-4">
                            <div class="product-rating">
                                <span class="star-rating">${renderStars(p.avg_rating)}</span>
                                <span class="review-count">(${p.review_count} customer reviews)</span>
                            </div>
                            <!-- Live Viewer Counter -->
                            <div class="live-activity-pill">
                                <span class="live-beacon"></span>
                                <span>${p.active_viewers} people viewing this piece</span>
                            </div>
                        </div>

                        <div class="detail-price-row">
                            <span class="detail-price">$${Number(p.price).toFixed(2)}</span>
                        </div>

                        <p class="detail-description">
                            ${escapeHtml(p.description)}
                        </p>

                        <!-- Colour Options -->
                        ${coloursHtml ? `
                            <div>
                                <h3 class="option-section-title">Select Colour</h3>
                                <div class="colour-picker-group">${coloursHtml}</div>
                            </div>
                        ` : ''}

                        <!-- Size Options -->
                        ${sizesHtml ? `
                            <div>
                                <h3 class="option-section-title">Select Size</h3>
                                <div class="size-picker-group">${sizesHtml}</div>
                            </div>
                        ` : ''}

                        <!-- CTA Actions -->
                        <div class="cta-action-row">
                            <button type="button" class="btn btn-primary btn-lg" onclick="StyleHub.addToCart(${p.id}, this)">
                                <span>Add to Shopping Bag</span>
                            </button>
                            <button type="button" class="btn btn-outline btn-lg" onclick="StyleHub.toggleWishlist(${p.id}, this)">
                                <span>♡ Save to Wishlist</span>
                            </button>
                        </div>

                        <!-- Brand Assurance Mini-List -->
                        <div class="product-guarantee-box mt-4">
                            <div class="guarantee-item">
                                <span class="guarantee-icon">🌿</span>
                                <div>
                                    <strong>100% Certified Organic Fabrics</strong>
                                    <span>Responsibly dyed with zero harsh chemical finishes</span>
                                </div>
                            </div>
                            <div class="guarantee-item">
                                <span class="guarantee-icon">🚀</span>
                                <div>
                                    <strong>Complimentary Express Delivery</strong>
                                    <span>Tracked courier dispatch on all orders over $150</span>
                                </div>
                            </div>
                            <div class="guarantee-item">
                                <span class="guarantee-icon">↩️</span>
                                <div>
                                    <strong>30-Day Effortless Returns</strong>
                                    <span>Hassle-free exchanges and instant return requests</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Product Specifications & Dossier Section -->
                <section class="section product-specs-section">
                    <div class="section-header">
                        <div>
                            <span class="eyebrow-tag">Garment Dossier</span>
                            <h2 class="section-title">Design, Fabric & Care Details</h2>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-6">
                        <div class="card spec-card">
                            <div class="card-body">
                                <span class="spec-icon">🧵</span>
                                <h3 class="spec-title">Textile Composition</h3>
                                <p class="text-secondary text-sm mt-2">Woven with long-staple organic cotton yarns and structured virgin wool. Pre-washed for a soft touch and enduring silhouette shape.</p>
                            </div>
                        </div>
                        <div class="card spec-card">
                            <div class="card-body">
                                <span class="spec-icon">📐</span>
                                <h3 class="spec-title">Sizing & Silhouette</h3>
                                <p class="text-secondary text-sm mt-2">Tailored with clean French finishes and modern lines. True to size with a balanced drape suited for daily comfort and formal wear.</p>
                            </div>
                        </div>
                        <div class="card spec-card">
                            <div class="card-body">
                                <span class="spec-icon">🧼</span>
                                <h3 class="spec-title">Garment Longevity</h3>
                                <p class="text-secondary text-sm mt-2">Machine wash cold on gentle cycle with similar shades. Hang dry away from direct heat to preserve fiber integrity.</p>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Reviews Section -->
                <section class="reviews-section" id="reviews-anchor">
                    <div class="section-header">
                        <div>
                            <span class="eyebrow-tag">Verified Feedback</span>
                            <h2 class="section-title">Customer Reviews & Ratings</h2>
                            <p class="section-desc">Average Rating: ${p.avg_rating} out of 5 (${p.review_count} verified reviews)</p>
                        </div>
                    </div>

                    <div class="reviews-grid">
                        <!-- Add Review Form (Protected by CSRF) -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Write a Customer Review</h3>
                            </div>
                            <div class="card-body">
                                <div id="review-alert" class="alert is-hidden" role="alert"></div>

                                <form id="add-review-form" class="auth-form" novalidate>
                                    <input type="hidden" name="csrf_token" value="">
                                    <input type="hidden" name="product_id" value="${p.id}">
                                    <input type="hidden" name="rating" id="review-rating-value" value="5">

                                    <div class="form-group">
                                        <label class="form-label">Star Rating</label>
                                        <div class="star-picker" id="star-picker">
                                            <span class="star-picker-item active" data-val="1">★</span>
                                            <span class="star-picker-item active" data-val="2">★</span>
                                            <span class="star-picker-item active" data-val="3">★</span>
                                            <span class="star-picker-item active" data-val="4">★</span>
                                            <span class="star-picker-item active" data-val="5">★</span>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="review-title" class="form-label">Headline / Title (Optional)</label>
                                        <input type="text" id="review-title" name="title" class="form-input" placeholder="e.g. Exceptional fit and quality">
                                    </div>

                                    <div class="form-group">
                                        <label for="review-comment" class="form-label">Review Details</label>
                                        <textarea id="review-comment" name="comment" class="form-textarea" rows="4" required placeholder="Describe your experience with the craftsmanship, fabric, and styling..."></textarea>
                                    </div>

                                    <button type="submit" class="btn btn-primary btn-block">Publish Review</button>
                                </form>
                            </div>
                        </div>

                        <!-- Existing Reviews List -->
                        <div class="reviews-list-container">
                            ${reviewsHtml || '<p class="text-secondary">Be the first to share your thoughts on this garment.</p>'}
                        </div>
                    </div>
                </section>

                <!-- Related Products Section -->
                <section class="section related-products-section" id="related-products-section">
                    <div class="section-header">
                        <div>
                            <span class="eyebrow-tag">Complete the Look</span>
                            <h2 class="section-title">You May Also Like</h2>
                            <p class="section-desc">Complementary apparel from our ${escapeHtml(p.category_name)} collection</p>
                        </div>
                        <a href="/frontend/products.html?category=${encodeURIComponent(p.category_slug)}" class="link-primary">View Collection →</a>
                    </div>
                    <div class="product-grid" id="related-products-grid">
                        ${getSkeletonCards(4)}
                    </div>
                </section>
            `;

            // Load Related Products dynamically
            try {
                const relRes = await fetch(`/backend/api/products.php?category=${encodeURIComponent(p.category_slug)}&limit=5`);
                const relJson = await relRes.json();
                const relProducts = (relJson.data?.products || []).filter(item => item.id !== p.id).slice(0, 4);
                const relGrid = document.getElementById('related-products-grid');
                if (relGrid && relProducts.length > 0) {
                    relGrid.innerHTML = relProducts.map(createProductCard).join('');
                } else if (relGrid) {
                    const relSection = document.getElementById('related-products-section');
                    if (relSection) relSection.classList.add('is-hidden');
                }
            } catch (err) {
                console.warn('Failed to load related products:', err);
            }

            // Bind image switcher
            StyleHub.selectThumb = function(url, el) {
                const mainImg = document.getElementById('main-product-img');
                if (mainImg) mainImg.src = url;
                document.querySelectorAll('.thumb-item').forEach(t => t.classList.remove('active'));
                if (el) el.classList.add('active');
            };

            StyleHub.selectColour = function(el) {
                document.querySelectorAll('.colour-pick-btn').forEach(c => c.classList.remove('active'));
                if (el) el.classList.add('active');
            };

            StyleHub.selectSize = function(el) {
                document.querySelectorAll('.size-pick-btn').forEach(s => s.classList.remove('active'));
                if (el) el.classList.add('active');
            };

            // Setup star picker interaction
            const starPicker = document.getElementById('star-picker');
            const ratingInput = document.getElementById('review-rating-value');
            if (starPicker && ratingInput) {
                starPicker.querySelectorAll('.star-picker-item').forEach(star => {
                    star.addEventListener('click', function() {
                        const val = parseInt(this.dataset.val, 10);
                        ratingInput.value = val;
                        starPicker.querySelectorAll('.star-picker-item').forEach(s => {
                            const sVal = parseInt(s.dataset.val, 10);
                            s.classList.toggle('active', sVal <= val);
                        });
                    });
                });
            }

            // Setup Review Submission with CSRF
            const reviewForm = document.getElementById('add-review-form');
            if (reviewForm) {
                reviewForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const alertBox = document.getElementById('review-alert');
                    showAlert(alertBox, '', 'hidden');

                    const token = await getCsrfToken();
                    const formData = new FormData(reviewForm);
                    const payload = {
                        product_id: parseInt(formData.get('product_id'), 10),
                        rating: parseInt(formData.get('rating'), 10),
                        title: formData.get('title'),
                        comment: formData.get('comment'),
                        csrf_token: token || formData.get('csrf_token')
                    };

                    try {
                        const res = await fetch('/backend/api/reviews.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-Token': token || ''
                            },
                            body: JSON.stringify(payload)
                        });
                        const data = await res.json();

                        if (res.status === 401) {
                            showAlert(alertBox, 'Please log in to your customer account to submit a review.', 'error');
                        } else if (res.ok && data.success) {
                            showAlert(alertBox, data.message || 'Review submitted successfully!', 'success');
                            setTimeout(() => window.location.reload(), 1200);
                        } else {
                            showAlert(alertBox, data.message || 'Failed to submit review.', 'error');
                        }
                    } catch (err) {
                        showAlert(alertBox, 'A network error occurred. Please try again.', 'error');
                    }
                });
            }

            // Update token input
            getCsrfToken();

        } catch (err) {
            console.error('Error loading product details:', err);
        }
    }

    /**
     * Interactive button helpers (Real async API calls)
     */
    async function addToCart(productId, btn, options = {}) {
        if (!productId) return;

        // Auto-detect selected colour & size if on product detail view
        if (!options.colour_id && !options.size_id) {
            const activeCol = document.querySelector('.colour-pick-btn.active');
            if (activeCol && activeCol.dataset.colourId) {
                options.colour_id = parseInt(activeCol.dataset.colourId, 10);
            }
            const activeSz = document.querySelector('.size-pick-btn.active');
            if (activeSz && activeSz.dataset.sizeId) {
                options.size_id = parseInt(activeSz.dataset.sizeId, 10);
            }
        }

        const originalText = btn ? btn.innerText : '';
        if (btn) {
            btn.disabled = true;
            btn.innerText = 'Adding...';
        }

        try {
            const token = await getCsrfToken();
            const payload = {
                action: 'add',
                product_id: parseInt(productId, 10),
                quantity: options.quantity || 1,
                colour_id: options.colour_id || null,
                size_id: options.size_id || null,
                csrf_token: token
            };

            const res = await fetch('/backend/api/cart.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': token || ''
                },
                body: JSON.stringify(payload)
            });

            const data = await res.json();
            if (res.ok && data.success) {
                if (btn) {
                    btn.innerText = '✓ Added';
                    btn.classList.add('btn-secondary');
                    setTimeout(() => {
                        btn.innerText = originalText;
                        btn.classList.remove('btn-secondary');
                        btn.disabled = false;
                    }, 1400);
                }
            } else {
                if (btn) {
                    btn.innerText = originalText;
                    btn.disabled = false;
                }
                alert(data.message || 'Could not add item to bag.');
            }
        } catch (err) {
            console.error('Error adding to cart:', err);
            if (btn) {
                btn.innerText = originalText;
                btn.disabled = false;
            }
        }
    }

    async function toggleWishlist(productId, btn) {
        if (!productId) return;
        if (btn) btn.disabled = true;

        try {
            const token = await getCsrfToken();
            const res = await fetch('/backend/api/wishlist.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': token || ''
                },
                body: JSON.stringify({
                    action: 'toggle',
                    product_id: parseInt(productId, 10),
                    csrf_token: token
                })
            });

            const data = await res.json();
            if (res.ok && data.success) {
                if (btn) {
                    const inWishlist = data.data?.in_wishlist;
                    btn.classList.toggle('active', !!inWishlist);
                    btn.innerText = inWishlist ? '♥' : '♡';
                }
            }
        } catch (err) {
            console.error('Error toggling wishlist:', err);
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    /**
     * Initialize customer login form
     */
    function initLoginForm() {
        const form = document.getElementById('customer-login-form');
        if (!form) return;

        const alertBox = document.getElementById('login-alert');

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            showAlert(alertBox, '', 'hidden');

            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn ? submitBtn.innerText : 'Sign In';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerText = 'Signing in...';
            }

            try {
                const token = await getCsrfToken();
                const formData = new FormData(form);
                const payload = {
                    email: formData.get('email'),
                    password: formData.get('password'),
                    csrf_token: token || formData.get('csrf_token')
                };

                const res = await fetch('/backend/auth/login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': token || ''
                    },
                    body: JSON.stringify(payload)
                });

                const data = await res.json();

                if (res.ok && data.success) {
                    showAlert(alertBox, data.message || 'Login successful. Redirecting...', 'success');
                    setTimeout(() => {
                        const urlParams = new URLSearchParams(window.location.search);
                        const redirect = urlParams.get('redirect') || data.data?.redirect || '/frontend/index.html';
                        window.location.href = redirect;
                    }, 800);
                } else {
                    showAlert(alertBox, data.message || 'Invalid credentials. Please try again.', 'error');
                }
            } catch (err) {
                showAlert(alertBox, 'A network error occurred. Please try again.', 'error');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerText = originalText;
                }
            }
        });
    }

    /**
     * Initialize customer register form
     */
    function initRegisterForm() {
        const form = document.getElementById('customer-register-form');
        if (!form) return;

        const alertBox = document.getElementById('register-alert');

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            showAlert(alertBox, '', 'hidden');

            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn ? submitBtn.innerText : 'Create Account';

            const password = form.querySelector('#reg-password')?.value;
            const confirmPassword = form.querySelector('#reg-confirm-password')?.value;

            if (password !== confirmPassword) {
                showAlert(alertBox, 'Passwords do not match. Please verify both fields.', 'error');
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerText = 'Creating Account...';
            }

            try {
                const token = await getCsrfToken();
                const formData = new FormData(form);
                const payload = {
                    name: formData.get('name'),
                    email: formData.get('email'),
                    phone: formData.get('phone'),
                    password: password,
                    csrf_token: token || formData.get('csrf_token')
                };

                const res = await fetch('/backend/auth/register.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': token || ''
                    },
                    body: JSON.stringify(payload)
                });

                const data = await res.json();

                if (res.ok && data.success) {
                    showAlert(alertBox, data.message || 'Account created successfully! Welcome to StyleHub.', 'success');
                    setTimeout(() => {
                        window.location.href = data.data?.redirect || '/frontend/index.html';
                    }, 1000);
                } else {
                    showAlert(alertBox, data.message || 'Registration failed. Please check the fields.', 'error');
                }
            } catch (err) {
                showAlert(alertBox, 'A network error occurred. Please try again.', 'error');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerText = originalText;
                }
            }
        });
    }

    /**
     * Complete Admin Management System (Phase 4)
     * Real-time metrics, product CRUD, inventory adjustments, low-stock threshold,
     * category & subcategory classification management, customer directory & account control,
     * order status tracking & dispatch dates, returns approval with automated inventory restocking.
     */
    async function initAdminDashboard() {
        const skeleton = document.getElementById('dashboard-skeleton');
        const content = document.getElementById('dashboard-content');
        const refreshBtn = document.getElementById('refresh-dashboard-btn');
        const flashBox = document.getElementById('admin-flash-message');

        // Notification Helper
        function showToast(message, type = 'success') {
            if (!flashBox) return;
            const alertClass = (type === 'error') ? 'alert-danger' : 'alert-success';
            flashBox.className = `alert ${alertClass} mb-4`;
            flashBox.innerHTML = `<strong>${type === 'error' ? 'Notice:' : 'Success:'}</strong> ${escapeHtml(message)}`;
            flashBox.classList.remove('is-hidden');
            setTimeout(() => {
                flashBox.classList.add('is-hidden');
            }, 4500);
        }

        // Generic Modal Helpers
        function openModal(id) {
            const m = document.getElementById(id);
            if (m) m.classList.remove('is-hidden');
        }

        function closeModal(id) {
            const m = document.getElementById(id);
            if (m) m.classList.add('is-hidden');
        }

        // Wire generic close buttons and backdrop clicks
        document.querySelectorAll('[data-close-modal]').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-close-modal');
                if (targetId) closeModal(targetId);
            });
        });

        document.querySelectorAll('.admin-modal-overlay').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.add('is-hidden');
                }
            });
        });

        // =====================================================================
        // TAB SWITCHING LOGIC
        // =====================================================================
        const sidebarNav = document.getElementById('admin-sidebar-nav');
        if (sidebarNav) {
            sidebarNav.querySelectorAll('button[data-tab]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const tab = btn.getAttribute('data-tab');
                    if (!tab) return;

                    sidebarNav.querySelectorAll('button[data-tab]').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');

                    document.querySelectorAll('.admin-tab-pane').forEach(p => p.classList.remove('active'));
                    const targetPane = document.getElementById(`pane-${tab}`);
                    if (targetPane) targetPane.classList.add('active');

                    // Load tab-specific data
                    if (tab === 'overview') loadStats();
                    else if (tab === 'notifications') loadAdminNotifications();
                    else if (tab === 'products') loadAdminProducts();
                    else if (tab === 'categories') loadAdminCategories();
                    else if (tab === 'orders') loadAdminOrders();
                    else if (tab === 'customers') loadAdminCustomers();
                    else if (tab === 'returns') loadAdminReturns();
                });
            });

            // Handle URL tab switching (e.g. ?tab=notifications or #tab-notifications)
            const urlTabParams = new URLSearchParams(window.location.search);
            const requestedTab = urlTabParams.get('tab') || (window.location.hash ? window.location.hash.replace('#tab-', '').replace('#', '') : null);
            if (requestedTab) {
                setTimeout(() => {
                    const targetBtn = sidebarNav.querySelector(`button[data-tab="${requestedTab}"]`);
                    if (targetBtn) targetBtn.click();
                }, 60);
            }
        }

        // =====================================================================
        // TAB 1: OVERVIEW & 13 LIVE MYSQL STATISTICS
        // =====================================================================
        async function loadStats() {
            if (skeleton) skeleton.classList.remove('is-hidden');
            if (content) content.classList.add('is-hidden');

            try {
                const res = await fetch('/backend/admin/stats.php', {
                    headers: { 'Accept': 'application/json' }
                });

                if (res.status === 403 || res.status === 401) {
                    window.location.href = '/backend/admin/login.php';
                    return;
                }

                const json = await res.json();
                if (json.success && json.data) {
                    const d = json.data;
                    setText('stat-total-products', d.total_products);
                    setText('stat-in-stock', d.in_stock_products);
                    setText('stat-low-stock', d.low_stock_products);
                    setText('stat-out-of-stock', d.out_of_stock_products);
                    setText('stat-orders-pending', d.orders_pending);
                    setText('stat-orders-in-queue', d.orders_in_queue);
                    setText('stat-orders-dispatched', d.orders_dispatched);
                    setText('stat-orders-delivered', d.orders_delivered);
                    setText('stat-orders-returned', d.orders_returned);
                    setText('stat-pending-returns', d.pending_returns);
                    setText('stat-total-colours', d.total_colours);
                    setText('stat-total-reviews', d.total_reviews);
                    setText('stat-active-viewers', d.active_viewers);

                    if (skeleton) skeleton.classList.add('is-hidden');
                    if (content) content.classList.remove('is-hidden');
                }
            } catch (err) {
                console.error('Error loading admin stats:', err);
                showToast('Failed to refresh dashboard stats', 'error');
            } finally {
                if (skeleton) skeleton.classList.add('is-hidden');
                if (content) content.classList.remove('is-hidden');
            }
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', loadStats);
        }

        // =====================================================================
        // TAB 2: PRODUCTS MANAGEMENT
        // =====================================================================
        const productSearchInput = document.getElementById('admin-product-search');
        const productCategorySelect = document.getElementById('admin-product-category-filter');
        const productResetBtn = document.getElementById('btn-reset-product-filters');
        const productTbody = document.getElementById('admin-products-tbody');
        const productCountLabel = document.getElementById('admin-product-count');
        const openAddProductBtn = document.getElementById('btn-open-add-product');
        const formSaveProduct = document.getElementById('form-save-product');
        const formAdjustStock = document.getElementById('form-adjust-stock');
        const formUploadImage = document.getElementById('form-upload-image');
        const formAddColour = document.getElementById('form-add-colour');
        const formAddSize = document.getElementById('form-add-size');

        let cachedCategories = [];

        async function populateCategoryDropdowns() {
            try {
                const res = await fetch('/backend/api/admin-categories.php');
                const json = await res.json();
                if (json.success && json.data && json.data.categories) {
                    cachedCategories = json.data.categories;

                    // Filter dropdown
                    if (productCategorySelect && productCategorySelect.options.length <= 1) {
                        cachedCategories.forEach(c => {
                            const opt = document.createElement('option');
                            opt.value = c.slug;
                            opt.textContent = c.name;
                            productCategorySelect.appendChild(opt);
                        });
                    }

                    // Add/Edit Product modal category select
                    const modalCatSelect = document.getElementById('prod-category');
                    if (modalCatSelect) {
                        modalCatSelect.innerHTML = '<option value="">Select Category</option>';
                        cachedCategories.forEach(c => {
                            const opt = document.createElement('option');
                            opt.value = c.id;
                            opt.textContent = c.name;
                            modalCatSelect.appendChild(opt);
                        });
                    }
                }
            } catch (err) {
                console.error('Failed to load categories for dropdown:', err);
            }
        }

        // Subcategory select update when category changes in modal
        const modalCatSelect = document.getElementById('prod-category');
        const modalSubcatSelect = document.getElementById('prod-subcategory');
        if (modalCatSelect && modalSubcatSelect) {
            modalCatSelect.addEventListener('change', () => {
                const selectedCatId = parseInt(modalCatSelect.value, 10);
                modalSubcatSelect.innerHTML = '<option value="">General / None</option>';
                const catObj = cachedCategories.find(c => c.id === selectedCatId);
                if (catObj && catObj.subcategories) {
                    catObj.subcategories.forEach(sub => {
                        const opt = document.createElement('option');
                        opt.value = sub.id;
                        opt.textContent = `${sub.name} (${sub.target_group})`;
                        modalSubcatSelect.appendChild(opt);
                    });
                }
            });
        }

        async function loadAdminProducts() {
            if (!productTbody) return;
            await populateCategoryDropdowns();

            const searchVal = productSearchInput ? productSearchInput.value.trim() : '';
            const catVal = productCategorySelect ? productCategorySelect.value.trim() : '';

            productTbody.innerHTML = `<tr><td colspan="8" class="text-center py-6 text-muted">Loading products...</td></tr>`;

            try {
                const query = new URLSearchParams();
                if (searchVal) query.append('search', searchVal);
                if (catVal) query.append('category', catVal);

                const res = await fetch(`/backend/api/admin-products.php?${query.toString()}`);
                const json = await res.json();

                if (!json.success || !json.data) {
                    productTbody.innerHTML = `<tr><td colspan="8" class="text-center py-6 text-danger">Failed to load products.</td></tr>`;
                    return;
                }

                const products = json.data.products || [];
                if (productCountLabel) {
                    productCountLabel.textContent = `Showing ${products.length} product(s)`;
                }

                if (products.length === 0) {
                    productTbody.innerHTML = `<tr><td colspan="8" class="text-center py-6 text-muted">No products found matching criteria.</td></tr>`;
                    return;
                }

                productTbody.innerHTML = products.map(p => {
                    const fallbackImg = 'https://images.unsplash.com/photo-1542272604-780c96856592?auto=format&fit=crop&w=400&q=80';
                    const photoUrl = p.primary_image || fallbackImg;

                    let statusBadge = '';
                    if (p.is_out_of_stock) {
                        statusBadge = `<span class="badge-out-of-stock">Out of Stock</span>`;
                    } else if (p.is_low_stock) {
                        statusBadge = `<span class="badge-low-stock">Low in Stock</span>`;
                    } else {
                        statusBadge = `<span class="badge-in-stock">In Stock</span>`;
                    }

                    const manualBadgeHtml = p.manual_badge_message 
                        ? `<span class="manual-message-chip">${escapeHtml(p.manual_badge_message)}</span>`
                        : `<span class="text-muted text-xs">—</span>`;

                    const subInfo = p.subcategory_name 
                        ? `${escapeHtml(p.category_name)} / ${escapeHtml(p.subcategory_name)} (${p.target_group || ''})`
                        : escapeHtml(p.category_name);

                    return `
                        <tr>
                            <td>
                                <img src="${escapeHtml(photoUrl)}" alt="${escapeHtml(p.name)}" class="admin-thumb" loading="lazy">
                            </td>
                            <td>
                                <div class="admin-product-info">
                                    <span class="admin-product-name">${escapeHtml(p.name)}</span>
                                    <span class="admin-product-sub">${escapeHtml(p.slug)}</span>
                                </div>
                            </td>
                            <td>${subInfo}</td>
                            <td><strong>$${parseFloat(p.price).toFixed(2)}</strong></td>
                            <td>
                                <span class="admin-stock-num">${p.total_stock}</span>
                                <span class="text-xs text-muted block">(Threshold: ${p.low_stock_threshold})</span>
                            </td>
                            <td>${statusBadge}</td>
                            <td>${manualBadgeHtml}</td>
                            <td>
                                <div class="admin-actions-cell">
                                    <button type="button" class="btn-action btn-action-primary" data-action="edit-product" data-id="${p.id}" title="Edit product details">Edit</button>
                                    <button type="button" class="btn-action btn-action-warning" data-action="stock-product" data-id="${p.id}" data-name="${escapeHtml(p.name)}" data-stock="${p.total_stock}" title="Adjust inventory stock">Stock</button>
                                    <button type="button" class="btn-action" data-action="photo-product" data-id="${p.id}" data-name="${escapeHtml(p.name)}" title="Upload photo">Photo</button>
                                    <button type="button" class="btn-action" data-action="variants-product" data-id="${p.id}" data-name="${escapeHtml(p.name)}" title="Manage colours & sizes">Variants</button>
                                    <button type="button" class="btn-action btn-action-danger" data-action="delete-product" data-id="${p.id}" data-name="${escapeHtml(p.name)}" title="Delete product">Delete</button>
                                </div>
                            </td>
                        </tr>
                    `;
                }).join('');

            } catch (err) {
                console.error('Error fetching admin products:', err);
                productTbody.innerHTML = `<tr><td colspan="8" class="text-center py-6 text-danger">Network error loading products.</td></tr>`;
            }
        }

        // Product search & category filter events
        if (productSearchInput) {
            let debounceTimer;
            productSearchInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(loadAdminProducts, 350);
            });
        }

        if (productCategorySelect) {
            productCategorySelect.addEventListener('change', loadAdminProducts);
        }

        if (productResetBtn) {
            productResetBtn.addEventListener('click', () => {
                if (productSearchInput) productSearchInput.value = '';
                if (productCategorySelect) productCategorySelect.value = '';
                loadAdminProducts();
            });
        }

        // Open Add Product Modal
        if (openAddProductBtn) {
            openAddProductBtn.addEventListener('click', () => {
                if (formSaveProduct) formSaveProduct.reset();
                setText('modal-product-title', 'Add New Product');
                setValue('prod-id', '');
                setValue('prod-action', 'create');
                const initStockGroup = document.getElementById('prod-initial-stock-group');
                if (initStockGroup) initStockGroup.classList.remove('is-hidden');
                openModal('modal-product');
            });
        }

        // Delegated clicks in products table (Edit, Stock, Photo, Variants, Delete)
        if (productTbody) {
            productTbody.addEventListener('click', async (e) => {
                const btn = e.target.closest('button[data-action]');
                if (!btn) return;

                const action = btn.getAttribute('data-action');
                const productId = parseInt(btn.getAttribute('data-id'), 10);
                const productName = btn.getAttribute('data-name') || '';

                if (action === 'edit-product') {
                    try {
                        const res = await fetch(`/backend/api/admin-products.php?id=${productId}`);
                        const json = await res.json();
                        if (json.success && json.data && json.data.product) {
                            const p = json.data.product;
                            setText('modal-product-title', `Edit: ${p.name}`);
                            setValue('prod-id', p.id);
                            setValue('prod-action', 'update');
                            setValue('prod-name', p.name);
                            setValue('prod-price', p.price);
                            setValue('prod-category', p.category_id);

                            // Trigger category change to populate subcategories
                            if (modalCatSelect) {
                                modalCatSelect.dispatchEvent(new Event('change'));
                                if (p.subcategory_id) {
                                    setValue('prod-subcategory', p.subcategory_id);
                                }
                            }

                            setValue('prod-description', p.description || '');
                            setValue('prod-threshold', p.low_stock_threshold || 5);
                            setValue('prod-manual-badge', p.manual_badge_message || '');

                            const featCb = document.getElementById('prod-is-featured');
                            if (featCb) featCb.checked = (parseInt(p.is_featured, 10) === 1);

                            const hideCb = document.getElementById('prod-hide-oos');
                            if (hideCb) hideCb.checked = (parseInt(p.hide_when_out_of_stock, 10) === 1);

                            // Hide initial stock field when editing existing product
                            const initStockGroup = document.getElementById('prod-initial-stock-group');
                            if (initStockGroup) initStockGroup.classList.add('is-hidden');

                            openModal('modal-product');
                        }
                    } catch (err) {
                        showToast('Failed to load product details for editing.', 'error');
                    }
                } else if (action === 'stock-product') {
                    const currentStock = btn.getAttribute('data-stock') || '0';
                    setValue('stock-product-id', productId);
                    setValue('stock-qty-input', currentStock);
                    setText('stock-product-name-display', `Product: ${productName}`);
                    openModal('modal-stock');
                } else if (action === 'photo-product') {
                    setValue('img-product-id', productId);
                    setText('img-product-name-display', `Product: ${productName}`);
                    if (formUploadImage) formUploadImage.reset();
                    openModal('modal-image');
                } else if (action === 'variants-product') {
                    setValue('variants-product-id', productId);
                    setText('variants-product-name-display', `Product: ${productName}`);
                    await loadProductVariants(productId);
                    openModal('modal-variants');
                } else if (action === 'delete-product') {
                    if (confirm(`Are you sure you want to remove "${productName}" from the catalog? This action cannot be undone.`)) {
                        try {
                            const csrf = await getCsrfToken();
                            const res = await fetch('/backend/api/admin-products.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-Token': csrf
                                },
                                body: JSON.stringify({
                                    action: 'delete',
                                    product_id: productId,
                                    csrf_token: csrf
                                })
                            });
                            const json = await res.json();
                            if (json.success) {
                                showToast(json.message || 'Product removed successfully.');
                                loadAdminProducts();
                                loadStats();
                            } else {
                                showToast(json.error || 'Failed to delete product.', 'error');
                            }
                        } catch (err) {
                            showToast('Network error deleting product.', 'error');
                        }
                    }
                }
            });
        }

        // Save Product (Create or Update)
        if (formSaveProduct) {
            formSaveProduct.addEventListener('submit', async (e) => {
                e.preventDefault();
                const csrf = await getCsrfToken();
                const formData = new FormData(formSaveProduct);
                const payload = Object.fromEntries(formData.entries());
                payload.csrf_token = csrf;

                try {
                    const res = await fetch('/backend/api/admin-products.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrf
                        },
                        body: JSON.stringify(payload)
                    });
                    const json = await res.json();
                    if (json.success) {
                        showToast(json.message || 'Product saved successfully.');
                        closeModal('modal-product');
                        loadAdminProducts();
                        loadStats();
                    } else {
                        showToast(json.error || 'Failed to save product.', 'error');
                    }
                } catch (err) {
                    showToast('Network error saving product.', 'error');
                }
            });
        }

        // Adjust Stock Form
        if (formAdjustStock) {
            formAdjustStock.addEventListener('submit', async (e) => {
                e.preventDefault();
                const csrf = await getCsrfToken();
                const pid = parseInt(document.getElementById('stock-product-id').value, 10);
                const qty = parseInt(document.getElementById('stock-qty-input').value, 10);

                try {
                    const res = await fetch('/backend/api/admin-products.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrf
                        },
                        body: JSON.stringify({
                            action: 'update_stock',
                            product_id: pid,
                            stock_quantity: qty,
                            csrf_token: csrf
                        })
                    });
                    const json = await res.json();
                    if (json.success) {
                        showToast('Inventory stock updated.');
                        closeModal('modal-stock');
                        loadAdminProducts();
                        loadStats();
                    } else {
                        showToast(json.error || 'Failed to update stock.', 'error');
                    }
                } catch (err) {
                    showToast('Network error updating stock.', 'error');
                }
            });
        }

        // Upload Product Photo Form
        if (formUploadImage) {
            formUploadImage.addEventListener('submit', async (e) => {
                e.preventDefault();
                const csrf = await getCsrfToken();
                const formData = new FormData(formUploadImage);
                formData.append('action', 'upload_image');
                formData.append('csrf_token', csrf);

                try {
                    const res = await fetch('/backend/api/admin-products.php', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-Token': csrf
                        },
                        body: formData
                    });
                    const json = await res.json();
                    if (json.success) {
                        showToast('Photo uploaded and linked successfully.');
                        closeModal('modal-image');
                        loadAdminProducts();
                    } else {
                        showToast(json.error || 'Failed to upload photo.', 'error');
                    }
                } catch (err) {
                    showToast('Network error uploading photo.', 'error');
                }
            });
        }

        // Load & Manage Product Variants (Colours & Sizes)
        async function loadProductVariants(productId) {
            const coloursList = document.getElementById('variants-colours-list');
            const sizesList = document.getElementById('variants-sizes-list');
            if (!coloursList || !sizesList) return;

            coloursList.innerHTML = '<span class="text-muted text-xs">Loading colours...</span>';
            sizesList.innerHTML = '<span class="text-muted text-xs">Loading sizes...</span>';

            try {
                const res = await fetch(`/backend/api/admin-products.php?id=${productId}`);
                const json = await res.json();
                if (json.success && json.data) {
                    const colours = json.data.colours || [];
                    const sizes = json.data.sizes || [];

                    // Render colours
                    if (colours.length === 0) {
                        coloursList.innerHTML = '<span class="text-muted text-xs">No specific colours defined.</span>';
                    } else {
                        coloursList.innerHTML = colours.map(c => `
                            <div class="d-flex align-items-center justify-content-between p-2 border-b">
                                <div>
                                    <span class="colour-pill-preview" data-colour-preview="${escapeHtml(c.colour_code || '#15803d')}"></span>
                                    <strong>${escapeHtml(c.colour_name)}</strong>
                                    <span class="text-xs text-muted">(${escapeHtml(c.colour_code || '')})</span>
                                </div>
                                <button type="button" class="btn-action btn-action-danger text-xs" data-del-colour="${c.id}">Remove</button>
                            </div>
                        `).join('');

                        // Apply background color to swatches without inline style
                        coloursList.querySelectorAll('[data-colour-preview]').forEach(el => {
                            el.style.backgroundColor = el.getAttribute('data-colour-preview');
                        });
                    }

                    // Render sizes
                    if (sizes.length === 0) {
                        sizesList.innerHTML = '<span class="text-muted text-xs">No specific sizes defined.</span>';
                    } else {
                        sizesList.innerHTML = sizes.map(s => `
                            <div class="d-flex align-items-center justify-content-between p-2 border-b">
                                <div>
                                    <strong>${escapeHtml(s.size_name)}</strong>
                                    <span class="text-xs text-muted">(Sort: ${s.sort_order})</span>
                                </div>
                                <button type="button" class="btn-action btn-action-danger text-xs" data-del-size="${s.id}">Remove</button>
                            </div>
                        `).join('');
                    }
                }
            } catch (err) {
                console.error('Error loading variants:', err);
            }
        }

        // Add Colour Form
        if (formAddColour) {
            formAddColour.addEventListener('submit', async (e) => {
                e.preventDefault();
                const pid = parseInt(document.getElementById('variants-product-id').value, 10);
                const cname = document.getElementById('new-colour-name').value.trim();
                const ccode = document.getElementById('new-colour-code').value.trim();
                const csrf = await getCsrfToken();

                try {
                    const res = await fetch('/backend/api/admin-products.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrf
                        },
                        body: JSON.stringify({
                            action: 'add_colour',
                            product_id: pid,
                            colour_name: cname,
                            colour_code: ccode,
                            csrf_token: csrf
                        })
                    });
                    const json = await res.json();
                    if (json.success) {
                        showToast('Colour option added.');
                        document.getElementById('new-colour-name').value = '';
                        await loadProductVariants(pid);
                        loadStats();
                    } else {
                        showToast(json.error || 'Failed to add colour.', 'error');
                    }
                } catch (err) {
                    showToast('Network error adding colour.', 'error');
                }
            });
        }

        // Add Size Form
        if (formAddSize) {
            formAddSize.addEventListener('submit', async (e) => {
                e.preventDefault();
                const pid = parseInt(document.getElementById('variants-product-id').value, 10);
                const sname = document.getElementById('new-size-name').value.trim();
                const sorder = parseInt(document.getElementById('new-size-order').value, 10) || 1;
                const csrf = await getCsrfToken();

                try {
                    const res = await fetch('/backend/api/admin-products.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrf
                        },
                        body: JSON.stringify({
                            action: 'add_size',
                            product_id: pid,
                            size_name: sname,
                            sort_order: sorder,
                            csrf_token: csrf
                        })
                    });
                    const json = await res.json();
                    if (json.success) {
                        showToast('Size option added.');
                        document.getElementById('new-size-name').value = '';
                        await loadProductVariants(pid);
                    } else {
                        showToast(json.error || 'Failed to add size.', 'error');
                    }
                } catch (err) {
                    showToast('Network error adding size.', 'error');
                }
            });
        }

        // Delegated clicks for deleting colour / size
        const variantsModal = document.getElementById('modal-variants');
        if (variantsModal) {
            variantsModal.addEventListener('click', async (e) => {
                const pid = parseInt(document.getElementById('variants-product-id').value, 10);
                const delColBtn = e.target.closest('[data-del-colour]');
                if (delColBtn) {
                    const cid = parseInt(delColBtn.getAttribute('data-del-colour'), 10);
                    const csrf = await getCsrfToken();
                    const res = await fetch('/backend/api/admin-products.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify({ action: 'delete_colour', colour_id: cid, csrf_token: csrf })
                    });
                    const json = await res.json();
                    if (json.success) {
                        showToast('Colour removed.');
                        await loadProductVariants(pid);
                    }
                    return;
                }

                const delSzBtn = e.target.closest('[data-del-size]');
                if (delSzBtn) {
                    const sid = parseInt(delSzBtn.getAttribute('data-del-size'), 10);
                    const csrf = await getCsrfToken();
                    const res = await fetch('/backend/api/admin-products.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify({ action: 'delete_size', size_id: sid, csrf_token: csrf })
                    });
                    const json = await res.json();
                    if (json.success) {
                        showToast('Size removed.');
                        await loadProductVariants(pid);
                    }
                }
            });
        }

        // =====================================================================
        // TAB 3: CATEGORIES & CLASSIFICATIONS
        // =====================================================================
        const categoriesTbody = document.getElementById('admin-categories-tbody');
        const openAddCategoryBtn = document.getElementById('btn-open-add-category');
        const formSaveCategory = document.getElementById('form-save-category');
        const formSaveSubcategory = document.getElementById('form-save-subcategory');

        async function loadAdminCategories() {
            if (!categoriesTbody) return;
            categoriesTbody.innerHTML = `<tr><td colspan="5" class="text-center py-6 text-muted">Loading categories...</td></tr>`;

            try {
                const res = await fetch('/backend/api/admin-categories.php');
                const json = await res.json();

                if (!json.success || !json.data) {
                    categoriesTbody.innerHTML = `<tr><td colspan="5" class="text-center py-6 text-danger">Failed to load categories.</td></tr>`;
                    return;
                }

                const categories = json.data.categories || [];
                if (categories.length === 0) {
                    categoriesTbody.innerHTML = `<tr><td colspan="5" class="text-center py-6 text-muted">No categories defined yet.</td></tr>`;
                    return;
                }

                categoriesTbody.innerHTML = categories.map(c => {
                    const subs = c.subcategories || [];
                    const subPills = subs.map(s => `
                        <span class="filter-pill mr-1 mb-1 inline-flex align-items-center">
                            ${escapeHtml(s.name)} <strong>(${escapeHtml(s.target_group)})</strong>
                            <button type="button" class="btn-action btn-action-danger ml-1 p-0 px-1 text-xs" data-del-subcat="${s.id}" title="Remove classification">&times;</button>
                        </span>
                    `).join('');

                    return `
                        <tr>
                            <td><strong>${escapeHtml(c.name)}</strong></td>
                            <td><code>${escapeHtml(c.slug)}</code></td>
                            <td>
                                <div class="d-flex flex-wrap align-items-center">
                                    ${subPills || '<span class="text-muted text-xs">No subcategories</span>'}
                                </div>
                            </td>
                            <td><span class="badge badge-info">${c.product_count} product(s)</span></td>
                            <td>
                                <div class="admin-actions-cell">
                                    <button type="button" class="btn-action btn-action-primary" data-action="edit-cat" data-id="${c.id}" data-name="${escapeHtml(c.name)}" data-desc="${escapeHtml(c.description || '')}">Edit</button>
                                    <button type="button" class="btn-action" data-action="add-subcat" data-id="${c.id}" data-name="${escapeHtml(c.name)}">+ Classification</button>
                                    <button type="button" class="btn-action btn-action-danger" data-action="delete-cat" data-id="${c.id}" data-name="${escapeHtml(c.name)}">Delete</button>
                                </div>
                            </td>
                        </tr>
                    `;
                }).join('');

            } catch (err) {
                console.error('Error fetching categories:', err);
                categoriesTbody.innerHTML = `<tr><td colspan="5" class="text-center py-6 text-danger">Network error loading categories.</td></tr>`;
            }
        }

        if (openAddCategoryBtn) {
            openAddCategoryBtn.addEventListener('click', () => {
                if (formSaveCategory) formSaveCategory.reset();
                setText('modal-category-title', 'Add New Category');
                setValue('cat-id', '');
                setValue('cat-action', 'create_category');
                const imgGrp = document.getElementById('cat-image-group');
                if (imgGrp) imgGrp.classList.remove('is-hidden');
                openModal('modal-category');
            });
        }

        // Delegated clicks in categories table (Edit, Add Subcategory, Delete)
        if (categoriesTbody) {
            categoriesTbody.addEventListener('click', async (e) => {
                const btn = e.target.closest('button[data-action]');
                const delSubBtn = e.target.closest('[data-del-subcat]');

                if (delSubBtn) {
                    const subId = parseInt(delSubBtn.getAttribute('data-del-subcat'), 10);
                    if (confirm('Delete this classification? Any assigned products will remain safe in this category.')) {
                        const csrf = await getCsrfToken();
                        const res = await fetch('/backend/api/admin-categories.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                            body: JSON.stringify({ action: 'delete_subcategory', subcategory_id: subId, csrf_token: csrf })
                        });
                        const json = await res.json();
                        if (json.success) {
                            showToast('Classification removed safely.');
                            loadAdminCategories();
                        } else {
                            showToast(json.error || 'Failed to remove classification.', 'error');
                        }
                    }
                    return;
                }

                if (!btn) return;
                const action = btn.getAttribute('data-action');
                const catId = parseInt(btn.getAttribute('data-id'), 10);
                const catName = btn.getAttribute('data-name') || '';

                if (action === 'edit-cat') {
                    setText('modal-category-title', `Rename: ${catName}`);
                    setValue('cat-id', catId);
                    setValue('cat-action', 'update_category');
                    setValue('cat-name', catName);
                    setValue('cat-description', btn.getAttribute('data-desc') || '');
                    const imgGrp = document.getElementById('cat-image-group');
                    if (imgGrp) imgGrp.classList.add('is-hidden');
                    openModal('modal-category');
                } else if (action === 'add-subcat') {
                    setText('modal-subcategory-title', `Add Classification to ${catName}`);
                    setValue('subcat-cat-id', catId);
                    if (formSaveSubcategory) formSaveSubcategory.reset();
                    openModal('modal-subcategory');
                } else if (action === 'delete-cat') {
                    if (confirm(`Are you sure you want to delete category "${catName}"? Active products must be reassigned first.`)) {
                        const csrf = await getCsrfToken();
                        const res = await fetch('/backend/api/admin-categories.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                            body: JSON.stringify({ action: 'delete_category', category_id: catId, csrf_token: csrf })
                        });
                        const json = await res.json();
                        if (json.success) {
                            showToast('Category removed safely.');
                            loadAdminCategories();
                        } else {
                            showToast(json.error || 'Failed to delete category.', 'error');
                        }
                    }
                }
            });
        }

        // Save Category Form
        if (formSaveCategory) {
            formSaveCategory.addEventListener('submit', async (e) => {
                e.preventDefault();
                const csrf = await getCsrfToken();
                const formData = new FormData(formSaveCategory);
                const payload = Object.fromEntries(formData.entries());
                payload.csrf_token = csrf;

                try {
                    const res = await fetch('/backend/api/admin-categories.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify(payload)
                    });
                    const json = await res.json();
                    if (json.success) {
                        showToast(json.message || 'Category saved.');
                        closeModal('modal-category');
                        loadAdminCategories();
                    } else {
                        showToast(json.error || 'Failed to save category.', 'error');
                    }
                } catch (err) {
                    showToast('Network error saving category.', 'error');
                }
            });
        }

        // Save Subcategory Form
        if (formSaveSubcategory) {
            formSaveSubcategory.addEventListener('submit', async (e) => {
                e.preventDefault();
                const csrf = await getCsrfToken();
                const formData = new FormData(formSaveSubcategory);
                const payload = Object.fromEntries(formData.entries());
                payload.action = 'create_subcategory';
                payload.csrf_token = csrf;

                try {
                    const res = await fetch('/backend/api/admin-categories.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify(payload)
                    });
                    const json = await res.json();
                    if (json.success) {
                        showToast('Classification created successfully.');
                        closeModal('modal-subcategory');
                        loadAdminCategories();
                    } else {
                        showToast(json.error || 'Failed to create classification.', 'error');
                    }
                } catch (err) {
                    showToast('Network error creating classification.', 'error');
                }
            });
        }

        // =====================================================================
        // TAB 4: ORDERS & DISPATCH TRACKING
        // =====================================================================
        const ordersTbody = document.getElementById('admin-orders-tbody');
        const orderSearchInput = document.getElementById('admin-order-search');
        const orderStatusPills = document.getElementById('admin-order-status-pills');
        const ordersCountLabel = document.getElementById('admin-orders-count');
        const formUpdateOrderStatus = document.getElementById('form-update-order-status');

        let currentOrderStatusFilter = '';

        async function loadAdminOrders() {
            if (!ordersTbody) return;
            ordersTbody.innerHTML = `<tr><td colspan="7" class="text-center py-6 text-muted">Loading orders...</td></tr>`;

            const searchVal = orderSearchInput ? orderSearchInput.value.trim() : '';

            try {
                const query = new URLSearchParams();
                if (currentOrderStatusFilter) query.append('status', currentOrderStatusFilter);
                if (searchVal) query.append('search', searchVal);

                const res = await fetch(`/backend/api/admin-orders.php?${query.toString()}`);
                const json = await res.json();

                if (!json.success || !json.data) {
                    ordersTbody.innerHTML = `<tr><td colspan="7" class="text-center py-6 text-danger">Failed to load orders.</td></tr>`;
                    return;
                }

                const orders = json.data.orders || [];
                if (ordersCountLabel) {
                    ordersCountLabel.textContent = `Showing ${orders.length} order(s)`;
                }

                if (orders.length === 0) {
                    ordersTbody.innerHTML = `<tr><td colspan="7" class="text-center py-6 text-muted">No orders matching criteria.</td></tr>`;
                    return;
                }

                ordersTbody.innerHTML = orders.map(o => {
                    const statusClass = 'badge-' + o.status.toLowerCase().replace(/\s+/g, '-');
                    return `
                        <tr>
                            <td><strong>${escapeHtml(o.order_number)}</strong></td>
                            <td>${escapeHtml(o.created_at.split(' ')[0])}</td>
                            <td>
                                <div><strong>${escapeHtml(o.customer_name)}</strong></div>
                                <div class="text-xs text-muted">${escapeHtml(o.customer_email)}</div>
                            </td>
                            <td><strong>$${parseFloat(o.total_amount).toFixed(2)}</strong></td>
                            <td><span class="badge-status ${statusClass}">${escapeHtml(o.status)}</span></td>
                            <td>${o.dispatch_date ? escapeHtml(o.dispatch_date) : '<span class="text-muted text-xs">—</span>'}</td>
                            <td>
                                <button type="button" class="btn-action btn-action-primary" data-view-order="${o.id}">View & Manage</button>
                            </td>
                        </tr>
                    `;
                }).join('');

            } catch (err) {
                console.error('Error fetching admin orders:', err);
                ordersTbody.innerHTML = `<tr><td colspan="7" class="text-center py-6 text-danger">Network error loading orders.</td></tr>`;
            }
        }

        if (orderStatusPills) {
            orderStatusPills.querySelectorAll('button[data-status]').forEach(btn => {
                btn.addEventListener('click', () => {
                    orderStatusPills.querySelectorAll('button[data-status]').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    currentOrderStatusFilter = btn.getAttribute('data-status') || '';
                    loadAdminOrders();
                });
            });
        }

        if (orderSearchInput) {
            let debounceTimer;
            orderSearchInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(loadAdminOrders, 350);
            });
        }

        // View Order Details & Status update
        if (ordersTbody) {
            ordersTbody.addEventListener('click', async (e) => {
                const btn = e.target.closest('[data-view-order]');
                if (!btn) return;
                const orderId = parseInt(btn.getAttribute('data-view-order'), 10);

                const detailsContainer = document.getElementById('order-modal-details');
                if (detailsContainer) detailsContainer.innerHTML = '<p class="text-muted text-center py-4">Loading order breakdown...</p>';

                openModal('modal-order');

                try {
                    const res = await fetch(`/backend/api/admin-orders.php?id=${orderId}`);
                    const json = await res.json();
                    if (json.success && json.data) {
                        const o = json.data.order;
                        const items = json.data.items || [];
                        const ret = json.data.return_request;

                        setText('modal-order-title', `Order ${o.order_number}`);
                        setValue('order-update-id', o.id);
                        setValue('order-status-select', o.status);
                        setValue('order-dispatch-input', o.dispatch_date ? o.dispatch_date.replace(' ', 'T').substring(0, 16) : '');

                        const itemsRows = items.map(it => `
                            <tr>
                                <td>${escapeHtml(it.product_name)}</td>
                                <td>${escapeHtml(it.colour_name || 'Standard')} / ${escapeHtml(it.size_name || 'Standard')}</td>
                                <td>${it.quantity}</td>
                                <td>$${parseFloat(it.unit_price).toFixed(2)}</td>
                                <td>$${parseFloat(it.total_price).toFixed(2)}</td>
                            </tr>
                        `).join('');

                        let returnBanner = '';
                        if (ret) {
                            returnBanner = `
                                <div class="alert alert-warning mb-3">
                                    <strong>Return Request (${ret.status}):</strong> ${escapeHtml(ret.reason)}
                                    ${ret.details ? `<br><small>${escapeHtml(ret.details)}</small>` : ''}
                                </div>
                            `;
                        }

                        detailsContainer.innerHTML = `
                            ${returnBanner}
                            <div class="admin-dossier-card mb-3">
                                <h4 class="admin-dossier-title">Customer & Destination</h4>
                                <div class="form-grid-2">
                                    <div>
                                        <p class="text-sm"><strong>Recipient:</strong> ${escapeHtml(o.shipping_name || o.customer_name)}</p>
                                        <p class="text-sm"><strong>Email:</strong> ${escapeHtml(o.customer_email || '—')}</p>
                                        <p class="text-sm"><strong>Phone:</strong> ${escapeHtml(o.shipping_phone || o.customer_phone || '—')}</p>
                                    </div>
                                    <div>
                                        <p class="text-sm"><strong>Delivery Address:</strong></p>
                                        <p class="text-sm text-muted">
                                            ${escapeHtml(o.address_line1 || '')}<br>
                                            ${o.address_line2 ? escapeHtml(o.address_line2) + '<br>' : ''}
                                            ${escapeHtml(o.city || '')}, ${escapeHtml(o.state || '')} ${escapeHtml(o.postal_code || '')}<br>
                                            ${escapeHtml(o.country || '')}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="admin-dossier-card">
                                <h4 class="admin-dossier-title">Ordered Items (${items.length})</h4>
                                <table class="admin-table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Variant</th>
                                            <th>Qty</th>
                                            <th>Unit Price</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${itemsRows}
                                    </tbody>
                                </table>
                                <div class="text-right mt-3 font-bold text-base">
                                    Total Amount: $${parseFloat(o.total_amount).toFixed(2)}
                                </div>
                            </div>
                        `;
                    }
                } catch (err) {
                    showToast('Failed to load order details.', 'error');
                }
            });
        }

        // Auto-set dispatch date when Dispatched is selected
        const orderStatusSelect = document.getElementById('order-status-select');
        const orderDispatchInput = document.getElementById('order-dispatch-input');
        if (orderStatusSelect && orderDispatchInput) {
            orderStatusSelect.addEventListener('change', () => {
                if (orderStatusSelect.value === 'Dispatched' && !orderDispatchInput.value) {
                    const now = new Date();
                    const iso = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
                    orderDispatchInput.value = iso;
                }
            });
        }

        // Save Order Status Form
        if (formUpdateOrderStatus) {
            formUpdateOrderStatus.addEventListener('submit', async (e) => {
                e.preventDefault();
                const csrf = await getCsrfToken();
                const orderId = parseInt(document.getElementById('order-update-id').value, 10);
                const status = document.getElementById('order-status-select').value;
                const dispatchVal = document.getElementById('order-dispatch-input').value;

                try {
                    const res = await fetch('/backend/api/admin-orders.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify({
                            order_id: orderId,
                            status: status,
                            dispatch_date: dispatchVal ? dispatchVal.replace('T', ' ') + ':00' : null,
                            csrf_token: csrf
                        })
                    });
                    const json = await res.json();
                    if (json.success) {
                        showToast(json.message || 'Order status updated successfully.');
                        closeModal('modal-order');
                        loadAdminOrders();
                        loadStats();
                    } else {
                        showToast(json.error || 'Failed to update order status.', 'error');
                    }
                } catch (err) {
                    showToast('Network error updating order status.', 'error');
                }
            });
        }

        // =====================================================================
        // TAB 5: CUSTOMER DIRECTORY & ACCOUNT CONTROL
        // =====================================================================
        const customersTbody = document.getElementById('admin-customers-tbody');
        const customerSearchInput = document.getElementById('admin-customer-search');
        const customerResetSearchBtn = document.getElementById('btn-reset-customer-search');
        const customersCountLabel = document.getElementById('admin-customers-count');
        const formEditCustomer = document.getElementById('form-edit-customer');

        async function loadAdminCustomers() {
            if (!customersTbody) return;
            customersTbody.innerHTML = `<tr><td colspan="9" class="text-center py-6 text-muted">Loading customer directory...</td></tr>`;

            const searchVal = customerSearchInput ? customerSearchInput.value.trim() : '';

            try {
                const query = new URLSearchParams();
                if (searchVal) query.append('search', searchVal);

                const res = await fetch(`/backend/api/admin-customers.php?${query.toString()}`);
                const json = await res.json();

                if (!json.success || !json.data) {
                    customersTbody.innerHTML = `<tr><td colspan="9" class="text-center py-6 text-danger">Failed to load customers.</td></tr>`;
                    return;
                }

                const customers = json.data.customers || [];
                if (customersCountLabel) {
                    customersCountLabel.textContent = `Showing ${customers.length} customer(s)`;
                }

                if (customers.length === 0) {
                    customersTbody.innerHTML = `<tr><td colspan="9" class="text-center py-6 text-muted">No customers found.</td></tr>`;
                    return;
                }

                customersTbody.innerHTML = customers.map(c => {
                    const isActive = (c.status === 'active');
                    const badgeClass = isActive ? 'badge-active' : 'badge-inactive';
                    const toggleBtnText = isActive ? 'Deactivate' : 'Activate';
                    const toggleActionClass = isActive ? 'btn-action-danger' : 'btn-action-primary';

                    return `
                        <tr>
                            <td>#${c.id}</td>
                            <td><strong>${escapeHtml(c.name)}</strong></td>
                            <td>${escapeHtml(c.email)}</td>
                            <td>${escapeHtml(c.phone || '—')}</td>
                            <td><span class="badge-status ${badgeClass}">${escapeHtml(c.status.toUpperCase())}</span></td>
                            <td>${c.total_orders}</td>
                            <td>$${parseFloat(c.total_spent).toFixed(2)}</td>
                            <td>${escapeHtml(c.created_at.split(' ')[0])}</td>
                            <td>
                                <div class="admin-actions-cell">
                                    <button type="button" class="btn-action" data-view-cust="${c.id}">Dossier</button>
                                    <button type="button" class="btn-action ${toggleActionClass}" data-toggle-cust="${c.id}" data-status="${c.status}" data-name="${escapeHtml(c.name)}">${toggleBtnText}</button>
                                    <button type="button" class="btn-action btn-action-primary" data-edit-cust="${c.id}" data-name="${escapeHtml(c.name)}" data-email="${escapeHtml(c.email)}" data-phone="${escapeHtml(c.phone || '')}">Edit</button>
                                </div>
                            </td>
                        </tr>
                    `;
                }).join('');

            } catch (err) {
                console.error('Error fetching customers:', err);
                customersTbody.innerHTML = `<tr><td colspan="9" class="text-center py-6 text-danger">Network error loading customer directory.</td></tr>`;
            }
        }

        if (customerSearchInput) {
            let debounceTimer;
            customerSearchInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(loadAdminCustomers, 350);
            });
        }

        if (customerResetSearchBtn) {
            customerResetSearchBtn.addEventListener('click', () => {
                if (customerSearchInput) customerSearchInput.value = '';
                loadAdminCustomers();
            });
        }

        // Delegated clicks for customer actions (Dossier, Toggle Status, Edit)
        if (customersTbody) {
            customersTbody.addEventListener('click', async (e) => {
                const viewBtn = e.target.closest('[data-view-cust]');
                const toggleBtn = e.target.closest('[data-toggle-cust]');
                const editBtn = e.target.closest('[data-edit-cust]');

                if (viewBtn) {
                    const custId = parseInt(viewBtn.getAttribute('data-view-cust'), 10);
                    const container = document.getElementById('customer-dossier-content');
                    if (container) container.innerHTML = '<p class="text-muted text-center py-6">Loading customer dossier...</p>';
                    openModal('modal-customer-dossier');

                    try {
                        const res = await fetch(`/backend/api/admin-customers.php?id=${custId}`);
                        const json = await res.json();
                        if (json.success && json.data) {
                            const c = json.data.customer;
                            const addresses = json.data.addresses || [];
                            const orders = json.data.orders || [];
                            const reviews = json.data.reviews || [];
                            const returns = json.data.returns || [];

                            setText('modal-customer-dossier-title', `Dossier: ${c.name} (#${c.id})`);

                            const addrList = addresses.length === 0 
                                ? '<p class="text-xs text-muted">No saved addresses on file.</p>'
                                : addresses.map(a => `
                                    <div class="border-b py-2 text-sm">
                                        <strong>${escapeHtml(a.full_name)}</strong> ${a.is_default ? '<span class="badge-status badge-active text-xs">Default</span>' : ''}<br>
                                        ${escapeHtml(a.address_line1)}${a.address_line2 ? ', ' + escapeHtml(a.address_line2) : ''}, ${escapeHtml(a.city)}, ${escapeHtml(a.state)} ${escapeHtml(a.postal_code)}<br>
                                        📞 ${escapeHtml(a.phone)}
                                    </div>
                                `).join('');

                            const ordList = orders.length === 0
                                ? '<p class="text-xs text-muted">No orders placed yet.</p>'
                                : orders.map(o => `
                                    <div class="d-flex justify-content-between border-b py-2 text-sm">
                                        <div>
                                            <strong>${escapeHtml(o.order_number)}</strong> (${escapeHtml(o.created_at.split(' ')[0])})
                                        </div>
                                        <div>
                                            <span class="badge-status badge-${o.status.toLowerCase().replace(/\s+/g, '-')} mr-2">${escapeHtml(o.status)}</span>
                                            <strong>$${parseFloat(o.total_amount).toFixed(2)}</strong>
                                        </div>
                                    </div>
                                `).join('');

                            const revList = reviews.length === 0
                                ? '<p class="text-xs text-muted">No customer reviews submitted.</p>'
                                : reviews.map(r => `
                                    <div class="border-b py-2 text-sm">
                                        <div class="d-flex justify-content-between">
                                            <strong>${escapeHtml(r.product_name)}</strong>
                                            <span class="text-amber-500 font-bold">★ ${r.rating}/5</span>
                                        </div>
                                        <p class="text-xs text-muted mt-1">${escapeHtml(r.comment)}</p>
                                    </div>
                                `).join('');

                            const retList = returns.length === 0
                                ? '<p class="text-xs text-muted">No return requests submitted.</p>'
                                : returns.map(ret => `
                                    <div class="border-b py-2 text-sm">
                                        <div class="d-flex justify-content-between">
                                            <strong>Order ${escapeHtml(ret.order_number)}</strong>
                                            <span class="badge-status badge-${ret.status.toLowerCase()}">${escapeHtml(ret.status)}</span>
                                        </div>
                                        <p class="text-xs text-muted mt-1">Reason: ${escapeHtml(ret.reason)}</p>
                                    </div>
                                `).join('');

                            container.innerHTML = `
                                <div class="admin-dossier-card mb-3">
                                    <h4 class="admin-dossier-title">Customer Information</h4>
                                    <div class="form-grid-2 text-sm">
                                        <div>
                                            <p><strong>Name:</strong> ${escapeHtml(c.name)}</p>
                                            <p><strong>Email:</strong> ${escapeHtml(c.email)}</p>
                                            <p><strong>Phone:</strong> ${escapeHtml(c.phone || '—')}</p>
                                        </div>
                                        <div>
                                            <p><strong>Account Status:</strong> <span class="badge-status badge-${c.status}">${escapeHtml(c.status.toUpperCase())}</span></p>
                                            <p><strong>Member Since:</strong> ${escapeHtml(c.created_at)}</p>
                                            <p class="text-xs text-muted">Password credentials strictly protected.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-grid-2">
                                    <div class="admin-dossier-card">
                                        <h4 class="admin-dossier-title">Saved Delivery Addresses (${addresses.length})</h4>
                                        ${addrList}
                                    </div>
                                    <div class="admin-dossier-card">
                                        <h4 class="admin-dossier-title">Order History (${orders.length})</h4>
                                        ${ordList}
                                    </div>
                                </div>

                                <div class="form-grid-2">
                                    <div class="admin-dossier-card">
                                        <h4 class="admin-dossier-title">Customer Reviews (${reviews.length})</h4>
                                        ${revList}
                                    </div>
                                    <div class="admin-dossier-card">
                                        <h4 class="admin-dossier-title">Return Requests (${returns.length})</h4>
                                        ${retList}
                                    </div>
                                </div>
                            `;
                        }
                    } catch (err) {
                        showToast('Failed to load customer dossier.', 'error');
                    }
                    return;
                }

                if (toggleBtn) {
                    const custId = parseInt(toggleBtn.getAttribute('data-toggle-cust'), 10);
                    const currentStatus = toggleBtn.getAttribute('data-status');
                    const custName = toggleBtn.getAttribute('data-name');
                    const newStatus = (currentStatus === 'active') ? 'inactive' : 'active';

                    if (confirm(`Change status of customer "${custName}" to ${newStatus.toUpperCase()}?`)) {
                        const csrf = await getCsrfToken();
                        const res = await fetch('/backend/api/admin-customers.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                            body: JSON.stringify({
                                action: 'toggle_status',
                                customer_id: custId,
                                status: newStatus,
                                csrf_token: csrf
                            })
                        });
                        const json = await res.json();
                        if (json.success) {
                            showToast(json.message || `Customer account set to ${newStatus}.`);
                            loadAdminCustomers();
                        } else {
                            showToast(json.error || 'Failed to update customer status.', 'error');
                        }
                    }
                    return;
                }

                if (editBtn) {
                    const custId = parseInt(editBtn.getAttribute('data-edit-cust'), 10);
                    setValue('cust-edit-id', custId);
                    setValue('cust-edit-name', editBtn.getAttribute('data-name'));
                    setValue('cust-edit-email', editBtn.getAttribute('data-email'));
                    setValue('cust-edit-phone', editBtn.getAttribute('data-phone'));
                    openModal('modal-customer-edit');
                }
            });
        }

        // Edit Customer Form submission
        if (formEditCustomer) {
            formEditCustomer.addEventListener('submit', async (e) => {
                e.preventDefault();
                const csrf = await getCsrfToken();
                const formData = new FormData(formEditCustomer);
                const payload = Object.fromEntries(formData.entries());
                payload.action = 'update_customer';
                payload.csrf_token = csrf;

                try {
                    const res = await fetch('/backend/api/admin-customers.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify(payload)
                    });
                    const json = await res.json();
                    if (json.success) {
                        showToast('Customer contact details updated.');
                        closeModal('modal-customer-edit');
                        loadAdminCustomers();
                    } else {
                        showToast(json.error || 'Failed to update customer.', 'error');
                    }
                } catch (err) {
                    showToast('Network error updating customer.', 'error');
                }
            });
        }

        // =====================================================================
        // TAB 6: RETURNS MANAGEMENT & RESTOCKING
        // =====================================================================
        const returnsTbody = document.getElementById('admin-returns-tbody');
        const returnSearchInput = document.getElementById('admin-return-search');
        const returnStatusPills = document.getElementById('admin-return-status-pills');
        const returnsCountLabel = document.getElementById('admin-returns-count');
        const formProcessReturn = document.getElementById('form-process-return');

        let currentReturnStatusFilter = '';

        async function loadAdminReturns() {
            if (!returnsTbody) return;
            returnsTbody.innerHTML = `<tr><td colspan="9" class="text-center py-6 text-muted">Loading returns...</td></tr>`;

            const searchVal = returnSearchInput ? returnSearchInput.value.trim() : '';

            try {
                const query = new URLSearchParams();
                if (currentReturnStatusFilter) query.append('status', currentReturnStatusFilter);
                if (searchVal) query.append('search', searchVal);

                const res = await fetch(`/backend/api/admin-returns.php?${query.toString()}`);
                const json = await res.json();

                if (!json.success || !json.data) {
                    returnsTbody.innerHTML = `<tr><td colspan="9" class="text-center py-6 text-danger">Failed to load returns.</td></tr>`;
                    return;
                }

                const returns = json.data.returns || [];
                if (returnsCountLabel) {
                    returnsCountLabel.textContent = `Showing ${returns.length} return request(s)`;
                }

                if (returns.length === 0) {
                    returnsTbody.innerHTML = `<tr><td colspan="9" class="text-center py-6 text-muted">No return requests found.</td></tr>`;
                    return;
                }

                returnsTbody.innerHTML = returns.map(r => {
                    const statusClass = 'badge-' + r.status.toLowerCase();
                    return `
                        <tr>
                            <td>#${r.id}</td>
                            <td><strong>${escapeHtml(r.order_number)}</strong></td>
                            <td>
                                <div><strong>${escapeHtml(r.customer_name)}</strong></div>
                                <div class="text-xs text-muted">${escapeHtml(r.customer_email)}</div>
                            </td>
                            <td><span class="badge badge-warning">${escapeHtml(r.reason)}</span></td>
                            <td><small>${escapeHtml(r.details || '—')}</small></td>
                            <td><span class="badge-status ${statusClass}">${escapeHtml(r.status)}</span></td>
                            <td><small>${escapeHtml(r.admin_notes || '—')}</small></td>
                            <td>${escapeHtml(r.created_at.split(' ')[0])}</td>
                            <td>
                                <button type="button" class="btn-action btn-action-primary" data-process-return="${r.id}">Process</button>
                            </td>
                        </tr>
                    `;
                }).join('');

            } catch (err) {
                console.error('Error fetching returns:', err);
                returnsTbody.innerHTML = `<tr><td colspan="9" class="text-center py-6 text-danger">Network error loading return requests.</td></tr>`;
            }
        }

        if (returnStatusPills) {
            returnStatusPills.querySelectorAll('button[data-status]').forEach(btn => {
                btn.addEventListener('click', () => {
                    returnStatusPills.querySelectorAll('button[data-status]').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    currentReturnStatusFilter = btn.getAttribute('data-status') || '';
                    loadAdminReturns();
                });
            });
        }

        if (returnSearchInput) {
            let debounceTimer;
            returnSearchInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(loadAdminReturns, 350);
            });
        }

        // Process Return Modal
        if (returnsTbody) {
            returnsTbody.addEventListener('click', async (e) => {
                const btn = e.target.closest('[data-process-return]');
                if (!btn) return;
                const retId = parseInt(btn.getAttribute('data-process-return'), 10);

                const summaryBox = document.getElementById('return-modal-summary');
                if (summaryBox) summaryBox.innerHTML = '<p class="text-muted text-center py-3">Loading return details...</p>';

                setValue('ret-process-id', retId);
                setValue('ret-admin-notes', '');
                openModal('modal-return');

                try {
                    const res = await fetch(`/backend/api/admin-returns.php?id=${retId}`);
                    const json = await res.json();
                    if (json.success && json.data) {
                        const r = json.data.return;
                        const items = json.data.items || [];

                        setText('modal-return-title', `Return Request #${r.id} for Order ${r.order_number}`);
                        setValue('ret-admin-notes', r.admin_notes || '');

                        const itemsList = items.map(it => `
                            <li><strong>${escapeHtml(it.product_name)}</strong> (Qty: ${it.quantity}) [${escapeHtml(it.colour_name || 'Standard')}, ${escapeHtml(it.size_name || 'Standard')}]</li>
                        `).join('');

                        summaryBox.innerHTML = `
                            <p class="text-sm"><strong>Customer:</strong> ${escapeHtml(r.customer_name)} (${escapeHtml(r.customer_email)})</p>
                            <p class="text-sm"><strong>Return Reason:</strong> ${escapeHtml(r.reason)}</p>
                            ${r.details ? `<p class="text-sm"><strong>Customer Details:</strong> ${escapeHtml(r.details)}</p>` : ''}
                            <div class="mt-2 text-xs">
                                <strong>Items to be Restocked into MySQL:</strong>
                                <ul class="pl-4 mt-1">
                                    ${itemsList}
                                </ul>
                            </div>
                        `;
                    }
                } catch (err) {
                    showToast('Failed to load return request details.', 'error');
                }
            });
        }

        // Submit Return Decision Form
        if (formProcessReturn) {
            formProcessReturn.addEventListener('submit', async (e) => {
                e.preventDefault();
                const csrf = await getCsrfToken();
                const retId = parseInt(document.getElementById('ret-process-id').value, 10);
                const decision = formProcessReturn.querySelector('input[name="decision"]:checked').value;
                const notes = document.getElementById('ret-admin-notes').value.trim();

                try {
                    const res = await fetch('/backend/api/admin-returns.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify({
                            action: 'process_return',
                            return_id: retId,
                            decision: decision,
                            admin_notes: notes,
                            csrf_token: csrf
                        })
                    });
                    const json = await res.json();
                    if (json.success) {
                        showToast(json.message || 'Return request processed.');
                        closeModal('modal-return');
                        loadAdminReturns();
                        loadStats();
                    } else {
                        showToast(json.error || 'Failed to process return.', 'error');
                    }
                } catch (err) {
                    showToast('Network error processing return.', 'error');
                }
            });
        }

        // =====================================================================
        // TAB 7 & HEADER: ADMIN NOTIFICATION CENTRE
        // Real-time polling, dropdown preview, filters, deep-links, and mark-read
        // =====================================================================
        const adminNotifBellBtn     = document.getElementById('admin-notif-bell-btn');
        const adminNotifBadge       = document.getElementById('admin-notif-badge');
        const adminNotifDropdown    = document.getElementById('admin-notif-dropdown');
        const adminNotifDropdownList= document.getElementById('admin-notif-dropdown-list');
        const dropdownUnreadPill    = document.getElementById('dropdown-unread-pill');
        const dropdownMarkAllBtn    = document.getElementById('dropdown-mark-all-read-btn');
        const dropdownViewAllBtn    = document.getElementById('dropdown-view-all-btn');
        const sidebarNotifBadge     = document.getElementById('sidebar-notif-badge');

        const notifTypeFilter       = document.getElementById('notif-type-filter');
        const notifStatusFilter     = document.getElementById('notif-status-filter');
        const notifDateFilter       = document.getElementById('notif-date-filter');
        const notifSearchInput      = document.getElementById('notif-search-input');
        const notifFeedContainer    = document.getElementById('notif-feed-container');
        const notifEmptyState       = document.getElementById('notif-empty-state');
        const notifSkeleton         = document.getElementById('notif-skeleton');
        const notifCountSummary     = document.getElementById('notif-count-summary');
        const notifPrevPageBtn      = document.getElementById('notif-prev-page-btn');
        const notifNextPageBtn      = document.getElementById('notif-next-page-btn');
        const notifMarkAllBtn       = document.getElementById('notif-mark-all-btn');
        const notifRefreshBtn       = document.getElementById('notif-refresh-btn');

        let notifPage = 0;
        const notifLimit = 15;
        let notifTotal = 0;

        function getNotifMeta(type) {
            const t = (type || '').toLowerCase();
            if (t.startsWith('order_')) {
                return { icon: '🛒', cssClass: 'type-order', label: 'Order' };
            }
            if (t.startsWith('stock_')) {
                return { icon: '⚠️', cssClass: 'type-stock', label: 'Stock Alert' };
            }
            if (t.startsWith('return_') || t.startsWith('refund_') || t.startsWith('replacement_')) {
                return { icon: '↩️', cssClass: 'type-return', label: 'Return' };
            }
            if (t.startsWith('customer_') || t.startsWith('review_') || t.startsWith('address_') || t.startsWith('cart_') || t.startsWith('wishlist_')) {
                return { icon: '👤', cssClass: 'type-customer', label: 'Customer' };
            }
            return { icon: '📦', cssClass: 'type-catalog', label: 'Catalog' };
        }

        function formatNotifDate(dateStr) {
            if (!dateStr) return '';
            try {
                const d = new Date(dateStr.replace(' ', 'T'));
                if (isNaN(d.getTime())) return dateStr;
                const now = new Date();
                const diffSec = Math.floor((now - d) / 1000);
                if (diffSec < 45) return 'Just now';
                if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
                if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`;
                return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            } catch (err) {
                return dateStr;
            }
        }

        function updateNotifBadges(unreadCount) {
            const count = parseInt(unreadCount, 10) || 0;
            if (count > 0) {
                if (adminNotifBadge) {
                    adminNotifBadge.textContent = count > 99 ? '99+' : count;
                    adminNotifBadge.classList.remove('is-hidden');
                }
                if (sidebarNotifBadge) {
                    sidebarNotifBadge.textContent = count > 99 ? '99+' : count;
                    sidebarNotifBadge.classList.remove('is-hidden');
                }
                if (dropdownUnreadPill) {
                    dropdownUnreadPill.textContent = `${count} new`;
                }
            } else {
                if (adminNotifBadge) adminNotifBadge.classList.add('is-hidden');
                if (sidebarNotifBadge) sidebarNotifBadge.classList.add('is-hidden');
                if (dropdownUnreadPill) dropdownUnreadPill.textContent = '0 new';
            }
        }

        function renderNotifCardHtml(n, isCompact = false) {
            const meta = getNotifMeta(n.notification_type);
            const timeAgo = formatNotifDate(n.created_at);
            const isUnread = parseInt(n.is_read, 10) === 0;

            let deepLinksHtml = '';
            if (n.related_order_id) {
                deepLinksHtml += `<button type="button" class="admin-notif-link" data-deep-link="order" data-id="${n.related_order_id}">🛒 Order #${n.order_number || n.related_order_id}</button>`;
            }
            if (n.related_user_id) {
                deepLinksHtml += `<button type="button" class="admin-notif-link" data-deep-link="customer" data-id="${n.related_user_id}">👤 Customer Dossier</button>`;
            }
            if (n.related_return_id) {
                deepLinksHtml += `<button type="button" class="admin-notif-link" data-deep-link="return" data-id="${n.related_return_id}">↩️ Return #${n.related_return_id}</button>`;
            }
            if (n.related_product_id) {
                deepLinksHtml += `<button type="button" class="admin-notif-link" data-deep-link="product" data-id="${n.related_product_id}">📦 Product #${n.related_product_id}</button>`;
            }

            const unreadDot = isUnread ? `<span class="admin-notif-unread-dot" title="Unread Alert"></span>` : '';
            const markReadBtn = isUnread ? `<button type="button" class="admin-notif-action-btn" data-mark-read="${n.id}" title="Mark as read">✓ Mark read</button>` : '';

            return `
                <div class="admin-notif-card ${isUnread ? 'is-unread' : ''}" id="notif-card-${n.id}">
                    <div class="admin-notif-icon ${meta.cssClass}">${meta.icon}</div>
                    <div class="admin-notif-body">
                        <div class="admin-notif-header">
                            <h5 class="admin-notif-title">${escapeHtml(n.title)}</h5>
                            <div class="d-flex align-items-center gap-2">
                                ${unreadDot}
                                <span class="text-xs text-muted">${timeAgo}</span>
                            </div>
                        </div>
                        <p class="admin-notif-msg">${escapeHtml(n.message)}</p>
                        <div class="admin-notif-meta">
                            ${deepLinksHtml}
                            <span class="ml-auto">${markReadBtn}</span>
                        </div>
                    </div>
                </div>
            `;
        }

        async function pollNotifications() {
            try {
                const res = await fetch('/backend/api/admin-notifications.php?poll=1');
                if (res.status === 401 || res.status === 403) return;
                const json = await res.json();
                if (json.success && json.data) {
                    updateNotifBadges(json.data.unread_count);
                    const list = json.data.latest_unread || [];
                    if (adminNotifDropdown && !adminNotifDropdown.classList.contains('is-hidden')) {
                        renderDropdownList(list);
                    }
                }
            } catch (err) {
                // Silently skip background poll network errors
            }
        }

        function renderDropdownList(list) {
            if (!adminNotifDropdownList) return;
            if (!list || list.length === 0) {
                adminNotifDropdownList.innerHTML = '<div class="admin-notif-empty">No unread notifications</div>';
                return;
            }
            adminNotifDropdownList.innerHTML = list.map(n => renderNotifCardHtml(n, true)).join('');
        }

        async function loadAdminNotifications(resetPage = false) {
            if (resetPage) notifPage = 0;
            if (notifSkeleton) notifSkeleton.classList.remove('is-hidden');
            if (notifFeedContainer) notifFeedContainer.innerHTML = '';
            if (notifEmptyState) notifEmptyState.classList.add('is-hidden');

            const type = notifTypeFilter ? notifTypeFilter.value : 'all';
            const status = notifStatusFilter ? notifStatusFilter.value : 'all';
            const datePreset = notifDateFilter ? notifDateFilter.value : 'all';
            const search = notifSearchInput ? notifSearchInput.value.trim() : '';

            const params = new URLSearchParams({
                type: type,
                status: status,
                date_preset: datePreset,
                search: search,
                limit: notifLimit.toString(),
                offset: (notifPage * notifLimit).toString()
            });

            try {
                const res = await fetch(`/backend/api/admin-notifications.php?${params.toString()}`);
                if (res.status === 401 || res.status === 403) {
                    window.location.href = '/backend/admin/login.php';
                    return;
                }
                const json = await res.json();
                if (notifSkeleton) notifSkeleton.classList.add('is-hidden');

                if (json.success && json.data) {
                    const list = json.data.notifications || [];
                    notifTotal = json.data.total || 0;
                    updateNotifBadges(json.data.unread_count || 0);

                    if (list.length === 0) {
                        if (notifEmptyState) notifEmptyState.classList.remove('is-hidden');
                    } else {
                        if (notifFeedContainer) {
                            notifFeedContainer.innerHTML = list.map(n => renderNotifCardHtml(n, false)).join('');
                        }
                    }

                    // Update pagination controls
                    const startCount = notifTotal > 0 ? (notifPage * notifLimit) + 1 : 0;
                    const endCount = Math.min((notifPage + 1) * notifLimit, notifTotal);
                    if (notifCountSummary) {
                        notifCountSummary.textContent = `Showing ${startCount}–${endCount} of ${notifTotal} notifications`;
                    }
                    if (notifPrevPageBtn) notifPrevPageBtn.disabled = (notifPage <= 0);
                    if (notifNextPageBtn) notifNextPageBtn.disabled = (endCount >= notifTotal);
                } else {
                    if (notifEmptyState) notifEmptyState.classList.remove('is-hidden');
                }
            } catch (err) {
                if (notifSkeleton) notifSkeleton.classList.add('is-hidden');
                showToast('Failed to load notifications from database.', 'error');
            }
        }

        async function markNotifRead(id) {
            const notifId = parseInt(id, 10);
            if (notifId <= 0) return;
            const csrf = await getCsrfToken();

            try {
                const res = await fetch('/backend/api/admin-notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ action: 'mark_read', id: notifId, csrf_token: csrf })
                });
                const json = await res.json();
                if (json.success) {
                    // Update visual card
                    const cards = document.querySelectorAll(`[id="notif-card-${notifId}"]`);
                    cards.forEach(card => {
                        card.classList.remove('is-unread');
                        const dot = card.querySelector('.admin-notif-unread-dot');
                        if (dot) dot.remove();
                        const btn = card.querySelector(`[data-mark-read="${notifId}"]`);
                        if (btn) btn.remove();
                    });
                    pollNotifications();
                }
            } catch (err) {
                showToast('Failed to mark notification as read.', 'error');
            }
        }

        async function markAllNotifsRead() {
            const csrf = await getCsrfToken();
            try {
                const res = await fetch('/backend/api/admin-notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ action: 'mark_all_read', csrf_token: csrf })
                });
                const json = await res.json();
                if (json.success) {
                    showToast('All notifications marked as read.');
                    updateNotifBadges(0);
                    renderDropdownList([]);
                    loadAdminNotifications();
                }
            } catch (err) {
                showToast('Failed to mark all as read.', 'error');
            }
        }

        // Deep link modal opening handlers
        async function openNotifOrderModal(orderId) {
            const detailsContainer = document.getElementById('order-modal-details');
            if (detailsContainer) detailsContainer.innerHTML = '<p class="text-muted text-center py-4">Loading order breakdown...</p>';
            openModal('modal-order');

            try {
                const res = await fetch(`/backend/api/admin-orders.php?id=${orderId}`);
                const json = await res.json();
                if (json.success && json.data) {
                    const o = json.data.order;
                    const items = json.data.items || [];
                    const ret = json.data.return_request;

                    setText('modal-order-title', `Order ${o.order_number}`);
                    setValue('order-update-id', o.id);
                    setValue('order-status-select', o.status);
                    setValue('order-dispatch-input', o.dispatch_date ? o.dispatch_date.replace(' ', 'T').substring(0, 16) : '');

                    const itemsRows = items.map(it => `
                        <tr>
                            <td>${escapeHtml(it.product_name)}</td>
                            <td>${escapeHtml(it.colour_name || 'Standard')} / ${escapeHtml(it.size_name || 'Standard')}</td>
                            <td>${it.quantity}</td>
                            <td>$${parseFloat(it.unit_price).toFixed(2)}</td>
                            <td>$${parseFloat(it.total_price).toFixed(2)}</td>
                        </tr>
                    `).join('');

                    let returnBanner = '';
                    if (ret) {
                        returnBanner = `
                            <div class="alert alert-warning mb-3">
                                <strong>Return Request (${ret.status}):</strong> ${escapeHtml(ret.reason)}
                                ${ret.details ? `<br><small>${escapeHtml(ret.details)}</small>` : ''}
                            </div>
                        `;
                    }

                    detailsContainer.innerHTML = `
                        ${returnBanner}
                        <div class="admin-dossier-card mb-3">
                            <h4 class="admin-dossier-title">Customer & Destination</h4>
                            <div class="form-grid-2">
                                <div>
                                    <p class="text-sm"><strong>Recipient:</strong> ${escapeHtml(o.shipping_name || o.customer_name)}</p>
                                    <p class="text-sm"><strong>Email:</strong> ${escapeHtml(o.customer_email || '—')}</p>
                                    <p class="text-sm"><strong>Phone:</strong> ${escapeHtml(o.shipping_phone || o.customer_phone || '—')}</p>
                                </div>
                                <div>
                                    <p class="text-sm"><strong>Delivery Address:</strong></p>
                                    <p class="text-xs text-muted">
                                        ${escapeHtml(o.address_line1 || '—')}<br>
                                        ${o.address_line2 ? `${escapeHtml(o.address_line2)}<br>` : ''}
                                        ${escapeHtml(o.city || '')}, ${escapeHtml(o.state || '')} ${escapeHtml(o.postal_code || '')}<br>
                                        ${escapeHtml(o.country || '')}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="admin-table-container">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Variant</th>
                                        <th>Quantity</th>
                                        <th>Unit Price</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${itemsRows}
                                </tbody>
                            </table>
                        </div>

                        <div class="text-right mt-3">
                            <p class="text-lg"><strong>Total Order Amount: $${parseFloat(o.total_amount).toFixed(2)}</strong></p>
                        </div>
                    `;
                }
            } catch (err) {
                showToast('Failed to load order breakdown.', 'error');
            }
        }

        async function openNotifCustomerDossier(custId) {
            const container = document.getElementById('customer-dossier-content');
            if (container) container.innerHTML = '<p class="text-muted text-center py-6">Loading customer dossier...</p>';
            openModal('modal-customer-dossier');

            try {
                const res = await fetch(`/backend/api/admin-customers.php?id=${custId}`);
                const json = await res.json();
                if (json.success && json.data) {
                    const c = json.data.customer;
                    const orders = json.data.orders || [];
                    const addresses = json.data.addresses || [];
                    const reviews = json.data.reviews || [];
                    const returns = json.data.returns || [];

                    setText('modal-customer-dossier-title', `Dossier: ${c.name} (#${c.id})`);

                    const addrList = addresses.length === 0 
                        ? '<p class="text-xs text-muted">No saved addresses on file.</p>'
                        : addresses.map(a => `
                            <div class="border-b py-2 text-sm">
                                <strong>${escapeHtml(a.full_name)}</strong> ${a.is_default == 1 ? '<span class="badge badge-sm badge-primary">Default</span>' : ''}<br>
                                ${escapeHtml(a.address_line1)}, ${escapeHtml(a.city)}, ${escapeHtml(a.country)}<br>
                                <span class="text-xs text-muted">Phone: ${escapeHtml(a.phone)}</span>
                            </div>
                        `).join('');

                    const orderList = orders.length === 0
                        ? '<p class="text-xs text-muted">No orders placed yet.</p>'
                        : orders.map(o => `
                            <div class="border-b py-2 text-sm d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>${o.order_number}</strong> — $${parseFloat(o.total_amount).toFixed(2)}<br>
                                    <span class="text-xs text-muted">${o.created_at}</span>
                                </div>
                                <span class="badge badge-sm">${o.status}</span>
                            </div>
                        `).join('');

                    container.innerHTML = `
                        <div class="admin-dossier-card mb-4">
                            <h4 class="admin-dossier-title">Customer Profile</h4>
                            <div class="form-grid-2">
                                <div>
                                    <p class="text-sm"><strong>Name:</strong> ${escapeHtml(c.name)}</p>
                                    <p class="text-sm"><strong>Email:</strong> ${escapeHtml(c.email)}</p>
                                    <p class="text-sm"><strong>Phone:</strong> ${escapeHtml(c.phone || '—')}</p>
                                </div>
                                <div>
                                    <p class="text-sm"><strong>Account Status:</strong> <span class="badge badge-sm ${c.status === 'active' ? 'badge-primary' : 'badge-danger'}">${c.status}</span></p>
                                    <p class="text-sm"><strong>Total Orders:</strong> ${c.total_orders}</p>
                                    <p class="text-sm"><strong>Lifetime Spend:</strong> $${parseFloat(c.lifetime_spent).toFixed(2)}</p>
                                </div>
                            </div>
                        </div>

                        <div class="form-grid-2">
                            <div class="admin-dossier-card">
                                <h4 class="admin-dossier-title">Saved Delivery Addresses (${addresses.length})</h4>
                                ${addrList}
                            </div>
                            <div class="admin-dossier-card">
                                <h4 class="admin-dossier-title">Order History (${orders.length})</h4>
                                ${orderList}
                            </div>
                        </div>
                    `;
                }
            } catch (err) {
                showToast('Failed to load customer dossier.', 'error');
            }
        }

        async function openNotifReturnModal(returnId) {
            const summaryBox = document.getElementById('return-modal-summary');
            if (summaryBox) summaryBox.innerHTML = '<p class="text-muted text-center py-3">Loading return details...</p>';

            setValue('ret-process-id', returnId);
            setValue('ret-admin-notes', '');
            openModal('modal-return');

            try {
                const res = await fetch(`/backend/api/admin-returns.php?id=${returnId}`);
                const json = await res.json();
                if (json.success && json.data) {
                    const r = json.data.return;
                    const items = json.data.items || [];

                    setText('modal-return-title', `Return Request #${r.id} for Order ${r.order_number}`);
                    setValue('ret-admin-notes', r.admin_notes || '');

                    const itemsList = items.map(it => `
                        <li><strong>${escapeHtml(it.product_name)}</strong> (Qty: ${it.quantity}) [${escapeHtml(it.colour_name || 'Standard')}, ${escapeHtml(it.size_name || 'Standard')}]</li>
                    `).join('');

                    summaryBox.innerHTML = `
                        <p class="text-sm"><strong>Customer:</strong> ${escapeHtml(r.customer_name)} (${escapeHtml(r.customer_email)})</p>
                        <p class="text-sm"><strong>Return Reason:</strong> ${escapeHtml(r.reason)}</p>
                        ${r.details ? `<p class="text-sm"><strong>Customer Details:</strong> ${escapeHtml(r.details)}</p>` : ''}
                        <div class="mt-2 text-xs">
                            <strong>Items to be Restocked into MySQL:</strong>
                            <ul class="pl-4 mt-1">
                                ${itemsList}
                            </ul>
                        </div>
                    `;
                }
            } catch (err) {
                showToast('Failed to load return request details.', 'error');
            }
        }

        // Global click handler for Notification deep links & mark read buttons
        document.addEventListener('click', (e) => {
            const deepBtn = e.target.closest('[data-deep-link]');
            if (deepBtn) {
                const linkType = deepBtn.getAttribute('data-deep-link');
                const entityId = parseInt(deepBtn.getAttribute('data-id'), 10);
                if (linkType === 'order') {
                    openNotifOrderModal(entityId);
                } else if (linkType === 'customer') {
                    openNotifCustomerDossier(entityId);
                } else if (linkType === 'return') {
                    openNotifReturnModal(entityId);
                } else if (linkType === 'product') {
                    if (sidebarNav) {
                        const prodBtn = sidebarNav.querySelector('button[data-tab="products"]');
                        if (prodBtn) prodBtn.click();
                    }
                }
                return;
            }

            const markBtn = e.target.closest('[data-mark-read]');
            if (markBtn) {
                const notifId = parseInt(markBtn.getAttribute('data-mark-read'), 10);
                markNotifRead(notifId);
                return;
            }

            // Close notification dropdown when clicking outside
            if (adminNotifDropdown && !adminNotifDropdown.classList.contains('is-hidden')) {
                if (!adminNotifDropdown.contains(e.target) && !adminNotifBellBtn.contains(e.target)) {
                    adminNotifDropdown.classList.add('is-hidden');
                    if (adminNotifBellBtn) adminNotifBellBtn.setAttribute('aria-expanded', 'false');
                }
            }
        });

        // Toggle Bell Dropdown
        if (adminNotifBellBtn && adminNotifDropdown) {
            adminNotifBellBtn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const isExpanded = adminNotifBellBtn.getAttribute('aria-expanded') === 'true';
                if (isExpanded) {
                    adminNotifDropdown.classList.add('is-hidden');
                    adminNotifBellBtn.setAttribute('aria-expanded', 'false');
                } else {
                    adminNotifDropdown.classList.remove('is-hidden');
                    adminNotifBellBtn.setAttribute('aria-expanded', 'true');
                    // Fetch latest unread notifications for dropdown
                    try {
                        const res = await fetch('/backend/api/admin-notifications.php?status=unread&limit=8');
                        const json = await res.json();
                        if (json.success && json.data) {
                            renderDropdownList(json.data.notifications || []);
                        }
                    } catch (err) {
                        // Keep fallback
                    }
                }
            });
        }

        // View All in Dropdown -> navigate to Notifications tab
        if (dropdownViewAllBtn) {
            dropdownViewAllBtn.addEventListener('click', (e) => {
                e.preventDefault();
                if (adminNotifDropdown) {
                    adminNotifDropdown.classList.add('is-hidden');
                    if (adminNotifBellBtn) adminNotifBellBtn.setAttribute('aria-expanded', 'false');
                }
                if (sidebarNav) {
                    const notifNavBtn = sidebarNav.querySelector('button[data-tab="notifications"]');
                    if (notifNavBtn) notifNavBtn.click();
                }
            });
        }

        // Mark All As Read buttons
        if (dropdownMarkAllBtn) {
            dropdownMarkAllBtn.addEventListener('click', markAllNotifsRead);
        }
        if (notifMarkAllBtn) {
            notifMarkAllBtn.addEventListener('click', markAllNotifsRead);
        }

        // Notification filters & pagination
        if (notifTypeFilter) notifTypeFilter.addEventListener('change', () => loadAdminNotifications(true));
        if (notifStatusFilter) notifStatusFilter.addEventListener('change', () => loadAdminNotifications(true));
        if (notifDateFilter) notifDateFilter.addEventListener('change', () => loadAdminNotifications(true));
        if (notifRefreshBtn) notifRefreshBtn.addEventListener('click', () => loadAdminNotifications(false));

        if (notifSearchInput) {
            let notifSearchDebounce;
            notifSearchInput.addEventListener('input', () => {
                clearTimeout(notifSearchDebounce);
                notifSearchDebounce = setTimeout(() => loadAdminNotifications(true), 350);
            });
        }

        if (notifPrevPageBtn) {
            notifPrevPageBtn.addEventListener('click', () => {
                if (notifPage > 0) {
                    notifPage--;
                    loadAdminNotifications(false);
                }
            });
        }

        if (notifNextPageBtn) {
            notifNextPageBtn.addEventListener('click', () => {
                if ((notifPage + 1) * notifLimit < notifTotal) {
                    notifPage++;
                    loadAdminNotifications(false);
                }
            });
        }

        // Start Safe Background Polling (every 12 seconds)
        pollNotifications();
        setInterval(pollNotifications, 12000);

        // Initialize with default Overview tab
        await loadStats();
    }

    /**
     * Initialize customer profile page
     */
    async function initProfilePage() {
        const container = document.getElementById('profile-info');
        if (!container) return;

        try {
            const res = await fetch('/backend/auth/me.php', {
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();
            if (data.success && data.data.logged_in && data.data.user) {
                const u = data.data.user;
                container.innerHTML = `
                    <div class="mb-3">
                        <span class="text-xs text-muted uppercase font-bold tracking-wider">FULL NAME</span>
                        <div class="text-base font-bold text-dark mt-1">${escapeHtml(u.name)}</div>
                    </div>
                    <div class="mb-3">
                        <span class="text-xs text-muted uppercase font-bold tracking-wider">EMAIL ADDRESS</span>
                        <div class="text-base text-dark mt-1">${escapeHtml(u.email)}</div>
                    </div>
                    <div class="mb-3">
                        <span class="text-xs text-muted uppercase font-bold tracking-wider">CONTACT TELEPHONE</span>
                        <div class="text-base text-dark mt-1">${escapeHtml(u.phone || 'Not provided')}</div>
                    </div>
                    <div class="mb-3">
                        <span class="text-xs text-muted uppercase font-bold tracking-wider">ACCOUNT REGISTERED</span>
                        <div class="text-base text-dark mt-1">${escapeHtml(u.created_at)}</div>
                    </div>
                    <div class="mt-4 pt-4 border-t border-light">
                        <button type="button" id="profile-logout-btn" class="btn btn-outline btn-sm text-danger">Sign Out of StyleHub</button>
                    </div>
                `;
                const logoutBtn = document.getElementById('profile-logout-btn');
                if (logoutBtn) logoutBtn.addEventListener('click', handleLogout);
            } else {
                container.innerHTML = `
                    <p>You are not currently signed in.</p>
                    <a href="/frontend/login.html" class="btn btn-primary btn-sm mt-2">Sign In</a>
                `;
            }
        } catch (err) {
            console.error('Error loading profile info:', err);
        }
    }

    /**
     * =========================================================================
     * PHASE 3: SHOPPING BAG / CART
     * =========================================================================
     */
    async function initCartPage() {
        const skeleton = document.getElementById('cart-skeleton-view');
        const content = document.getElementById('cart-content-view');
        const alertBox = document.getElementById('cart-alert');
        const subtitle = document.getElementById('cart-item-count-subtitle');

        if (!content) return;

        try {
            const res = await fetch('/backend/api/cart.php', {
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();

            if (skeleton) skeleton.classList.add('is-hidden');
            content.classList.remove('is-hidden');

            if (!res.ok || !data.success) {
                showAlert(alertBox, data.message || 'Failed to load shopping bag.', 'error');
                return;
            }

            const cart = data.data;
            const items = cart.items || [];

            if (subtitle) {
                subtitle.innerText = `${cart.item_count} ${cart.item_count === 1 ? 'piece' : 'pieces'} curated`;
            }

            if (items.length === 0) {
                content.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">🛍️</div>
                        <h2 class="empty-state-title">Your shopping bag is empty</h2>
                        <p class="empty-state-text">Explore our refined tailoring, outerwear, and wardrobe essentials.</p>
                        <a href="/frontend/products.html" class="btn btn-primary mt-4">Start Shopping</a>
                    </div>
                `;
                return;
            }

            let itemsHtml = items.map(it => `
                <div class="cart-item-row" data-item-id="${it.item_id}">
                    <img src="${escapeHtml(it.image_url)}" alt="${escapeHtml(it.product_name)}" class="cart-item-img" loading="lazy">
                    <div class="cart-item-details">
                        <a href="/frontend/product-details.html?id=${it.product_id}" class="cart-item-name">${escapeHtml(it.product_name)}</a>
                        <div class="cart-item-meta">
                            <span>Colour: <strong>${escapeHtml(it.colour_name)}</strong></span>
                            <span>Size: <strong>${escapeHtml(it.size_name)}</strong></span>
                            <span>Unit: <strong>$${it.price.toFixed(2)}</strong></span>
                        </div>
                    </div>
                    <div class="stepper">
                        <button type="button" class="stepper-btn" onclick="StyleHub.updateCartQuantity(${it.item_id}, ${it.quantity - 1})" aria-label="Decrease quantity">-</button>
                        <span class="stepper-val">${it.quantity}</span>
                        <button type="button" class="stepper-btn" onclick="StyleHub.updateCartQuantity(${it.item_id}, ${it.quantity + 1})" aria-label="Increase quantity">+</button>
                    </div>
                    <div class="text-right">
                        <div class="font-bold text-dark text-base">$${it.line_total.toFixed(2)}</div>
                        <button type="button" class="text-xs text-danger hover-underline mt-1" onclick="StyleHub.removeCartItem(${it.item_id})" aria-label="Remove item">Remove</button>
                    </div>
                </div>
            `).join('');

            const freeShippingHtml = cart.free_shipping
                ? `<div class="shipping-free-notice">✓ Free express delivery unlocked on this order!</div>`
                : `<div class="shipping-free-notice">Add $${(150 - cart.subtotal).toFixed(2)} more for complimentary delivery</div>`;

            content.innerHTML = `
                <div class="cart-layout">
                    <div class="cart-table-wrapper">
                        ${itemsHtml}
                        <div class="flex justify-between items-center mt-6 pt-4 border-t border-light">
                            <a href="/frontend/products.html" class="link-primary text-sm">← Continue Browsing</a>
                            <button type="button" class="btn btn-outline btn-sm text-danger" onclick="StyleHub.clearCart()">Clear Shopping Bag</button>
                        </div>
                    </div>
                    <aside class="cart-summary-column">
                        <div class="cart-summary-card">
                            <h2 class="summary-title">Order Summary</h2>
                            <div class="summary-row">
                                <span>Items Subtotal</span>
                                <span>$${cart.subtotal.toFixed(2)}</span>
                            </div>
                            <div class="summary-row">
                                <span>Express Delivery</span>
                                <span>${cart.shipping === 0 ? '<span class="text-success font-bold">Complimentary</span>' : '$' + cart.shipping.toFixed(2)}</span>
                            </div>
                            ${freeShippingHtml}
                            <div class="summary-row total-row">
                                <span>Grand Total</span>
                                <span class="text-primary">$${cart.grand_total.toFixed(2)}</span>
                            </div>
                            <a href="/frontend/checkout.html" class="btn btn-primary btn-block btn-lg mt-6">
                                Proceed to Checkout →
                            </a>
                            <div class="text-xs text-muted text-center mt-4">
                                🛡️ Secure 256-bit checkout with Cash on Delivery demo
                            </div>
                        </div>
                    </aside>
                </div>
            `;
        } catch (err) {
            console.error('Error loading cart:', err);
            if (skeleton) skeleton.classList.add('is-hidden');
            showAlert(alertBox, 'A network error occurred while loading your cart.', 'error');
        }
    }

    async function updateCartQuantity(itemId, quantity) {
        if (quantity < 1) {
            return removeCartItem(itemId);
        }
        try {
            const token = await getCsrfToken();
            const res = await fetch('/backend/api/cart.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': token || ''
                },
                body: JSON.stringify({
                    action: 'update',
                    item_id: itemId,
                    quantity: quantity,
                    csrf_token: token
                })
            });
            const data = await res.json();
            if (res.ok && data.success) {
                initCartPage();
            } else {
                alert(data.message || 'Could not update quantity.');
            }
        } catch (err) {
            console.error('Error updating cart quantity:', err);
        }
    }

    async function removeCartItem(itemId) {
        try {
            const token = await getCsrfToken();
            const res = await fetch('/backend/api/cart.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': token || ''
                },
                body: JSON.stringify({
                    action: 'remove',
                    item_id: itemId,
                    csrf_token: token
                })
            });
            const data = await res.json();
            if (res.ok && data.success) {
                initCartPage();
            } else {
                alert(data.message || 'Could not remove item.');
            }
        } catch (err) {
            console.error('Error removing cart item:', err);
        }
    }

    async function clearCart() {
        if (!confirm('Are you sure you want to empty your shopping bag?')) return;
        try {
            const token = await getCsrfToken();
            const res = await fetch('/backend/api/cart.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': token || ''
                },
                body: JSON.stringify({
                    action: 'clear',
                    csrf_token: token
                })
            });
            const data = await res.json();
            if (res.ok && data.success) {
                initCartPage();
            }
        } catch (err) {
            console.error('Error clearing cart:', err);
        }
    }

    /**
     * =========================================================================
     * PHASE 3: WISHLIST / SAVED PIECES
     * =========================================================================
     */
    async function initWishlistPage() {
        const skeleton = document.getElementById('wishlist-skeleton-view');
        const content = document.getElementById('wishlist-content-view');
        const alertBox = document.getElementById('wishlist-alert');
        const subtitle = document.getElementById('wishlist-count-subtitle');

        if (!content) return;

        try {
            const res = await fetch('/backend/api/wishlist.php', {
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();

            if (skeleton) skeleton.classList.add('is-hidden');
            content.classList.remove('is-hidden');

            if (!res.ok || !data.success) {
                showAlert(alertBox, data.message || 'Failed to load wishlist.', 'error');
                return;
            }

            const items = data.data?.items || [];
            if (subtitle) {
                subtitle.innerText = `${items.length} ${items.length === 1 ? 'piece' : 'pieces'} saved in your wardrobe`;
            }

            if (items.length === 0) {
                content.innerHTML = `
                    <div class="empty-state col-span-full">
                        <div class="empty-state-icon">♡</div>
                        <h2 class="empty-state-title">Your wishlist is empty</h2>
                        <p class="empty-state-text">You haven't saved any garments to your wishlist yet. Explore our latest arrivals.</p>
                        <a href="/frontend/products.html" class="btn btn-primary mt-4">Explore Products</a>
                    </div>
                `;
                return;
            }

            content.innerHTML = items.map(p => {
                const lowStockBadge = p.is_low_stock
                    ? `<span class="badge badge-low-stock">Low in Stock</span>`
                    : '';

                return `
                    <article class="product-card" data-product-id="${p.product_id}">
                        <div class="product-media">
                            <img src="${escapeHtml(p.image_url)}" alt="${escapeHtml(p.name)}" class="product-img" loading="lazy">
                            ${lowStockBadge}
                            <button type="button" class="wishlist-btn active" title="Remove from wishlist" onclick="StyleHub.removeFromWishlist(${p.product_id})">
                                ♥
                            </button>
                        </div>
                        <div class="product-content">
                            <span class="product-category-tag">${escapeHtml(p.category_name)}</span>
                            <h3 class="product-title">
                                <a href="/frontend/product-details.html?id=${p.product_id}">${escapeHtml(p.name)}</a>
                            </h3>
                            <div class="rating-badge mb-2">
                                <span class="stars">${renderStars(p.avg_rating)}</span>
                                <span class="rating-text">(${p.review_count})</span>
                            </div>
                            <div class="product-card-footer">
                                <div class="product-price">$${p.price.toFixed(2)}</div>
                                <div class="flex gap-2">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="StyleHub.moveWishlistToCart(${p.product_id}, this)">Move to Bag</button>
                                </div>
                            </div>
                        </div>
                    </article>
                `;
            }).join('');

        } catch (err) {
            console.error('Error loading wishlist:', err);
            if (skeleton) skeleton.classList.add('is-hidden');
            showAlert(alertBox, 'A network error occurred while loading your wishlist.', 'error');
        }
    }

    async function removeFromWishlist(productId) {
        try {
            const token = await getCsrfToken();
            const res = await fetch('/backend/api/wishlist.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': token || ''
                },
                body: JSON.stringify({
                    action: 'remove',
                    product_id: parseInt(productId, 10),
                    csrf_token: token
                })
            });
            const data = await res.json();
            if (res.ok && data.success) {
                initWishlistPage();
            }
        } catch (err) {
            console.error('Error removing from wishlist:', err);
        }
    }

    async function moveWishlistToCart(productId, btn) {
        await addToCart(productId, btn);
        await removeFromWishlist(productId);
        initWishlistPage();
    }

    /**
     * =========================================================================
     * PHASE 3: ADDRESS MANAGEMENT
     * =========================================================================
     */
    let addressListCache = [];

    async function initAddressesPage() {
        const skeleton = document.getElementById('addresses-skeleton-view');
        const content = document.getElementById('addresses-content-view');
        const alertBox = document.getElementById('address-alert');
        const openModalBtn = document.getElementById('open-add-address-btn');
        const modal = document.getElementById('address-modal');
        const closeModalBtn = document.getElementById('close-address-modal-btn');
        const form = document.getElementById('address-form');

        if (!content) return;

        if (openModalBtn && modal) {
            openModalBtn.addEventListener('click', () => {
                resetAddressForm();
                document.getElementById('modal-address-title').innerText = 'Add Delivery Address';
                document.getElementById('address-form-action').value = 'add';
                document.getElementById('address-form-id').value = '';
                modal.classList.remove('is-hidden');
            });
        }
        if (closeModalBtn && modal) {
            closeModalBtn.addEventListener('click', () => {
                modal.classList.add('is-hidden');
            });
        }

        if (form) {
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                const token = await getCsrfToken();
                const formData = new FormData(form);
                const payload = {
                    action: formData.get('action') || 'add',
                    address_id: formData.get('address_id') ? parseInt(formData.get('address_id'), 10) : 0,
                    full_name: formData.get('full_name'),
                    phone: formData.get('phone'),
                    address_line1: formData.get('address_line1'),
                    address_line2: formData.get('address_line2'),
                    city: formData.get('city'),
                    state: formData.get('state'),
                    postal_code: formData.get('postal_code'),
                    country: formData.get('country'),
                    is_default: form.querySelector('#addr-default')?.checked ? 1 : 0,
                    csrf_token: token
                };

                try {
                    const res = await fetch('/backend/api/addresses.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-Token': token || ''
                        },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (res.ok && data.success) {
                        modal.classList.add('is-hidden');
                        showAlert(alertBox, data.message || 'Address saved.', 'success');
                        loadAddresses();
                    } else {
                        alert(data.message || 'Failed to save address.');
                    }
                } catch (err) {
                    console.error('Error saving address:', err);
                    alert('Network error while saving address.');
                }
            });
        }

        async function loadAddresses() {
            try {
                const res = await fetch('/backend/api/addresses.php', {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();

                if (skeleton) skeleton.classList.add('is-hidden');
                content.classList.remove('is-hidden');

                if (res.status === 401) {
                    content.innerHTML = `
                        <div class="empty-state col-span-full">
                            <div class="empty-state-icon">🔒</div>
                            <h2 class="empty-state-title">Sign In Required</h2>
                            <p class="empty-state-text">Please sign in to your StyleHub account to manage delivery destinations.</p>
                            <a href="/frontend/login.html?redirect=/frontend/addresses.html" class="btn btn-primary mt-4">Sign In</a>
                        </div>
                    `;
                    return;
                }

                if (!res.ok || !data.success) {
                    showAlert(alertBox, data.message || 'Failed to retrieve addresses.', 'error');
                    return;
                }

                const addresses = data.data?.addresses || [];
                addressListCache = addresses;

                if (addresses.length === 0) {
                    content.innerHTML = `
                        <div class="empty-state col-span-full">
                            <div class="empty-state-icon">📍</div>
                            <h2 class="empty-state-title">No Saved Addresses</h2>
                            <p class="empty-state-text">Add your delivery destination for faster checkout.</p>
                            <button type="button" class="btn btn-primary mt-4" onclick="document.getElementById('open-add-address-btn')?.click()">+ Add Address</button>
                        </div>
                    `;
                    return;
                }

                content.innerHTML = addresses.map(addr => `
                    <div class="address-card ${addr.is_default ? 'default-address' : ''}" data-id="${addr.id}">
                        <div>
                            <div class="address-header">
                                <div class="address-name">${escapeHtml(addr.full_name)}</div>
                                ${addr.is_default ? '<span class="badge badge-success">Default</span>' : ''}
                            </div>
                            <div class="address-text">
                                <div>${escapeHtml(addr.address_line1)}</div>
                                ${addr.address_line2 ? `<div>${escapeHtml(addr.address_line2)}</div>` : ''}
                                <div>${escapeHtml(addr.city)}, ${escapeHtml(addr.state)} ${escapeHtml(addr.postal_code)}</div>
                                <div>${escapeHtml(addr.country)}</div>
                                <div class="text-xs text-muted mt-2">📞 ${escapeHtml(addr.phone)}</div>
                            </div>
                        </div>
                        <div class="address-actions-bar">
                            ${!addr.is_default ? `<button type="button" class="btn btn-outline btn-sm" onclick="StyleHub.setDefaultAddress(${addr.id})">Set Default</button>` : ''}
                            <button type="button" class="btn btn-outline btn-sm" onclick="StyleHub.editAddress(${addr.id})">Edit</button>
                            <button type="button" class="btn btn-outline btn-sm text-danger" onclick="StyleHub.deleteAddress(${addr.id})">Delete</button>
                        </div>
                    </div>
                `).join('');

            } catch (err) {
                console.error('Error fetching addresses:', err);
                if (skeleton) skeleton.classList.add('is-hidden');
            }
        }

        loadAddresses();
    }

    function resetAddressForm() {
        const form = document.getElementById('address-form');
        if (form) form.reset();
    }

    function editAddress(id) {
        const addr = addressListCache.find(a => a.id === id);
        if (!addr) return;

        const modal = document.getElementById('address-modal');
        if (!modal) return;

        document.getElementById('modal-address-title').innerText = 'Edit Delivery Address';
        document.getElementById('address-form-action').value = 'edit';
        document.getElementById('address-form-id').value = addr.id;

        const f = document.getElementById('address-form');
        if (f) {
            f.querySelector('#addr-fullname').value = addr.full_name || '';
            f.querySelector('#addr-phone').value = addr.phone || '';
            f.querySelector('#addr-line1').value = addr.address_line1 || '';
            f.querySelector('#addr-line2').value = addr.address_line2 || '';
            f.querySelector('#addr-city').value = addr.city || '';
            f.querySelector('#addr-state').value = addr.state || '';
            f.querySelector('#addr-postal').value = addr.postal_code || '';
            f.querySelector('#addr-country').value = addr.country || 'United States';
            f.querySelector('#addr-default').checked = !!addr.is_default;
        }

        modal.classList.remove('is-hidden');
    }

    async function setDefaultAddress(id) {
        try {
            const token = await getCsrfToken();
            const res = await fetch('/backend/api/addresses.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': token || ''
                },
                body: JSON.stringify({
                    action: 'set_default',
                    address_id: id,
                    csrf_token: token
                })
            });
            const data = await res.json();
            if (res.ok && data.success) {
                initAddressesPage();
            } else {
                alert(data.message || 'Could not set default address.');
            }
        } catch (err) {
            console.error('Error setting default address:', err);
        }
    }

    async function deleteAddress(id) {
        if (!confirm('Are you sure you want to remove this delivery address?')) return;
        try {
            const token = await getCsrfToken();
            const res = await fetch('/backend/api/addresses.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': token || ''
                },
                body: JSON.stringify({
                    action: 'delete',
                    address_id: id,
                    csrf_token: token
                })
            });
            const data = await res.json();
            if (res.ok && data.success) {
                initAddressesPage();
            } else {
                alert(data.message || 'Could not delete address.');
            }
        } catch (err) {
            console.error('Error deleting address:', err);
        }
    }

    /**
     * =========================================================================
     * PHASE 3: CHECKOUT & DEMO ORDER PLACEMENT
     * =========================================================================
     */
    let selectedCheckoutAddressId = null;

    async function initCheckoutPage() {
        const skeleton = document.getElementById('checkout-skeleton-view');
        const content = document.getElementById('checkout-content-view');
        const emptyView = document.getElementById('checkout-empty-view');
        const alertBox = document.getElementById('checkout-alert');
        const itemsPreview = document.getElementById('checkout-items-preview');
        const addressListEl = document.getElementById('checkout-addresses-list');
        const manualForm = document.getElementById('checkout-manual-address-form');
        const addAddrBtn = document.getElementById('checkout-add-address-btn');
        const modal = document.getElementById('checkout-address-modal');
        const closeModalBtn = document.getElementById('close-co-modal-btn');
        const cancelModalBtn = document.getElementById('cancel-co-modal-btn');
        const modalForm = document.getElementById('checkout-address-form');
        const placeOrderBtn = document.getElementById('place-order-btn');

        if (!content) return;

        if (addAddrBtn && modal) {
            addAddrBtn.addEventListener('click', () => modal.classList.remove('is-hidden'));
        }
        if (closeModalBtn && modal) {
            closeModalBtn.addEventListener('click', () => modal.classList.add('is-hidden'));
        }
        if (cancelModalBtn && modal) {
            cancelModalBtn.addEventListener('click', () => modal.classList.add('is-hidden'));
        }

        if (modalForm) {
            modalForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const token = await getCsrfToken();
                const payload = {
                    action: 'add',
                    full_name: document.getElementById('mco-fullname').value,
                    phone: document.getElementById('mco-phone').value,
                    address_line1: document.getElementById('mco-line1').value,
                    address_line2: document.getElementById('mco-line2').value,
                    city: document.getElementById('mco-city').value,
                    state: document.getElementById('mco-state').value,
                    postal_code: document.getElementById('mco-postal').value,
                    country: document.getElementById('mco-country').value,
                    is_default: 1,
                    csrf_token: token
                };

                try {
                    const res = await fetch('/backend/api/addresses.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-Token': token || ''
                        },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (res.ok && data.success) {
                        modal.classList.add('is-hidden');
                        modalForm.reset();
                        await loadAddressesForCheckout(data.data?.address_id);
                    } else {
                        alert(data.message || 'Could not save address.');
                    }
                } catch (err) {
                    console.error('Error saving address in checkout modal:', err);
                }
            });
        }

        async function loadAddressesForCheckout(preselectId = null) {
            try {
                const res = await fetch('/backend/api/addresses.php', {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();

                if (res.ok && data.success && data.data?.addresses?.length > 0) {
                    const addresses = data.data.addresses;
                    if (manualForm) manualForm.classList.add('is-hidden');
                    if (addressListEl) {
                        addressListEl.classList.remove('is-hidden');
                        addressListEl.innerHTML = addresses.map((a, idx) => {
                            const isChecked = preselectId ? (a.id === preselectId) : (a.is_default || idx === 0);
                            if (isChecked) selectedCheckoutAddressId = a.id;
                            return `
                                <label class="address-card cursor-pointer ${isChecked ? 'default-address' : ''}">
                                    <div class="flex items-start gap-3">
                                        <input type="radio" name="checkout_selected_address" value="${a.id}" ${isChecked ? 'checked' : ''} class="mt-1" onchange="StyleHub.selectCheckoutAddress(${a.id})">
                                        <div>
                                            <div class="font-bold text-dark">${escapeHtml(a.full_name)} ${a.is_default ? '<span class="badge badge-success ml-2">Default</span>' : ''}</div>
                                            <div class="text-sm text-secondary mt-1">
                                                ${escapeHtml(a.address_line1)}<br>
                                                ${a.address_line2 ? escapeHtml(a.address_line2) + '<br>' : ''}
                                                ${escapeHtml(a.city)}, ${escapeHtml(a.state)} ${escapeHtml(a.postal_code)}<br>
                                                📞 ${escapeHtml(a.phone)}
                                            </div>
                                        </div>
                                    </div>
                                </label>
                            `;
                        }).join('');
                    }
                } else {
                    selectedCheckoutAddressId = null;
                    if (addressListEl) addressListEl.classList.add('is-hidden');
                    if (manualForm) manualForm.classList.remove('is-hidden');
                    if (addAddrBtn) addAddrBtn.classList.add('is-hidden');
                }
            } catch (err) {
                console.warn('Could not load customer addresses for checkout:', err);
                selectedCheckoutAddressId = null;
                if (manualForm) manualForm.classList.remove('is-hidden');
            }
        }

        try {
            const res = await fetch('/backend/api/cart.php', {
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();

            if (skeleton) skeleton.classList.add('is-hidden');

            if (!res.ok || !data.success || !data.data?.items || data.data.items.length === 0) {
                if (emptyView) emptyView.classList.remove('is-hidden');
                if (content) content.classList.add('is-hidden');
                return;
            }

            const cart = data.data;
            if (content) content.classList.remove('is-hidden');

            if (itemsPreview) {
                itemsPreview.innerHTML = cart.items.map(it => `
                    <div class="flex justify-between items-center py-2 border-b border-light text-sm">
                        <div class="flex items-center gap-3">
                            <img src="${escapeHtml(it.image_url)}" alt="${escapeHtml(it.product_name)}" class="avatar-sm rounded" loading="lazy">
                            <div>
                                <div class="font-bold text-dark">${escapeHtml(it.product_name)}</div>
                                <div class="text-xs text-muted">Qty: ${it.quantity} | ${escapeHtml(it.colour_name)}, ${escapeHtml(it.size_name)}</div>
                            </div>
                        </div>
                        <div class="font-bold text-dark">$${it.line_total.toFixed(2)}</div>
                    </div>
                `).join('');
            }

            setText('checkout-subtotal-val', `$${cart.subtotal.toFixed(2)}`);
            setText('checkout-shipping-val', cart.shipping === 0 ? 'Complimentary' : `$${cart.shipping.toFixed(2)}`);
            setText('checkout-total-val', `$${cart.grand_total.toFixed(2)}`);

            const shippingBadge = document.getElementById('checkout-shipping-badge');
            if (shippingBadge) {
                if (cart.free_shipping) {
                    shippingBadge.innerText = '✓ Complimentary express delivery unlocked!';
                } else {
                    shippingBadge.innerText = `Add $${(150 - cart.subtotal).toFixed(2)} more for complimentary delivery`;
                }
            }

            await loadAddressesForCheckout();

        } catch (err) {
            console.error('Error initializing checkout:', err);
            if (skeleton) skeleton.classList.add('is-hidden');
            showAlert(alertBox, 'Failed to initialize checkout. Please try again.', 'error');
        }

        if (placeOrderBtn) {
            placeOrderBtn.addEventListener('click', async function() {
                showAlert(alertBox, '', 'hidden');
                placeOrderBtn.disabled = true;
                const originalText = placeOrderBtn.innerText;
                placeOrderBtn.innerText = 'Processing Order...';

                try {
                    const token = await getCsrfToken();
                    let payload = {
                        csrf_token: token
                    };

                    if (selectedCheckoutAddressId) {
                        payload.address_id = selectedCheckoutAddressId;
                    } else {
                        const fn = document.getElementById('co-fullname')?.value.trim();
                        const ph = document.getElementById('co-phone')?.value.trim();
                        const a1 = document.getElementById('co-line1')?.value.trim();
                        const a2 = document.getElementById('co-line2')?.value.trim();
                        const city = document.getElementById('co-city')?.value.trim();
                        const state = document.getElementById('co-state')?.value.trim();
                        const postal = document.getElementById('co-postal')?.value.trim();
                        const country = document.getElementById('co-country')?.value.trim() || 'United States';

                        if (!fn || !ph || !a1 || !city || !postal) {
                            showAlert(alertBox, 'Please complete all required shipping fields (Name, Phone, Street, City, Postal Code).', 'error');
                            placeOrderBtn.disabled = false;
                            placeOrderBtn.innerText = originalText;
                            return;
                        }

                        payload.full_name = fn;
                        payload.phone = ph;
                        payload.address_line1 = a1;
                        payload.address_line2 = a2;
                        payload.city = city;
                        payload.state = state;
                        payload.postal_code = postal;
                        payload.country = country;
                    }

                    const res = await fetch('/backend/api/checkout.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-Token': token || ''
                        },
                        body: JSON.stringify(payload)
                    });

                    const data = await res.json();
                    if (res.ok && data.success) {
                        showAlert(alertBox, data.message || 'Order placed successfully! Redirecting...', 'success');
                        setTimeout(() => {
                            window.location.href = data.data?.redirect || `/frontend/order-tracking.html?order=${data.data?.order_number}`;
                        }, 1000);
                    } else {
                        showAlert(alertBox, data.message || 'Failed to place order. Please check address details.', 'error');
                        placeOrderBtn.disabled = false;
                        placeOrderBtn.innerText = originalText;
                    }
                } catch (err) {
                    console.error('Checkout error:', err);
                    showAlert(alertBox, 'A network error occurred while processing your order.', 'error');
                    placeOrderBtn.disabled = false;
                    placeOrderBtn.innerText = originalText;
                }
            });
        }
    }

    function selectCheckoutAddress(addressId) {
        selectedCheckoutAddressId = addressId;
        document.querySelectorAll('#checkout-addresses-list .address-card').forEach(card => {
            card.classList.remove('default-address');
        });
        const activeRadio = document.querySelector(`input[name="checkout_selected_address"][value="${addressId}"]`);
        if (activeRadio) {
            const parent = activeRadio.closest('.address-card');
            if (parent) parent.classList.add('default-address');
        }
    }

    /**
     * =========================================================================
     * PHASE 3: CUSTOMER ORDER HISTORY
     * =========================================================================
     */
    async function initOrderHistoryPage() {
        const skeleton = document.getElementById('orders-skeleton-view');
        const content = document.getElementById('orders-content-view');
        const emptyView = document.getElementById('orders-empty-view');
        const alertBox = document.getElementById('orders-alert');
        const subtitle = document.getElementById('orders-count-subtitle');
        const returnModal = document.getElementById('return-modal');
        const closeReturnBtn = document.getElementById('close-return-modal-btn');
        const cancelReturnBtn = document.getElementById('cancel-return-modal-btn');
        const returnForm = document.getElementById('return-request-form');

        if (!content) return;

        if (closeReturnBtn && returnModal) {
            closeReturnBtn.addEventListener('click', () => returnModal.classList.add('is-hidden'));
        }
        if (cancelReturnBtn && returnModal) {
            cancelReturnBtn.addEventListener('click', () => returnModal.classList.add('is-hidden'));
        }

        if (returnForm) {
            returnForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const token = await getCsrfToken();
                const orderId = document.getElementById('return-order-id')?.value;
                const reason = document.getElementById('return-reason')?.value;
                const details = document.getElementById('return-details')?.value;

                if (!reason) {
                    alert('Please select a reason for the return.');
                    return;
                }

                try {
                    const res = await fetch('/backend/api/returns.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-Token': token || ''
                        },
                        body: JSON.stringify({
                            order_id: parseInt(orderId, 10),
                            reason: reason,
                            details: details,
                            csrf_token: token
                        })
                    });
                    const data = await res.json();
                    if (res.ok && data.success) {
                        returnModal.classList.add('is-hidden');
                        returnForm.reset();
                        showAlert(alertBox, data.message || 'Return request submitted successfully.', 'success');
                        loadOrders();
                    } else {
                        alert(data.message || 'Could not submit return request.');
                    }
                } catch (err) {
                    console.error('Error submitting return request:', err);
                }
            });
        }

        async function loadOrders() {
            try {
                const res = await fetch('/backend/api/orders.php', {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();

                if (skeleton) skeleton.classList.add('is-hidden');

                if (res.status === 401) {
                    content.classList.remove('is-hidden');
                    content.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state-icon">🔒</div>
                            <h2 class="empty-state-title">Sign In Required</h2>
                            <p class="empty-state-text">Please sign in to view your StyleHub purchases and order tracking.</p>
                            <a href="/frontend/login.html?redirect=/frontend/order-history.html" class="btn btn-primary mt-4">Sign In</a>
                        </div>
                    `;
                    return;
                }

                if (!res.ok || !data.success) {
                    showAlert(alertBox, data.message || 'Failed to retrieve order history.', 'error');
                    return;
                }

                const orders = data.data?.orders || [];
                if (subtitle) {
                    subtitle.innerText = `${orders.length} ${orders.length === 1 ? 'order' : 'orders'} placed with StyleHub`;
                }

                if (orders.length === 0) {
                    if (emptyView) emptyView.classList.remove('is-hidden');
                    content.classList.add('is-hidden');
                    return;
                }

                content.classList.remove('is-hidden');
                if (emptyView) emptyView.classList.add('is-hidden');

                content.innerHTML = orders.map(ord => {
                    let badgeClass = 'badge-secondary';
                    if (ord.status === 'In Queue') badgeClass = 'badge-info';
                    else if (ord.status === 'Dispatched') badgeClass = 'badge-dark';
                    else if (ord.status === 'Delivered') badgeClass = 'badge-success';
                    else if (ord.status === 'Returned') badgeClass = 'badge-danger';

                    const canReturn = (ord.status === 'Delivered' && !ord.return_id);
                    const hasReturn = !!ord.return_id;

                    return `
                        <div class="card mb-4">
                            <div class="card-body flex justify-between items-center flex-wrap gap-4">
                                <div>
                                    <div class="text-xs text-muted font-bold tracking-wider">REFERENCE</div>
                                    <div class="text-lg font-black text-dark">${escapeHtml(ord.order_number)}</div>
                                    <div class="text-xs text-muted mt-1">Placed on ${escapeHtml(ord.created_at)}</div>
                                </div>
                                <div>
                                    <div class="text-xs text-muted">ITEMS</div>
                                    <div class="font-bold text-dark">${ord.total_items} items</div>
                                </div>
                                <div>
                                    <div class="text-xs text-muted">TOTAL</div>
                                    <div class="font-bold text-primary text-base">$${parseFloat(ord.total_amount).toFixed(2)}</div>
                                </div>
                                <div>
                                    <div class="text-xs text-muted mb-1">STATUS</div>
                                    <span class="badge ${badgeClass}">${escapeHtml(ord.status)}</span>
                                    ${hasReturn ? `<span class="badge badge-warning ml-1">Return: ${escapeHtml(ord.return_status)}</span>` : ''}
                                </div>
                                <div class="flex gap-2">
                                    <a href="/frontend/order-tracking.html?order=${ord.order_number}" class="btn btn-outline btn-sm">Track Order</a>
                                    ${canReturn ? `<button type="button" class="btn btn-primary btn-sm" onclick="StyleHub.openReturnModal(${ord.id}, '${escapeHtml(ord.order_number)}')">Request Return</button>` : ''}
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');

            } catch (err) {
                console.error('Error loading order history:', err);
                if (skeleton) skeleton.classList.add('is-hidden');
            }
        }

        loadOrders();
    }

    function openReturnModal(orderId, orderNumber) {
        const modal = document.getElementById('return-modal');
        if (!modal) return;
        document.getElementById('return-order-id').value = orderId;
        document.getElementById('return-order-reference').value = orderNumber;
        modal.classList.remove('is-hidden');
    }

    /**
     * =========================================================================
     * PHASE 3: REAL-TIME ORDER TRACKING & FULFILLMENT TIMELINE
     * =========================================================================
     */
    async function initOrderTrackingPage() {
        const form = document.getElementById('tracking-search-form');
        const input = document.getElementById('track-order-input');
        const skeleton = document.getElementById('tracking-skeleton-view');
        const detailsView = document.getElementById('tracking-details-view');
        const alertBox = document.getElementById('tracking-alert');
        const returnBtn = document.getElementById('tracking-return-btn');
        const returnModal = document.getElementById('tracking-return-modal');
        const closeReturnModalBtn = document.getElementById('close-track-return-modal-btn');
        const cancelReturnModalBtn = document.getElementById('cancel-track-return-modal-btn');
        const returnForm = document.getElementById('track-return-form');

        if (!detailsView) return;

        if (closeReturnModalBtn && returnModal) {
            closeReturnModalBtn.addEventListener('click', () => returnModal.classList.add('is-hidden'));
        }
        if (cancelReturnModalBtn && returnModal) {
            cancelReturnModalBtn.addEventListener('click', () => returnModal.classList.add('is-hidden'));
        }

        if (returnForm) {
            returnForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const token = await getCsrfToken();
                const orderId = document.getElementById('track-return-order-id')?.value;
                const reason = document.getElementById('track-return-reason')?.value;
                const details = document.getElementById('track-return-details')?.value;

                if (!reason) {
                    alert('Please select a reason for the return.');
                    return;
                }

                try {
                    const res = await fetch('/backend/api/returns.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-Token': token || ''
                        },
                        body: JSON.stringify({
                            order_id: parseInt(orderId, 10),
                            reason: reason,
                            details: details,
                            csrf_token: token
                        })
                    });
                    const data = await res.json();
                    if (res.ok && data.success) {
                        returnModal.classList.add('is-hidden');
                        returnForm.reset();
                        showAlert(alertBox, data.message || 'Return request logged successfully.', 'success');
                        const orderNum = input.value.trim();
                        if (orderNum) fetchTrackingDetails(orderNum);
                    } else {
                        alert(data.message || 'Could not submit return request.');
                    }
                } catch (err) {
                    console.error('Error submitting tracking return request:', err);
                }
            });
        }

        async function fetchTrackingDetails(orderNum) {
            if (!orderNum) return;
            showAlert(alertBox, '', 'hidden');
            if (skeleton) skeleton.classList.remove('is-hidden');
            if (detailsView) detailsView.classList.add('is-hidden');

            try {
                const res = await fetch('/backend/api/orders.php?order_number=' + encodeURIComponent(orderNum), {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();

                if (skeleton) skeleton.classList.add('is-hidden');

                if (!res.ok || !data.success || !data.data?.order) {
                    showAlert(alertBox, data.message || 'Order reference not found. Please verify the code and try again.', 'error');
                    return;
                }

                const order = data.data.order;
                if (detailsView) detailsView.classList.remove('is-hidden');

                setText('display-order-number', order.order_number);
                setText('display-order-date', `Placed on ${order.created_at}`);

                const statusBadge = document.getElementById('display-order-status-badge');
                if (statusBadge) {
                    statusBadge.innerText = order.status;
                    let badgeClass = 'badge-info';
                    if (order.status === 'Pending') badgeClass = 'badge-secondary';
                    else if (order.status === 'Dispatched') badgeClass = 'badge-dark';
                    else if (order.status === 'Delivered') badgeClass = 'badge-success';
                    else if (order.status === 'Returned') badgeClass = 'badge-danger';
                    statusBadge.className = `badge ${badgeClass}`;
                }

                const returnedBanner = document.getElementById('returned-status-banner');
                if (returnedBanner) {
                    returnedBanner.classList.toggle('is-hidden', !order.is_returned);
                }

                const existingReturnBanner = document.getElementById('existing-return-banner');
                if (existingReturnBanner) {
                    if (order.existing_return) {
                        existingReturnBanner.classList.remove('is-hidden');
                        existingReturnBanner.innerHTML = `<strong>Return Request Status:</strong> ${escapeHtml(order.existing_return.status.toUpperCase())} — Reason: ${escapeHtml(order.existing_return.reason)} (Requested on ${escapeHtml(order.existing_return.created_at)})`;
                    } else {
                        existingReturnBanner.classList.add('is-hidden');
                    }
                }

                if (returnBtn) {
                    if (order.is_return_eligible) {
                        returnBtn.classList.remove('is-hidden');
                        returnBtn.onclick = () => {
                            document.getElementById('track-return-order-id').value = order.id;
                            returnModal.classList.remove('is-hidden');
                        };
                    } else {
                        returnBtn.classList.add('is-hidden');
                    }
                }

                const stepPending = document.getElementById('step-pending');
                const stepInQueue = document.getElementById('step-inqueue');
                const stepDispatched = document.getElementById('step-dispatched');
                const stepDelivered = document.getElementById('step-delivered');
                const progressBar = document.getElementById('timeline-progress-bar');
                const dispatchedMeta = document.getElementById('step-dispatched-meta');

                if (dispatchedMeta && order.dispatch_date) {
                    dispatchedMeta.innerText = `Shipped ${order.dispatch_date}`;
                }

                const allSteps = [stepPending, stepInQueue, stepDispatched, stepDelivered];
                allSteps.forEach(s => {
                    if (s) {
                        s.classList.remove('completed', 'current');
                    }
                });

                let progressClass = 'progress-step-1';
                if (order.status === 'Pending') {
                    progressClass = 'progress-step-1';
                    if (stepPending) stepPending.classList.add('current');
                } else if (order.status === 'In Queue') {
                    progressClass = 'progress-step-2';
                    if (stepPending) stepPending.classList.add('completed');
                    if (stepInQueue) stepInQueue.classList.add('current');
                } else if (order.status === 'Dispatched') {
                    progressClass = 'progress-step-3';
                    if (stepPending) stepPending.classList.add('completed');
                    if (stepInQueue) stepInQueue.classList.add('completed');
                    if (stepDispatched) stepDispatched.classList.add('current');
                } else if (order.status === 'Delivered' || order.status === 'Returned') {
                    progressClass = 'progress-step-4';
                    if (stepPending) stepPending.classList.add('completed');
                    if (stepInQueue) stepInQueue.classList.add('completed');
                    if (stepDispatched) stepDispatched.classList.add('completed');
                    if (stepDelivered) stepDelivered.classList.add('completed');
                }

                if (progressBar) {
                    progressBar.className = `timeline-connector-progress ${progressClass}`;
                }

                const itemsList = document.getElementById('tracking-items-list');
                if (itemsList && order.items) {
                    itemsList.innerHTML = order.items.map(it => `
                        <div class="cart-item-row">
                            <img src="${escapeHtml(it.image_url || 'https://images.unsplash.com/photo-1542272604-780c96856592?auto=format&fit=crop&w=600&q=80')}" alt="${escapeHtml(it.product_name)}" class="cart-item-img" loading="lazy">
                            <div class="cart-item-details">
                                <div class="cart-item-name">${escapeHtml(it.product_name)}</div>
                                <div class="cart-item-meta">
                                    <span>Colour: <strong>${escapeHtml(it.colour_name)}</strong></span>
                                    <span>Size: <strong>${escapeHtml(it.size_name)}</strong></span>
                                    <span>Qty: <strong>${it.quantity}</strong></span>
                                </div>
                            </div>
                            <div class="font-bold text-dark">$${parseFloat(it.total_price).toFixed(2)}</div>
                        </div>
                    `).join('');
                }

                const addrEl = document.getElementById('tracking-shipping-address');
                if (addrEl && order.shipping_address) {
                    const a = order.shipping_address;
                    addrEl.innerHTML = `
                        <strong>${escapeHtml(a.name || 'Recipient')}</strong><br>
                        ${escapeHtml(a.line1 || '')}<br>
                        ${a.line2 ? escapeHtml(a.line2) + '<br>' : ''}
                        ${escapeHtml(a.city || '')}, ${escapeHtml(a.state || '')} ${escapeHtml(a.postal_code || '')}<br>
                        ${escapeHtml(a.country || '')}<br>
                        📞 ${escapeHtml(a.phone || '')}
                    `;
                }

                const subtotal = Math.max(0, order.total_amount - order.shipping_amount);
                setText('track-subtotal-val', `$${subtotal.toFixed(2)}`);
                setText('track-shipping-val', order.shipping_amount === 0 ? 'Complimentary' : `$${order.shipping_amount.toFixed(2)}`);
                setText('track-total-val', `$${order.total_amount.toFixed(2)}`);

            } catch (err) {
                console.error('Error fetching order tracking details:', err);
                if (skeleton) skeleton.classList.add('is-hidden');
                showAlert(alertBox, 'Failed to fetch tracking information.', 'error');
            }
        }

        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const code = input ? input.value.trim() : '';
                if (code) {
                    fetchTrackingDetails(code);
                }
            });
        }

        const urlParams = new URLSearchParams(window.location.search);
        const orderParam = urlParams.get('order');
        if (orderParam && input) {
            input.value = orderParam;
            fetchTrackingDetails(orderParam);
        }
    }

    /**
     * Mobile Menu Hamburger toggle
     */
    function initMobileMenu() {
        const toggle = document.querySelector('.mobile-menu-toggle');
        const menu = document.querySelector('.nav-menu');
        if (toggle && menu) {
            toggle.addEventListener('click', () => {
                menu.classList.toggle('is-active');
            });
        }
    }

    /**
     * Helpers
     */
    function showAlert(box, msg, type) {
        if (!box) return;
        if (type === 'hidden' || !msg) {
            box.className = 'alert is-hidden';
            box.innerText = '';
            return;
        }
        box.className = `alert alert-${type}`;
        box.innerText = msg;
    }

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.innerText = value ?? '--';
    }

    function setValue(id, value) {
        const el = document.getElementById(id);
        if (el) {
            el.value = (value !== null && value !== undefined) ? value : '';
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Auto-init on DOMContentLoaded
    document.addEventListener('DOMContentLoaded', function () {
        getCsrfToken();
        checkAuthStatus();
        initMobileMenu();
        initLoginForm();
        initRegisterForm();
        initProfilePage();

        if (document.getElementById('home-product-sections')) {
            initHomePage();
        }

        if (document.getElementById('catalog-products-grid')) {
            initProductsPage();
        }

        if (document.getElementById('product-detail-view')) {
            initProductDetailsPage();
        }

        if (document.getElementById('dashboard-skeleton')) {
            initAdminDashboard();
        }

        if (document.getElementById('cart-main-container')) {
            initCartPage();
        }

        if (document.getElementById('wishlist-main-container')) {
            initWishlistPage();
        }

        if (document.getElementById('addresses-main-container')) {
            initAddressesPage();
        }

        if (document.getElementById('checkout-main-container')) {
            initCheckoutPage();
        }

        if (document.getElementById('orders-main-container')) {
            initOrderHistoryPage();
        }

        if (document.getElementById('tracking-main-container')) {
            initOrderTrackingPage();
        }
    });

    Object.assign(StyleHub, {
        getCsrfToken,
        checkAuthStatus,
        handleLogout,
        initAdminDashboard,
        filterSection,
        addToCart,
        toggleWishlist,
        selectThumb: (url, el) => {
            const mainImg = document.getElementById('main-product-img');
            if (mainImg) mainImg.src = url;
            document.querySelectorAll('.thumb-item').forEach(t => t.classList.remove('active'));
            if (el) el.classList.add('active');
        },
        selectColour: (el) => {
            document.querySelectorAll('.colour-pick-btn').forEach(c => c.classList.remove('active'));
            if (el) el.classList.add('active');
        },
        selectSize: (el) => {
            document.querySelectorAll('.size-pick-btn').forEach(s => s.classList.remove('active'));
            if (el) el.classList.add('active');
        },
        updateCartQuantity,
        removeCartItem,
        clearCart,
        removeFromWishlist,
        moveWishlistToCart,
        setDefaultAddress,
        editAddress,
        deleteAddress,
        selectCheckoutAddress,
        openReturnModal
    });
})(window.StyleHub);
