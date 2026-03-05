
# ☕ FogsTasa Cafe POS System

A robust, web-based Point of Sale (POS) system custom-built for **FogsTasa's Cafe**. Designed to handle complex restaurant workflows with lightning-fast performance, strict security, and offline-tolerant architecture.

## ✨ Key Features

### 🍽️ Order Management & Workflow
* **True Sub-Checks:** Allows multiple independent open bills on a single physical table (e.g., Table 5 - Check 1, Table 5 - Check 2) without data pollution.
* **Smart Table Grid:** Visual indicators for occupied tables, including fast-read item summaries directly on the table selection screen.
* **Takeout Queue:** Dedicated holding area for to-go orders with customer name tracking.
* **Special Instructions:** Item-level text notes (e.g., "No Bacon") that instantly sync to the cart and print in **BOLD/UPPERCASE** directly on the kitchen prep tickets.

### 💸 Checkout & Payments
* **Multi-Tender (Split Payments):** Pay a single bill using a combination of methods (e.g., ₱150 GCash, ₱50 Cash) with precise change calculation.
* **Item-Specific Splitting:** Cashiers can select specific items from a large bill to charge to a single customer.
* **Advanced Discounting Engine:**
  * **Item-Level:** Flat or percentage discounts on specific items.
  * **Global Target Discounts:** Target discounts specifically to Food, Drinks, or completely custom category clusters using a dynamic Junction Table architecture.
  * **Relational SC/PWD:** Automatically sorts and targets the highest-priced applicable items based on the pax count, capturing mandated government ID numbers.

### ⏰ Enterprise HR & Payroll (NEW)
* **Frictionless Timeclock:** Dedicated PIN-based timeclock accessible from the Login screen and POS Navbar, allowing staff to clock in/out without interrupting an active cashier session.
* **Autonomous "Ghost Shift" Cleanup:** Powered by a background MySQL Event Scheduler, the system automatically detects shifts left open for >16 hours, forces a clock-out, and flags the timecard in red for manager review.
* **Dynamic Payroll Generator:** Instantly calculates gross pay and overtime. Rules for "Max Regular Hours" and "Overtime Multiplier" are fully database-driven and adjustable in the Admin panel.
* **Manager Overrides:** Admins can manually correct missed clock-in/out times, which immediately triggers a permanent Security Audit Log entry to prevent payroll fraud.

### 🖨️ Hardware Integration (ESC/POS)
* **Multi-Printer Routing:** Automatically splits and routes items to their respective prep stations (Kitchen Printer vs. Bar Printer).
* **Smart Printing:** Tracks which items have already been sent to the kitchen to prevent duplicate prep tickets.
* **Acoustic Alerts:** Triggers native Epson buzzer commands to alert chefs of new incoming tickets.
* **Dynamic Receipt Formatting:** Auto-truncates long item names, adjusts for double-width headers, cleanly splits regular vs. senior items on the final bill, and prints signature lines for SC/PWD verification.

### 📊 Management & Admin
* **Cash Drawer Tracking:** Tracks opening float, gross sales, refunds, and expected cash, generating an end-of-shift variance report (Z-Report).
* **Product Manager:** Grouped interface allowing admins to toggle product availability (instantly hiding items from the POS without deleting them).
* **Live Dashboard:** Real-time analytics tracking total orders, gross sales, and payment method breakdowns.

### 🛡️ Security & Performance
* **Bcrypt Authentication:** Military-grade password hashing for staff PIN logins and timeclock entries.
* **Anti-Brute Force:** IP-based rate limiter that temporarily locks out rapid failed login attempts.
* **CSRF Protection:** Cryptographic tokens strictly required for all state-changing API endpoints, including saving orders, clearing carts, and clocking in.
* **Smart Caching:** Utilizes `filemtime()` for JavaScript cache-busting, ensuring the tablet always loads instantly unless the code is actually updated.
* **SQLi Prevention:** Strict use of Prepared Statements across all database queries.

---

## 🛠️ Technology Stack
* **Backend:** PHP 8+
* **Database:** MySQL / MariaDB (Relational Architecture with Event Schedulers)
* **Frontend:** Vanilla HTML5, CSS3, ES6 JavaScript
* **Libraries:** * `mike42/escpos-php` (For Network/USB thermal printing)
  * `SweetAlert2` (For native-feeling UI modals and prompts)

---

## 🚀 Installation & Setup

1. **Clone & Serve:** Place the repository in your web server directory (e.g., Apache/Nginx on Proxmox/Linux).
2. **Database Import:** Import the provided SQL schema into your MariaDB instance. Ensure the `view_table_status` View is created.
3. **Enable Background Automation (Crucial):** Run this command in your database's SQL console to allow the system to self-heal Ghost Shifts:
   ```sql
   SET GLOBAL event_scheduler = ON;

```

4. **Dependencies:** Run `composer install` in the root directory to install the Mike42 ESC/POS library.
5. **Default Login:** Select Admin and use PIN: 1234 (Please change this immediately after your first login!)
6. **Configuration:** * Update `db.php` with your database credentials.
* Ensure your PHP environment has `display_errors = 0` in production for security.


7. **Printers:** Assign your thermal printer IPs (e.g., `192.168.1.100`) via the system settings table for Kitchen, Bar, and Receipt routing.
*To use your own logo replace the current files with the image of yours*
*Color and theme change is not yet available*

---

## 📋 Recent Updates (Changelog)

* **[HR & Payroll]** Deployed the Enterprise Timeclock module. Added an autonomous database daemon to catch ghost shifts, and a dynamic Payroll Generator supporting custom Overtime multipliers.
* **[Workflow]** Added "Special Instructions" text areas. Notes now sync uniquely to the cart and print dynamically to the kitchen/bar printers to prevent errors.
* **[Database]** Dropped the "Takeout Hack" for split bills. Upgraded `get_active_order.php` to fully support True Sub-Checks natively on Dine-In tables.
* **[UI/UX]** Added `GROUP_CONCAT` to table fetching so cashiers can see what items belong to which sub-check instantly.
* **[Admin]** Upgraded the `get_products.php` API. The Admin panel now sees all items grouped by category with a `[HIDDEN]` badge, while the POS tablet strictly filters them out.
* **[Security]** Applied strict CSRF headers to the clear cart, transfer table, and timeclock endpoints. Patched SQL injection vulnerabilities in the login rate limiter.