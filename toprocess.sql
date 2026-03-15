-- 1. Create the new Verified Customers table
CREATE TABLE `verified_customers` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `passcode` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_verified_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Drop the redundant physical status column
ALTER TABLE `tables` DROP COLUMN `status`;

CREATE TABLE login_attempts (
    ip_address VARCHAR(45) PRIMARY KEY,
    attempts INT DEFAULT 1,
    last_attempt DATETIME
);

-- 1. Create the Relational Tables (With strict constraints matching your schema)
CREATE TABLE IF NOT EXISTS refunds (
    id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT(10) UNSIGNED NOT NULL,
    manager_id INT(10) UNSIGNED DEFAULT NULL,
    reason VARCHAR(255) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_refund_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_refund_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refund_items (
    id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    refund_id INT(10) UNSIGNED NOT NULL,
    order_item_id INT(10) UNSIGNED NOT NULL,
    qty INT(10) UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_ri_refund FOREIGN KEY (refund_id) REFERENCES refunds(id) ON DELETE CASCADE,
    CONSTRAINT fk_ri_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Extract your past refunded orders and move them to the new master table
INSERT INTO refunds (order_id, manager_id, reason, total_amount, created_at)
SELECT 
    id, 
    COALESCE(voided_by, 1), 
    COALESCE(void_reason, 'Legacy Full Refund'),
    grand_total,
    updated_at
FROM orders 
WHERE status = 'refunded';

-- 3. Extract the specific items from those refunded orders and link them
INSERT INTO refund_items (refund_id, order_item_id, qty, amount)
SELECT 
    r.id,
    oi.id,
    oi.quantity,
    oi.line_total
FROM order_items oi
JOIN refunds r ON oi.order_id = r.order_id;

-- The Parent: Tracks the "Who" and "Why"
CREATE TABLE IF NOT EXISTS voids (
    id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT(10) UNSIGNED NOT NULL,
    manager_id INT(10) UNSIGNED DEFAULT NULL,
    reason VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_void_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_void_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The Child: Tracks the "What"
CREATE TABLE IF NOT EXISTS void_items (
    id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    void_id INT(10) UNSIGNED NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    quantity INT(10) UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_vi_void FOREIGN KEY (void_id) REFERENCES voids(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;