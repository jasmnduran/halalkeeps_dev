# Halal Keeps — System Architecture

> **Application Name:** Halal Keeps  
> **Tagline:** *Verify Halal, Anytime, Anywhere*  
> **Platform:** Multi-role Halal Food Business Directory, Certification & POS Platform — Davao City  
> **Stack:** PHP 8.0+, MySQL/MariaDB, Tailwind CSS (CDN), Vanilla JS, mPDF  
> **Local URL:** `http://localhost/halal_final/`

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [High-Level Architecture](#2-high-level-architecture)
3. [Technology Stack](#3-technology-stack)
4. [Directory Structure](#4-directory-structure)
5. [Configuration & Bootstrap Layer](#5-configuration--bootstrap-layer)
6. [Authentication & Session Management](#6-authentication--session-management)
7. [Role & Permission System](#7-role--permission-system)
8. [Core Modules](#8-core-modules)
   - [8.1 Public Landing Page](#81-public-landing-page)
   - [8.2 Customer Dashboard](#82-customer-dashboard)
   - [8.3 Business Owner Dashboard](#83-business-owner-dashboard)
   - [8.4 Admin Panel](#84-admin-panel)
   - [8.5 HCB Staff Portal (Legacy Admin)](#85-hcb-staff-portal-legacy-admin)
9. [API Endpoints](#9-api-endpoints)
10. [Database Architecture](#10-database-architecture)
11. [Key Business Algorithms](#11-key-business-algorithms)
    - [11.1 TPB Consumer Demand Survey Engine](#111-tpb-consumer-demand-survey-engine)
    - [11.2 Credibility Score Engine](#112-credibility-score-engine)
    - [11.3 HaCeRa Readiness Assessment](#113-hacera-readiness-assessment)
    - [11.4 Compliance Index Formulas](#114-compliance-index-formulas)
12. [Payment Integration (PayMongo)](#12-payment-integration-paymongo)
13. [External Integrations](#13-external-integrations)
14. [Security & Data Loss Prevention (DLP)](#14-security--data-loss-prevention-dlp)
15. [POS & Order Management System](#15-pos--order-management-system)
16. [Staff Management (Restaurant RBAC)](#16-staff-management-restaurant-rbac)
17. [Assets & Frontend](#17-assets--frontend)
18. [Environment Configuration](#18-environment-configuration)
19. [File Upload System](#19-file-upload-system)
20. [Notification System](#20-notification-system)
21. [Data Flow Diagrams](#21-data-flow-diagrams)

---

## 1. System Overview

Halal Keeps is a multi-stakeholder web platform with three primary user audiences:

| Audience | Purpose |
|---|---|
| **Customers** | Discover and order from Halal-certified, Muslim-Friendly, and Non-Pork restaurants in Davao City. Take TPB consumer demand surveys. |
| **Business Owners / Staff** | Register and manage food businesses, apply for Halal certification, run a POS system, and access consumer demand analytics. |
| **Admins / HCB Staff** | Review certification applications, manage system roles, configure DLP policies, and monitor all system activity. |

**Core features at a glance:**

- Halal Food Directory with live compliance metrics and an interactive D3.js map
- Business Registration and Halal Certification (HDP application pipeline)
- HaCeRa — Halal Readiness Assessment (5-module self-assessment wizard)
- TPB Consumer Demand Survey (Theory of Planned Behavior: ATT + SN + PBC)
- POS / Order Management System (cashier, chef, owner views)
- Credibility Score Engine (composite 0–100 score per restaurant)
- Restaurant Staff Role-Based Access Control (owner, ops_manager, cashier, chef)
- Payment processing via PayMongo (GCash, PayMaya/bank transfer, cash)
- Data Loss Prevention (DLP) and full audit logging
- Google OAuth 2.0 login

---

## 2. High-Level Architecture

```
┌──────────────────────────────────────────────────────────────────────────┐
│                           CLIENT BROWSER                                 │
│  Tailwind CSS (CDN) · Vanilla JS · D3.js · Chart.js · Font Awesome       │
└───────────────────────────────┬──────────────────────────────────────────┘
                                │ HTTP / HTTPS
┌───────────────────────────────▼──────────────────────────────────────────┐
│                          XAMPP / Apache                                  │
│  .htaccess — URL rewriting, security headers, HTTPS redirect              │
└───────────────────────────────┬──────────────────────────────────────────┘
                                │
┌───────────────────────────────▼──────────────────────────────────────────┐
│                         PHP 8.0+ Application                             │
│                                                                          │
│  config/app.php  ─── Bootstrap: session, DB, constants, helpers, RBAC   │
│                                                                          │
│  ┌─────────────┐  ┌──────────────┐  ┌────────────┐  ┌───────────────┐  │
│  │ Public Pages│  │ Auth Module  │  │  API Layer  │  │  Dashboards   │  │
│  │  index.php  │  │ login.php    │  │  /api/*.php │  │ /dashboard/   │  │
│  │  (landing)  │  │ register.php │  │             │  │  customer/    │  │
│  └─────────────┘  │ google_cb.php│  └────────────┘  │  business_    │  │
│                   └──────────────┘                   │  owner/       │  │
│                                                      │  admin/       │  │
│                                                      └───────────────┘  │
│                                                                          │
│  includes/functions.php — Central helper library                        │
│  includes/HaCeRaCalculator.php — Scoring engine                         │
│  includes/staff_rbac.php — Restaurant staff access control              │
└───────────────────────────────┬──────────────────────────────────────────┘
                                │ MySQLi (utf8mb4)
┌───────────────────────────────▼──────────────────────────────────────────┐
│                      MySQL / MariaDB Database                            │
│                        (halal_system)                                    │
└──────────────────────────────────────────────────────────────────────────┘
                                │
                  ┌─────────────┼──────────────┐
                  ▼             ▼              ▼
          ┌───────────┐  ┌──────────┐  ┌─────────────┐
          │  Google   │  │PayMongo  │  │   mPDF      │
          │  OAuth    │  │Payments  │  │ (PDF export)│
          │   2.0     │  │  API     │  └─────────────┘
          └───────────┘  └──────────┘
```

---

## 3. Technology Stack

| Layer | Technology |
|---|---|
| **Language** | PHP 8.0+ |
| **Database** | MySQL / MariaDB (`halal_system` database) |
| **Database driver** | MySQLi (procedural + OOP) |
| **Frontend CSS** | Tailwind CSS v3 (CDN) + Custom CSS (`assets/css/style.css`) |
| **Frontend JS** | Vanilla JavaScript (ES6+), D3.js (map), Chart.js (charts) |
| **Icon set** | Font Awesome 6, Google Material Symbols |
| **Fonts** | Google Fonts — Inter, Outfit, Manrope, Plus Jakarta Sans |
| **PDF generation** | [mPDF 8.3](https://mpdf.github.io/) (`mpdf/mpdf` via Composer) |
| **OAuth** | Google OAuth 2.0 (custom implementation, no SDK) |
| **Payments** | PayMongo REST API (GCash, PayMaya/bank transfer) |
| **Web server** | Apache (XAMPP on Windows; production-ready) |
| **Charset** | UTF-8 / utf8mb4 throughout |
| **PHP session** | Native PHP sessions with DLP-enforced timeout |
| **PHP minimum** | PHP 8.0 (enforced at runtime in `config/app.php`) |

---

## 4. Directory Structure

```
halal_final/
│
├── index.php                        # Public landing page (dual-view: Customer / Business Owner)
├── .env                             # Runtime secrets (not committed)
├── .env.example                     # Environment variable template
├── .htaccess                        # Apache URL rewriting + security headers
├── composer.json                    # PHP dependencies (mpdf/mpdf)
│
├── config/                          # Bootstrap & global configuration
│   ├── app.php                      # Application entry point (loads all below)
│   ├── constants.php                # All system-wide constants (roles, limits, API keys…)
│   ├── database.php                 # MySQLi connection + idempotent schema helpers
│   └── env.php                      # .env file loader (idempotent)
│
├── auth/                            # Authentication flows
│   ├── login.php                    # Email/password login + brute-force lockout
│   ├── register.php                 # New account registration (religion + preference)
│   ├── logout.php                   # Session destroy + redirect
│   ├── apply_role.php               # Apply for a non-customer system role
│   ├── google_callback.php          # Google OAuth 2.0 callback handler
│   └── oauth_probe.php              # OAuth debugging helper
│
├── includes/                        # Shared PHP libraries (included by app.php)
│   ├── functions.php                # Central helper library (auth, credibility, traffic…)
│   ├── HaCeRaCalculator.php         # HaCeRa scoring engine (static class)
│   ├── header.php                   # Shared dashboard <head> + role-aware sidebar
│   ├── footer.php                   # Shared dashboard footer + $extra_js injection
│   ├── staff_rbac.php               # Restaurant staff sub-role access guard
│   ├── check_access.php             # Generic access guard
│   └── committee_evidence_reviews.php
│
├── api/                             # Public / authenticated JSON endpoints
│   ├── submit_survey.php            # POST: TPB 15-item survey submission
│   ├── tpb_analytics.php            # GET: TPB aggregate scores (public/owner scopes)
│   ├── credibility_scores.php       # GET/POST: credibility score engine
│   ├── halal_directory_metrics.php  # GET: city compliance metrics for the map
│   ├── notifications.php            # GET/POST: in-app notifications
│   ├── ph_locations.php             # GET: Philippine regions/provinces/cities/barangays
│   ├── upload_requirement.php       # POST: certification document upload
│   ├── check_survey_status.php      # GET: survey completion check
│   ├── paymongo_webhook.php         # POST: PayMongo payment event receiver
│   └── process_paymongo_payment.php # POST: initiate PayMongo payment session
│
├── dashboard/
│   ├── profile.php                  # Shared user profile editor (all roles)
│   │
│   ├── customer/                    # ROLE_CUSTOMER (role_id = 1)
│   │   ├── index.php                # Customer home + recommendations + survey card
│   │   ├── restaurants.php          # Full restaurant listing + sort + credibility
│   │   ├── restaurant_view.php      # Restaurant detail + menu + reviews + traffic panel
│   │   ├── orders.php               # Order history + status tracking
│   │   ├── reviews.php              # Submitted reviews
│   │   ├── tpb_survey.php           # 15-item Likert TPB survey
│   │   ├── apply_business.php       # Business registration application form
│   │   ├── my_staff_applications.php
│   │   └── api/                     # Customer-facing AJAX/action endpoints
│   │       ├── place_order.php
│   │       ├── confirm_received.php
│   │       ├── order_payment_callback.php
│   │       ├── submit_review.php
│   │       └── submit_staff_request.php
│   │
│   ├── business_owner/              # ROLE_BUSINESS_OWNER (role_id = 2)
│   │   ├── index.php                # Role router
│   │   ├── owner_dashboard.php      # 4-tab owner home (overview/financial/traffic/demand)
│   │   ├── operations_manager.php   # Ops manager view (profile/menu/expenses/reviews/req.)
│   │   ├── restaurant.php           # Restaurant profile editor
│   │   ├── hacera.php               # HaCeRa 5-module readiness wizard
│   │   ├── halal_certification.php  # Halal certification tracker
│   │   ├── applications.php         # Application status view
│   │   ├── certificates.php         # Issued certificates viewer
│   │   ├── manage_orders.php        # Order queue (owner/ops manager)
│   │   ├── pos_order_processing.php # Cashier POS interface
│   │   ├── chef_dashboard.php       # Chef kitchen view
│   │   ├── payments.php             # Payment records
│   │   ├── staff_applications.php   # Incoming staff applications
│   │   ├── staff_roles.php          # Staff role management
│   │   ├── print_requisition.php    # Printable kitchen requisition
│   │   └── api/                     # Business owner AJAX endpoints
│   │       ├── add_menu_item.php
│   │       ├── update_menu_item.php
│   │       ├── hacera_api.php       # HaCeRa AJAX (save/upload/publish/PDF)
│   │       ├── modify_order.php
│   │       ├── void_order.php
│   │       ├── pos_payment_callback.php
│   │       ├── confirm_cash_payment.php
│   │       ├── get_cashier_orders.php
│   │       ├── get_order_payment_statuses.php
│   │       ├── log_backdated_order.php
│   │       ├── menu_item_approval.php
│   │       ├── staff_action.php
│   │       ├── staff_app_action.php
│   │       ├── staff_application_handler.php
│   │       ├── staff_management_handler.php
│   │       ├── staff_role_handler.php
│   │       ├── update_order_status.php
│   │       └── chef_action.php
│   │
│   └── admin/                       # ROLE_ADMIN (role_id = 11) — new dashboard
│       ├── index.php                # Admin home + system stats
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
├── admin/                           # Legacy HCB staff portal
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
├── database/                        # SQL migration files (run manually)
│   ├── business_applications_migration.sql
│   ├── cash_payment_migration.sql
│   ├── credibility_score_migration.sql
│   ├── demand_survey_checkpoint_migration.sql
│   ├── menu_approval_migration.sql
│   ├── order_survey_integration_migration.sql
│   ├── restaurant_staff_migration.sql
│   ├── tpb_pbc_migration.sql
│   └── tpb_survey_migration.sql
│
├── uploads/                         # Runtime file storage (auto-created)
│   ├── avatars/
│   ├── documents/
│   ├── certificates/
│   ├── laboratory/
│   ├── role_applications/
│   ├── restaurant_images/
│   ├── menu_images/
│   ├── receipts/
│   ├── staff_applications/
│   ├── business_docs/
│   └── products/
│
└── assets/
    ├── css/style.css                # Custom stylesheet (Islamic Green + Gold theme)
    ├── js/main.js                   # Shared dashboard JS
    ├── js/survey_modal.js           # TPB survey modal logic
    ├── images/                      # Static images (logos)
    └── data/                        # Philippine administrative JSON files
        ├── regions.json
        ├── provinces.json
        ├── cities.json
        └── barangays.json
```

---

## 5. Configuration & Bootstrap Layer

Every PHP page begins with `require_once 'config/app.php'`. This single entry point chains all shared dependencies in a fixed order.

### Bootstrap Chain

```
Any PHP page
  └─ config/app.php
        ├─ config/env.php          (load .env → getenv())
        ├─ config/database.php     (open MySQLi $conn, UTF-8)
        ├─ config/constants.php    (all defines: roles, keys, limits)
        ├─ includes/functions.php  (helper functions)
        └─ includes/staff_rbac.php (restaurant sub-role guard)
```

`config/app.php` also:
- Enforces PHP 8.0+ minimum version
- Starts the PHP session
- Sets UTF-8 globally (`ini_set`, `mb_internal_encoding`, `Content-Type` header)
- Enforces DLP session timeout (reads `session_timeout_minutes` from `system_settings` table)
- Refreshes `$_SESSION['staff_role_key']` on every request for `ROLE_BUSINESS_OWNER` sessions
- Creates all `uploads/` subdirectories if they don't yet exist

### Key Constants (`config/constants.php`)

| Group | Examples |
|---|---|
| **Identity** | `SITE_NAME = 'Halal Keeps'`, `SITE_TAGLINE` |
| **URL** | `BASE_URL` — auto-detected from `HTTP_HOST`; reads `APP_SUBFOLDER` from `.env` for localhost |
| **Paths** | `UPLOAD_DIR` (absolute), `MAX_FILE_SIZE` (20 MB) |
| **OAuth** | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` |
| **Payments** | `PAYMONGO_SECRET_KEY`, `PAYMONGO_PUBLIC_KEY`, `PAYMONGO_WEBHOOK_SECRET`, `PAYMONGO_API_URL` |
| **System roles** | `ROLE_CUSTOMER=1` through `ROLE_ADMIN=11` |
| **Staff sub-roles** | `STAFF_ROLE_OWNER`, `STAFF_ROLE_OPS_MANAGER`, `STAFF_ROLE_CASHIER`, `STAFF_ROLE_CHEF` |
| **Security** | `MAX_LOGIN_ATTEMPTS=5`, `LOCKOUT_DURATION=900s`, `SESSION_TIMEOUT=1800s`, `FIELD_ENCRYPT_KEY` |
| **Data classification** | `DATA_PUBLIC`, `DATA_INTERNAL`, `DATA_CONFIDENTIAL`, `DATA_RESTRICTED` |
| **Menu** | `MENU_ITEM_CATEGORIES` (11 items), `ALLOWED_SERVING_TYPES`, `CUISINE_TYPES`, `SERVICE_TYPES` |
| **Finance** | `EXPENSE_CATEGORIES` (9 types with icons), `EXPENSE_PERIODS`, `INFLOW_TYPES` |
| **App status** | `STATUS_DRAFT`, `STATUS_SUBMITTED`, `STATUS_UNDER_REVIEW`, `STATUS_APPROVED`, etc. |

---

## 6. Authentication & Session Management

### Login Flow

```
User submits email + password
        │
        ▼
  Check failed_login_attempts ──── ≥ MAX_LOGIN_ATTEMPTS ──→ Account locked error
        │
        ▼
  password_verify() against bcrypt hash in users.password
        │
     ┌──┴──┐
   Fail   Pass
     │      │
     ▼      ▼
  Increment  Reset attempts
  attempts   autoAssignCustomerRole()
             createUserSession()
             getDashboardUrl(role_id) → redirect
```

### Google OAuth Flow

```
Login page → getGoogleAuthUrl() → Google consent screen
        │
        ▼
auth/google_callback.php
  ├─ Exchange code for access token
  ├─ Fetch user profile from Google API
  ├─ Upsert into users table
  ├─ autoAssignCustomerRole()
  ├─ createUserSession()
  └─ getDashboardUrl(role_id) → redirect
```

### Session Variables

| Key | Description |
|---|---|
| `user_id` | Authenticated user's primary key |
| `role_id` | System role ID (maps to `roles` table) |
| `role_status` | `'approved'` / `'pending'` |
| `full_name` | Display name |
| `email` | User email |
| `staff_role_key` | Restaurant sub-role key (refreshed every request) |
| `last_activity` | Unix timestamp — used for DLP session timeout |

### Security Controls

- **Brute-force lockout:** `MAX_LOGIN_ATTEMPTS = 5`; lockout duration `LOCKOUT_DURATION = 900s`
- **Session timeout:** Configurable via `system_settings` table (`session_timeout_minutes`); defaults to 30 minutes
- **Session expiry redirect:** Sends user to `auth/login.php?error=...` with message
- **Password hashing:** bcrypt via PHP `password_hash()` / `password_verify()`
- **Field encryption:** AES via `FIELD_ENCRYPT_KEY` from `.env` for sensitive database columns

---

## 7. Role & Permission System

### System Roles

| `role_id` | Constant | Audience |
|---|---|---|
| 1 | `ROLE_CUSTOMER` | End consumers; browse, order, review, take surveys |
| 2 | `ROLE_BUSINESS_OWNER` | Food business operators and their staff |
| 3 | `ROLE_EVALUATOR` | Halal certification evaluators |
| 4 | `ROLE_AUDITOR_TECHNICAL` | Technical auditors |
| 5 | `ROLE_AUDITOR_SHARIAH` | Shariah auditors |
| 6 | `ROLE_IMPARTIAL_COMMITTEE` | Impartial committee members |
| 7 | `ROLE_DECISION_COMMITTEE` | Decision committee members |
| 8 | `ROLE_PRESIDENT` | HCB President |
| 9 | `ROLE_RECEIVING_OFFICER` | Receiving officer |
| 10 | `ROLE_LABORATORY_ANALYST` | Laboratory analyst |
| 11 | `ROLE_ADMIN` | System administrator |

Role assignment: default is `ROLE_CUSTOMER` on registration. Users apply for higher roles via `auth/apply_role.php`; admin approves in the dashboard.

### Restaurant Staff Sub-Roles

These are per-restaurant roles stored in `restaurant_staff_roles.role_key`, separate from the system-wide role_id.

| Key | Constant | Access |
|---|---|---|
| `owner` | `STAFF_ROLE_OWNER` | `owner_dashboard.php` |
| `ops_manager` | `STAFF_ROLE_OPS_MANAGER` | `operations_manager.php` |
| `cashier` | `STAFF_ROLE_CASHIER` | `pos_order_processing.php` |
| `chef` | `STAFF_ROLE_CHEF` | `chef_dashboard.php` |

Access enforcement is handled by `includes/staff_rbac.php` → `requireStaffAccess($page_name)`, which checks `$_SESSION['staff_role_key']` against the `STAFF_ROLE_PAGES` constant map.

### Role Routing

`getDashboardUrl($role_id)` in `includes/functions.php` maps each role to its home URL. After login, users are immediately redirected to their appropriate dashboard.

---

## 8. Core Modules

### 8.1 Public Landing Page

**File:** `index.php`  
**Access:** Unauthenticated (logged-in users are redirected to their dashboard)

**Features:**
- Dual-view tab switcher: **Customer View** | **Business Owner View**
- **Compliance Metrics Strip** — 5 live formula results (see §11.4)
- **Interactive D3.js Philippines map** — city-level compliance data
- **Live Purchase Intention Barometer** — TPB aggregate scores from `tpb_survey_responses`
- **Verified Halal Business Directory** — only `halal_restaurants` + `business_branches` data
- Trust / About section and CTA blocks

Data sources are all live from the database; no seed/dummy data is shown to users.

---

### 8.2 Customer Dashboard

**Role:** `ROLE_CUSTOMER` | Base path: `dashboard/customer/`

| Page | Key Functionality |
|---|---|
| `index.php` | Hero stats, cuisine filter chips, personalized restaurant recommendations (scored by food_preference + cuisine + city match), recent orders, latest reviews, TPB survey card |
| `restaurants.php` | Full listing with search, cuisine filters, 4 sort modes (Highest Rated / Credibility / Most Visited / Most Reviewed), credibility tier badges, HaCeRa readiness pills, animated foot-traffic meter |
| `restaurant_view.php` | Full restaurant detail: menu, reviews, credibility panel (4-component breakdown), traffic source split, `logBusinessView()` call |
| `tpb_survey.php` | 15-item Likert TPB survey (Set 1 for Muslim, Set 2 for Non-Muslim), 5-point scale, sticky progress bar, retake support |
| `orders.php` | Order history with status tracking (pending → confirmed → preparing → delivered / cancelled) |
| `reviews.php` | Submitted reviews with star ratings |
| `apply_business.php` | Multi-step business registration form with document uploads |

---

### 8.3 Business Owner Dashboard

**Role:** `ROLE_BUSINESS_OWNER` | Base path: `dashboard/business_owner/`

#### Owner Dashboard (`owner_dashboard.php`) — 4 Tabs

| Tab | Contents |
|---|---|
| **Overview** | HaCeRa readiness card, KPI stats (orders / revenue / rating / staff), staff management, recent orders |
| **Financial Report** | Daily revenue / expenses / net cash flow, Chart.js hourly bar chart, expense ledger |
| **Business Visits & Foot Traffic** | Online views vs. verified dine-in split, growth %, KPI cards |
| **Customer Purchase Intention** | TPB demand analytics — Muslim vs. Non-Muslim PI scores, radar chart (ATT / SN / PBC), insight tiles |

#### Other Business Owner Pages

| Page | Purpose |
|---|---|
| `operations_manager.php` | Accessible to `owner` + `ops_manager`; tabs: Profile, Menu, Expenses, Reviews, Requisitions |
| `restaurant.php` | Full restaurant profile editor (name, address, cuisine, hours, service types, image) |
| `hacera.php` | HaCeRa 5-module wizard with live AJAX scoring (see §11.3) |
| `halal_certification.php` | Halal certification application tracker / status pipeline |
| `manage_orders.php` | Order queue for owner/ops manager |
| `pos_order_processing.php` | Cashier POS interface |
| `chef_dashboard.php` | Kitchen view for chefs (active orders, requisitions) |
| `staff_applications.php` | Review and approve/reject incoming staff applications |
| `staff_roles.php` | Manage restaurant staff role definitions |
| `print_requisition.php` | Printable kitchen requisition form |

---

### 8.4 Admin Panel

**Role:** `ROLE_ADMIN` | Base path: `dashboard/admin/`

| Page | Purpose |
|---|---|
| `index.php` | System stats overview |
| `business_applications.php` | Review and approve/reject business registration applications |
| `approve_roles.php` | Approve pending system role applications |
| `role_applications.php` | Full list of all role applications |
| `authorization.php` | Role and permission management |
| `dlp_features.php` | DLP feature toggle panel |
| `logging_monitoring.php` | System event log viewer |
| `secure_data.php` | Encrypted field viewer |

---

### 8.5 HCB Staff Portal (Legacy Admin)

**Path:** `admin/`  
Hosts the Halal Certification Body (HCB) staff portal with audit tools and system configuration.

| File | Purpose |
|---|---|
| `users.php` | User management (view, lock/unlock, assign roles) |
| `roles.php` | System role definitions |
| `activity_logs.php` | Full user activity audit trail |
| `login_logs.php` | Login attempt log (success + failure) |
| `settings.php` | System settings (session timeout, feature flags) |
| `system_logs.php` | System-level event log |
| `data_classification.php` | Data classification levels per table/column |
| `dlp_settings.php` | DLP policy configuration (session timeout, screenshot block, print block, etc.) |
| `encrypted_fields.php` | Encrypted field registry |
| `api_permissions.php` | API endpoint permission matrix |

---

## 9. API Endpoints

### Public/Authenticated REST Endpoints (`api/`)

| Endpoint | Method | Auth | Purpose |
|---|---|---|---|
| `submit_survey.php` | POST | ROLE_CUSTOMER | Submit 15-item TPB survey |
| `tpb_analytics.php` | GET | Public / Owner | TPB aggregate scores |
| `credibility_scores.php` | GET/POST | Public / Admin | Query or recompute credibility scores |
| `halal_directory_metrics.php` | GET | Public | City compliance metrics for map |
| `notifications.php` | GET/POST | Authenticated | In-app notifications CRUD |
| `ph_locations.php` | GET | Public | Philippine regions/provinces/cities/barangays |
| `upload_requirement.php` | POST | ROLE_CUSTOMER | Certification document upload |
| `check_survey_status.php` | GET | ROLE_CUSTOMER | Survey completion check |
| `paymongo_webhook.php` | POST | PayMongo signed | Payment event receiver |
| `process_paymongo_payment.php` | POST | Customer | Initiate PayMongo checkout session |

### Internal AJAX Endpoints (`dashboard/*/api/`)

All internal endpoints check session auth and role before processing. They return JSON responses.

**Business Owner:**

| Endpoint | Purpose |
|---|---|
| `add_menu_item.php` | Validate + insert menu item |
| `update_menu_item.php` | Validate + update menu item |
| `hacera_api.php` | HaCeRa: save response, upload doc, get scores, generate PDF, publish |
| `modify_order.php` | Change order status |
| `void_order.php` | Void / cancel order |
| `confirm_cash_payment.php` | Mark cash payment confirmed |
| `get_cashier_orders.php` | Poll active orders for POS |
| `update_order_status.php` | Update order status from POS |
| `staff_action.php` | Assign / deactivate / remove / change staff role |
| `staff_app_action.php` | Approve / reject staff application |
| `chef_action.php` | Chef kitchen actions (requisitions) |

**Customer:**

| Endpoint | Purpose |
|---|---|
| `place_order.php` | Create order + PayMongo or COD payment |
| `confirm_received.php` | Mark order as received by customer |
| `submit_review.php` | Post review + update `avg_rating` |
| `submit_staff_request.php` | Apply for a staff position |

**Admin:**

| Endpoint | Purpose |
|---|---|
| `admin/api/check_permission.php` | Check if an action is allowed by DLP policy |
| `admin/api/log_dlp.php` | Log a DLP event |
| `admin/api/ping_session.php` | Keep-alive for DLP session timeout |
| `dashboard/admin/api/business_app_detail.php` | Fetch business application detail |

---

## 10. Database Architecture

**Database name:** `halal_system`  
**Charset:** `utf8mb4`  
**Driver:** MySQLi

### Core Tables

| Table | Purpose |
|---|---|
| `users` | All accounts. Includes `religion`, `food_preference`, `failed_login_attempts`, `account_locked` |
| `roles` | System role definitions (id + name) |
| `halal_restaurants` | Live restaurant registry. Includes `hacera_hrp`, `hacera_band`, `credibility_score`, `credibility_tier`, `avg_rating`, `business_owner_id` |
| `business_applications` | Business registration applications and Halal certification submissions |
| `business_application_documents` | Documents attached to applications |
| `business_branches` | Branch addresses per business |
| `restaurant_staff` | User ↔ restaurant staff assignments. `is_active` flag |
| `restaurant_staff_roles` | Per-restaurant role definitions (role_key + display name) |
| `menu_items` | Restaurant menu items with `category`, `serving_type`, `price`, `approval_status` |
| `orders` | Customer orders. `service_type`: `dine_in` \| `takeout` \| `delivery` |
| `order_items` | Line items per order |
| `order_status_history` | Full audit trail of order status changes |
| `reviews` | Customer reviews with star ratings |
| `tpb_survey_responses` | Aggregate TPB scores per user (`mean_att`, `mean_sn`, `mean_pbc`, `normalized_pi`, `questionnaire_set`) |
| `tpb_survey_answers` | Individual 15-item Likert responses per user |
| `notifications` | In-app notification feed |
| `activity_log` | User action audit trail |
| `system_settings` | Key-value store for runtime configuration (session timeout, DLP flags, etc.) |
| `restaurant_credibility_log` | Historical credibility score log per restaurant |
| `business_views` | Profile view tracking (digital traffic) |
| `restaurant_expenses` | Restaurant operational expenses |
| `restaurant_expense_items` | Expense line items |
| `restaurant_other_inflows` | Non-order revenue (catering, grants, donations, etc.) |
| `paymongo_webhook_logs` | Webhook event log for idempotency and debugging |
| `cashier_notifications` | POS-specific notifications for cashier users |

### Schema Migration Strategy

Migrations are SQL files in `database/` run manually in phpMyAdmin. All tables also auto-create idempotently on first use via `CREATE TABLE IF NOT EXISTS` in PHP, and `db_add_column()` / `db_add_fk()` helpers in `config/database.php` allow safe incremental column additions without breaking existing data.

---

## 11. Key Business Algorithms

### 11.1 TPB Consumer Demand Survey Engine

**Files:** `api/submit_survey.php`, `api/tpb_analytics.php`, `dashboard/customer/tpb_survey.php`

The survey uses the **Theory of Planned Behavior** (ATT + SN + PBC) with 15 Likert-scale items (1–5).

**Questionnaire sets:**
- **Set 1** — Muslim users (religion-specific attitude + social norm framing)
- **Set 2** — Non-Muslim users (inclusive / general framing of the same constructs)

**Scoring formulas:**

```
Mean(ATT)      = Σ(ATT1–ATT5) / 5
Mean(SN)       = Σ(SN1–SN5)   / 5
Mean(PBC)      = Σ(PBC1–PBC5) / 5

Raw PI         = Mean(ATT) + Mean(SN) + Mean(PBC)     [range: 3.0 – 15.0]
Normalized PI  = Raw PI / 3                            [range: 1.0 –  5.0]
PI%            = (Normalized PI − 1) / 4 × 100        [range: 20% – 100%]
```

Results are stored in `tpb_survey_responses` and aggregated for the owner's **Customer Purchase Intention** tab and the public **Purchase Intention Barometer** on the landing page.

---

### 11.2 Credibility Score Engine

**Files:** `api/credibility_scores.php`, `includes/functions.php` → `computeCredibilityScore()`

A composite 0–100 score per restaurant made up of four weighted components:

| Component | Weight | Source Data |
|---|---|---|
| **Foot Traffic** | 35% | Verified dine-in order count (`orders` where `service_type = 'dine_in'`) |
| **Engagement** | 25% | `business_views` (digital profile visits) |
| **Compliance** | 25% | Halal certification status + HaCeRa readiness band |
| **Popularity** | 15% | Review count + average rating from `reviews` |

**Credibility Tiers:**

| Score | Tier | Label |
|---|---|---|
| 75–100 | Tier 3 | Highly Credible |
| 50–74 | Tier 2 | Credible |
| 25–49 | Tier 1 | Building Credibility |
| 0–24 | Tier 0 | New |

Scores are cached on `halal_restaurants.credibility_score` with a 24-hour staleness check. Historical scores are logged to `restaurant_credibility_log`.

---

### 11.3 HaCeRa Readiness Assessment

**Files:** `dashboard/business_owner/hacera.php`, `dashboard/business_owner/api/hacera_api.php`, `includes/HaCeRaCalculator.php`

**HaCeRa** (Halal Certification Readiness Assessment) is a 5-module self-assessment wizard.

| Module | Focus Area |
|---|---|
| 1 | Conditions of Certification (permits, licenses) |
| 2 | Raw Materials & Ingredients (Halal certificates, traceability) |
| 3 | Processing & Logistics (contamination controls, transport) |
| 4 | Packaging & Labelling (10 HDIP label requirements) |
| 5 | Premises & Welfare (prayer facilities, Muslim staff ratio) |

**Scoring:**

```
Module Score  = Σ(completed_item_weights) / Σ(all_item_weights) × 100
HRP (Overall) = Mean of all 5 Module Scores
```

**Readiness Bands:**

| HRP% | Band |
|---|---|
| 80–100% | Ready |
| 60–79% | Near Ready |
| 40–59% | Developing |
| 0–39% | Not Ready |

The published `hacera_hrp` and `hacera_band` values are written to `halal_restaurants` and displayed on restaurant cards in the customer directory. A gap analysis engine highlights missing high-priority checklist items.

---

### 11.4 Compliance Index Formulas

Displayed as 5 metric cards on the landing page, calculated from live restaurant data:

```
Compliance Index      = Total Compliant ÷ Total Food Businesses × 100
Halal-Compliant Score = (Halal-Certified + Muslim-Friendly + Non-Pork) ÷ Total Compliant × 100
Halal-Certified Score = Halal-Certified ÷ Total Compliant × 100
Muslim-Friendly Score = Muslim-Friendly ÷ Total Compliant × 100
Non-Pork Score        = Non-Pork ÷ Total Compliant × 100
```

All counts come exclusively from `halal_restaurants` + `business_branches`.

---

## 12. Payment Integration (PayMongo)

**Files:** `api/paymongo_webhook.php`, `api/process_paymongo_payment.php`, `dashboard/customer/api/place_order.php`, `dashboard/business_owner/api/pos_payment_callback.php`, `dashboard/business_owner/api/confirm_cash_payment.php`

### Supported Payment Methods

| Method | Type |
|---|---|
| **GCash** | Online (PayMongo) |
| **PayMaya / Bank Transfer** | Online (PayMongo) |
| **Cash on Delivery / Walk-in** | Offline (manually confirmed by cashier) |

Delivery orders force online payment (`DELIVERY_REQUIRES_ONLINE_PAYMENT = true`).

### Online Payment Flow

```
Customer places order
        │
        ▼
place_order.php → creates order (status: pending)
        │
        ▼
process_paymongo_payment.php
  → POST /v1/checkout_sessions to PayMongo API
  → Returns checkout URL
        │
        ▼
Customer redirected to PayMongo-hosted payment page
        │
        ▼
PayMongo sends webhook → api/paymongo_webhook.php
  ├─ Verifies event type (checkout_session.payment.paid / payment.paid / payment.failed)
  ├─ Idempotency check: skip if payment_verified_at is already set
  ├─ Updates orders (payment_status = 'paid', order_status = 'confirmed')
  ├─ Writes to order_status_history audit trail
  ├─ Notifies all active cashiers via cashier_notifications
  ├─ Notifies business owner
  └─ Notifies customer
```

### Cash Payment Flow

```
Walk-in customer places order at POS
        │
        ▼
pos_order_processing.php → order created (payment_method: 'cash')
        │
        ▼
Cashier manually confirms → confirm_cash_payment.php
        │
        ▼
Order marked 'confirmed', kitchen notified
```

All webhook events are logged to `paymongo_webhook_logs` for debugging and auditing.

---

## 13. External Integrations

| Integration | Purpose | Key Files |
|---|---|---|
| **Google OAuth 2.0** | Social login / sign-up | `auth/google_callback.php`, `config/constants.php` |
| **PayMongo** | Online payments (GCash, PayMaya) | `api/paymongo_webhook.php`, `api/process_paymongo_payment.php` |
| **mPDF** | PDF generation (HaCeRa reports, certificates) | `dashboard/business_owner/api/hacera_api.php`, `vendor/mpdf/mpdf` |
| **D3.js** | Interactive Philippines compliance map on landing page | `index.php` (CDN) |
| **Chart.js** | Financial/analytics charts in dashboards | Various dashboard pages (CDN) |
| **Google Fonts** | Inter, Outfit, Manrope, Plus Jakarta Sans | Loaded via CDN in HTML heads |
| **Font Awesome 6** | Icon set throughout the UI | Loaded via CDN |
| **Tailwind CSS v3** | Utility-first CSS framework | Loaded via CDN with `forms` + `container-queries` plugins |

### Philippine Location Data

`api/ph_locations.php` serves region → province → city → barangay data from JSON files in `assets/data/`. Used in cascading dropdowns for registration and profile address forms.

---

## 14. Security & Data Loss Prevention (DLP)

### DLP Features

Configurable via the admin `dlp_settings.php` panel and stored in the `system_settings` table:

| DLP Control | Setting Key | Default |
|---|---|---|
| Session timeout | `dlp_session_timeout` | 1800s (30 min) |
| Max failed logins | `dlp_max_login_attempts` | 5 |
| Account lockout duration | `dlp_lockout_duration` | 900s (15 min) |
| Block PrintScreen | `dlp_screenshot_block` | Enabled |
| Block Ctrl+P | `dlp_print_block` | Enabled |
| Copy warning threshold | `dlp_copy_warn_threshold` | 50 chars |
| Restrict data export | `dlp_export_restrict` | Enabled |
| Print watermark | `dlp_watermark_enabled` | Enabled |
| Block right-click on sensitive tables | `dlp_right_click_block` | Enabled |
| Session expiry warning | `dlp_session_warning_secs` | 60s before expiry |

### Field-Level Encryption

Sensitive database fields are encrypted at rest using a symmetric key (`FIELD_ENCRYPT_KEY` from `.env`). The encrypted field registry is managed in `admin/encrypted_fields.php`. Decrypted values are visible only to admins in `dashboard/admin/secure_data.php`.

### Data Classification Levels

| Level | Constant | Description |
|---|---|---|
| Public | `DATA_PUBLIC` | Non-sensitive, freely visible |
| Internal | `DATA_INTERNAL` | Internal use only |
| Confidential | `DATA_CONFIDENTIAL` | Access restricted by role |
| Restricted | `DATA_RESTRICTED` | Highest sensitivity; encrypted + logged |

Classification is configured per table/column in `admin/data_classification.php`.

### Audit Logging

- **User activity log** — `logActivity($conn, $uid, $action, $details, $type)` inserts into `activity_log`
- **Admin activity log** — `logAdminActivity()` in `admin/admin_functions.php`
- **DLP events** — logged via `admin/api/log_dlp.php`
- **Login attempts** — logged to `login_logs` (success + failure)
- **Payment events** — logged to `paymongo_webhook_logs`
- **Order status changes** — logged to `order_status_history`

### API Permission Matrix

`admin/api_permissions.php` maintains a per-endpoint permission table checked by `admin/api/check_permission.php`. AJAX calls from sensitive pages validate against this before proceeding.

### .htaccess Security

- URL rewriting to clean URLs
- Security response headers (configured per deployment environment)
- HTTPS redirect block (commented out by default, activated in production)
- Blocks direct access to `.env`, `database/`, and other sensitive paths

---

## 15. POS & Order Management System

**Files:** `dashboard/business_owner/pos_order_processing.php`, `chef_dashboard.php`, `manage_orders.php`

### Order Lifecycle

```
Customer places order (dine_in / takeout / delivery)
        │
        ▼
  orders table: status = 'pending'
        │
        ├─ Online payment → PayMongo webhook confirms → status = 'confirmed'
        │
        └─ Cash / Walk-in → Cashier confirms payment → status = 'confirmed'
                │
                ▼
        Kitchen is notified → Chef sees in chef_dashboard
                │
                ▼
        status = 'preparing'
                │
                ▼
        status = 'ready'
                │
                ▼
        status = 'delivered' (or 'cancelled' / 'void' at any earlier stage)
                │
                ▼
        Customer confirms receipt → confirm_received.php
```

### Order Status Set

`pending` → `confirmed` → `preparing` → `ready` → `delivered` | `cancelled` | `voided`

### POS Cashier Interface

- Walk-in order intake with menu item selection
- Cash or PayMongo payment processing
- `get_cashier_orders.php` polls for new paid orders (AJAX)
- `cashier_notifications` table feeds real-time order alerts
- `get_order_payment_statuses.php` tracks online payment resolution

### Kitchen (Chef) Interface

- `chef_dashboard.php` shows active confirmed/preparing orders
- Chef can update order status per item
- Kitchen requisition system with `print_requisition.php`

### Backdated Orders

`api/log_backdated_order.php` allows business owners to log past orders for financial reporting accuracy.

---

## 16. Staff Management (Restaurant RBAC)

**Files:** `includes/staff_rbac.php`, `dashboard/business_owner/staff_roles.php`, `dashboard/business_owner/staff_applications.php`, various `api/staff_*.php` endpoints

### How it works

1. Any `ROLE_CUSTOMER` user can apply for a staff position via `dashboard/customer/api/submit_staff_request.php`
2. Business owner receives the application in `staff_applications.php`
3. Owner approves and assigns a sub-role via `api/staff_app_action.php` → inserts into `restaurant_staff`
4. On next login (or next request refresh), `config/app.php` sets `$_SESSION['staff_role_key']`
5. `requireStaffAccess($page_name)` enforces page-level access per sub-role
6. If a staff member is deactivated mid-session, the next request detects the null role key and redirects to the customer marketplace with a flash message

### Staff Deactivation Safety

```php
// In config/app.php — runs on every request for ROLE_BUSINESS_OWNER:
if ($prev_key !== null && $new_key === null) {
    // Was active, now deactivated → kick to customer view
    redirect to dashboard/customer/restaurants.php
}
```

---

## 17. Assets & Frontend

### CSS — `assets/css/style.css`

Custom dashboard stylesheet layered on top of Tailwind. Design tokens:

| Token | Value |
|---|---|
| **Primary** (Islamic Green) | `#10b981` base; CSS variables `--primary-50` through `--primary-900` |
| **Accent** (Gold) | `#f59e0b` base; CSS variables `--accent-50` through `--accent-900` |
| **Neutral** (Slate) | CSS variables `--neutral-50` through `--neutral-950` |
| **Sidebar width** | `--sidebar-width: 280px` / `--sidebar-collapsed: 70px` |
| **Header height** | `--header-height: 70px` |

### JavaScript — `assets/js/main.js`

Shared dashboard JS initialized on `DOMContentLoaded`. Modules:

| Function | Purpose |
|---|---|
| `initNavbar()` | Scroll effect + mobile menu toggle |
| `initAlerts()` | Close buttons + 5s auto-dismiss for success alerts |
| `initModals()` | `[data-modal]` trigger, `.modal-overlay` backdrop, `.modal-close` |
| `initNotifications()` | Notification panel toggle, AJAX load, ARIA `aria-expanded` |
| `initDropdowns()` | Custom dropdown menus |
| `initFileUploads()` | File input previews |
| `initSidebar()` | Collapse/expand sidebar |
| `initMobileSearch()` | Mobile search toggle |
| `initNavGroups()` | Accordion navigation groups |
| `initTooltips()` | Tooltip initialization |
| `openModal(id)` / `closeModal()` | Global modal controls |

**`assets/js/survey_modal.js`** — handles the TPB survey modal rendering and submission flow.

### Philippine Location Data (`assets/data/`)

Four JSON files (`regions.json`, `provinces.json`, `cities.json`, `barangays.json`) power cascading location dropdowns in registration and profile forms without requiring database calls for static geographic data.

---

## 18. Environment Configuration

All secrets and environment-specific settings are loaded from `.env` via `config/env.php`.

| Variable | Purpose |
|---|---|
| `APP_SUBFOLDER` | XAMPP subfolder name (leave blank in production) |
| `APP_ENV` | `local` or `production` (controls error display) |
| `DB_HOST` | Database host (default: `127.0.0.1`) |
| `DB_PORT` | Database port (default: `3306`) |
| `DB_USER` | Database username |
| `DB_PASS` | Database password |
| `DB_NAME` | Database name (default: `halal_system`) |
| `GOOGLE_CLIENT_ID` | Google OAuth client ID |
| `GOOGLE_CLIENT_SECRET` | Google OAuth client secret |
| `PAYMONGO_SECRET_KEY` | PayMongo secret key (`sk_test_` or `sk_live_`) |
| `PAYMONGO_PUBLIC_KEY` | PayMongo public key (`pk_test_` or `pk_live_`) |
| `PAYMONGO_WEBHOOK_SECRET` | PayMongo webhook signing secret (`whsec_...`) |
| `FIELD_ENCRYPT_KEY` | 64-char hex key for field-level encryption |

`BASE_URL` is computed automatically from `HTTP_HOST` + `APP_SUBFOLDER` — no manual URL setting needed.

---

## 19. File Upload System

**Constant:** `UPLOAD_DIR = dirname(__DIR__) . '/uploads/'`  
**Max file size:** 20 MB (`MAX_FILE_SIZE`)  
**Allowed types:** PDF, DOC, DOCX, JPG, JPEG, PNG, GIF (documents); JPG, JPEG, PNG, GIF, WEBP (images)

Upload subdirectories are created automatically on every bootstrap by `config/app.php`:

```
uploads/
├── avatars/               User profile photos
├── documents/             General certification documents
├── certificates/          Issued Halal certificates
├── laboratory/            Lab analyst reports
├── role_applications/     Documents for role upgrade applications
├── restaurant_images/     Restaurant cover/gallery images
├── menu_images/           Menu item photos
├── receipts/              Payment receipts
├── staff_applications/    Staff application documents
├── business_docs/         Business registration documents
└── products/              Product documentation
```

Uploads are handled by `uploadFile($file, $subdir, $allowed_types)` in `includes/functions.php`, which validates file extension, size, and generates unique filenames.

---

## 20. Notification System

**Files:** `api/notifications.php`, `includes/functions.php` → `createNotification()` / `getNotifications()`

### Notification Types

| Type | Usage |
|---|---|
| `success` | Payment confirmed, application approved |
| `info` | Status updates, informational messages |
| `warning` | Session warnings, pending items |
| `action_required` | New paid order for cashier, staff application pending |

### Notification Flow

1. Any server-side event calls `createNotification($conn, $user_id, $title, $message, $type, $link)`
2. Row inserted into `notifications` table
3. Dashboard header's bell icon loads unread count via AJAX → `api/notifications.php?action=get`
4. Panel displays notification list with relative timestamps (`timeAgo()`)
5. Read state updated via `action=mark_read` or `action=mark_all_read`

---

## 21. Data Flow Diagrams

### User Registration & Role Assignment

```
Browser → auth/register.php
  ├─ Collect: name, email, password, religion, food_preference, address
  ├─ INSERT INTO users (role_id = ROLE_CUSTOMER, role_status = 'approved')
  └─ Redirect → auth/login.php?registered=1

User wants higher role
  ├─ auth/apply_role.php → INSERT INTO role_applications
  └─ Admin reviews → dashboard/admin/approve_roles.php
       └─ UPDATE users SET role_id = X, role_status = 'approved'
```

### Business Registration & Certification

```
Customer → dashboard/customer/apply_business.php
  ├─ Multi-step form: certification path, business info, branch address, docs
  ├─ INSERT INTO business_applications
  ├─ INSERT INTO business_application_documents (uploads)
  └─ INSERT INTO business_branches

Admin reviews → dashboard/admin/business_applications.php
  ├─ View docs via dashboard/admin/api/business_app_detail.php
  └─ Approve: UPDATE business_applications SET status = 'approved'
              INSERT INTO halal_restaurants (triggers restaurant creation)

Business owner → dashboard/business_owner/halal_certification.php
  └─ Track status: draft → submitted → under_review → approved / returned / rejected
```

### Order Placement & Fulfillment

```
Customer (online order)                 Walk-in (POS)
        │                                     │
  place_order.php                  pos_order_processing.php
  INSERT INTO orders                   INSERT INTO orders
  PayMongo checkout ─→ PayMongo        confirm_cash_payment.php
        │                                     │
  paymongo_webhook.php                        │
  UPDATE orders                               │
  → status: confirmed ←────────────────────────
        │
  Notify cashier + owner + customer
        │
  chef_dashboard.php (kitchen view)
        │
  UPDATE status: preparing → ready → delivered
        │
  confirm_received.php (customer)
```

---

*Last updated: October 2026*
