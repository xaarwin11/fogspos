-- ============================================================
--  FOGS POS — MASTER DATABASE SCHEMA (FINAL GOLD VERSION)
--  - Zero Redundancy
--  - Smart Occupancy View included
--  - Optimized for Mobile & Desktop POS
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4;

-- 1. ROLES
CREATE TABLE `roles` (
    `id`          TINYINT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `role_name`   VARCHAR(50)         NOT NULL,
    `description` TEXT                DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`id`, `role_name`, `description`) VALUES
(1, 'admin',   'Full access to all settings and reports'),
(2, 'manager', 'Can void orders and apply discounts'),
(3, 'staff',   'Can take orders only');

-- 2. USERS
CREATE TABLE `users` (
    `id`          INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `username`    VARCHAR(64)         NOT NULL COMMENT 'Display name only',
    `passcode`    VARCHAR(255)        NOT NULL COMMENT 'Bcrypt hash',
    `role_id`     TINYINT UNSIGNED    NOT NULL,
    `first_name`  VARCHAR(100)        DEFAULT NULL,
    `last_name`   VARCHAR(100)        DEFAULT NULL,
    `hourly_rate` DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
    `is_active`   TINYINT(1)          NOT NULL DEFAULT 1,
    `created_at`  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username`  (`username`),
    UNIQUE KEY `uq_passcode`  (`passcode`),
    KEY `idx_role_id`         (`role_id`),
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. CATEGORIES
CREATE TABLE `categories` (
    `id`         INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(100)        NOT NULL,
    `cat_type`   ENUM('food','drink','other') NOT NULL DEFAULT 'food',
    `sort_order` TINYINT UNSIGNED    NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1)          NOT NULL DEFAULT 1,
    `created_at` DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_category_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. MODIFIERS
CREATE TABLE `modifiers` (
    `id`         INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(100)        NOT NULL,
    `price`      DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
    `is_active`  TINYINT(1)          NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. PRODUCTS (CLEANED: No redundant flags)
CREATE TABLE `products` (
    `id`            INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `category_id`   INT UNSIGNED        NOT NULL,
    `name`          VARCHAR(200)        NOT NULL,
    `price`         DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
    `available`     TINYINT(1)          NOT NULL DEFAULT 1,
    `show_on_kds`   TINYINT(1)          NOT NULL DEFAULT 0,
    `sort_order`    SMALLINT UNSIGNED   NOT NULL DEFAULT 0,
    `created_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_category_id`       (`category_id`),
    KEY `idx_available`         (`available`),
    CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. PRODUCT → MODIFIER
CREATE TABLE `product_modifiers` (
    `product_id`  INT UNSIGNED NOT NULL,
    `modifier_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`product_id`, `modifier_id`),
    KEY `idx_pm_modifier_id` (`modifier_id`),
    CONSTRAINT `fk_pm_product`  FOREIGN KEY (`product_id`)  REFERENCES `products`  (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pm_modifier` FOREIGN KEY (`modifier_id`) REFERENCES `modifiers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. CATEGORY → MODIFIER
CREATE TABLE `category_modifiers` (
    `category_id` INT UNSIGNED NOT NULL,
    `modifier_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`category_id`, `modifier_id`),
    KEY `idx_cm_modifier_id` (`modifier_id`),
    CONSTRAINT `fk_cm_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cm_modifier` FOREIGN KEY (`modifier_id`) REFERENCES `modifiers`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. PRODUCT VARIATIONS
CREATE TABLE `product_variations` (
    `id`         INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED        NOT NULL,
    `name`       VARCHAR(100)        NOT NULL,
    `price`      DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
    `sort_order` TINYINT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_variation_product` (`product_id`),
    CONSTRAINT `fk_variation_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. TABLES
CREATE TABLE `tables` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `table_number` VARCHAR(20)     NOT NULL,
    `table_type`   ENUM('physical','virtual') NOT NULL DEFAULT 'physical',
    `status`       ENUM('available','occupied') NOT NULL DEFAULT 'available',
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_table_number` (`table_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. DISCOUNTS
CREATE TABLE `discounts` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100)    NOT NULL,
    `type`        ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    `value`       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    `target_type` ENUM('all','highest','food','drink','custom') NOT NULL DEFAULT 'all',
    `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. ORDERS
CREATE TABLE `orders` (
    `id`             INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `table_id`       INT UNSIGNED        DEFAULT NULL,
    `order_type`     ENUM('dine_in','takeout') NOT NULL DEFAULT 'dine_in',
    `status`         ENUM('open','paid','voided') NOT NULL DEFAULT 'open',
    `discount_id`    INT UNSIGNED        DEFAULT NULL,
    `subtotal`       DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
    `discount_total` DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
    `discount_note`  VARCHAR(100)        DEFAULT NULL,
    `grand_total`    DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
    `reference`      VARCHAR(64)         DEFAULT NULL,
    `hidden_in_kds`  TINYINT(1)          NOT NULL DEFAULT 0,
    `checked_out_by` INT UNSIGNED        DEFAULT NULL,
    `voided_by`      INT UNSIGNED        DEFAULT NULL,
    `void_reason`    VARCHAR(255)        DEFAULT NULL,
    `paid_at`        DATETIME            DEFAULT NULL,
    `created_at`     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_orders_table_id`      (`table_id`),
    KEY `idx_orders_status`        (`status`),
    KEY `idx_orders_discount_id`   (`discount_id`),
    KEY `idx_orders_checked_out`   (`checked_out_by`),
    KEY `idx_orders_voided_by`     (`voided_by`),
    KEY `idx_orders_created_at`    (`created_at`),
    CONSTRAINT `fk_orders_table`        FOREIGN KEY (`table_id`)       REFERENCES `tables`    (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_orders_discount`     FOREIGN KEY (`discount_id`)    REFERENCES `discounts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_orders_checked_by`   FOREIGN KEY (`checked_out_by`) REFERENCES `users`     (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_orders_voided_by`    FOREIGN KEY (`voided_by`)      REFERENCES `users`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. ORDER ITEMS
CREATE TABLE `order_items` (
    `id`              INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `order_id`        INT UNSIGNED        NOT NULL,
    `product_id`      INT UNSIGNED        NOT NULL,
    `variation_id`    INT UNSIGNED        DEFAULT NULL,
    `product_name`    VARCHAR(200)        NOT NULL,
    `variation_name`  VARCHAR(100)        DEFAULT NULL,
    `base_price`      DECIMAL(10,2)       NOT NULL,
    `modifier_total`  DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
    `discount_note`   VARCHAR(255)        DEFAULT NULL,
    `line_total`      DECIMAL(10,2)       NOT NULL,
    `quantity`        INT UNSIGNED        NOT NULL DEFAULT 1,
    `served`          INT UNSIGNED        NOT NULL DEFAULT 0,
    `kitchen_printed` INT UNSIGNED        NOT NULL DEFAULT 0,
    `created_at`      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_oi_order_id`     (`order_id`),
    KEY `idx_oi_product_id`   (`product_id`),
    KEY `idx_oi_variation_id` (`variation_id`),
    CONSTRAINT `fk_oi_order`     FOREIGN KEY (`order_id`)     REFERENCES `orders`             (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_oi_product`   FOREIGN KEY (`product_id`)   REFERENCES `products`           (`id`),
    CONSTRAINT `fk_oi_variation` FOREIGN KEY (`variation_id`) REFERENCES `product_variations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. ORDER ITEM MODIFIERS
CREATE TABLE `order_item_modifiers` (
    `id`            INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `order_item_id` INT UNSIGNED        NOT NULL,
    `modifier_id`   INT UNSIGNED        NOT NULL,
    `name`          VARCHAR(100)        NOT NULL,
    `price`         DECIMAL(10,2)       NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_oim_order_item` (`order_item_id`),
    CONSTRAINT `fk_oim_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. PAYMENTS
CREATE TABLE `payments` (
    `id`           INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `order_id`     INT UNSIGNED        NOT NULL,
    `method`       ENUM('cash','gcash','card','other') NOT NULL DEFAULT 'cash',
    `amount`       DECIMAL(10,2)       NOT NULL,
    `change_given` DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
    `processed_by` INT UNSIGNED        DEFAULT NULL,
    `created_at`   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_payments_order` (`order_id`),
    CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`)     REFERENCES `orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_payments_user`  FOREIGN KEY (`processed_by`) REFERENCES `users`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. PRINTERS
CREATE TABLE `printers` (
    `id`              INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `printer_label`   VARCHAR(100)        NOT NULL,
    `role`            ENUM('receipt','kitchen','bar') NOT NULL DEFAULT 'receipt',
    `connection_type` ENUM('usb','lan')   NOT NULL,
    `path`            VARCHAR(255)        NOT NULL,
    `port`            INT                 NOT NULL DEFAULT 9100,
    `character_limit` INT                 NOT NULL DEFAULT 48,
    `beep_on_print`   TINYINT(1)          NOT NULL DEFAULT 0,
    `cut_after_print` TINYINT(1)          NOT NULL DEFAULT 1,
    `is_active`       TINYINT(1)          NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. SYSTEM SETTINGS
CREATE TABLE `system_settings` (
    `setting_key`   VARCHAR(64)     NOT NULL,
    `setting_value` TEXT            DEFAULT NULL,
    `category`      ENUM('business','pos','financial','hardware') NOT NULL DEFAULT 'pos',
    `description`   VARCHAR(255)    DEFAULT NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `category`, `description`) VALUES
('store_name',       'FogsTasa\'s Cafe',   'business',  'Printed on receipts'),
('store_address',    'San Esteban, Ilocos Sur', 'business', 'Printed on receipts'),
('currency_symbol',  '₱',                 'pos',       'Shown before all prices'),
('vat_rate',         '0',                 'financial', 'VAT percentage (0 to disable)'),
('route_receipt',    '1',                 'hardware',  'Enable receipt printer routing'),
('route_kitchen',    '1',                 'hardware',  'Enable kitchen printer routing'),
('route_bar',        '1',                 'hardware',  'Enable bar printer routing');

-- 17. TIME TRACKING
CREATE TABLE `time_tracking` (
    `id`           INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED        NOT NULL,
    `clock_in`     DATETIME            NOT NULL,
    `clock_out`    DATETIME            DEFAULT NULL,
    `hours_worked` DECIMAL(10,2)       DEFAULT NULL,
    `created_at`   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tt_user_id`   (`user_id`),
    KEY `idx_tt_clock_in`  (`clock_in`),
    CONSTRAINT `fk_tt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. AUDIT LOG
CREATE TABLE `audit_log` (
    `id`          INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED        DEFAULT NULL,
    `action_type` ENUM(
        'login', 'logout',
        'order_created', 'order_updated', 'order_paid', 'order_voided',
        'product_created', 'product_updated', 'product_deleted',
        'discount_applied',
        'user_created', 'user_updated', 'setting_changed'
    ) NOT NULL,
    `target_type` VARCHAR(50)     DEFAULT NULL,
    `target_id`   INT UNSIGNED    DEFAULT NULL,
    `details`     JSON            DEFAULT NULL,
    `ip_address`  VARCHAR(45)     DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_al_user_id`     (`user_id`),
    KEY `idx_al_action_type` (`action_type`),
    KEY `idx_al_created_at`  (`created_at`),
    CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  19. THE "SMART" TABLE STATUS (THE VIEW)
--  Automatically tracks occupancy based on open orders.
-- ============================================================
DROP VIEW IF EXISTS view_table_status;

CREATE VIEW view_table_status AS
SELECT 
    t.id,
    t.table_number,
    t.table_type,
    COALESCE(o.id, 0) as active_order_id,
    COALESCE(o.grand_total, 0.00) as current_total,
    CASE 
        WHEN o.id IS NOT NULL THEN 'occupied' 
        ELSE 'available' 
    END as status
FROM tables t
LEFT JOIN orders o ON t.id = o.table_id AND o.status = 'open';