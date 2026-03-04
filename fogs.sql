

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;

CREATE TABLE `audit_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action_type` enum('login','logout','order_created','order_updated','order_paid','order_voided','product_created','product_updated','product_deleted','discount_applied','user_created','user_updated','setting_changed','payment','refund') NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int(10) UNSIGNED DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `cat_type` enum('food','drink','other') NOT NULL DEFAULT 'food',
  `sort_order` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


INSERT INTO `categories` (`id`, `name`, `cat_type`, `sort_order`, `is_active`, `created_at`) VALUES
(1, 'Iced Coffee', 'drink', 1, 1, '2026-02-21 08:15:49'),
(2, 'Sandwich', 'food', 8, 1, '2026-02-21 08:15:49'),
(3, 'Rice Meal', 'food', 9, 1, '2026-02-21 08:15:49'),
(4, 'Hot Coffee', 'drink', 2, 1, '2026-02-21 08:15:49'),
(5, 'Frappe', 'drink', 5, 1, '2026-02-21 08:15:49'),
(6, 'Milktea', 'drink', 6, 1, '2026-02-21 08:15:49'),
(7, 'Drinks', 'drink', 5, 1, '2026-02-21 08:15:49'),
(8, 'Non-coffee', 'drink', 3, 1, '2026-02-21 08:15:49'),
(9, 'Pasta', 'food', 10, 1, '2026-02-21 08:15:49'),
(10, 'Soup', 'food', 11, 1, '2026-02-21 08:15:49'),
(11, 'Pica-pica', 'food', 7, 1, '2026-02-21 08:15:49'),
(12, 'Salad', 'food', 12, 1, '2026-02-21 08:15:49'),
(13, 'Others', 'food', 13, 1, '2026-02-21 08:15:49');


CREATE TABLE `category_modifiers` (
  `category_id` int(10) UNSIGNED NOT NULL,
  `modifier_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `category_modifiers` (`category_id`, `modifier_id`) VALUES
(1, 1),
(4, 1),
(5, 1),
(5, 2),
(6, 2),
(1, 3),
(4, 3);


CREATE TABLE `discounts` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` enum('percent','fixed') NOT NULL DEFAULT 'percent',
  `value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `target_type` enum('all','food','drink','highest','specific') NOT NULL DEFAULT 'all',
  `target_categories` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`target_categories`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `login_attempts` (
  `ip_address` varchar(45) NOT NULL,
  `attempts` int(11) DEFAULT 1,
  `last_attempt` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;


CREATE TABLE `modifiers` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `orders` (
  `id` int(10) UNSIGNED NOT NULL,
  `table_id` int(10) UNSIGNED DEFAULT NULL,
  `order_type` enum('dine_in','takeout') NOT NULL DEFAULT 'dine_in',
  `status` enum('open','paid','voided','refunded') DEFAULT 'open',
  `discount_id` int(10) UNSIGNED DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_note` varchar(100) DEFAULT NULL,
  `grand_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reference` varchar(64) DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `hidden_in_kds` tinyint(1) NOT NULL DEFAULT 0,
  `checked_out_by` int(10) UNSIGNED DEFAULT NULL,
  `voided_by` int(10) UNSIGNED DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `variation_id` int(10) UNSIGNED DEFAULT NULL,
  `product_name` varchar(200) NOT NULL,
  `variation_name` varchar(100) DEFAULT NULL,
  `base_price` decimal(10,2) NOT NULL,
  `modifier_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_note` varchar(255) DEFAULT NULL,
  `item_notes` varchar(255) DEFAULT NULL,
  `line_total` decimal(10,2) NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `served` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `kitchen_printed` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_item_modifiers` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_item_id` int(10) UNSIGNED NOT NULL,
  `modifier_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_sc_pwd` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `discount_type` enum('SC','PWD') NOT NULL DEFAULT 'SC',
  `person_name` varchar(100) NOT NULL,
  `id_number` varchar(50) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;


CREATE TABLE `payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `method` enum('cash','gcash','card','other') NOT NULL DEFAULT 'cash',
  `amount` decimal(10,2) NOT NULL,
  `change_given` decimal(10,2) NOT NULL DEFAULT 0.00,
  `processed_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `printers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `connection_type` varchar(50) NOT NULL,
  `path` varchar(255) NOT NULL,
  `character_limit` int(11) DEFAULT 32,
  `beep_on_print` tinyint(1) DEFAULT 1,
  `cut_after_print` tinyint(1) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `products` (
  `id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `available` tinyint(1) NOT NULL DEFAULT 1,
  `show_on_kds` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_modifiers` (
  `product_id` int(10) UNSIGNED NOT NULL,
  `modifier_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `product_variations` (
  `id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sort_order` tinyint(3) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `register_shifts` (
  `id` int(11) NOT NULL,
  `opened_at` datetime NOT NULL,
  `opened_by` int(11) NOT NULL,
  `opening_cash` decimal(10,2) NOT NULL,
  `closed_at` datetime DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `expected_cash` decimal(10,2) DEFAULT NULL,
  `actual_cash` decimal(10,2) DEFAULT NULL,
  `variance` decimal(10,2) DEFAULT NULL,
  `status` enum('open','closed') DEFAULT 'open'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `roles` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`id`, `role_name`, `description`) VALUES
(1, 'admin', 'Full system access'),
(2, 'manager', 'Can refund and void orders'),
(3, 'staff', 'Standard POS cashier');

CREATE TABLE `system_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `category` enum('business','pos','financial','hardware') NOT NULL DEFAULT 'pos',
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tables` (
  `id` int(10) UNSIGNED NOT NULL,
  `table_number` varchar(20) NOT NULL,
  `table_type` enum('physical','virtual') NOT NULL DEFAULT 'physical',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `time_tracking` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `clock_in` datetime NOT NULL,
  `clock_out` datetime DEFAULT NULL,
  `hours_worked` decimal(10,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(64) NOT NULL COMMENT 'Display name only',
  `passcode` varchar(255) NOT NULL COMMENT 'Bcrypt hash',
  `role_id` tinyint(3) UNSIGNED NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `hourly_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `username`, `passcode`, `role_id`, `is_active`) VALUES
(1, 'Admin', '$2y$10$y5H4u.0Gk3wR.0E62hIeO.Q8v7g2kX6X8z2m8A2h5l9e4k2b8T2K2', 1, 1);

CREATE TABLE `verified_customers` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `passcode` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `view_table_status` (
`id` int(10) unsigned
,`table_number` varchar(20)
,`table_type` enum('physical','virtual')
,`active_order_id` decimal(10,0)
,`current_total` decimal(10,2)
,`status` varchar(9)
);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_al_user_id` (`user_id`),
  ADD KEY `idx_al_action_type` (`action_type`),
  ADD KEY `idx_al_created_at` (`created_at`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_category_name` (`name`);

--
-- Indexes for table `category_modifiers`
--
ALTER TABLE `category_modifiers`
  ADD PRIMARY KEY (`category_id`,`modifier_id`),
  ADD KEY `idx_cm_modifier_id` (`modifier_id`);

--
-- Indexes for table `discounts`
--
ALTER TABLE `discounts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`ip_address`);

--
-- Indexes for table `modifiers`
--
ALTER TABLE `modifiers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_orders_table_id` (`table_id`),
  ADD KEY `idx_orders_status` (`status`),
  ADD KEY `idx_orders_discount_id` (`discount_id`),
  ADD KEY `idx_orders_checked_out` (`checked_out_by`),
  ADD KEY `idx_orders_voided_by` (`voided_by`),
  ADD KEY `idx_orders_created_at` (`created_at`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_oi_order_id` (`order_id`),
  ADD KEY `idx_oi_product_id` (`product_id`),
  ADD KEY `idx_oi_variation_id` (`variation_id`);

--
-- Indexes for table `order_item_modifiers`
--
ALTER TABLE `order_item_modifiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_oim_order_item` (`order_item_id`);

--
-- Indexes for table `order_sc_pwd`
--
ALTER TABLE `order_sc_pwd`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payments_order` (`order_id`),
  ADD KEY `fk_payments_user` (`processed_by`);

--
-- Indexes for table `printers`
--
ALTER TABLE `printers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_category_id` (`category_id`),
  ADD KEY `idx_available` (`available`);

--
-- Indexes for table `product_modifiers`
--
ALTER TABLE `product_modifiers`
  ADD PRIMARY KEY (`product_id`,`modifier_id`),
  ADD KEY `idx_pm_modifier_id` (`modifier_id`);

--
-- Indexes for table `product_variations`
--
ALTER TABLE `product_variations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_variation_product` (`product_id`);

--
-- Indexes for table `register_shifts`
--
ALTER TABLE `register_shifts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_role_name` (`role_name`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `tables`
--
ALTER TABLE `tables`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_table_number` (`table_number`);

--
-- Indexes for table `time_tracking`
--
ALTER TABLE `time_tracking`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tt_user_id` (`user_id`),
  ADD KEY `idx_tt_clock_in` (`clock_in`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_username` (`username`),
  ADD UNIQUE KEY `uq_passcode` (`passcode`),
  ADD KEY `idx_role_id` (`role_id`);

--
-- Indexes for table `verified_customers`
--
ALTER TABLE `verified_customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_verified_phone` (`phone`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `discounts`
--
ALTER TABLE `discounts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `modifiers`
--
ALTER TABLE `modifiers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=213;

--
-- AUTO_INCREMENT for table `order_item_modifiers`
--
ALTER TABLE `order_item_modifiers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `order_sc_pwd`
--
ALTER TABLE `order_sc_pwd`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `printers`
--
ALTER TABLE `printers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=109;

--
-- AUTO_INCREMENT for table `product_variations`
--
ALTER TABLE `product_variations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=141;

--
-- AUTO_INCREMENT for table `register_shifts`
--
ALTER TABLE `register_shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tables`
--
ALTER TABLE `tables`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `time_tracking`
--
ALTER TABLE `time_tracking`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `verified_customers`
--
ALTER TABLE `verified_customers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

-- --------------------------------------------------------

--
-- Structure for view `view_table_status`
--
DROP TABLE IF EXISTS `view_table_status`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_table_status`  AS SELECT `t`.`id` AS `id`, `t`.`table_number` AS `table_number`, `t`.`table_type` AS `table_type`, coalesce(`o`.`id`,0) AS `active_order_id`, coalesce(`o`.`grand_total`,0.00) AS `current_total`, CASE WHEN `o`.`id` is not null THEN 'occupied' ELSE 'available' END AS `status` FROM (`tables` `t` left join `orders` `o` on(`t`.`id` = `o`.`table_id` and `o`.`status` = 'open')) ;

--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `category_modifiers`
--
ALTER TABLE `category_modifiers`
  ADD CONSTRAINT `fk_cm_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cm_modifier` FOREIGN KEY (`modifier_id`) REFERENCES `modifiers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_checked_by` FOREIGN KEY (`checked_out_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_orders_discount` FOREIGN KEY (`discount_id`) REFERENCES `discounts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_orders_table` FOREIGN KEY (`table_id`) REFERENCES `tables` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_orders_voided_by` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_oi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `fk_oi_variation` FOREIGN KEY (`variation_id`) REFERENCES `product_variations` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `order_item_modifiers`
--
ALTER TABLE `order_item_modifiers`
  ADD CONSTRAINT `fk_oim_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `order_sc_pwd`
--
ALTER TABLE `order_sc_pwd`
  ADD CONSTRAINT `order_sc_pwd_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payments_user` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_modifiers`
--
ALTER TABLE `product_modifiers`
  ADD CONSTRAINT `fk_pm_modifier` FOREIGN KEY (`modifier_id`) REFERENCES `modifiers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pm_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_variations`
--
ALTER TABLE `product_variations`
  ADD CONSTRAINT `fk_variation_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `time_tracking`
--
ALTER TABLE `time_tracking`
  ADD CONSTRAINT `fk_tt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);
COMMIT;
