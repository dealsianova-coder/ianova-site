-- =========================================================
-- IANOVA — database schema + seed data
-- Import with:  mysql -u root -p < schema.sql
-- =========================================================


-- ---------------------------------------------------------
-- Categories
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80)  NOT NULL,
    slug        VARCHAR(80)  NOT NULL UNIQUE,
    emoji       VARCHAR(10)  NOT NULL DEFAULT '🛍️',
    sort_order  INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Sellers
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS sellers (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    business_name  VARCHAR(150) NOT NULL,
    email          VARCHAR(160) NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    phone                  VARCHAR(40)  DEFAULT NULL,
    business_description   TEXT DEFAULT NULL,
    status         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Products
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    category_id      INT NOT NULL,
    name             VARCHAR(160) NOT NULL,
    slug             VARCHAR(160) NOT NULL UNIQUE,
    description      TEXT,
    price            DECIMAL(10,2) NOT NULL,
    original_price   DECIMAL(10,2) DEFAULT NULL,
    emoji            VARCHAR(10) NOT NULL DEFAULT '📦',
    bg_color         VARCHAR(10) NOT NULL DEFAULT '#EFF3EC',
    rating           DECIMAL(2,1) NOT NULL DEFAULT 4.5,
    review_count     INT NOT NULL DEFAULT 0,
    stock            INT NOT NULL DEFAULT 100,
    is_flash_deal    TINYINT(1) NOT NULL DEFAULT 0,
    seller_name      VARCHAR(120) DEFAULT 'IANOVA Direct',
    seller_id        INT DEFAULT NULL,
    image            VARCHAR(255) DEFAULT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE SET NULL,
    FULLTEXT KEY ft_search (name, description)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Users
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Orders
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT DEFAULT NULL,
    guest_name   VARCHAR(120) DEFAULT NULL,
    guest_email  VARCHAR(160) DEFAULT NULL,
    guest_phone  VARCHAR(40)  DEFAULT NULL,
    address      VARCHAR(255) DEFAULT NULL,
    total        DECIMAL(10,2) NOT NULL DEFAULT 0,
    status       ENUM('pending','paid','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    order_id    INT NOT NULL,
    product_id  INT NOT NULL,
    quantity    INT NOT NULL DEFAULT 1,
    unit_price  DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wishlist (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    product_id  INT NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_product (user_id, product_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ---------------------------------------------------------
-- Administrators and store settings
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    last_login    DATETIME NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(80) PRIMARY KEY,
    setting_value TEXT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Newsletter
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    email          VARCHAR(160) NOT NULL UNIQUE,
    subscribed_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =========================================================
-- Seed data
-- =========================================================

INSERT INTO categories (name, slug, emoji, sort_order) VALUES
('Electronics',   'electronics',   '📱', 1),
('Fashion',       'fashion',       '👗', 2),
('Groceries',     'groceries',     '🥦', 3),
('Home & Living', 'home-living',   '🛋️', 4),
('Beauty',        'beauty',        '💄', 5),
('Baby & Kids',   'baby-kids',     '🧸', 6),
('Sports',        'sports',        '🏀', 7),
('Gaming',        'gaming',        '🎮', 8);

INSERT INTO products
(category_id, name, slug, description, price, original_price, emoji, bg_color, rating, review_count, stock, is_flash_deal, seller_name) VALUES
(1, 'Wireless earbuds, noise cancelling', 'wireless-earbuds-nc', 'True-wireless earbuds with active noise cancellation and 30-hour battery life.', 34.99, 56.00, '🎧', '#FBEAD9', 4.5, 214, 120, 1, 'SoundHub'),
(4, 'Cooking oil, 2L bottle', 'cooking-oil-2l', 'Refined vegetable cooking oil, cholesterol-free, 2 litre bottle.', 6.10, 7.60, '🧴', '#E4F0E9', 5.0, 98, 500, 1, 'IANOVA Direct'),
(2, 'Kids running sneakers', 'kids-running-sneakers', 'Lightweight breathable sneakers for active kids, sizes 28–35.', 19.50, NULL, '👟', '#F3E7F5', 4.5, 56, 80, 0, 'StepRight Kids'),
(3, 'Long grain rice, 5kg', 'long-grain-rice-5kg', 'Premium long grain rice, 5kg bag, restocked weekly.', 8.40, 9.60, '🍚', '#FCE9E4', 5.0, 340, 900, 1, 'IANOVA Direct'),
(1, '43" smart LED television', 'smart-led-tv-43', '43-inch smart LED TV, full HD, built-in streaming apps.', 289.00, NULL, '📺', '#E6EEF7', 4.5, 122, 40, 0, 'VisionTech'),
(4, 'Storage basket set, 3pc', 'storage-basket-set-3pc', 'Woven storage baskets, set of 3 sizes, for home organisation.', 14.20, 16.70, '🧺', '#FCEFD8', 4.5, 41, 150, 1, 'HomeCraft'),
(5, 'Shea butter body cream', 'shea-butter-body-cream', 'Pure shea butter body cream, 250ml, for dry and sensitive skin.', 9.90, NULL, '🧴', '#E9F2E1', 5.0, 187, 300, 0, 'PureGlow'),
(8, 'Wireless game controller', 'wireless-game-controller', 'Bluetooth wireless controller, compatible with PC and console.', 22.40, 29.90, '🎮', '#F5E8E1', 4.5, 73, 90, 1, 'PlayCore'),
(1, 'Bluetooth portable speaker', 'bluetooth-portable-speaker', 'Waterproof portable speaker with 12-hour battery.', 27.50, 39.00, '🔊', '#E6EEF7', 4.5, 165, 110, 0, 'SoundHub'),
(2, 'Men''s cotton t-shirt, 3-pack', 'mens-cotton-tshirt-3pack', '100% cotton crew neck t-shirts, pack of 3.', 15.00, NULL, '👕', '#F3E7F5', 4.5, 89, 240, 0, 'BasicWear'),
(3, 'Fresh eggs, tray of 30', 'fresh-eggs-tray-30', 'Farm-fresh grade A eggs, tray of 30.', 5.30, NULL, '🥚', '#FCE9E4', 4.5, 210, 400, 0, 'IANOVA Direct'),
(6, 'Baby stroller, foldable', 'baby-stroller-foldable', 'Lightweight foldable stroller with adjustable canopy.', 64.00, 82.00, '🍼', '#FBEAD9', 4.5, 47, 25, 1, 'LittleSteps');
