-- StyleHub Sample Data
-- Populates initial admin, customer accounts, categories, subcategories, products, inventory, orders, reviews

USE `stylehub_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Admins
-- Password for admin: Admin@StyleHub2026!
INSERT INTO `admins` (`id`, `username`, `email`, `password`, `role`) VALUES
(1, 'admin', 'admin@stylehub.local', '$2y$10$supy11BuzT.hUxA3W8Xo9.6f7GZPe2yMhInG5VhM5dTkT/rAvOlt.', 'superadmin');

-- 2. Users (Customers)
-- Password for customers: Customer@StyleHub2026!
INSERT INTO `users` (`id`, `name`, `email`, `password`, `phone`, `status`) VALUES
(1, 'Jane Doe', 'customer@stylehub.local', '$2y$10$SzMfHKp20MemEvE9BsGKp.azxMQGmIkyWhFh835.kYPJ/AN7jvKBO', '+1 (555) 019-2834', 'active'),
(2, 'Alex Morgan', 'alex@stylehub.local', '$2y$10$SzMfHKp20MemEvE9BsGKp.azxMQGmIkyWhFh835.kYPJ/AN7jvKBO', '+1 (555) 014-9982', 'active');

-- 3. Categories (Pants, Shirts, Jackets, Shoes)
INSERT INTO `categories` (`id`, `name`, `slug`, `description`, `image_url`) VALUES
(1, 'Pants', 'pants', 'Tailored trousers, chinos, denim, and relaxed everyday bottoms.', 'https://images.unsplash.com/photo-1542272604-780c96856592?auto=format&fit=crop&w=800&q=80'),
(2, 'Shirts', 'shirts', 'Crisp button-ups, luxury cotton tops, and refined casual tees.', 'https://images.unsplash.com/photo-1602810318383-e386cc2a3ccf?auto=format&fit=crop&w=800&q=80'),
(3, 'Jackets', 'jackets', 'Outerwear, wool coats, trench jackets, and lightweight blousons.', 'https://images.unsplash.com/photo-1551028719-00167b16eac5?auto=format&fit=crop&w=800&q=80'),
(4, 'Shoes', 'shoes', 'Handcrafted leather loafers, dress footwear, and minimalist sneakers.', 'https://images.unsplash.com/photo-1549298916-b41d501d3772?auto=format&fit=crop&w=800&q=80');

-- 4. Subcategories (Men, Women, Children for each category)
INSERT INTO `subcategories` (`id`, `category_id`, `name`, `slug`, `target_group`) VALUES
-- Pants
(1, 1, 'Men\'s Pants', 'mens-pants', 'Men'),
(2, 1, 'Women\'s Pants', 'womens-pants', 'Women'),
(3, 1, 'Children\'s Pants', 'childrens-pants', 'Children'),
-- Shirts
(4, 2, 'Men\'s Shirts', 'mens-shirts', 'Men'),
(5, 2, 'Women\'s Shirts', 'womens-shirts', 'Women'),
(6, 2, 'Children\'s Shirts', 'childrens-shirts', 'Children'),
-- Jackets
(7, 3, 'Men\'s Jackets', 'mens-jackets', 'Men'),
(8, 3, 'Women\'s Jackets', 'womens-jackets', 'Women'),
(9, 3, 'Children\'s Jackets', 'childrens-jackets', 'Children'),
-- Shoes
(10, 4, 'Men\'s Shoes', 'mens-shoes', 'Men'),
(11, 4, 'Women\'s Shoes', 'womens-shoes', 'Women'),
(12, 4, 'Children\'s Shoes', 'childrens-shoes', 'Children');

-- 5. Products
INSERT INTO `products` (`id`, `category_id`, `subcategory_id`, `name`, `slug`, `description`, `price`, `low_stock_threshold`, `is_featured`, `hide_when_out_of_stock`, `manual_badge_message`) VALUES
-- Pants
(1, 1, 1, 'Forest Tailored Wool Trousers', 'forest-tailored-wool-trousers', 'Precision cut from double-twist virgin wool in our signature deep forest green. Features subtle forward pleats and clean tapered hems.', 145.00, 5, 1, 1, 'New Winter Collection'),
(2, 1, 2, 'Olive High-Rise Pleated Chinos', 'olive-high-rise-pleated-chinos', 'Structured yet breathable cotton twill trousers with a relaxed taper through the leg and sustainable corozo nut buttons.', 115.00, 5, 0, 1, 'Staff Pick'),
(3, 1, 3, 'Kids Comfort Stretch Cargo Pants', 'kids-comfort-stretch-cargo-pants', 'Durable organic cotton pants reinforced at knees with flexible elastic waistband for everyday play.', 55.00, 5, 0, 1, NULL),
(4, 1, 1, 'Charcoal Slim Slate Denim', 'charcoal-slim-slate-denim', 'Raw Japanese selvedge denim in charcoal grey with subtle stretch and custom matte black hardware.', 135.00, 4, 0, 1, 'Limited Edition'),

-- Shirts
(5, 2, 1, 'Emerald Poplin Oversized Shirt', 'emerald-poplin-oversized-shirt', 'Crisp 100% GOTS organic cotton poplin in luminous emerald green. Features a relaxed drop-shoulder silhouette and mother-of-pearl buttons.', 98.00, 6, 1, 1, 'Bestseller'),
(6, 2, 2, 'Silk Crepe Relaxed Blouse', 'silk-crepe-relaxed-blouse', 'Fluid mulberry silk shirt in crisp white with covered placket and French seams throughout.', 160.00, 5, 1, 1, 'Pure Silk'),
(7, 2, 3, 'Junior Mini Stripe Oxford Shirt', 'junior-mini-stripe-oxford-shirt', 'Soft brushed cotton yarn-dyed stripes. Pre-washed for cloud-like softness from day one.', 48.00, 4, 0, 1, NULL),
(8, 2, 1, 'Classic White Heavyweight Tee', 'classic-white-heavyweight-tee', '280gsm heavyweight combed cotton tee with reinforced ribbed collar and boxy modern cut.', 42.00, 8, 0, 1, 'Essential'),

-- Jackets
(9, 3, 1, 'Hunter Green Quilted Field Coat', 'hunter-green-quilted-field-coat', 'Diamond-quilted weather-resistant shell filled with recycled insulation, corduroy collar accent, and dual bellow pockets.', 220.00, 5, 1, 1, 'Weatherproof'),
(10, 3, 2, 'Tailored Wool Wrap Trench', 'tailored-wool-wrap-trench', 'Double-faced melton wool trench coat in heather grey featuring a storm flap and matching fabric sash belt.', 295.00, 3, 1, 1, 'Runway Edit'),
(11, 3, 3, 'Kids Forest Puffer Bomber', 'kids-forest-puffer-bomber', 'Thermal-lined hooded cold-weather jacket in two-tone forest green and slate grey.', 85.00, 5, 0, 1, 'Warmth Rated'),
(12, 3, 1, 'Matte Black Minimalist Overshirt', 'matte-black-minimalist-overshirt', 'Technical stretch twill overshirt functioning as an effortless mid-layer or lightweight jacket.', 130.00, 4, 0, 1, NULL),

-- Shoes
(13, 4, 1, 'Forest Calfskin Penny Loafers', 'forest-calfskin-penny-loafers', 'Hand-stitched Italian leather penny loafers with Goodyear-welted leather soles in subtle deep racing green.', 210.00, 4, 1, 1, 'Artisan Handcrafted'),
(14, 4, 2, 'Chunky Slate Leather Derby', 'chunky-slate-leather-derby', 'Polished calf leather derby shoes grounded on a lightweight lugged commando outsole.', 185.00, 3, 0, 1, 'Low Stock Notice'),
(15, 4, 3, 'Children\'s Retro Court Sneaker', 'childrens-retro-court-sneaker', 'Supple white leather upper with forest green suede accents and durable non-marking rubber cupsole.', 65.00, 5, 0, 1, NULL),
(16, 4, 1, 'Monochrome Clean Low Sneakers', 'monochrome-clean-low-sneakers', 'Minimalist white sneaker crafted from full-grain leather with cushioned Ortholite insoles.', 125.00, 5, 1, 1, 'Signature Core');

-- 6. Product Colours
INSERT INTO `product_colours` (`id`, `product_id`, `colour_name`, `colour_code`) VALUES
(1, 1, 'Forest Green', '#166534'),
(2, 1, 'Charcoal Grey', '#334155'),
(3, 2, 'Olive Green', '#3f6212'),
(4, 2, 'Sand White', '#f8fafc'),
(5, 3, 'Khaki Olive', '#4d7c0f'),
(6, 4, 'Charcoal Black', '#0f172a'),
(7, 5, 'Emerald Green', '#15803d'),
(8, 5, 'Crisp White', '#ffffff'),
(9, 6, 'Pure White', '#ffffff'),
(10, 6, 'Sage Mist', '#86efac'),
(11, 7, 'Green Stripe', '#15803d'),
(12, 8, 'Optical White', '#ffffff'),
(13, 8, 'Slate Black', '#0f172a'),
(14, 9, 'Hunter Green', '#14532d'),
(15, 9, 'Midnight Slate', '#1e293b'),
(16, 10, 'Heather Grey', '#64748b'),
(17, 10, 'Deep Pine', '#064e3b'),
(18, 11, 'Forest Green', '#15803d'),
(19, 12, 'Matte Black', '#09090b'),
(20, 13, 'Deep Green Leather', '#14532d'),
(21, 13, 'Ebony Black', '#020617'),
(22, 14, 'Slate Grey Leather', '#475569'),
(23, 15, 'White / Green Accent', '#15803d'),
(24, 16, 'Pure White', '#ffffff');

-- 7. Product Sizes
INSERT INTO `product_sizes` (`id`, `product_id`, `size_name`) VALUES
(1, 1, '30W'), (2, 1, '32W'), (3, 1, '34W'), (4, 1, '36W'),
(5, 2, 'S'), (6, 2, 'M'), (7, 2, 'L'),
(8, 3, '6-7Y'), (9, 3, '8-9Y'), (10, 3, '10-11Y'),
(11, 4, '30W'), (12, 4, '32W'), (13, 4, '34W'),
(14, 5, 'S'), (15, 5, 'M'), (16, 5, 'L'), (17, 5, 'XL'),
(18, 6, 'XS'), (19, 6, 'S'), (20, 6, 'M'), (21, 6, 'L'),
(22, 7, '6-7Y'), (23, 7, '8-9Y'), (24, 7, '10-11Y'),
(25, 8, 'S'), (26, 8, 'M'), (27, 8, 'L'), (28, 8, 'XL'),
(29, 9, 'M'), (30, 9, 'L'), (31, 9, 'XL'),
(32, 10, 'XS'), (33, 10, 'S'), (34, 10, 'M'),
(35, 11, '6-7Y'), (36, 11, '8-9Y'), (37, 11, '10-11Y'),
(38, 12, 'S'), (39, 12, 'M'), (40, 12, 'L'),
(41, 13, 'EU 41'), (42, 13, 'EU 42'), (43, 13, 'EU 43'), (44, 13, 'EU 44'),
(45, 14, 'EU 38'), (46, 14, 'EU 39'), (47, 14, 'EU 40'),
(48, 15, 'EU 31'), (49, 15, 'EU 32'), (50, 15, 'EU 33'),
(51, 16, 'EU 41'), (52, 16, 'EU 42'), (53, 16, 'EU 43'), (54, 16, 'EU 44');

-- 8. Product Inventory
-- Notice: some items have healthy stock (15-30), some have LOW stock (<= threshold, e.g. 2, 3), some are out of stock (0)
INSERT INTO `product_inventory` (`product_id`, `colour_id`, `size_id`, `stock_quantity`) VALUES
-- Forest Tailored Wool Trousers (Healthy stock: 18 total)
(1, 1, 1, 5), (1, 1, 2, 6), (1, 1, 3, 4), (1, 2, 2, 3),
-- Olive High-Rise Pleated Chinos (Low stock item! total = 3, threshold is 5)
(2, 3, 5, 1), (2, 3, 6, 2), (2, 4, 6, 0),
-- Kids Cargo Pants (Healthy)
(3, 5, 8, 10), (3, 5, 9, 8),
-- Charcoal Slim Slate Denim (Healthy)
(4, 6, 11, 7), (4, 6, 12, 12),
-- Emerald Poplin Oversized Shirt (Healthy)
(5, 7, 14, 15), (5, 7, 15, 20), (5, 8, 15, 12),
-- Silk Crepe Relaxed Blouse (Low stock item! total = 3, threshold is 5)
(6, 9, 18, 1), (6, 9, 19, 2), (6, 10, 19, 0),
-- Junior Mini Stripe Oxford Shirt (Healthy)
(7, 11, 22, 14), (7, 11, 23, 10),
-- Classic White Heavyweight Tee (High stock)
(8, 12, 25, 30), (8, 12, 26, 45), (8, 13, 26, 25),
-- Hunter Green Quilted Field Coat (Healthy)
(9, 14, 29, 6), (9, 14, 30, 8), (9, 15, 30, 4),
-- Tailored Wool Wrap Trench (Low stock item! total = 2, threshold is 3)
(10, 16, 32, 1), (10, 16, 33, 1), (10, 17, 34, 0),
-- Kids Forest Puffer Bomber (Healthy)
(11, 18, 35, 10), (11, 18, 36, 12),
-- Matte Black Minimalist Overshirt (Low stock! total = 2, threshold is 4)
(12, 19, 38, 1), (12, 19, 39, 1),
-- Forest Calfskin Penny Loafers (Healthy)
(13, 20, 41, 5), (13, 20, 42, 6), (13, 21, 42, 4),
-- Chunky Slate Leather Derby (Low stock! total = 2, threshold is 3)
(14, 22, 45, 1), (14, 22, 46, 1),
-- Children's Retro Court Sneaker (Healthy)
(15, 23, 48, 8), (15, 23, 49, 10),
-- Monochrome Clean Low Sneakers (Healthy)
(16, 24, 51, 12), (16, 24, 52, 15), (16, 24, 53, 10);

-- 9. Product Images
INSERT INTO `product_images` (`product_id`, `image_url`, `is_primary`, `alt_text`) VALUES
(1, 'https://images.unsplash.com/photo-1542272604-780c96856592?auto=format&fit=crop&w=800&q=80', 1, 'Forest green tailored wool trousers on display'),
(2, 'https://images.unsplash.com/photo-1509551388413-e18d0ac5d495?auto=format&fit=crop&w=800&q=80', 1, 'Olive high-rise pleated chinos styled with neutral tones'),
(3, 'https://images.unsplash.com/photo-1519457431-44ccd64a579b?auto=format&fit=crop&w=800&q=80', 1, 'Kids comfort stretch cargo pants in khaki green'),
(4, 'https://images.unsplash.com/photo-1541099649105-f69ad21f3246?auto=format&fit=crop&w=800&q=80', 1, 'Charcoal grey slim slate denim trousers'),
(5, 'https://images.unsplash.com/photo-1602810318383-e386cc2a3ccf?auto=format&fit=crop&w=800&q=80', 1, 'Emerald poplin oversized button-up shirt'),
(6, 'https://images.unsplash.com/photo-1598554747436-c9293d6a588f?auto=format&fit=crop&w=800&q=80', 1, 'Silk crepe relaxed white blouse'),
(7, 'https://images.unsplash.com/photo-1503944583220-79d8926ad5e2?auto=format&fit=crop&w=800&q=80', 1, 'Junior striped cotton oxford shirt'),
(8, 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=800&q=80', 1, 'Classic crisp heavyweight white crewneck tee'),
(9, 'https://images.unsplash.com/photo-1551028719-00167b16eac5?auto=format&fit=crop&w=800&q=80', 1, 'Hunter green quilted outdoor field jacket'),
(10, 'https://images.unsplash.com/photo-1539571696357-5a69c17a67c6?auto=format&fit=crop&w=800&q=80', 1, 'Tailored wool wrap trench coat in heather grey'),
(11, 'https://images.unsplash.com/photo-1514989940723-e8e51635b782?auto=format&fit=crop&w=800&q=80', 1, 'Kids hooded forest green winter jacket'),
(12, 'https://images.unsplash.com/photo-1591047139829-d91aecb6caea?auto=format&fit=crop&w=800&q=80', 1, 'Minimalist matte black jacket overshirt'),
(13, 'https://images.unsplash.com/photo-1549298916-b41d501d3772?auto=format&fit=crop&w=800&q=80', 1, 'Forest green artisan calfskin penny loafers'),
(14, 'https://images.unsplash.com/photo-1614252369475-531eba835eb1?auto=format&fit=crop&w=800&q=80', 1, 'Chunky lugged slate grey leather derby shoes'),
(15, 'https://images.unsplash.com/photo-1514989940723-e8e51635b782?auto=format&fit=crop&w=800&q=80', 1, 'Children clean court sneakers with green heel patch'),
(16, 'https://images.unsplash.com/photo-1560769629-975ec94e6a86?auto=format&fit=crop&w=800&q=80', 1, 'Monochrome clean white leather minimalist sneakers');

-- 10. Addresses for Jane Doe (user_id = 1)
INSERT INTO `addresses` (`id`, `user_id`, `full_name`, `phone`, `address_line1`, `address_line2`, `city`, `state`, `postal_code`, `country`, `is_default`) VALUES
(1, 1, 'Jane Doe', '+1 (555) 019-2834', '142 Evergreen Terrace', 'Apt 4B', 'Portland', 'Oregon', '97201', 'United States', 1),
(2, 1, 'Jane Doe', '+1 (555) 019-2834', '88 Market Street', 'Suite 1200', 'San Francisco', 'California', '94105', 'United States', 0);

-- 11. Orders
INSERT INTO `orders` (`id`, `order_number`, `user_id`, `address_id`, `total_amount`, `shipping_amount`, `tax_amount`, `status`, `payment_method`, `dispatch_date`) VALUES
(1, 'ORD-2026-1001', 1, 1, 243.00, 0.00, 0.00, 'Delivered', 'Cash on Delivery (Demo)', '2026-11-01 10:30:00'),
(2, 'ORD-2026-1002', 1, 1, 220.00, 0.00, 0.00, 'Dispatched', 'Cash on Delivery (Demo)', '2026-11-08 14:15:00'),
(3, 'ORD-2026-1003', 1, 1, 145.00, 10.00, 0.00, 'In Queue', 'Cash on Delivery (Demo)', NULL),
(4, 'ORD-2026-1004', 1, 2, 185.00, 0.00, 0.00, 'Returned', 'Cash on Delivery (Demo)', '2026-10-25 09:00:00'),
(5, 'ORD-2026-1005', 2, NULL, 98.00, 10.00, 0.00, 'Pending', 'Cash on Delivery (Demo)', NULL);

-- 12. Order Items
INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `product_name`, `colour_name`, `size_name`, `quantity`, `unit_price`, `total_price`) VALUES
(1, 1, 1, 'Forest Tailored Wool Trousers', 'Forest Green', '32W', 1, 145.00, 145.00),
(2, 1, 5, 'Emerald Poplin Oversized Shirt', 'Emerald Green', 'M', 1, 98.00, 98.00),
(3, 2, 9, 'Hunter Green Quilted Field Coat', 'Hunter Green', 'L', 1, 220.00, 220.00),
(4, 3, 1, 'Forest Tailored Wool Trousers', 'Charcoal Grey', '32W', 1, 145.00, 145.00),
(5, 4, 14, 'Chunky Slate Leather Derby', 'Slate Grey Leather', 'EU 39', 1, 185.00, 185.00),
(6, 5, 5, 'Emerald Poplin Oversized Shirt', 'Crisp White', 'M', 1, 98.00, 98.00);

-- 13. Returns
INSERT INTO `returns` (`id`, `order_id`, `user_id`, `reason`, `details`, `status`, `admin_notes`) VALUES
(1, 4, 1, 'Size Too Small', 'Derby shoes were too tight across the instep. Customer requested return.', 'Completed', 'Item inspected and returned to inventory in prime condition.'),
(2, 2, 1, 'Changed Mind', 'Customer prefers lighter jacket shade.', 'Pending', 'Awaiting receipt of parcel at central warehouse.');

-- 14. Reviews (Ratings and review comments)
INSERT INTO `reviews` (`id`, `product_id`, `user_id`, `rating`, `title`, `comment`, `is_approved`) VALUES
(1, 1, 1, 5, 'Flawless tailoring & drape', 'The deep forest green tone is stunning in person. Wool feels ultra premium and hangs cleanly with zero wrinkles.', 1),
(2, 1, 2, 5, 'Exceptional quality', 'Fits true to size. Wore it to an exhibition and received multiple compliments.', 1),
(3, 5, 1, 5, 'Crisp poplin perfection', 'Great oversized fit without looking sloppy. Holds its structure all day long.', 1),
(4, 5, 2, 4, 'Vibrant green shade', 'Very nice organic cotton handfeel. Sleeves are slightly generous but looks very chic cuffed.', 1),
(5, 9, 1, 5, 'Warm and sophisticated', 'The corduroy trim adds a refined heritage touch. Warm yet surprisingly lightweight.', 1),
(6, 10, 2, 5, 'Pure luxury coat', 'Heavyweight melton wool that blocks cold drafts completely. Worth every penny.', 1),
(7, 13, 1, 5, 'Superb craftsmanship', 'The leather finish and stitching rival bespoke makers. Extremely comfortable.', 1),
(8, 16, 2, 4, 'Clean minimalist sneaker', 'Very soft leather with immediate comfort out of the box.', 1);

-- 15. Live Review Activity (Viewers counter)
INSERT INTO `live_review_activity` (`product_id`, `active_viewers`, `recent_views_count`) VALUES
(1, 14, 218),
(2, 6, 84),
(3, 3, 42),
(4, 8, 97),
(5, 19, 340),
(6, 9, 115),
(7, 4, 58),
(8, 22, 412),
(9, 17, 289),
(10, 12, 194),
(11, 5, 76),
(12, 7, 102),
(13, 11, 165),
(14, 8, 122),
(15, 6, 91),
(16, 25, 480);

-- 16. Product Admin Notes
INSERT INTO `product_admin_notes` (`product_id`, `admin_id`, `note`) VALUES
(1, 1, 'Restock planned for mid-November with mill in Biella.'),
(2, 1, 'Monitor low stock alert; supplier lead time is 3 weeks.'),
(10, 1, 'Featured in upcoming editorial campaign.'),
(14, 1, 'Re-order 50 units in EU sizes 40-44 once supplier confirms batch.');

-- 17. Sample Wishlist for customer (user_id = 1)
INSERT INTO `wishlists` (`id`, `user_id`, `session_id`) VALUES
(1, 1, 'sess_demo_jane_doe');

INSERT INTO `wishlist_items` (`wishlist_id`, `product_id`) VALUES
(1, 9),
(1, 10),
(1, 13);

-- 18. Sample Cart for customer (user_id = 1)
INSERT INTO `carts` (`id`, `user_id`, `session_id`) VALUES
(1, 1, 'sess_demo_jane_doe');

INSERT INTO `cart_items` (`cart_id`, `product_id`, `colour_id`, `size_id`, `quantity`) VALUES
(1, 1, 1, 2, 1),
(1, 8, 12, 26, 2);

SET FOREIGN_KEY_CHECKS = 1;

