# Halal Keeps — System Documentation

> **Platform:** Halal Food Business Directory, Certification & Management Platform  
> **Location:** Davao City, Philippines  
> **Stack:** PHP 8.0+, MySQL/MariaDB, Tailwind CSS, Vanilla JS, Chart.js, PayMongo  
> **Local URL:** `http://localhost/halal_final/`

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Architecture](#2-architecture)
3. [User Roles](#3-user-roles)
4. [Core Workflows](#4-core-workflows)
   - 4.1 [Registration & Authentication](#41-registration--authentication)
   - 4.2 [Business Registration & Onboarding](#42-business-registration--onboarding)
   - 4.3 [HaCeRa — Halal Readiness Assessment](#43-hacera--halal-readiness-assessment)
   - 4.4 [Menu Management & Approval Pipeline](#44-menu-management--approval-pipeline)
   - 4.5 [Customer Ordering Workflow](#45-customer-ordering-workflow)
   - 4.6 [POS / Walk-in Order Workflow](#46-pos--walk-in-order-workflow)
   - 4.7 [Kitchen & Requisition Workflow](#47-kitchen--requisition-workflow)
   - 4.8 [Financial Reporting Workflow](#48-financial-reporting-workflow)
   - 4.9 [Other Revenue Inflows Workflow](#49-other-revenue-inflows-workflow)
   - 4.10 [Backdated Order Logging](#410-backdated-order-logging)
   - 4.11 [TPB Consumer Demand Survey](#411-tpb-consumer-demand-survey)
   - 4.12 [Credibility Score System](#412-credibility-score-system)
   - 4.13 [Review & Rating Workflow](#413-review--rating-workflow)
   - 4.14 [Staff Management Workflow](#414-staff-management-workflow)
   - 4.15 [Admin & Role Approval Workflow](#415-admin--role-approval-workflow)
5. [Module Reference](#5-module-reference)
6. [Data Flow Diagrams](#6-data-flow-diagrams)
7. [Key Database Tables](#7-key-database-tables)
8. [Financial Calculations](#8-financial-calculations)
9. [Security & Access Control](#9-security--access-control)
10. [Integrations](#10-integrations)

---

## 1. System Overview

Halal Keeps serves three primary audiences and ties them together in a single platform:

| Audience | What they do |
|---|---|
| **Customers** | Browse the Halal food directory, place orders, complete TPB surveys, write reviews |
| **Business Owners & Staff** | Register businesses, manage menus/orders/expenses, track finances, complete HaCeRa |
| **Admins / HCB Staff** | Approve role and business applications, monitor system activity, manage certifications |

### The 5 Pillars of Halal Keeps

```
1. Halal Food Directory      — discover verified Halal, Muslim-Friendly, and Non-Pork businesses
2. Business Certification    — HaCeRa readiness assessment + official Halal certification pipeline
3. Restaurant Operations     — POS, orders, menu, staff management, expenses, inflows
4. Consumer Demand Analytics — Theory of Planned Behavior (TPB) survey engine
5. Credibility Scoring       — composite trust score for restaurants based on compliance + activity
```

---

## 2. Architecture

```
halal_final/
│
├── config/             Bootstrap layer (app.php, constants.php, database.php, env.php)
├── auth/               Authentication (login, register, OAuth, logout, apply_role)
├── includes/           Shared PHP libraries (functions.php, HaCeRaCalculator.php,
│                         header.php, footer.php, staff_rbac.php)
├── api/                Public/authenticated JSON API endpoints
├── dashboard/
│   ├── customer/       Customer-facing pages
│   ├── business_owner/ Business owner + staff role pages
│   └── admin/          Admin dashboard
├── admin/              Legacy HCB staff portal
├── assets/             CSS, JS, images, PH location JSON files
└── index.php           Public landing page
```

### Request Lifecycle

```
Browser Request
    │
    ▼
config/app.php  ──►  session_start()
                ──►  UTF-8 enforcement
                ──►  database.php  (MySQLi connection)
                ──►  constants.php (all ROLE_*, STAFF_ROLE_*, categories)
                ──►  functions.php (all helper functions)
                ──►  staff_rbac.php (requireStaffAccess guard)
                ──►  DLP session timeout check
                │
                ▼
    Page PHP file
        │  requireLogin()         — redirects to auth/login.php if not authenticated
        │  requireRole([...])     — redirects if role not in allowed list
        │  requireStaffRole(...)  — redirects if staff sub-role not allowed
        │
        ▼
    includes/header.php  —  renders <head>, sidebar, top header
        │
        ▼
    Page HTML + PHP logic
        │
        ▼
    includes/footer.php  —  closes layout, outputs $extra_js (page-specific JS)
```

---

## 3. User Roles

### System-wide Roles

| ID | Constant | Who | Default Access |
|---|---|---|---|
| 1 | `ROLE_CUSTOMER` | End customers | Browse, order, review, TPB survey |
| 2 | `ROLE_BUSINESS_OWNER` | Business owners + their staff | Restaurant management, orders, finances |
| 3 | `ROLE_EVALUATOR` | HCB evaluator | Certification review |
| 4 | `ROLE_AUDITOR_TECHNICAL` | Technical auditor | Technical audit actions |
| 5 | `ROLE_AUDITOR_SHARIAH` | Shariah auditor | Shariah audit actions |
| 6 | `ROLE_IMPARTIAL_COMMITTEE` | Impartial committee | Committee review |
| 7 | `ROLE_DECISION_COMMITTEE` | Decision committee | Final certification decision |
| 8 | `ROLE_PRESIDENT` | HCB president | Presidential approval |
| 9 | `ROLE_RECEIVING_OFFICER` | Receiving officer | Application intake |
| 10 | `ROLE_LABORATORY_ANALYST` | Lab analyst | Lab test management |
| 11 | `ROLE_ADMIN` | System administrator | Full system access |

### Restaurant Staff Sub-roles

These are **per-restaurant** roles assigned by the business owner. A user with `ROLE_BUSINESS_OWNER` accesses different dashboard pages depending on their `staff_role_key`.

| Key | Label | Dashboard Page | Responsibilities |
|---|---|---|---|
| `owner` | Business Owner | `owner_dashboard.php` | All financial data, staff management, full access |
| `ops_manager` | Operations Manager | `operations_manager.php` | Menu approval, expenses, inflows, grocery lists |
| `cashier` | Cashier | `pos_order_processing.php` | Walk-in POS orders, cash payment confirmation |
| `chef` | Chef / Head Cook | `chef_dashboard.php` | Kitchen view, grocery requisitions |

---

## 4. Core Workflows

### 4.1 Registration & Authentication

#### Customer Registration

```
1. User visits auth/register.php
2. Fills in:
     - Full name, email, password
     - Religion:         Muslim | Non-Muslim        (drives TPB survey set selection)
     - Food preference:  Halal | Non-Pork | Muslim-Friendly | Any
     - Cuisine preferences (multi-select)
     - Full address (Region → Province → City → Barangay via cascading JSON dropdowns)
3. Account created with role_id = ROLE_CUSTOMER, role_status = 'approved'
4. Redirected to customer dashboard
```

#### Login Flow

```
1. User enters email + password
2. Brute-force check: ≥ 5 failed attempts in 15 minutes → account locked
3. Password verified (password_hash / password_verify)
4. Session set: user_id, role_id, role_name, full_name, email, staff_role_key
5. getDashboardUrl($role_id) → redirect to role-specific dashboard
```

#### Google OAuth

```
1. User clicks "Continue with Google"
2. Redirected to Google → auth/google_callback.php
3. User record upserted (email match → update, new email → insert with role_id=1)
4. Session set, redirect to dashboard
```

#### Session Timeout

- Configurable in `system_settings` table (default: 30 minutes of inactivity)
- Enforced on every request in `config/app.php`
- DLP ping via `admin/api/ping_session.php` keeps admin sessions alive

---

### 4.2 Business Registration & Onboarding

#### Application Submission

```
Customer (logged in as ROLE_CUSTOMER)
    │
    ▼
dashboard/customer/apply_business.php
    │
    ├── Step 1: Certification path selection
    │           (Halal Certified | Muslim-Friendly | Non-Pork)
    │
    ├── Step 2: Business details
    │           (name, type, cuisine, description, contact)
    │
    ├── Step 3: Branch address
    │           (Region → Province → City → Barangay + street)
    │
    ├── Step 4: Document uploads
    │           (business permits, owner ID, other supporting docs)
    │
    └── Submitted → business_applications table (status = 'submitted')
                 → business_application_documents table
                 → business_branches table
```

#### Admin Review

```
dashboard/admin/business_applications.php
    │
    ├── Admin reviews application details + uploaded documents
    ├── AJAX detail view: dashboard/admin/api/business_app_detail.php
    │
    ├── APPROVE → halal_restaurants record created automatically
    │            → user role updated / confirmed as ROLE_BUSINESS_OWNER
    │            → notification sent to applicant
    │
    └── REJECT  → application status = 'rejected'
                → notification sent with reason
```

#### Restaurant Profile Setup (after approval)

```
ROLE_BUSINESS_OWNER
    │
    ▼
dashboard/business_owner/restaurant.php
    │
    ├── Restaurant name, address, cuisine types (multi-select from CUISINE_TYPES)
    ├── Operating hours (open_from → open_to → stored as "HH:MM AM – HH:MM PM")
    ├── Description, phone, email
    ├── Services offered (Dine-in | Takeout | Delivery — stored as JSON array)
    └── Logo image upload (restaurant_images/ directory)
```

---

### 4.3 HaCeRa — Halal Readiness Assessment

HaCeRa (Halal Certification Readiness Assessment) helps a business owner understand how ready they are for official Halal certification before applying.

#### Workflow

```
dashboard/business_owner/hacera.php
    │
    ├── MODULE 1: Conditions of Certification
    │   └── Business permits, DTI/SEC, BIR, mayor's permit, sanitation, food handler certs
    │
    ├── MODULE 2: Raw Materials & Ingredients
    │   └── Halal certificates for meat, no pork/alcohol, ingredient traceability
    │
    ├── MODULE 3: Processing & Logistics
    │   └── Halal-dedicated equipment, contamination controls, transport conditions
    │
    ├── MODULE 4: Packaging & Labelling
    │   └── 10 HDIP labelling requirements (allergens, Halal mark, net content, etc.)
    │
    └── MODULE 5: Premises & Welfare
        └── Prayer facilities, ablution area, Muslim staff %, halal-dedicated prep areas
```

Each item is answered: **Yes** (earns weight) / **No** / **N/A**

#### Scoring

```
Section Readiness (%) = Σ(weights of Yes items) / Σ(all item weights) × 100

HRP (Halal Readiness Percentage) = arithmetic mean of all 5 module scores

Readiness Bands:
  ≥ 90%  →  Inspection Ready
  ≥ 70%  →  Near Ready
  ≥ 50%  →  In Progress
  ≥ 25%  →  Early Stage
  <  25%  →  Not Started
```

Weight tiers: W=3 (critical/mandatory), W=2 (standard), W=1 (informational)

#### Publish

```
Owner clicks "Publish Score"
    │
    ▼
dashboard/business_owner/api/hacera_api.php  (action: publish_score)
    │
    ├── Writes hacera_hrp, hacera_band, hacera_published_at to halal_restaurants
    └── HRP% now displays on:
          - Owner dashboard (HaCeRa readiness card)
          - Customer-facing restaurant cards (readiness pill)
          - Customer restaurant detail page (credibility panel)
```

Supporting documents can be uploaded per checklist item and stored in `hacera_documents`.

---

### 4.4 Menu Management & Approval Pipeline

#### Chef Creates Menu Items

```
dashboard/business_owner/chef_dashboard.php
    │
    ▼
Chef fills in: name, description, price, category, serving type, image
    │
    ▼
dashboard/business_owner/api/add_menu_item.php
    │
    ├── Validates category against MENU_ITEM_CATEGORIES constant
    ├── Validates serving_type against ALLOWED_SERVING_TYPES constant
    └── Inserts into menu_items with approval_status = 'pending'
```

#### Ops Manager Approves

```
dashboard/business_owner/operations_manager.php  (Menu tab)
    │
    ├── Pending items table shown to Ops Manager
    │
    ├── APPROVE → dashboard/business_owner/api/menu_item_approval.php
    │            → approval_status = 'approved', reviewed_by = user_id, reviewed_at = NOW()
    │            → Item now visible to customers and available on POS
    │
    └── REJECT  → approval_status = 'rejected', rejection_reason stored
                → Chef notified
```

#### Menu Item Lifecycle

```
PENDING (chef submitted)
    │
    ├── Approved by Ops Manager → APPROVED (visible on customer menu + POS)
    │
    └── Rejected by Ops Manager → REJECTED (hidden, reason shown to chef)

Updates follow the same approval flow.
Only APPROVED + is_available = 1 items appear on:
  - Customer restaurant view
  - POS item picker
  - Log Order form (owner/ops manager backdated orders)
```

---

### 4.5 Customer Ordering Workflow

```
Customer browses dashboard/customer/restaurants.php
    │
    ▼
Selects a restaurant → dashboard/customer/restaurant_view.php
    │
    ├── Surveys customer (TPB survey must be completed before ordering)
    │
    ▼
Customer adds items to cart
    │
    ├── Selects service type: Dine-in | Takeout | Delivery
    │   ├── Dine-in:  table number input
    │   └── Delivery: address input
    │
    ├── Selects payment method:
    │   ├── Cash        → order created, payment_status = 'awaiting_cash'
    │   ├── GCash       → PayMongo checkout URL generated → redirect
    │   └── Bank Transfer → PayMongo checkout URL generated → redirect
    │
    ▼
dashboard/customer/api/place_order.php
    │
    ├── Inserts into orders table
    ├── Notifies business owner + cashiers
    └── Returns order confirmation or PayMongo redirect URL

                    ┌──────────────────────────────────┐
                    │         Payment Processing        │
                    │                                   │
  Cash:             │  Cashier confirms receipt via     │
  GCash/Bank:       │  POS → payment_status = 'paid'   │
                    │                                   │
                    │  PayMongo webhook fires →          │
                    │  payment_verified_at set →        │
                    │  payment_status = 'paid'          │
                    └──────────────────────────────────┘
    │
    ▼
Order status progresses:
  pending → confirmed → preparing → ready → delivered

Customer marks as received → dashboard/customer/api/confirm_received.php
```

---

### 4.6 POS / Walk-in Order Workflow

The POS is accessible to the business owner and assigned cashier staff.

```
dashboard/business_owner/pos_order_processing.php
    │
    ├── STEP 1: Build order
    │   ├── Search / select approved menu items
    │   ├── Set quantities
    │   └── Add customer name + notes (optional)
    │
    ├── STEP 2: Choose service type
    │   (Dine-in | Takeout | Delivery)
    │
    ├── STEP 3: Choose payment method
    │   ├── Cash        → order_status = 'confirmed', payment_status = 'paid' immediately
    │   ├── GCash       → PayMongo checkout link generated → customer pays online
    │   └── Bank Transfer → PayMongo checkout link generated
    │
    └── ORDER SAVED
        │
        ├── order_number format: POS-YYYYMMDDHHmmss-XXXX
        ├── order_source = 'pos', order_placed_via = 'pos'
        └── Items stored as JSON in orders.items_json

Cash Payment Confirmation (if payment was cash):
    │
    ▼
Cashier enters cash received amount
    │
    ▼
dashboard/business_owner/api/confirm_cash_payment.php
    │
    ├── Validates: cash_received >= total_amount
    ├── Calculates change_amount = cash_received - total_amount
    ├── Sets payment_status = 'paid', payment_confirmed_by = cashier_id
    └── Sets payment_verified_at = NOW()  ← critical for financial reports
```

---

### 4.7 Kitchen & Requisition Workflow

```
CHEF creates grocery list
    │
    ▼
dashboard/business_owner/chef_dashboard.php  (Grocery Lists tab)
    │
    ├── Adds items: ingredient name, quantity, unit, estimated cost, category
    ├── Submits list → grocery_lists.status = 'pending'
    │
    ▼
OPS MANAGER reviews
    │
    ├── dashboard/business_owner/operations_manager.php  (Grocery Lists tab)
    │
    ├── ACKNOWLEDGE → status = 'acknowledged' (confirms receipt)
    │
    ├── APPROVE     → status = 'approved'
    │   │            Chef notified: list approved for purchase
    │   │
    │   └── After purchase, Ops Manager encodes expenses
    │       from approved grocery list (Expenses tab)
    │
    └── REJECT      → status = 'rejected', rejection reason stored
                    Chef notified

CHEF marks fulfilled → status = 'fulfilled'

Printable requisition:
    dashboard/business_owner/print_requisition.php
```

---

### 4.8 Financial Reporting Workflow

All financial data is scoped to a **reporting period** selected by the business owner.

#### Period Selector

```
?tab=financial_report&fin_period=today|week|month|year
                     &fin_month=YYYY-MM   (only used when fin_period=month)
```

| Period | Date Range | Chart Mode |
|---|---|---|
| Today | `CURDATE()` | Hourly bars (0–23) |
| This Week | Mon of current week → today | Daily bars |
| By Month | First day of selected month → last day | Daily bars |
| This Year | Jan 1 – Dec 31 of current year | Monthly bars (Jan–Dec) |

#### Revenue Calculation

```
Period Revenue = 
    SUM(orders.total_amount - orders.delivery_fee)
        WHERE payment_status = 'paid'
          AND payment_verified_at is within the selected period
        
  + SUM(restaurant_other_inflows.amount)
        WHERE status = 'approved'
          AND inflow_date is within the selected period
```

#### KPI Cards

```
┌─────────────────────────────────────────────────────┐
│  Period Revenue   Period Expenses   Net Cash Flow    │
│  Other Inflows                                       │
└─────────────────────────────────────────────────────┘

Net Cash Flow = Period Revenue − Period Expenses

Period Expenses = 
    SUM(delivery_fee from paid orders in period)
  + SUM(restaurant_expenses.amount WHERE expense_date in period)
```

#### Expenses Table

The **Synchronized Expenses** table always mirrors the same period as the KPI cards. Both the business owner and ops manager see the same data via `getSharedExpenses()`.

Expense logging:
- **Business Owner** logs from owner_dashboard → Financial Report tab
- **Ops Manager** logs from operations_manager → Expenses tab
- Both use the shared `restaurant_expenses` table

---

### 4.9 Other Revenue Inflows Workflow

Ops Managers can log non-order revenue (catering, grants, sponsorships, etc.).

#### Inflow Types

| Type | Key | Auto-approved? |
|---|---|---|
| Catering Order | `catering` | ✅ Yes — Ops Manager self-approves |
| Event / Reservation | `event` | ❌ Requires explicit approval |
| Grant / Aid | `grant` | ❌ Requires explicit approval |
| Sponsorship | `sponsorship` | ❌ Requires explicit approval |
| Donation | `donation` | ❌ Requires explicit approval |
| Space Rental | `rental` | ❌ Requires explicit approval |
| Supplier Rebate | `rebate` | ❌ Requires explicit approval |
| Other | `other` | ❌ Requires explicit approval |

#### Workflow

```
Ops Manager → operations_manager.php (Other Inflows tab)
    │
    ├── Fills in: type, amount, description, date
    ├── For catering/event: client name, event date, reference number
    ├── Optional receipt/document upload
    │
    ├── CATERING: auto-approved on save → immediately counts in revenue
    │
    └── ALL OTHERS: status = 'pending'
            │
            ▼
        Ops Manager reviews pending inflows
            │
            ├── APPROVE → status = 'approved'
            │            → appears in revenue KPI cards and trend chart
            │            → visible to Business Owner in financial report
            │
            └── REJECT  → status = 'rejected', reason stored
```

Business Owner view (Financial Report tab → Other Revenue Inflows panel):
- Read-only view of all inflows scoped to the selected period
- Approved inflows are included in Period Revenue total
- Pending inflows are shown but not counted

---

### 4.10 Backdated Order Logging

Both the business owner and ops manager can log orders that occurred on a specific past date.

```
Owner: Financial Report tab → "Log a Past Order" card → "New Entry" button
Ops Manager: operations_manager.php → "Log Order" tab

    ├── Select order date (any past date ≥ today)
    ├── Select order time (for hourly chart accuracy)
    ├── Choose service type + payment method
    ├── Optional: customer name, notes
    ├── Pick items from approved menu (search + category filter)
    └── Submit

    ▼
dashboard/business_owner/api/log_backdated_order.php
    │
    ├── Validates all menu items against approved menu
    │   (approval_status = 'approved' AND is_available = 1)
    ├── Inserts order with:
    │   ├── payment_status     = 'paid'
    │   ├── order_status       = 'delivered'
    │   ├── order_source       = 'pos'
    │   ├── payment_verified_at = <selected date + time>  ← drives period filter
    │   └── order_number       = BK-YYYYMMDD-XXXX
    └── Logs activity via logActivity()
```

The `payment_verified_at` is set to the backdated datetime so the order appears in the correct financial report period.

---

### 4.11 TPB Consumer Demand Survey

The Theory of Planned Behavior (TPB) survey measures consumer purchase intention for Halal food.

#### Survey Flow

```
Customer → dashboard/customer/tpb_survey.php
    │
    ├── Set 1 (Muslim users):     religion-specific attitude/norms/control questions
    ├── Set 2 (Non-Muslim users): inclusive framing of same constructs
    │
    ├── 15 Likert items (1–5 scale):
    │   ├── ATT1–5: Attitude toward Halal food purchase
    │   ├── SN1–5:  Subjective Norms (peer/social influence)
    │   └── PBC1–5: Perceived Behavioral Control (ease/ability to buy Halal)
    │
    └── Submit → api/submit_survey.php
```

#### Score Calculation

```
Mean(ATT) = Σ(ATT1-5) / 5
Mean(SN)  = Σ(SN1-5)  / 5
Mean(PBC) = Σ(PBC1-5) / 5

Raw PI         = Mean(ATT) + Mean(SN) + Mean(PBC)   [3.0 – 15.0]
Normalized PI  = Raw PI / 3                          [1.0 –  5.0]
PI Index (%)   = (Normalized PI − 1) / 4 × 100      [20% – 100%]

Intent Tiers:
  Normalized PI ≥ 4.5  →  Very High
  Normalized PI ≥ 3.5  →  High
  Normalized PI ≥ 2.5  →  Moderate
  Normalized PI ≥ 1.5  →  Low
  Normalized PI <  1.5  →  Very Low
```

#### Analytics

Survey results feed:
1. **Business Owner Dashboard** (Customer Purchase Intention tab) — Muslim vs Non-Muslim PI comparison, ATT/SN/PBC radar chart, dominant construct
2. **Landing page** (index.php) — Live Purchase Intention Barometer, D3.js Philippines map
3. **`api/tpb_analytics.php`** — JSON endpoint for both public scope and owner scope

---

### 4.12 Credibility Score System

Each restaurant gets a composite trust score (0–100) recomputed every 24 hours.

#### Formula

```
Credibility Score (0–100) =
    (Foot Traffic Score    × 0.35)    ← orders + views last 30 days, log-scaled
  + (Engagement Score      × 0.25)    ← avg_rating (60%) + review volume (40%)
  + (Compliance Score      × 0.25)    ← active certificate (50 pts) + HRP/100×50 pts
  + (Popularity Score      × 0.15)    ← profile views last 30 days + momentum bonus
```

#### Tiers

| Score | Tier | Label |
|---|---|---|
| ≥ 80 | 3 | Top Tier |
| ≥ 55 | 2 | Highly Recommended |
| ≥ 30 | 1 | Popular Choice |
| < 30 | 0 | Unranked |

Score is cached on `halal_restaurants.credibility_score`. Stale check: > 24 hours since `credibility_updated_at`.

Displayed on:
- Customer restaurant listing cards (score bar + tier stars)
- Customer restaurant detail page (4-component breakdown panel)
- Landing page directory listings

---

### 4.13 Review & Rating Workflow

```
Customer places and receives an order
    │
    ▼
Customer visits their review page or restaurant view
    │
    ▼
Submits: rating (1–5), food_rating (1–5), service_rating (1–5), comment text
    │
    ▼
dashboard/customer/api/submit_review.php
    │
    ├── Inserts into reviews table
    └── Updates halal_restaurants.avg_rating (live aggregate, not denormalised)

Ops Manager can reply to reviews:
    │
    ▼
dashboard/business_owner/operations_manager.php (Reviews tab)
    │
    └── Updates reviews.response, response_by, response_at
```

---

### 4.14 Staff Management Workflow

#### Assigning Staff

```
Business Owner → owner_dashboard.php (Overview tab → Staff Management section)
    │
    ├── Sees list of approved staff applications
    ├── Assigns user to role: ops_manager | cashier | chef
    │
    └── dashboard/business_owner/api/staff_action.php
            action = assign
            └── Inserts into restaurant_staff + restaurant_staff_roles
```

#### Staff Application from User Side

```
User → dashboard/customer/my_staff_applications.php
    │
    └── Fills in application for a specific restaurant
        → dashboard/customer/api/submit_staff_request.php
        → staff_applications table (status = 'pending')

Owner → dashboard/business_owner/staff_applications.php
    │
    ├── Reviews application
    │
    ├── APPROVE → staff_applications.status = 'approved'
    │            → user can now log in and access assigned dashboard page
    │
    └── REJECT  → staff_applications.status = 'rejected'
```

#### Staff Role Lifecycle

```
Active staff member
    │
    ├── Owner can: Activate | Deactivate | Change Role | Remove
    │   └── dashboard/business_owner/api/staff_action.php
    │
    └── On login: staff_role_key resolved from restaurant_staff table
                → $_SESSION['staff_role_key'] set
                → requireStaffRole() enforces page access
```

---

### 4.15 Admin & Role Approval Workflow

#### Role Application

```
Logged-in user with no business role → auth/apply_role.php
    │
    ├── Selects desired role (ROLE_BUSINESS_OWNER or other HCB roles)
    └── Application submitted → pending admin review

Admin → dashboard/admin/approve_roles.php
    │         OR
    │     dashboard/admin/role_applications.php
    │
    ├── APPROVE → user.role_id updated
    │            → user.role_status = 'approved'
    │            → notification sent
    │
    └── REJECT  → role application rejected + reason
```

#### Business Application Review

```
Admin → dashboard/admin/business_applications.php
    │
    ├── Reviews submitted application + uploaded documents
    ├── AJAX detail: dashboard/admin/api/business_app_detail.php
    │
    ├── APPROVE
    │   ├── business_applications.status = 'approved'
    │   ├── halal_restaurants record auto-created
    │   └── Applicant notified — can now access business owner dashboard
    │
    └── REJECT/RETURN
        └── business_applications.status updated + reason stored
```

#### Admin Monitoring

```
dashboard/admin/logging_monitoring.php
    ├── system_logs table — PHP error + event logs
    └── admin_activity_log — admin action audit trail

dashboard/admin/dlp_features.php
    └── Data Loss Prevention settings (copy/paste control, screen capture detection)

Legacy admin/ portal:
    admin/activity_logs.php  — user activity trail
    admin/login_logs.php     — login attempt history (brute-force monitoring)
    admin/users.php          — user account management
```

---

## 5. Module Reference

| Module | Files | Access |
|---|---|---|
| Landing Page & Directory | `index.php`, `api/halal_directory_metrics.php` | Public |
| Authentication | `auth/login.php`, `auth/register.php`, `auth/logout.php`, `auth/google_callback.php`, `auth/apply_role.php` | Public |
| Customer Dashboard | `dashboard/customer/index.php` | ROLE_CUSTOMER |
| Restaurant Listing | `dashboard/customer/restaurants.php` | ROLE_CUSTOMER |
| Restaurant Detail | `dashboard/customer/restaurant_view.php` | ROLE_CUSTOMER |
| Place Order | `dashboard/customer/api/place_order.php` | ROLE_CUSTOMER |
| Order History | `dashboard/customer/orders.php` | ROLE_CUSTOMER |
| Reviews | `dashboard/customer/reviews.php`, `dashboard/customer/api/submit_review.php` | ROLE_CUSTOMER |
| TPB Survey | `dashboard/customer/tpb_survey.php`, `api/submit_survey.php` | ROLE_CUSTOMER |
| Business Application | `dashboard/customer/apply_business.php` | ROLE_CUSTOMER |
| Owner Dashboard | `dashboard/business_owner/owner_dashboard.php` | ROLE_BUSINESS_OWNER + owner staff |
| Operations Manager | `dashboard/business_owner/operations_manager.php` | ROLE_BUSINESS_OWNER + ops_manager staff |
| POS | `dashboard/business_owner/pos_order_processing.php` | ROLE_BUSINESS_OWNER + cashier staff |
| Chef Dashboard | `dashboard/business_owner/chef_dashboard.php` | ROLE_BUSINESS_OWNER + chef staff |
| Manage Orders | `dashboard/business_owner/manage_orders.php` | ROLE_BUSINESS_OWNER + all staff |
| Restaurant Profile | `dashboard/business_owner/restaurant.php` | ROLE_BUSINESS_OWNER |
| HaCeRa | `dashboard/business_owner/hacera.php`, `dashboard/business_owner/api/hacera_api.php` | ROLE_BUSINESS_OWNER (actual owner only) |
| Halal Certification | `dashboard/business_owner/halal_certification.php` | ROLE_BUSINESS_OWNER |
| Staff Management | `dashboard/business_owner/staff_applications.php`, `dashboard/business_owner/staff_roles.php`, `dashboard/business_owner/api/staff_action.php` | ROLE_BUSINESS_OWNER |
| Financial Reporting | Part of `owner_dashboard.php` (Financial Report tab) | ROLE_BUSINESS_OWNER (owner staff) |
| Backdated Orders | `dashboard/business_owner/api/log_backdated_order.php` | ROLE_BUSINESS_OWNER |
| Admin Dashboard | `dashboard/admin/index.php` | ROLE_ADMIN |
| Business Approvals | `dashboard/admin/business_applications.php`, `dashboard/admin/approve_roles.php` | ROLE_ADMIN |
| System Monitoring | `dashboard/admin/logging_monitoring.php`, `admin/activity_logs.php` | ROLE_ADMIN |
| Credibility API | `api/credibility_scores.php` | Public (read) / Admin (write) |
| TPB Analytics API | `api/tpb_analytics.php` | Public |
| Notifications API | `api/notifications.php` | Authenticated |

---

## 6. Data Flow Diagrams

### Order Revenue Flow

```
Customer Web Order
    │  payment_status = 'paid'
    │  payment_verified_at = PayMongo webhook timestamp
    │
    ├──► Financial Report (total_amount - delivery_fee)
    └──► Hourly/Daily/Monthly chart slot

POS Order (Cash)
    │  Cashier confirms cash received
    │  payment_verified_at = confirmation timestamp
    │
    ├──► Financial Report (total_amount - delivery_fee)
    └──► Hourly/Daily/Monthly chart slot

POS Order (GCash/Bank)
    │  PayMongo webhook fires
    │  payment_verified_at = webhook timestamp
    │
    └──► Financial Report + Chart

Backdated Order (BK- prefix)
    │  payment_verified_at = chosen historical datetime
    │
    └──► Appears in the matching historical period's report
```

### Expense Flow

```
Business Owner logs expense (owner_dashboard.php)
    │
Ops Manager logs expense (operations_manager.php)
    │
    └──► restaurant_expenses table
            │  expense_date column → used for all period filters
            │
            ├──► Financial Report KPI (Expenses card) for selected period
            ├──► Hourly/Daily/Monthly expense bars on trend chart
            └──► Synchronized Expenses table (same period as KPI cards)
```

### Credibility Score Flow

```
Business events (orders, reviews, profile views, HaCeRa, certificates)
    │
    ▼
computeCredibilityScore() called:
    ├── orders last 30 days (dine-in + takeout) → Foot Traffic score (35%)
    ├── avg_rating + review count → Engagement score (25%)
    ├── halal_certificates active + hacera_hrp → Compliance score (25%)
    └── business_views last 30 days → Popularity score (15%)
    │
    ▼
halal_restaurants.credibility_score updated
restaurant_credibility_log row inserted
    │
    ▼
Customer-facing restaurant cards + detail pages display tier + score
```

---

## 7. Key Database Tables

| Table | Primary Purpose | Key Columns |
|---|---|---|
| `users` | All accounts | `id`, `role_id`, `religion`, `food_preference`, `full_name`, `email` |
| `halal_restaurants` | Restaurant registry | `id`, `business_owner_id`, `restaurant_name`, `credibility_score`, `hacera_hrp`, `hacera_band` |
| `orders` | All orders (customer web + POS + backdated) | `id`, `order_number`, `restaurant_id`, `customer_id`, `order_source`, `payment_status`, `payment_verified_at`, `total_amount`, `delivery_fee`, `items_json` |
| `menu_items` | Restaurant menu | `id`, `restaurant_id`, `name`, `price`, `category`, `approval_status`, `is_available` |
| `restaurant_expenses` | Operational expenses | `id`, `restaurant_id`, `logged_by`, `added_by_role`, `category`, `amount`, `expense_date`, `period_tag` |
| `restaurant_expense_items` | Itemized expense lines | `expense_id`, `description`, `unit_price`, `quantity`, `line_total` |
| `restaurant_other_inflows` | Non-order revenue | `id`, `restaurant_id`, `inflow_type`, `amount`, `status`, `inflow_date` |
| `reviews` | Customer reviews | `id`, `restaurant_id`, `customer_id`, `rating`, `food_rating`, `service_rating`, `response` |
| `restaurant_staff` | Staff assignments | `id`, `restaurant_id`, `user_id`, `staff_role_id`, `is_active` |
| `restaurant_staff_roles` | Per-restaurant role definitions | `id`, `restaurant_id`, `role_key`, `role_label` |
| `business_applications` | Certification applications | `id`, `user_id`, `halal_classification`, `status`, `business_name` |
| `business_hacera_responses` | HaCeRa checklist answers | `restaurant_id`, `checklist_item_id`, `response_value` |
| `grocery_lists` | Chef requisitions | `id`, `restaurant_id`, `created_by`, `status`, `approved_by` |
| `tpb_survey_responses` | TPB scores per user | `user_id`, `mean_att`, `mean_sn`, `mean_pbc`, `raw_pi`, `normalized_pi`, `questionnaire_set` |
| `business_views` | Restaurant profile view log | `restaurant_id`, `viewed_at`, `user_id` |
| `notifications` | In-app notifications | `user_id`, `title`, `message`, `type`, `is_read` |
| `halal_certificates` | Issued Halal certificates | `restaurant_id`, `certificate_number`, `issue_date`, `expiry_date`, `status` |
| `staff_applications` | Staff role applications | `user_id`, `restaurant_id`, `applied_role`, `status` |
| `activity_log` | User action audit | `user_id`, `action`, `details`, `module`, `ip_address` |
| `login_attempts` | Brute-force protection | `email`, `ip_address`, `attempted_at` |
| `system_settings` | Key-value config | `setting_key`, `setting_value` |

---

## 8. Financial Calculations

### Revenue

```php
// Orders (all periods)
SUM(total_amount - delivery_fee)
WHERE payment_status = 'paid'
  AND payment_verified_at [within selected period]

// + Other inflows
SUM(amount)
FROM restaurant_other_inflows
WHERE status = 'approved'
  AND inflow_date [within selected period]
```

### Expenses

```php
// Delivery fees (from orders)
SUM(delivery_fee)
WHERE payment_status = 'paid'
  AND payment_verified_at [within selected period]

// + Operational expenses
SUM(amount)
FROM restaurant_expenses
WHERE expense_date [within selected period]
```

### Net Cash Flow

```
Net = Revenue − Expenses
```

### Financial Period WHERE Clauses

| fin_period | Orders | Expenses | Inflows |
|---|---|---|---|
| `today` | `DATE(payment_verified_at) = CURDATE()` | `DATE(expense_date) = CURDATE()` | `DATE(inflow_date) = CURDATE()` |
| `week` | `DATE(payment_verified_at) BETWEEN [Mon] AND CURDATE()` | `expense_date BETWEEN [Mon] AND CURDATE()` | `inflow_date BETWEEN [Mon] AND CURDATE()` |
| `month` | `MONTH(pva)=M AND YEAR(pva)=Y` | `MONTH(expense_date)=M AND YEAR=Y` | `MONTH(inflow_date)=M AND YEAR=Y` |
| `year` | `YEAR(payment_verified_at) = YEAR(NOW())` | `YEAR(expense_date) = YEAR(NOW())` | `YEAR(inflow_date) = YEAR(NOW())` |

Month `M` and Year `Y` derive from `?fin_month=YYYY-MM` (defaults to current month).

---

## 9. Security & Access Control

### Authentication Guards

| Function | Where defined | Effect |
|---|---|---|
| `requireLogin()` | `includes/functions.php` | Redirect to `auth/login.php` if not authenticated |
| `requireRole(array $roles)` | `includes/functions.php` | Redirect to dashboard if session role not in list |
| `requireStaffRole($conn, $key)` | `includes/staff_rbac.php` | Redirect if staff sub-role does not match |

### Brute-Force Protection

- 5 failed logins in 15 minutes → account locked
- Tracked in `login_attempts` table by email + IP
- Lockout duration: 900 seconds (configurable via `MAX_LOGIN_ATTEMPTS`, `LOCKOUT_DURATION`)

### Session Timeout

- Default: 30 minutes inactivity
- Configurable per-deployment via `system_settings` table key `session_timeout`
- Admin sessions kept alive by periodic AJAX ping to `admin/api/ping_session.php`

### Data Loss Prevention (DLP)

- Configurable text copy/paste and screen capture detection for sensitive pages
- Controlled via `admin/dlp_settings.php` and `dashboard/admin/dlp_features.php`
- Events logged to `admin/api/log_dlp.php`

### Sensitive Field Encryption

- `FIELD_ENCRYPT_KEY` from `.env` — never hardcoded
- Encrypted fields registry in `admin/encrypted_fields.php`
- Data classification levels: `public` | `internal` | `confidential` | `restricted`

### Input Handling

- All user input passes through `strip_tags()`, `trim()` at minimum
- SQL via prepared statements (`$conn->prepare()` + `bind_param()`) throughout
- File uploads validated: extension allowlist, 20 MB size cap

---

## 10. Integrations

### PayMongo (Payment Gateway)

**Purpose:** Online payments for customer orders and POS orders via GCash and bank transfer.

```
Order created with payment_method = 'gcash' or 'bank_transfer'
    │
    ▼
createPayMongoCheckoutSession()  (includes/functions.php)
    │
    ├── POST to https://api.paymongo.com/v1/checkout_sessions
    ├── Returns checkout_url → user redirected to PayMongo hosted page
    │
    └── On payment: PayMongo sends webhook →
            api/paymongo_webhook.php
                OR
            dashboard/business_owner/api/pos_payment_callback.php
                │
                └── Verifies webhook signature
                    Sets payment_status = 'paid'
                    Sets payment_verified_at = NOW()
```

Configuration: `PAYMONGO_SECRET_KEY`, `PAYMONGO_PUBLIC_KEY`, `PAYMONGO_WEBHOOK_SECRET` from `.env`

### Google OAuth

**Purpose:** One-click login/registration via Google account.

```
auth/login.php or auth/register.php → "Continue with Google"
    │
    ▼
Google Authorization URL (accounts.google.com/o/oauth2/v2/auth)
    │
    ▼
auth/google_callback.php
    ├── Exchanges authorization code for tokens
    ├── Fetches user profile (name, email, avatar)
    ├── Upserts user (email match → update, new → insert with role_id=1)
    └── Sets session → redirects to dashboard
```

Configuration: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` from `.env`

### Philippine Location Data

**Purpose:** Cascading dropdowns for address fields.

```
assets/data/regions.json      →  16 Philippine regions
assets/data/provinces.json    →  All provinces per region
assets/data/cities.json       →  All cities/municipalities per province
assets/data/barangays.json    →  All barangays per city
```

Consumed by `api/ph_locations.php` and loaded client-side by all address forms.

### Chart.js

**Purpose:** Financial trend charts, TPB radar chart.

```
dashboard/business_owner/owner_dashboard.php
    ├── Hourly/Daily/Monthly/Yearly cash flow bar + line chart
    └── ATT/SN/PBC radar chart (Muslim vs Non-Muslim)

Served via CDN: https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js
```

### D3.js (Landing Page)

**Purpose:** Interactive Philippines map on public landing page showing Halal compliance by city.

```
index.php
    ├── D3.js loads Philippines GeoJSON
    ├── Cities coloured by compliance level
    └── Hover tooltip shows city metrics from api/halal_directory_metrics.php
```

---

*This document covers the complete Halal Keeps system as of October 2026.*  
*For technical API reference and database schema details, see `CODEBASE.md`.*
