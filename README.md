# ☕ FogsTasa POS System

A high-performance, mobile-responsive Point of Sale (POS) system custom-built for **FogsTasa's Cafe**. 

This system moves away from typical "student project" designs and utilizes a professional **Thin Client / Thick Server Architecture**. The frontend (Vanilla JS) handles rapid, state-driven UI updates, while the backend (PHP/MySQL) acts as a "Zero-Trust" authority, recalculating all math, discounts, and inventory server-side to ensure bulletproof financial accuracy.

## ✨ Key Features

* 📱 **Mobile-First POS UI:** A seamless, app-like experience. On desktop, it utilizes a split-screen grid. On mobile/tablets, the menu takes full-screen priority with a smooth "Slide-Up" cart overlay.
* 🛒 **Smart Cart Engine:** Uses unique Hash Keys to instantly group identical items, variations, and modifiers, preventing database clutter and messy receipts.
* 💸 **Pro-Grade Discounting:** * Automated SC/PWD logic that intelligently targets the highest-priced items (1 Food + 1 Drink per Pax).
    * Tap-to-edit item-level custom discounts.
    * Global order percentages and flat-rate discounts.
* 💳 **Advanced Checkout:** * "Quick-Cash" additive denomination buttons (e.g., tap +₱500 twice for ₱1000).
    * **Split Bill by Item:** Select exactly which items a customer is paying for to calculate complex partial payments effortlessly.
* 🔐 **High-Speed PIN Login:** Features touch-optimized, anti-ghosting numeric entry designed for fast-paced cafe environments.
* 📊 **Admin Command Center:** Built-in staff Time Clock tracking, a dynamic Sales Dashboard, and a comprehensive Menu Manager.

---

## 🛠️ Tech Stack

* **Backend:** PHP 8.x (RESTful API approach)
* **Database:** MySQL (Relational "Gold" Schema with Foreign Key cascades and Views)
* **Frontend:** HTML5, CSS3 (Custom CSS Variables), Vanilla JavaScript (ES6)
* **Libraries:** [SweetAlert2](https://sweetalert2.github.io/) (for all modals and popups)

---

## 📂 Folder Structure

```text
/fogs-app
│
├── /admin                # Management interfaces
│   ├── dashboard.php     # Sales & Order History
│   └── products.php      # Menu Manager (Variations & Modifiers)
│
├── /api                  # The "Thick Server" Brains (PHP endpoints)
│   ├── auth_login.php    # PIN verification
│   ├── checkout.php      # Payment & Split Bill logic
│   ├── get_products.php  # Menu fetching with modifier mapping
│   ├── save_order.php    # Diff-based cart reconciliation & Discount Math
│   ├── save_product.php  # Menu updating
│   └── timeclock.php     # Staff time tracking logic
│
├── /assets               # Static files
│   ├── /css
│   │   └── main.css      # Master stylesheet (Mobile & Desktop)
│   ├── /js
│   │   ├── pos.js        # The Frontend "Thin Client" State Engine
│   │   └── sweetalert2.js
│   └── /img              # Logos and graphics
│
├── /components           # Reusable UI pieces
│   └── navbar.php        # Navigation & Timeclock UI
│
├── /pos                  # Cashier Interface
│   └── index.php         # Main POS screen
│
├── db.php                # Database connection & Error handling
├── index.php             # PIN Login screen
└── fogs.sql              # Master Database Schema & Initial Data
