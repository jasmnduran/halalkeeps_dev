# Halal Keeps — Codebase Documentation

> **Platform:** Halal Food Business Directory & Certification Platform, Davao City  
> **Stack:** PHP 8.0+, MySQL / MariaDB, Tailwind CSS (CDN), custom CSS (`assets/css/style.css`), Vanilla JS  
> **Local URL:** `http://localhost/halal_final/`

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Directory Structure](#2-directory-structure)
3. [Configuration Layer (`config/`)](#3-configuration-layer-config)
4. [Entry Point (`index.php`)](#4-entry-point-indexphp)
5. [Authentication (`auth/`)](#5-authentication-auth)
6. [Shared Includes (`includes/`)](#6-shared-includes-includes)
7. [Public API Endpoints (`api/`)](#7-public-api-endpoints-api)
8. [Customer Dashboard (`dashboard/customer/`)](#8-customer-dashboard-dashboardcustomer)
9. [Business Owner Dashboard (`dashboard/business_owner/`)](#9-business-owner-dashboard-dashboardbusiness_owner)
10. [Admin Panel (`admin/` + `dashboard/admin/`)](#10-admin-panel-admin--dashboardadmin)
11. [Database Migrations (`database/`)](#11-database-migrations-database)
12. [Assets (`assets/`)](#12-assets-assets)
13. [Key Database Tables](#13-key-database-tables)
14. [Role System](#14-role-system)
15. [Theory of Planned Behavior (TPB) Survey Engine](#15-theory-of-planned-behavior-tpb-survey-engine)
16. [Credibility Score System](#16-credibility-score-system)
17. [HaCeRa Assessment System](#17-hacera-assessment-system)
18. [Menu Management](#18-menu-management)

---

## 1. Project Overview

Halal Keeps is a multi-role web platform serving three primary audiences:

| Audience | Purpose |
|---|---|
| **Customers** | Discover and order from Halal-certified, Muslim-Friendly, and Non-Pork restaurants in Davao City |
| **Business Owners** | Register, manage, and certify their food businesses; access consumer demand analytics |
| **Admins / HCB Staff** | Review certification applications, manage roles, monitor system activity |

**Core modules:**
- Halal Food Directory (landing page + live metrics)
- Business Registration & Halal Certification (HDP application pipeline)
- HaCeRa — Halal Readiness Assessment (5-module compliance self-assessment)
- TPB Consumer Demand Survey (Theory of Planned Behavior: ATT + SN + PBC)
- POS / Order Management
- Credibility Score Engine
- Staff Role-Based Access Control (RBAC)
- Data Loss Prevention (DLP) & Audit Logging

---

## 2. Directory Structure

```
halal_final/
├── index.php                        # Public landing page (dual-tab: Customer / Business Owner)
├── .env                             # Environment secrets (not committed)
├── .env.example                     # Environment variable template
├── .htaccess                        # URL rewriting & security headers
│
├── config/                          # Bootstrap & global constants
│   ├── app.php                      # Application entry point (session, DB, functions)
│   ├── constants.php                # All system-wide constants (roles, categories, etc.)
│   ├── database.php                 # MySQLi connection setup
│   └── env.php                      # .env file loader
│
├── auth/                            # Authentication flows
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   ├── apply_role.php
│   ├── google_callback.php          # Google OAuth callback
│   └── oauth_probe.php
│
├── includes/                        # Shared PHP libraries
│   ├── functions.php                # All helper functions (traffic, credibility, expenses…)
│   ├── HaCeRaCalculator.php         # HaCeRa scoring engine (static class)
│   ├── header.php                   # Shared dashboard <head> + sidebar
│   ├── footer.php                   # Shared dashboard footer + scripts
│   ├── staff_rbac.php               # Restaurant staff role enforcement
│   ├── check_access.php             # Generic access guard
│   └── committee_evidence_reviews.php
│
├── api/                             # Public/authenticated JSON API endpoints
│   ├── submit_survey.php            # TPB survey submission (POST → redirect)
│   ├── tpb_analytics.php            # TPB aggregate scores (JSON)
│   ├── credibility_scores.php       # Credibility score engine API
│   ├── halal_directory_metrics.php  # City-level compliance metrics (JSON)
│   ├── notifications.php            # In-app notifications API
│   ├── ph_locations.php             # Philippine regions/provinces/cities/barangays
│   └── upload_requirement.php       # Certification document upload
│
├── dashboard/
│   ├── profile.php                  # Shared user profile editor (all roles)
│   │
│   ├── customer/                    # ROLE_CUSTOMER pages
│   │   ├── index.php                # Customer home + recommendations + survey card
│   │   ├── restaurants.php          # Restaurant listing + sorting + credibility cards
│   │   ├── restaurant_view.php      # Full restaurant detail + credibility panel
│   │   ├── orders.php               # Order history & tracking
│   │   ├── reviews.php              # Customer reviews
│   │   ├── tpb_survey.php           # TPB Consumer Demand Survey (15-item, Set 1/2)
│   │   ├── apply_business.php       # Business registration application form
│   │   ├── my_staff_applications.php
│   │   └── api/
│   │       ├── place_order.php      # POST: create order + PayMongo integration
│   │       ├── confirm_received.php # POST: mark order delivered
│   │       ├── order_payment_callback.php
│   │       ├── submit_review.php    # POST: submit restaurant review
│   │       └── submit_staff_request.php
│   │
│   ├── business_owner/              # ROLE_BUSINESS_OWNER pages
│   │   ├── index.php                # Role router (redirects to owner_dashboard)
│   │   ├── owner_dashboard.php      # Main owner dashboard (4 tabs)
│   │   ├── operations_manager.php   # Ops manager view (profile/menu/expenses/reviews)
│   │   ├── restaurant.php           # Restaurant profile editor
│   │   ├── hacera.php               # HaCeRa 5-module wizard
│   │   ├── halal_certification.php  # Halal certification application
│   │   ├── applications.php         # Application status tracker
│   │   ├── certificates.php         # Issued certificates viewer
│   │   ├── manage_orders.php        # Order queue management
│   │   ├── pos_order_processing.php # POS cashier interface
│   │   ├── chef_dashboard.php       # Chef kitchen view
│   │   ├── payments.php             # Payment records
│   │   ├── staff_applications.php   # Incoming staff applications
│   │   ├── staff_roles.php          # Staff role management
│   │   ├── print_requisition.php    # Printable kitchen requisition
│   │   └── api/
│   │       ├── add_menu_item.php    # POST: add menu item (validates categories)
│   │       ├── update_menu_item.php # POST: update menu item
│   │       ├── hacera_api.php       # HaCeRa AJAX (save/upload/publish/PDF)
│   │       ├── modify_order.php     # AJAX: update order status
│   │       ├── void_order.php       # AJAX: void/cancel order
│   │       ├── pos_payment_callback.php
│   │       ├── staff_action.php     # AJAX: assign/deactivate/remove staff
│   │       ├── staff_app_action.php
│   │       ├── staff_application_handler.php
│   │       ├── staff_management_handler.php
│   │       ├── staff_role_handler.php
│   │       └── chef_action.php
│   │
│   └── admin/                       # Admin dashboard (ROLE_ADMIN)
│       ├── index.php
│       ├── approve_roles.php
│       ├── authorization.php
│       ├── business_applications.php
│       ├── dlp_features.php
│       ├── logging_monitoring.php
│       ├── role_applications.php
│       ├── secure_data.php
│       └── api/
│           └── business_app_detail.php
│
├── admin/                           # Legacy admin module (HCB staff portal)
│   ├── index.php
│   ├── admin_functions.php
│   ├── activity_logs.php
│   ├── login_logs.php
│   ├── users.php
│   ├── roles.php
│   ├── settings.php
│   ├── system_logs.php
│   ├── data_classification.php
│   ├── dlp_settings.php
│   ├── encrypted_fields.php
│   ├── api_permissions.php
│   ├── admin_migration.sql
│   ├── includes/
│   │   ├── admin_header.php
│   │   └── admin_footer.php
│   └── api/
│       ├── check_permission.php
│       ├── log_dlp.php
│       └── ping_session.php
│
├── database/                        # SQL migration files (run manually in phpMyAdmin)
│   ├── business_applications_migration.sql
│   ├── restaurant_staff_migration.sql
│   ├── credibility_score_migration.sql
│   ├── tpb_survey_migration.sql
│   └── tpb_pbc_migration.sql
│
└── assets/
    ├── css/style.css                # Custom dashboard stylesheet (Islamic Green + Gold theme)
    ├── js/main.js                   # Shared dashboard JS (modals, sidebar, notifications)
    ├── images/                      # Static images (logos)
    └── data/                        # Philippine location JSON files
        ├── regions.json
        ├── provinces.json
        ├── cities.json
        └── barangays.json
```

---

## 3. Configuration Layer (`config/`)

### `config/env.php`
Loads `.env` key=value pairs into `getenv()`. Called first by every bootstrap. Idempotent (safe to require multiple times).

### `config/database.php`
Establishes a single `mysqli` connection stored in `$conn`. Forces UTF-8 charset. Calls `db_add_column()` helper for idempotent schema migrations.

### `config/constants.php`
Defines all application-wide constants:

| Constant | Value / Purpose |
|---|---|
| `SITE_NAME` | `'Halal Keeps'` |
| `BASE_URL` | Auto-detected from `HTTP_HOST`; reads `APP_SUBFOLDER` from `.env` for localhost |
| `UPLOAD_DIR` | Absolute path to `uploads/` |
| `ROLE_CUSTOMER` | `1` |
| `ROLE_BUSINESS_OWNER` | `2` |
| `ROLE_EVALUATOR` | `3` |
| `ROLE_ADMIN` | `11` |
| `STAFF_ROLE_OWNER` | `'owner'` |
| `STAFF_ROLE_OPS_MANAGER` | `'ops_manager'` |
| `STAFF_ROLE_CASHIER` | `'cashier'` |
| `STAFF_ROLE_CHEF` | `'chef'` |
| `MENU_ITEM_CATEGORIES` | 11 alphabetised menu categories (see §18) |
| `ALLOWED_SERVING_TYPES` | `['Per serving', 'For sharing']` |
| `EXPENSE_CATEGORIES` | Ingredient, Utilities, Wages… (with icon/color metadata) |
| `CUISINE_TYPES` | Filipino, Middle Eastern, Indian… |
| `SERVICE_TYPES` | `['Dine-in', 'Takeout', 'Delivery']` |
| `MAX_LOGIN_ATTEMPTS` | `5` |
| `LOCKOUT_DURATION` | `900` seconds (15 min) |
| `SESSION_TIMEOUT` | `1800` seconds (30 min) |
| `FIELD_ENCRYPT_KEY` | Read from `.env` — used for sensitive field encryption |

### `config/app.php`
Main application bootstrap. Runs on **every request**:
1. PHP 8.0+ guard
2. `session_start()`
3. UTF-8 enforcement
4. Loads `database.php`, `constants.php`, `functions.php`, `staff_rbac.php`
5. DLP session timeout enforcement (reads from `system_settings`)
6. Staff role key refresh for `ROLE_BUSINESS_OWNER` sessions
7. Creates upload subdirectories if missing

---

## 4. Entry Point (`index.php`)

**Access:** Public (unauthenticated). Logged-in users are redirected to their dashboard.

**Data loaded server-side:**
- `halal_restaurants` joined to `business_applications` + `business_branches` — live Halal Keeps registered businesses, grouped by city and classification
- TPB aggregate scores from `tpb_survey_responses` (ATT, SN, PBC, normalized PI per religion group)
- Region XI city list derived from `halal_restaurants` + `business_branches` (no dummy seed data)

**UI — Dual-view tab switcher:**

| Tab | Contents |
|---|---|
| **Customer View** (default) | Compliance metrics strip (5 formulas), Interactive D3.js Philippines map, Live Purchase Intention Barometer (TPB), Verified Halal Business Directory (Halal Keeps registered only), Trust / About section |
| **Business Owner View** | Value proposition hero, Live demand analytics preview (PI = ATT + SN + PBC), HaCeRa 5-module overview, CTA strip |

**Compliance Index Formulas:**
```
Compliance Index         = Total Compliant ÷ Total Food Businesses × 100
Halal-Compliant Score    = (HC + MF + NP) ÷ Total Compliant × 100
Halal-Certified Score    = Halal-Certified ÷ Total Compliant × 100
Muslim-Friendly Score    = Muslim-Friendly ÷ Total Compliant × 100
Non-Pork Score           = Non-Pork ÷ Total Compliant × 100
```

**Note:** All counts are sourced exclusively from `halal_restaurants` + `business_branches`. The `halal_directory_businesses` seed table and `halal_directory_city_metrics` are **not used** for any counts displayed on the page.

---

## 5. Authentication (`auth/`)

### `auth/register.php`
- Collects: name, email, password, **religion** (`Muslim` | `Non-Muslim`), **food_preference** (`Halal` | `Non-Pork` | `Muslim-Friendly` | `Any`), cuisine preferences, full address
- Religion and food_preference drive TPB survey questionnaire set selection
- Inserts into `users` with `role_id = ROLE_CUSTOMER`, `role_status = 'approved'`
- Supports Google OAuth pre-fill

### `auth/login.php`
- Email + password login with brute-force lockout (`MAX_LOGIN_ATTEMPTS` / `LOCKOUT_DURATION`)
- Sets session: `user_id`, `role_id`, `role_status`, `full_name`, `email`, `staff_role_key`
- Redirects to `getDashboardUrl()` based on role

### `auth/google_callback.php`
- Handles Google OAuth 2.0 callback
- Upserts user record, sets session, redirects
- **Requires** `http://localhost/halal_final/auth/google_callback.php` registered in Google Cloud Console → Credentials → Authorized Redirect URIs

### `auth/logout.php`
Destroys session, redirects to `auth/login.php`.

### `auth/apply_role.php`
Shown to users with no approved role. Allows applying for `ROLE_BUSINESS_OWNER` or other roles pending admin review.

---

## 6. Shared Includes (`includes/`)

### `includes/functions.php`
Central helper library. Key functions:

| Function | Purpose |
|---|---|
| `requireLogin()` | Redirect to login if not authenticated |
| `requireRole(array)` | Redirect if session role not in allowed list |
| `sanitize($str)` | `trim + stripslashes + htmlspecialchars` |
| `formatCurrency($n)` | `₱` formatted number |
| `formatDate / formatDateTime` | Consistent date display |
| `generateReference($prefix)` | Unique reference numbers (e.g. `ORD-2026-00001`) |
| `uploadFile($file, $dir, $types)` | Validated file upload to `uploads/` |
| `createNotification($conn, $uid, ...)` | Insert into `notifications` table |
| `getNotifications($conn, $uid)` | Fetch unread notifications |
| `logActivity($conn, $uid, ...)` | Insert into `activity_log` |
| `getDashboardUrl($role_id)` | Map role → dashboard URL |
| `getSidebarMenu($role_id)` | Return navigation items for role |
| `db_add_column($conn, $table, $col, $def)` | Idempotent `ALTER TABLE … ADD COLUMN IF NOT EXISTS` |
| `getStatusBadge($status)` | HTML badge for order/cert status |
| `getBusinessTrafficStats($conn, $rid)` | Aggregate `business_views` + dine-in `orders` into traffic stats |
| `logBusinessView($conn, $rid)` | Insert row into `business_views` on profile page load |
| `ensureBusinessViewsTable($conn)` | Idempotent creation of `business_views` table |
| `ensureCredibilitySchema($conn)` | Idempotent creation of credibility columns + log table |
| `getCredibilityTier(float $score)` | Map 0–100 → Tier 0–3 with label/color/icon |
| `computeCredibilityScore($conn, $id)` | Full weighted credibility formula (see §16) |
| `getCredibilityScore($conn, $id)` | Cached score with 24h staleness check |
| `getSharedExpenses($conn, $rid, $period)` | Canonical expense query shared by owner + ops manager |
| `timeAgo($datetime)` | Human-readable relative time |

### `includes/HaCeRaCalculator.php`
Static class. All HaCeRa scoring logic:

| Method | Purpose |
|---|---|
| `getModules()` | Returns all 5 modules with checklist items, weights, and types |
| `scoreModule($module_id, $completed_ids)` | Weighted section readiness % |
| `computeHRP($module_scores)` | Mean of all 5 module scores → overall HRP |
| `getBand($score)` | Map HRP% → readiness band label + color |
| `buildCompletedIds($responses, $doc_ids)` | Items answered `'yes'` count as complete |

### `includes/header.php`
Renders: `<head>`, sidebar navigation (role-aware via `getSidebarMenu()`), top bar with notifications bell, user avatar, and session info. Included by every dashboard page.

### `includes/footer.php`
Closes layout divs, outputs `$extra_js` variable (page-specific JS injected by each page).

### `includes/staff_rbac.php`
Enforces restaurant staff sub-role restrictions. Key function: `requireStaffAccess($page_name)` — checks `$_SESSION['staff_role_key']` against `STAFF_ROLE_PAGES` constant.

---

## 7. Public API Endpoints (`api/`)

### `api/submit_survey.php`
**Method:** POST → **redirect**  
**Auth:** `ROLE_CUSTOMER`  
**Purpose:** Accepts 15 Likert responses (ATT1-5, SN1-5, PBC1-5), computes TPB scores, upserts `tpb_survey_responses`, replaces `tpb_survey_answers`, redirects back to survey page with `$_SESSION['survey_flash']`.

**Formula:**
```
Mean(ATT)      = sum(ATT1-5) / 5
Mean(SN)       = sum(SN1-5)  / 5
Mean(PBC)      = sum(PBC1-5) / 5
Raw PI         = Mean(ATT) + Mean(SN) + Mean(PBC)   [3.0 – 15.0]
Normalized PI  = Raw PI / 3                          [1.0 –  5.0]
PI%            = (Normalized PI − 1) / 4 × 100      [20% – 100%]
```

**Questionnaire sets:**
- Set 1 (Muslim): religion-specific attitude, social norm, and behavioral control questions
- Set 2 (Non-Muslim): inclusive framing of same constructs

### `api/tpb_analytics.php`
**Method:** GET  
**Params:** `?scope=public` (no auth) | `?scope=owner` (ROLE_BUSINESS_OWNER)  
**Returns:** JSON with avg_att, avg_sn, avg_pbc, avg_pi, pi_percentage, dominant construct — grouped by religion. Owner scope adds item-level averages.

### `api/credibility_scores.php`
**Method:** GET/POST  
**Actions:**

| Action | Method | Auth | Purpose |
|---|---|---|---|
| `single` | GET `?id=N` | Public | Cached score for one restaurant |
| `leaderboard` | GET `?limit=N` | Public | Top N by credibility score |
| `recompute` | POST | Owner/Admin | Force-recompute one restaurant |
| `recompute_all` | POST | Admin only | Batch recompute all active restaurants |

### `api/halal_directory_metrics.php`
Returns compliance metrics and business listings for cities/regions. Used by the interactive map on the landing page.

### `api/notifications.php`
**Actions:** `get`, `mark_read`, `mark_all_read`  
Feeds the notification bell in the dashboard header.

### `api/ph_locations.php`
Returns Philippine regions, provinces, cities, barangays as JSON. Drives cascading location dropdowns in registration and profile forms.

### `api/upload_requirement.php`
Handles document uploads for Halal certification applications.

---

## 8. Customer Dashboard (`dashboard/customer/`)

### `dashboard/customer/index.php`
**Role:** `ROLE_CUSTOMER`  
Displays:
- Hero banner with stats (total restaurants, orders, reviews)
- Cuisine filter chips
- Personalised restaurant recommendations (scored by food_preference + cuisine + city match)
- Nearby restaurants
- Business upgrade banner
- Recent orders + latest reviews columns
- **TPB Survey Card** — shows completion status + score if done, or invitation to take it

### `dashboard/customer/restaurants.php`
Full restaurant listing with:
- Search bar + cuisine filter chips
- **Sort bar:** Highest Rated | Credibility Score | Most Visited | Most Reviewed
- **Restaurant cards** showing:
  - Halal certification badge (top-left)
  - Credibility tier badge (top-right, if scored)
  - HaCeRa readiness pill (bottom-left)
  - Rating pill (bottom-right)
  - Credibility score bar + tier stars
  - **Traffic split:** Profile Views (digital) vs Verified Dine-ins (physical)
  - Animated 5-bar foot-traffic meter (Very Busy → New)
  - Service type badges

### `dashboard/customer/restaurant_view.php`
Full restaurant detail page. In addition to menu and reviews:
- Logs profile view via `logBusinessView()`
- **Credibility + Traffic Panel:**
  - Composite score (0–100) with tier label
  - 4-component breakdown: Foot Traffic (35%), Engagement (25%), Compliance (25%), Popularity (15%)
  - Separated digital profile views vs verified dine-in cards
  - Traffic source proportion bar

### `dashboard/customer/tpb_survey.php`
**TPB Consumer Demand Survey** — 15 items across 3 constructs:
- Dynamically serves **Set 1** (Muslim) or **Set 2** (Non-Muslim) based on `users.religion`
- Sections: A (Attitude / ATT), B (Subjective Norms / SN), C (Perceived Behavioral Control / PBC)
- 5-point Likert scale with color-coded selection states
- Sticky progress bar (0/15 → 15/15)
- Results view shows 3-construct score grid + normalized PI + item breakdown table
- Retake support

### `dashboard/customer/orders.php`
Order history with status tracking (pending → confirmed → preparing → delivered/cancelled).

### `dashboard/customer/reviews.php`
Customer's submitted reviews with star ratings.

### `dashboard/customer/apply_business.php`
Multi-step business registration form (certification path, business details, branch address, document uploads). Feeds `business_applications` + `business_application_documents`.

### Customer APIs (`dashboard/customer/api/`)

| File | Purpose |
|---|---|
| `place_order.php` | Create order, handle COD or PayMongo payment link |
| `confirm_received.php` | Mark order as delivered by customer |
| `order_payment_callback.php` | PayMongo webhook for customer orders |
| `submit_review.php` | Post review + update `halal_restaurants.avg_rating` |
| `submit_staff_request.php` | Apply for a staff position at a restaurant |

---

## 9. Business Owner Dashboard (`dashboard/business_owner/`)

### `dashboard/business_owner/owner_dashboard.php`
**4-tab dashboard:**

| Tab | Key | Contents |
|---|---|---|
| Main Overview | `overview` | HaCeRa readiness card, KPI stats (orders/revenue/rating/staff), staff management, recent orders |
| Financial Report | `financial_report` | Daily revenue/expenses/net cash flow, Chart.js hourly bar chart, expense ledger |
| Business Visits & Foot Traffic | `business_visits` | Online views vs dine-in split, growth %, KPI cards |
| **Customer Purchase Intention** | `demand_insights` | TPB demand analytics — Muslim vs Non-Muslim PI scores, radar chart (ATT/SN/PBC), business insight tiles |

### `dashboard/business_owner/operations_manager.php`
Accessible to `owner` and `ops_manager` staff roles. Tabs:
- **Profile** — restaurant details editor
- **Menu** — add/edit/delete menu items (JS `buildRow()` form; categories/serving types from PHP constants)
- **Expenses** — shared expense ledger (synchronized with owner dashboard)
- **Reviews** — read-only review summary and list
- **Requisitions** — kitchen ingredient requisitions

### `dashboard/business_owner/restaurant.php`
Full restaurant profile editor. Manages: name, address, cuisine type, operating hours, description, service types, image upload. Menu item management (uses `MENU_ITEM_CATEGORIES` and `ALLOWED_SERVING_TYPES` constants via `json_encode()`).

### `dashboard/business_owner/hacera.php`
**HaCeRa — Halal Readiness Assessment wizard.** 5-module step-by-step form:
1. Conditions of Certification (permits, licenses)
2. Raw Materials & Ingredients (Halal certificates, traceability)
3. Processing & Logistics (contamination controls, transport)
4. Packaging & Labelling (10 HDIP label requirements)
5. Premises & Welfare (prayer facilities, Muslim staff)

Live score updates via AJAX. Gap analysis engine highlights missing high-priority items. Publish button writes `hacera_hrp` + `hacera_band` to `halal_restaurants`.

### `dashboard/business_owner/halal_certification.php`
Halal certification application tracker. Shows status pipeline (draft → submitted → under review → approved/returned/rejected).

### `dashboard/business_owner/manage_orders.php`
Order queue for the restaurant. Owner/ops manager view of all incoming orders with status controls.

### `dashboard/business_owner/pos_order_processing.php`
Cashier POS interface. Walk-in order intake, menu item selection, payment processing.

### `dashboard/business_owner/chef_dashboard.php`
Kitchen view for chefs. Shows active orders, ingredient requisitions, recipe mapping.

### `dashboard/business_owner/staff_applications.php`
Incoming staff role applications. Owner can approve/reject and assign roles.

### `dashboard/business_owner/staff_roles.php`
Manage restaurant staff roles (owner, ops_manager, cashier, chef).

### Business Owner APIs (`dashboard/business_owner/api/`)

| File | Purpose |
|---|---|
| `add_menu_item.php` | POST: validate + insert menu item. Validates `category` against `MENU_ITEM_CATEGORIES`; validates `serving_type` against `ALLOWED_SERVING_TYPES`. Default fallback: `'Main Course'` / `'Per serving'` |
| `update_menu_item.php` | POST: validate + update menu item. Same validation as add |
| `hacera_api.php` | AJAX: `save_response`, `upload_document`, `delete_document`, `get_scores`, `generate_pdf`, `publish_score` |
| `modify_order.php` | AJAX: change order status |
| `void_order.php` | AJAX: void/cancel order |
| `pos_payment_callback.php` | PayMongo webhook for POS payments |
| `staff_action.php` | AJAX: assign/deactivate/remove/change-role staff |
| `staff_app_action.php` | AJAX: approve/reject staff application |
| `staff_application_handler.php` | Handles staff application form submissions |
| `staff_management_handler.php` | Bulk staff management operations |
| `staff_role_handler.php` | Create/update staff role definitions |
| `chef_action.php` | Chef-specific AJAX actions (requisitions, inventory) |

---

## 10. Admin Panel (`admin/` + `dashboard/admin/`)

### `dashboard/admin/` (new admin dashboard)

| File | Purpose |
|---|---|
| `index.php` | Admin home — system stats |
| `business_applications.php` | Review and approve/reject business registration applications |
| `approve_roles.php` | Approve pending role applications |
| `role_applications.php` | Full list of role applications |
| `authorization.php` | Role and permission management |
| `dlp_features.php` | Data Loss Prevention feature toggles |
| `logging_monitoring.php` | System event log viewer |
| `secure_data.php` | Encrypted field viewer |
| `api/business_app_detail.php` | AJAX: fetch business application detail |

### `admin/` (legacy HCB staff portal)

| File | Purpose |
|---|---|
| `index.php` | Admin dashboard home |
| `users.php` | User management (view, lock, unlock, assign roles) |
| `roles.php` | System role definitions |
| `activity_logs.php` | User activity audit trail |
| `login_logs.php` | Login attempt log |
| `settings.php` | System settings (session timeout, feature flags) |
| `system_logs.php` | System-level event log |
| `data_classification.php` | Data classification levels per table/column |
| `dlp_settings.php` | DLP policy configuration |
| `encrypted_fields.php` | Encrypted field registry |
| `api_permissions.php` | API endpoint permission matrix |
| `admin_functions.php` | Admin-specific helper functions |
| `api/check_permission.php` | AJAX: check if action is permitted |
| `api/log_dlp.php` | AJAX: log DLP event |
| `api/ping_session.php` | AJAX: keep session alive |

---

## 11. Database Migrations (`database/`)

Run these SQL files manually in phpMyAdmin in order. All tables also auto-create idempotently on first use via PHP (`CREATE TABLE IF NOT EXISTS`).

| File | Purpose |
|---|---|
| `business_applications_migration.sql` | `business_applications`, `business_application_documents`, `business_branches` tables |
| `restaurant_staff_migration.sql` | `restaurant_staff`, `restaurant_staff_roles` tables; default role seeds |
| `tpb_survey_migration.sql` | `tpb_survey_responses`, `tpb_survey_answers`, analytical views (original 2-construct model) |
| `tpb_pbc_migration.sql` | **Upgrade:** adds `mean_pbc`, `raw_pi`, `normalized_pi`, `questionnaire_set` columns; refreshes views for 3-construct ATT+SN+PBC model |
| `credibility_score_migration.sql` | Adds `credibility_score`, `credibility_tier`, `credibility_updated_at` to `halal_restaurants`; creates `restaurant_credibility_log` table |

---

## 12. Assets (`assets/`)

### `assets/css/style.css`
Custom dashboard stylesheet. Design tokens:
- **Primary (Islamic Green):** `#10b981` base, `--primary-50` through `--primary-900`
- **Accent (Gold):** `#f59e0b` base, `--accent-50` through `--accent-900`
- **Neutral (Slate):** `--neutral-50` through `--neutral-950`
- Sidebar layout variables: `--sidebar-width: 280px`, `--sidebar-collapsed: 70px`, `--header-height: 70px`
- Fonts: `Inter` (body) + `Outfit` (display) from Google Fonts

### `assets/js/main.js`
Shared dashboard JavaScript. Initializes on `DOMContentLoaded`:
- `initNavbar()` — scroll effect + mobile toggle
- `initAlerts()` — close buttons + auto-dismiss success alerts (5s)
- `initModals()` — `[data-modal]` trigger, `.modal-overlay` backdrop, `.modal-close` buttons
- `initNotifications()` — notification panel toggle, AJAX load, ARIA `aria-expanded`
- `initDropdowns()`, `initFileUploads()`, `initSidebar()`, `initMobileSearch()`, `initNavGroups()`, `initTooltips()`
- Global: `openModal(id)`, `closeModal()`

### `assets/data/`
Four JSON files for Philippine administrative divisions used by cascading location dropdowns:
`regions.json` → `provinces.json` → `cities.json` → `barangays.json`

---

## 13. Key Database Tables

| Table | Purpose |
|---|---|
| `users` | All accounts (customers, owners, staff, admins). Includes `religion`, `food_preference`, `credibility_score` |
| `roles` | System role definitions |
| `halal_restaurants` | Live restaurant registry. Auto-created on approved application. Includes `hacera_hrp`, `hacera_band`, `credibility_score`, `credibility_tier` |
| `restaurant_staff` | User ↔ restaurant staff assignments |
| `restaurant_staff_roles` | Per-restaurant role definitions (owner/ops_manager/cashier/chef) |
| `restaurant_expenses` | Operational expenses (shared view by owner + ops manager) |
| `restaurant_expense_items` | Line items for expenses |
| `orders` | Customer orders. `service_type`: `dine_in` | `takeout` | `delivery` |
| `menu_items` | Restaurant menu items with `category` and `serving_type` |
| `reviews` | Customer reviews with `rating`, `food_rating`, `service_rating` |
| `business_applications` | Halal certification applications with `halal_classification` |
| `business_branches` | Branch address data (provides `address_city` used for city grouping) |
| `business_application_documents` | Uploaded docs per application |
| `halal_certificates` | Issued Halal certificates |
| `business_views` | Online profile view log (one row per visit to restaurant detail page) |
| `tpb_survey_responses` | One row per user: ATT/SN/PBC means, raw_pi, normalized_pi, questionnaire_set |
| `tpb_survey_answers` | Individual Likert scores (1–5) per item per response |
| `restaurant_credibility_log` | Audit trail of credibility score computations |
| `business_hacera_responses` | HaCeRa yes/no/na answers per checklist item per restaurant |
| `hacera_documents` | Supporting documents uploaded per HaCeRa checklist item |
| `staff_applications` | Staff role applications from users |
| `notifications` | In-app notifications |
| `system_settings` | Key-value app settings (session timeout, feature flags) |
| `admin_activity_log` | Admin action audit trail |
| `login_attempts` | Brute-force protection log |
| `halal_directory_businesses` | External reference directory (used only for the old seed data; live page uses `halal_restaurants`) |
| `halal_directory_city_metrics` | External city-level food business statistics (used as `total_food_biz` denominator only) |

---

## 14. Role System

### System-wide Roles (`roles` table)

| ID | Constant | Description |
|---|---|---|
| 1 | `ROLE_CUSTOMER` | End customer — browse, order, review, take surveys |
| 2 | `ROLE_BUSINESS_OWNER` | Business owner + all restaurant staff sub-roles |
| 3 | `ROLE_EVALUATOR` | HCB evaluator |
| 4 | `ROLE_AUDITOR_TECHNICAL` | Technical auditor |
| 5 | `ROLE_AUDITOR_SHARIAH` | Shari'ah auditor |
| 6 | `ROLE_IMPARTIAL_COMMITTEE` | Impartial committee member |
| 7 | `ROLE_DECISION_COMMITTEE` | Decision committee member |
| 8 | `ROLE_PRESIDENT` | HCB president |
| 9 | `ROLE_RECEIVING_OFFICER` | Receiving officer |
| 10 | `ROLE_LABORATORY_ANALYST` | Lab analyst |
| 11 | `ROLE_ADMIN` | System administrator |

### Restaurant Staff Sub-roles (`restaurant_staff_roles` table)

| Key | Label | Accessible Pages |
|---|---|---|
| `owner` | Business Owner | `owner_dashboard.php` |
| `ops_manager` | Operations Manager | `operations_manager.php` |
| `cashier` | Cashier | `pos_order_processing.php` |
| `chef` | Chef / Head Cook | `chef_dashboard.php` |

Staff sub-roles are enforced by `requireStaffAccess()` in `staff_rbac.php`.

---

## 15. Theory of Planned Behavior (TPB) Survey Engine

### Overview
Measures consumer purchase intention for Halal-certified restaurants using the 3-construct TPB model.

### Formula
```
Mean(ATT) = Σ(ATT1-5) / 5
Mean(SN)  = Σ(SN1-5)  / 5
Mean(PBC) = Σ(PBC1-5) / 5

Raw PI         = Mean(ATT) + Mean(SN) + Mean(PBC)   [range: 3.0 – 15.0]
Normalized PI  = Raw PI / 3                          [range: 1.0 –  5.0]
PI Index (%)   = (Normalized PI − 1) / 4 × 100      [range: 20% – 100%]
```

### Questionnaire Sets
| Set | Target | Religion Filter |
|---|---|---|
| Set 1 | Muslim Demand | `users.religion = 'Muslim'` |
| Set 2 | Non-Muslim Demand | `users.religion = 'Non-Muslim'` |

Each set has 5 items per construct (ATT, SN, PBC) = 15 items total.

### Intent Tiers
| Normalized PI | Label |
|---|---|
| ≥ 4.5 | Very High |
| ≥ 3.5 | High |
| ≥ 2.5 | Moderate |
| ≥ 1.5 | Low |
| < 1.5 | Very Low |

### Key Files
| File | Role |
|---|---|
| `dashboard/customer/tpb_survey.php` | Survey UI (Set 1 or Set 2 by religion) |
| `api/submit_survey.php` | POST handler → computes scores → upserts DB → redirect |
| `api/tpb_analytics.php` | Aggregate JSON for dashboard widgets + landing page |
| `database/tpb_survey_migration.sql` | Creates survey tables (original model) |
| `database/tpb_pbc_migration.sql` | Upgrades to 3-construct model (adds PBC columns) |

---

## 16. Credibility Score System

### Formula
Composite weighted score (0–100) per restaurant:

| Component | Weight | Source |
|---|---|---|
| Foot Traffic & Dine-ins | **35%** | `orders` WHERE `service_type IN (dine_in, takeout)` last 30 days — log-scaled, cap 200 |
| Customer Engagement | **25%** | `avg_rating` (60%) + review volume (40%) — both log-scaled |
| Halal Compliance | **25%** | Active certificate (50 pts) + `hacera_hrp / 100 × 50` pts |
| Platform Popularity | **15%** | Profile views last 30 days (log-scaled, cap 500) + momentum growth bonus (up to +20 pts) |

### Credibility Tiers

| Score | Tier | Label |
|---|---|---|
| ≥ 80 | 3 | Top Tier |
| ≥ 55 | 2 | Highly Recommended |
| ≥ 30 | 1 | Popular Choice |
| < 30 | 0 | Unranked |

### Key Files
| File | Role |
|---|---|
| `includes/functions.php` | `computeCredibilityScore()`, `getCredibilityScore()`, `getCredibilityTier()`, `ensureCredibilitySchema()` |
| `api/credibility_scores.php` | JSON API (single / leaderboard / recompute / recompute_all) |
| `database/credibility_score_migration.sql` | `credibility_score`, `credibility_tier`, `credibility_updated_at` columns + `restaurant_credibility_log` table |

Scores are **cached on `halal_restaurants`** and automatically recomputed if stale (> 24 hours).

---

## 17. HaCeRa Assessment System

### Overview
Halal Certification Readiness Assessment — 5-module compliance checklist for food businesses before applying for official Halal certification.

### Scoring Formula
```
Section Readiness (%) = (Σ weights of completed items / Σ total possible weights) × 100

HRP = arithmetic mean of all 5 module section readiness scores
```

Items are answered `yes` / `no` / `na`. Only `yes` earns the item's weight.

### Weight Tiers
| Weight | Priority | Examples |
|---|---|---|
| W = 3 | High | Halal certificates for meat, no alcohol in kitchen, Muslim crew, ablution facilities |
| W = 2 | Standard | Business permits, processing SOP, labelling requirements |
| W = 1 | Informational | Optional certifications (FDA, ISO, DENR) |

### 5 Modules

| # | Title | Items | Max Weight |
|---|---|---|---|
| 1 | Conditions of Certification | 8 | 14 |
| 2 | Raw Materials & Ingredients | 8 | 22 |
| 3 | Processing & Logistics | 9 | 20 |
| 4 | Packaging & Labelling | 10 | 23 |
| 5 | Premises & Welfare | 9 | 23 |

### Readiness Bands

| HRP % | Band |
|---|---|
| ≥ 90% | Inspection Ready |
| ≥ 70% | Near Ready |
| ≥ 50% | In Progress |
| ≥ 25% | Early Stage |
| < 25% | Not Started |

### Key Files
| File | Role |
|---|---|
| `includes/HaCeRaCalculator.php` | Scoring engine (pure static class, no DB) |
| `dashboard/business_owner/hacera.php` | Wizard UI with live AJAX score updates |
| `dashboard/business_owner/api/hacera_api.php` | AJAX: save response, upload doc, get scores, publish, generate PDF |

---

## 18. Menu Management

### Categories (`MENU_ITEM_CATEGORIES` in `config/constants.php`)
Alphabetised list of 11 standardised categories:

1. Appetizer
2. Dessert
3. House Specialty
4. Main Course
5. Noodles (Chicken, Seafood)
6. Rice
7. Rice Toppings
8. Sandwiches
9. Soups
10. Steamed Dumplings
11. Vegetables

### Serving Types (`ALLOWED_SERVING_TYPES` in `config/constants.php`)
- Per serving
- For sharing

> `'By portion'` was removed. `'General'`, `'Seafood'`, `'Meat'`, `'Vegetable'`, `'Beverage'` categories were removed. Existing items with old category values display as-is and are remapped on next edit.

### Data Flow
```
config/constants.php
    ↓  (json_encode via PHP→JS bridge)
operations_manager.php / restaurant.php
    ↓  (JS buildRow() → <select> dropdowns)
api/add_menu_item.php / api/update_menu_item.php
    ↓  (in_array() whitelist validation)
menu_items table (category, serving_type columns)
```

All four files in the chain reference `MENU_ITEM_CATEGORIES` and `ALLOWED_SERVING_TYPES` — changing the constants automatically propagates to UI and validation.

---

*Last updated: documentation generated from live codebase.*
