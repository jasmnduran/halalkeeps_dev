# Halal Keeps — System Documentation

> **Version:** Current (as of October 2026)
> **Stack:** PHP 8.0+, MySQL / MariaDB 10.4, XAMPP (local) / Apache (production)
> **Database:** `halal_system`
> **Tagline:** *Verify Halal, Anytime, Anywhere*

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Architecture](#2-architecture)
3. [User Roles](#3-user-roles)
4. [Authentication & Session Flow](#4-authentication--session-flow)
5. [Halal Certification Pipeline](#5-halal-certification-pipeline)
6. [Restaurant Operations](#6-restaurant-operations)
7. [HaCeRa AI Assessment System](#7-hacera-ai-assessment-system)
8. [Document Screening AI System](#8-document-screening-ai-system)
9. [TPB Demand Survey System](#9-tpb-demand-survey-system)
10. [Payment Processing](#10-payment-processing)
11. [Ingredient & Inventory System](#11-ingredient--inventory-system)
12. [Credibility Score System](#12-credibility-score-system)
13. [Admin & Security Features](#13-admin--security-features)
14. [API Endpoints](#14-api-endpoints)
15. [Database Schema](#15-database-schema)
16. [Foreign Key Relationships](#16-foreign-key-relationships)
17. [Data Flow Diagrams](#17-data-flow-diagrams)
18. [File & Upload Structure](#18-file--upload-structure)
19. [Environment Configuration](#19-environment-configuration)

---

## 1. System Overview

**Halal Keeps** is a full-stack web application for the Halal Certification Board (HCB). It serves two primary purposes:

1. **Halal Certification Management** — end-to-end workflow for businesses applying for halal certification, from Letter of Intent submission through inspection, lab testing, committee review, and certificate award.
2. **Halal Restaurant Marketplace** — a public-facing directory and ordering platform for customers to find and order from halal-certified restaurants.

The system supports **11 distinct user roles**, each with isolated dashboards, and integrates two AI subsystems (HaCeRa for readiness assessment and Document Screening for intelligent document validation).

---

## 2. Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                      Public Internet                        │
└───────────────────────────┬─────────────────────────────────┘
                            │ HTTP/HTTPS
┌───────────────────────────▼─────────────────────────────────┐
│                  Apache / XAMPP Web Server                  │
│                  PHP 8.0+ Application Layer                 │
├─────────────────────────────────────────────────────────────┤
│  config/app.php      ← Bootstrap (session, DB, constants)  │
│  config/database.php ← MySQLi connection                   │
│  config/constants.php← Role IDs, keys, feature flags       │
│  config/env.php      ← .env loader                         │
├─────────────────────────────────────────────────────────────┤
│  includes/functions.php   ← Core helpers, role routing     │
│  includes/staff_rbac.php  ← Restaurant staff permissions   │
│  includes/HaCeRa*.php     ← HaCeRa AI assessment engine   │
│  includes/Document*.php   ← Document screening AI engine  │
│  includes/Ingredient*.php ← Ingredient/inventory engine    │
├─────────────────────────────────────────────────────────────┤
│  auth/         ← Login, register, Google OAuth, apply role │
│  dashboard/    ← Role-scoped dashboards (11 roles)         │
│  admin/        ← Legacy HCB staff admin panel              │
│  api/          ← Public/authenticated JSON endpoints       │
│  assets/       ← CSS, JS, images, PH location JSON data    │
├─────────────────────────────────────────────────────────────┤
│              MySQL / MariaDB — halal_system DB             │
│              uploads/ — file store (PDFs, images)          │
└─────────────────────────────────────────────────────────────┘
```

### Key Design Decisions

- **No framework** — vanilla PHP with MySQLi prepared statements throughout.
- **Single DB connection** — `$conn` initialized in `config/database.php`, available globally.
- **Every page bootstraps via `config/app.php`** — session start, timeout enforcement, staff role refresh, and upload directory creation all run on every request.
- **Role enforcement** — `requireRole()` and `requireStaffRole()` guard every restricted page.
- **Tailwind CSS (CDN)** — UI built with Tailwind + Material Symbols + Font Awesome icons.
- **PayMongo** — Philippine payment gateway for online card/GCash/PayMaya payments.

---

## 3. User Roles

The system defines **11 system-level roles** stored in the `roles` table, plus **4 restaurant-level staff sub-roles**.

### System Roles

| ID | Constant | Role Name | Category | Dashboard |
|---|---|---|---|---|
| 1 | `ROLE_CUSTOMER` | Customer | `customer` | `dashboard/customer/` |
| 2 | `ROLE_BUSINESS_OWNER` | Business Owner | `business` | `dashboard/business_owner/` |
| 3 | `ROLE_EVALUATOR` | Evaluator | `hcb_employee` | `dashboard/evaluator/` |
| 4 | `ROLE_AUDITOR_TECHNICAL` | Auditor - Technical | `hcb_employee` | `dashboard/auditor/` |
| 5 | `ROLE_AUDITOR_SHARIAH` | Auditor - Shariah | `hcb_employee` | `dashboard/auditor/` |
| 6 | `ROLE_IMPARTIAL_COMMITTEE` | Impartial Committee | `hcb_employee` | `dashboard/impartial_committee/` |
| 7 | `ROLE_DECISION_COMMITTEE` | Decision Committee | `hcb_employee` | `dashboard/decision_committee/` |
| 8 | `ROLE_PRESIDENT` | President | `hcb_employee` | `dashboard/president/` |
| 9 | `ROLE_RECEIVING_OFFICER` | Receiving Officer | `lab_employee` | `dashboard/receiving_officer/` |
| 10 | `ROLE_LABORATORY_ANALYST` | Laboratory Analyst | `lab_employee` | `dashboard/lab_analyst/` |
| 11 | `ROLE_ADMIN` | Administrator | `hcb_employee` | `dashboard/admin/` |

### Restaurant Staff Sub-Roles (per-restaurant, not system-wide)

These are stored in `restaurant_staff_roles` and assigned via `restaurant_staff`. A staff member holds system `ROLE_BUSINESS_OWNER` (id=2) but is routed to a restricted workspace based on their `staff_role_key`.

| Key | Label | Workspace Page | Capabilities |
|---|---|---|---|
| `owner` | Business Owner | `owner_dashboard.php` | Full access to all restaurant features |
| `ops_manager` | Operations Manager | `operations_manager.php` | Menu, reviews, grocery lists, expenses, halal cert |
| `cashier` | Cashier | `pos_order_processing.php` | POS orders, payment confirmation |
| `chef` | Chef / Head Cook | `chef_dashboard.php` | Kitchen inventory, recipes, suppliers, POS guardrails |

### Role Capabilities Summary

**Customer (1)**
- Browse the Halal Restaurants directory
- Place and track food orders
- Write restaurant reviews
- Complete the TPB demand survey
- Apply to register their own business
- Apply for restaurant staff positions

**Business Owner (2)**
- Manage their restaurant profile, menu, and images
- Manage restaurant staff (hire, assign roles, activate/deactivate)
- Conduct HaCeRa self-assessment
- Initiate and track halal certification pipeline
- View orders, process payments, print grocery requisitions
- Access owner analytics dashboard (revenue, credibility score)

**Evaluator (3)**
- Review and verify/return Letters of Intent (LOI)
- Send requirement lists to business owners
- Verify submitted HDP applications and their documents
- Create Terms of Reference (ToR) for inspections
- Schedule inspections

**Auditor - Technical (4) & Auditor - Shariah (5)**
- View and conduct assigned inspections
- Submit audit findings (conforming / non-conforming / partial)
- Raise Non-Conformance Reports (NCRs)
- Create Corrective Action Reports

**Impartial Committee (6)**
- Review inspection evidence and corrective actions
- Submit potential decisions (recommend approve / reject / need more info)

**Decision Committee (7)**
- Make final binding decisions (approve / reject / defer) on applications
- View decision history

**President (8)**
- Award halal certificates to approved applications
- Manage all issued certificates
- Manage system users
- View reports and analytics
- Access admin panel

**Receiving Officer (9)**
- Receive laboratory sample requests from business owners
- Track sample intake and status

**Laboratory Analyst (10)**
- Analyze received samples
- Record analysis results and halal status
- Generate and finalize laboratory reports

**Administrator (11)**
- Manage all users and role approvals
- Configure RBAC permissions
- Monitor system logs and activity
- Manage DLP (Data Loss Prevention) settings
- Manage data classification and encrypted fields
- Review business registration applications

---

## 4. Authentication & Session Flow

### Registration Flow

```
User visits /auth/register.php
     │
     ├─ Fills form: first_name, last_name, email, phone, password
     │  + demographic: religion, food_preference, cuisine_preferences
     │  + address: region, province, city, barangay, postal_code
     │
     ├─ Server validation:
     │   • Password: min 8 chars, 1 uppercase, 1 lowercase, 1 digit, 1 special char
     │   • Email: unique check in users table
     │   • Religion: whitelist ['Muslim', 'Non-Muslim']
     │   • Food preference: whitelist ['Halal', 'Non-Pork', 'Muslim-Friendly', 'Any']
     │
     ├─ INSERT into users (role_id=1 ROLE_CUSTOMER, role_status='approved')
     ├─ logActivity() → activity_log
     └─ Redirect → /auth/login.php?registered=1
```

### Google OAuth Flow

```
User clicks "Sign in with Google"
     │
     ├─ /auth/login.php → getGoogleAuthUrl() → Google OAuth consent screen
     │   (state token stored in session for CSRF protection)
     │
     ├─ Google redirects → /auth/google_callback.php
     │   • Validates state token
     │   • Exchanges code for access token
     │   • Fetches user profile (id, email, name, avatar)
     │
     ├─ If user exists (by google_id or email):
     │   → autoAssignCustomerRole() → createUserSession() → getDashboardUrl()
     │
     └─ If new user:
         → INSERT into users (google_id, role_id=1, role_status='approved')
         → createUserSession() → getDashboardUrl()
```

### Login Flow

```
POST /auth/login.php
     │
     ├─ Query: SELECT u.*, r.role_name FROM users u JOIN roles r WHERE u.email = ?
     │
     ├─ Check account_locked → error if true
     │
     ├─ password_verify($password, $user['password'])
     │
     ├─ On SUCCESS:
     │   • Reset failed_login_attempts = 0, account_locked = 0
     │   • UPDATE last_login = NOW()
     │   • logActivity() → activity_log
     │   • autoAssignCustomerRole() [safety: ensures role is set]
     │   • createUserSession():
     │       $_SESSION['user_id']       = user id
     │       $_SESSION['role_id']       = system role id
     │       $_SESSION['role_status']   = 'approved'
     │       $_SESSION['full_name']     = full name
     │       $_SESSION['email']         = email
     │       $_SESSION['last_activity'] = time()
     │   • Redirect → getDashboardUrl($role_id)
     │
     └─ On FAILURE:
         • failed_login_attempts++
         • If >= MAX_LOGIN_ATTEMPTS (5): account_locked = 1
         • Show remaining attempts warning
```

### Session Management (every request via config/app.php)

```
config/app.php runs on every page load:
     │
     ├─ Check $_SESSION['last_activity']:
     │   If time() - last_activity > session_timeout_minutes × 60:
     │     → session_destroy() → redirect to login with "session expired" message
     │
     ├─ Update $_SESSION['last_activity'] = time()
     │
     └─ If ROLE_BUSINESS_OWNER:
         Re-query restaurant_staff table to refresh staff_role_key
         If staff was deactivated:
           → Flash message → redirect to customer/restaurants.php
```

### Role Application Flow

```
/auth/apply_role.php
     │
     ├─ Customer selects a role to apply for
     ├─ Uploads supporting documents (resume, credentials, business docs)
     ├─ INSERT into role_applications (status='pending')
     ├─ logActivity()
     └─ Admin/President reviews and approves/rejects
         → UPDATE users SET role_id=?, role_status='approved'
         → createNotification() to applicant
```

---

## 5. Halal Certification Pipeline

This is the core workflow of the system. It moves a business from initial intent through certification.

### Pipeline Overview

```
Business Owner                  Evaluator
      │                              │
  [1] Submit LOI ─────────────────► Receives notification
      │                              │
      │                          [2] Review LOI
      │                              ├─ Return → Owner revises & resubmits
      │                              └─ Verify → Send requirement list
      │
  [3] Receive requirements ◄─────────
      │
  [4] Submit HDP Application
      (upload all required documents)
      │
      │                          [5] Verify Application
      │                              ├─ Incomplete → Return to Owner
      │                              └─ Verified → Create ToR
      │
      │                          [6] Create Terms of Reference
      │                              └─ Schedule Inspection
      │
      │                      Auditors (Technical + Shariah)
      │                          [7] Conduct On-site Inspection
      │                              ├─ Conforming → pass
      │                              ├─ Non-conforming → Raise NCR
      │                              └─ Partial → CAR required
      │
      │         Receiving Officer / Lab Analyst (parallel track)
      │                          [8] Lab Sample Request
      │                              → Receive → Analyze → Report
      │
      │                      Impartial Committee
      │                          [9] Review Evidence (inspection + lab)
      │                              └─ Submit Potential Decision
      │
      │                      Decision Committee
      │                          [10] Final Decision
      │                              ├─ Approved → notify President
      │                              ├─ Rejected → notify Owner
      │                              └─ Deferred
      │
      │                      President
      │                          [11] Award Certificate
      │                              └─ Issue halal_certificate record
      │
  [12] Certificate Active ◄──────────
      │
      └─ Restaurant visible in public directory
```

### Step-by-Step Detail

#### Step 1 — Letter of Intent (LOI)

- **Table:** `letter_of_intent`
- **Submitted by:** Business Owner (`dashboard/business_owner/letter_of_intent.php`)
- **Fields:** company_name, company_address, company_info, date_of_intent, menu_list, contact details, letter_content
- **Status flow:** `draft` → `submitted` → `under_review` → `verified` | `returned` | `rejected`
- On submit: INSERT into `letter_of_intent`, `logActivity()`, `createNotification()` to all evaluators

#### Step 2 — LOI Verification

- **Table:** `loi_verification`
- **Actor:** Evaluator (`dashboard/evaluator/verify_loi.php`)
- Evaluator reviews the LOI and either:
  - **Verifies** → inserts into `loi_requirements` (requirement checklist), notifies owner
  - **Returns** → adds feedback, notifies owner to revise
- Standard requirement list (17 items per HDIP Shari'ah standards) is sent to the owner

#### Step 3 — HDP Application Submission

- **Table:** `hdp_applications` (Halal Development Program)
- **Table:** `application_requirement_uploads`
- **Actor:** Business Owner (`dashboard/business_owner/applications.php`)
- Owner uploads each required document (up to 17+ documents)
- Documents stored under `uploads/documents/`
- Status: `draft` → `submitted`

#### Step 4 — Application Verification

- **Table:** `application_verification`
- **Actor:** Evaluator (`dashboard/evaluator/verify_applications.php`)
- Reviews all uploaded documents against the requirement list
- Can mark as `verified` or return as `incomplete` with feedback
- On verification → `hdp_applications.status = 'verified'`

#### Step 5 — Terms of Reference & Inspection Scheduling

- **Tables:** `terms_of_reference`, `inspection_schedules`
- **Actor:** Evaluator (`dashboard/evaluator/terms_of_reference.php`, `inspection_schedules.php`)
- Evaluator creates a ToR defining scope, enterprise type, auditor count and fees:
  - Micro Enterprise: 1 Technical + 1 Shariah auditor @ ₱500 each
  - Small Enterprise: 1 Technical + 1 Shariah auditor @ ₱700 each
  - Medium Enterprise: 1 Technical + 2 Shariah auditors @ ₱900 each
- Schedules inspection date, time, location, and assigns auditors

#### Step 6 — On-site Inspection

- **Tables:** `inspections`, `ncr_reports`, `corrective_action_reports`
- **Actors:** Auditor - Technical (4) and Auditor - Shariah (5) (`dashboard/auditor/`)
- Auditors record:
  - Conformity status: `conforming` | `non_conforming` | `partial` | `pending`
  - Audit findings (free text)
  - Remarks
- Non-conformances → `ncr_reports` (major / minor / observation)
- Corrective actions required → `corrective_action_reports`

#### Step 7 — Laboratory Analysis (parallel track)

- **Tables:** `laboratory_requests`, `laboratory_analyses`, `laboratory_reports`
- **Actors:** Business Owner requests, Receiving Officer (9) receives, Lab Analyst (10) analyzes
- Flow:
  1. Owner submits lab request (`dashboard/business_owner/laboratory.php`)
  2. Receiving Officer receives sample (`dashboard/receiving_officer/receive_samples.php`)
  3. Lab Analyst conducts analysis (`dashboard/lab_analyst/analyze_samples.php`)
  4. Lab report generated with `halal_status` (halal / haram / mushbooh)
  5. Report finalized and sent

#### Step 8 — Impartial Committee Review

- **Table:** `potential_decisions`
- **Actor:** Impartial Committee (6) (`dashboard/impartial_committee/`)
- Reviews all evidence: inspection reports, NCRs, CARs, lab reports
- Submits a potential decision:
  - `recommend_approve`
  - `recommend_reject`
  - `need_more_info`
  - `pending`

#### Step 9 — Decision Committee Final Decision

- **Table:** `final_decisions`
- **Actor:** Decision Committee (7) (`dashboard/decision_committee/`)
- Reviews potential decisions and makes binding final decision:
  - `approved` → triggers certificate preparation
  - `rejected` → notifies business owner
  - `deferred` → sends back for more information

#### Step 10 — Certificate Award

- **Table:** `halal_certificates`
- **Actor:** President (8) (`dashboard/president/award_certificates.php`)
- On approval:
  1. President prepares certificate (uploads logo, certificate file)
  2. Sets `issue_date` and `expiry_date`
  3. Awards certificate: `status = 'awarded'` → `'active'`
  4. `halal_restaurants.certificate_id` linked to the certificate
  5. Restaurant becomes publicly visible in the directory

**Certificate status lifecycle:** `prepared` → `decorated` → `awarded` → `active` → `expired` | `revoked` | `suspended`

**Certificate fees** (by enterprise type):
- Micro: ₱2,000
- Small: ₱2,500
- Medium: ₱4,000

---

## 6. Restaurant Operations

Once certified, a Business Owner manages their restaurant through a multi-role staff system.

### Restaurant Profile

- **Table:** `halal_restaurants`
- Owner manages: restaurant name, description, address, city, province, phone, email, operating hours, cuisine type, image (`uploads/restaurant_images/`)
- Linked to `halal_certificates` via `certificate_id`

### Menu Management

- **Table:** `menu_items`
- Menu item approval workflow:
  1. Owner/Ops Manager adds item → `approval_status = 'pending'`
  2. Admin reviews → `approved` | `rejected`
  3. Chef can set `operational_status` (available/unavailable based on inventory)
  4. `customer_visibility_status` on recipes: `draft` | `published` | `needs_reapproval`
- Categories: Appetizer, Main Course, Rice, Noodles, House Specialty, Desserts, Soups, etc.
- Serving types: Per serving / For sharing

### Staff Management

```
Owner creates staff roles → dashboard/business_owner/staff_roles.php
     │
     └─ Publishes open positions (role_key: ops_manager / cashier / chef)

Customers apply → dashboard/customer/my_staff_applications.php
     │
     └─ INSERT into staff_application_requests (not a core table — handled via API)

Owner reviews → dashboard/business_owner/staff_applications.php
     │
     ├─ Approve → INSERT into restaurant_staff (user_id, staff_role_id, is_active=1)
     │            UPDATE users SET role_id=2 (ROLE_BUSINESS_OWNER)
     │            Staff member now has scoped ROLE_BUSINESS_OWNER access
     │
     └─ Deactivate → restaurant_staff.is_active = 0
                    → On next request, app.php detects deactivation
                    → Redirects to customer/restaurants.php
```

**Manager PIN**: `restaurant_staff.manager_pin` — used for POS cash payment confirmation.

### POS & Ordering

**Online Customer Orders:**
```
Customer browses restaurants → dashboard/customer/restaurants.php
     │
     ├─ Opens restaurant → dashboard/customer/restaurant_view.php
     ├─ Adds items to cart → dashboard/customer/api/place_order.php
     │   INSERT into orders (order_number, customer_id, restaurant_id, items_json, totals)
     │
     ├─ Payment:
     │   ├─ Cash → payment_method='cash', status='pending'
     │   └─ Online (GCash/Card) → api/process_paymongo_payment.php
     │       → PayMongo checkout session → payment confirmed via webhook
     │
     └─ Track order → dashboard/customer/orders.php
```

**POS (In-Restaurant) Orders:**
```
Cashier opens POS → dashboard/business_owner/pos_order_processing.php
     │
     ├─ Select menu items (real-time ingredient availability check)
     ├─ Add to order → place order for restaurant table
     ├─ Cash payment → confirm_cash_payment API
     │   → Manager PIN required for manual confirmation
     └─ Online payment → PayMongo link sent to customer
```

**Order Status Lifecycle:**
`pending` → `confirmed` → `preparing` → `ready` → `out_for_delivery` → `delivered` | `cancelled`

**Order history tracked in:** `order_status_history`

### Reviews & Credibility

- **Table:** `reviews`
- Customers submit reviews after delivery: overall rating, food_rating, service_rating, comment
- `halal_restaurants.avg_rating` and `total_reviews` updated on each review
- Owner/Ops Manager can respond to reviews

---

## 7. HaCeRa AI Assessment System

**HaCeRa** (Halal Certification Readiness Assessment) is a pre-certification self-assessment tool that helps business owners evaluate their readiness before formally applying.

### Modules (5 total, 44 checklist items)

| Module | Title | Items | Focus |
|---|---|---|---|
| 1 | Conditions of Certification | 8 | Business registration, permits, legal documents |
| 2 | Raw Materials & Ingredients | 8 | Halal ingredient sourcing, certificates, traceability |
| 3 | Processing & Logistics | 9 | Production workflow, transport, cross-contamination controls |
| 4 | Packaging & Labelling | 10 | Label compliance per HDIP Shari'ah standards |
| 5 | Premises & Welfare | 9 | Facilities, ablution, prayer room, Muslim staff |

### Weight System

- **Weight 3 (High):** Halal ingredient sources, zero cross-contamination, Shari'ah labelling, Muslim cook/crew
- **Weight 2 (Standard):** Business licences, permits, record-keeping, kitchen layout
- **Weight 1 (Informational):** Optional / supplementary items

### Scoring Formula

```
Section Readiness (%) = (Σ weights of completed items / Σ total possible weights) × 100

Halal Readiness Progress (HRP) = arithmetic mean of all 5 module scores

Readiness Bands:
  ≥ 90% → Inspection Ready  (green)
  ≥ 70% → Near Ready        (blue)
  ≥ 50% → In Progress       (amber)
  ≥ 25% → Early Stage       (orange)
  < 25% → Not Started       (red)
```

### HaCeRa Data Flow

```
Owner opens dashboard/business_owner/hacera.php
     │
     ├─ For each checklist item: answers Yes/No/NA
     │   INSERT/UPDATE business_hacera_responses (checklist_item_id, response_value)
     │
     ├─ Optional: uploads supporting documents
     │   INSERT into hacera_documents (checklist_item_id, file_path)
     │
     ├─ HaCeRaCalculator::buildCompletedIds() — collects all 'yes' responses
     ├─ HaCeRaCalculator::scoreModule() — per-module score
     ├─ HaCeRaCalculator::computeHRP() — overall HRP
     ├─ HaCeRaCalculator::getBand() — readiness band label + color
     │
     └─ HRP stored on halal_restaurants.hacera_hrp + hacera_band
         for use in credibility score computation
```

### HaCeRa AI Analysis (AI Subsystem)

```
Owner requests AI analysis → dashboard/business_owner/api/hacera_ai_api.php
     │
     ├─ HaCeRaAIService → collects all responses, gaps, and evidence
     ├─ HaCeRaContextBuilder → builds structured context for AI prompt
     ├─ HaCeRaAIProcessor → sends prompt to AI provider (OpenAI/compatible)
     │   (input_hash stored to avoid duplicate runs)
     │
     ├─ Response stored in:
     │   hacera_ai_runs (status, summary, report_json)
     │   hacera_ai_findings (module_id, finding_type, severity, recommended_action)
     │   hacera_ai_evidence_links → links findings to uploaded docs
     │   hacera_ai_actions → corrective action recommendations
     │
     └─ Owner views findings, accepts/marks actions, rates AI quality
         hacera_ai_feedback (rating, feedback_text)
```

---

## 8. Document Screening AI System

An intelligent document validation system that automatically screens uploaded HDP application documents.

### Screening Flow

```
Document uploaded to HDP application
     │
     └─ INSERT into document_screening_jobs (status='queued', source_type, application_id)

Background script (scripts/process_document_screening_queue.php) polls queue:
     │
     ├─ DocumentOCRService → extracts text from PDF/image (using Poppler for PDF)
     │   → UPDATE document_screening_jobs (ocr_method, ocr_confidence, extracted_text)
     │
     ├─ DocumentClassificationService → identifies document type
     │   (halal_cert, business_permit, lab_report, etc.)
     │   classification_confidence stored
     │
     ├─ DocumentFieldExtractor → extracts structured fields (issuer, date, product, etc.)
     │   INSERT into document_extracted_fields (field_key, raw_value, normalized_value, confidence)
     │
     ├─ DocumentConsistencyValidator → runs rule-based validation
     │   INSERT into document_validation_findings (rule_id, outcome, severity, explanation)
     │   Outcomes: pass / fail / warning / manual_review
     │
     ├─ CertificateRecognitionService → checks issuer against certifier_recognition_records
     │   (known halal certifying bodies database)
     │
     └─ DocumentScreeningHaCeRaBridge → links screening results to HaCeRa checklist items
         UPDATE hacera_documents with screening job reference
```

### Admin Document Review

```
dashboard/admin/api/document_screening_api.php
dashboard/admin/api/document_screening_preview.php
     │
     └─ Admin reviews validation findings, overrides if needed
         document_validation_findings.review_status = 'accepted' | 'overridden'
         document_review_decisions (reviewer_id, decision, notes)
```

---

## 9. TPB Demand Survey System

The **Theory of Planned Behavior (TPB)** survey measures market demand for halal-certified food among customers. Results inform business decisions and certification prioritization.

### Survey Constructs

| Construct | Abbreviation | Items | Description |
|---|---|---|---|
| Attitude | ATT | ATT1–ATT5 | Personal evaluation of halal food consumption |
| Subjective Norm | SN | SN1–SN5 | Social/peer influence on halal choices |
| Perceived Behavioral Control | PBC | PBC1–PBC5 | Perceived ease of accessing halal food |
| Purchase Intention | PI | PI1–PI5 | Likelihood of choosing halal restaurants |

All items use a 5-point Likert scale (1=Strongly Disagree to 5=Strongly Agree).

### Survey Flow

```
Customer opens dashboard/customer/tpb_survey.php
     │
     ├─ Check survey completion: api/check_survey_status.php
     │
     ├─ Survey rendered (questionnaire set based on customer's religion/food_preference)
     │
     ├─ POST to api/submit_survey.php:
     │   INSERT/UPDATE tpb_survey_responses:
     │     mean_att = AVG(ATT1-ATT5)
     │     mean_sn  = AVG(SN1-SN5)
     │     mean_pbc = AVG(PBC1-PBC5)
     │     raw_pi = AVG(PI1-PI5)
     │     normalized_pi = ((raw_pi - 1) / 4) × 100
     │
     │   INSERT into tpb_survey_answers (item_code, score)
     │
     └─ Analytics available via api/tpb_analytics.php and api/halal_directory_metrics.php
```

### Aggregate Views

```sql
VIEW tpb_demand_summary   -- ATT, SN, PBC, PI scores grouped by religion + questionnaire_set
VIEW tpb_market_demand    -- Overall demand summary (mean scores, response count)
VIEW tpb_item_averages    -- Per-item Likert averages broken down by religion
```

---

## 10. Payment Processing

### PayMongo Integration (Online Payments)

Used for:
- Customer online food orders (GCash, card, PayMaya)
- Halal certification inspection fees
- Laboratory testing fees
- Certificate fees

```
api/process_paymongo_payment.php
     │
     ├─ createPayMongoCheckoutSession($amount, $description, $success_url, $cancel_url)
     │   → POST https://api.paymongo.com/v1/checkout_sessions
     │   → Returns checkout_url (redirect customer here)
     │
     ├─ Customer completes payment on PayMongo page
     │
     ├─ PayMongo sends webhook → api/paymongo_webhook.php
     │   • HMAC signature validation
     │   • Event type: checkout_session.payment.paid
     │   → UPDATE orders.payment_status = 'paid'
     │   → INSERT into payments (reference_type, amount, transaction_reference)
     │   → INSERT into paymongo_webhook_logs (event_type, payload, processed_at)
     │
     └─ Success redirect → customer order tracking page
```

### Cash Payments (POS)

```
Cashier confirms cash → dashboard/business_owner/api/confirm_cash_payment.php
     │
     ├─ Requires manager_pin verification (restaurant_staff.manager_pin)
     ├─ Records cash_received and change_amount on orders table
     └─ order.payment_status = 'paid', payment_confirmed_by = cashier_id
```

### Payment Table (`payments`)

Covers multiple reference types:
- `inspection` → references `inspection_schedules.id`
- `laboratory` → references `laboratory_requests.id`
- `certification` → references `halal_certificates.id`
- `order` → references `orders.id`

---

## 11. Ingredient & Inventory System

A chef-managed kitchen inventory system that links ingredients to recipes and enforces menu availability at the POS.

### Data Flow

```
Chef adds inventory batch → ingredient_inventory_batches
     │  (batch_code, quantity_received, unit, expiration_date, evidence_status)
     │
     ├─ Chef builds recipe → menu_recipes (menu_item_id, serving_size)
     │   └─ Adds ingredients → recipe_ingredients (inventory_id, quantity, unit)
     │       └─ AI mapping: ingredient_aliases + ai_ingredient_mappings
     │           (maps recipe text to actual inventory batches)
     │
     ├─ Customer places order → ingredient_stock_reservations created
     │   (reserves ingredient quantities per order)
     │
     ├─ Order fulfilled → ingredient_stock_movements (debit entries)
     │   ingredient_inventory_batches.quantity_available decremented
     │
     └─ RecipeAvailabilityCalculator checks:
         If insufficient stock → menu_items.operational_status = 'unavailable'
         POS blocks ordering of unavailable items ("POS Guardrails")
```

### Ingredient Evidence

- Suppliers linked to ingredient batches
- `ingredient_documents` stores halal certificates for ingredient batches
- `ingredient_evidence_status` on `menu_items` tracks compliance state

### AI Ingredient Mapping

- `IngredientMenuAIService.php` — uses AI to suggest ingredient-to-inventory matches
- `ai_ingredient_mappings` stores confidence scores for each suggestion
- Chef reviews and accepts/rejects AI suggestions

---

## 12. Credibility Score System

A composite scoring system that ranks restaurants for public directory visibility.

### Components (each normalized 0–100)

| Component | Weight | Data Source |
|---|---|---|
| `score_foot_traffic` | Dine-in + takeout orders (last 30 days) | `orders` table |
| `score_engagement` | Reviews count, avg rating, profile views | `reviews` + `halal_restaurants` |
| `score_compliance` | Halal cert active + HaCeRa HRP score | `halal_certificates` + `hacera_hrp` |
| `score_popularity` | Repeat-visit momentum (30-day rolling window) | `orders` repeat customers |

### Score Computation

```
Total Score = weighted composite of 4 components (0–100)

Tiers:
  3 → Top Tier (score ≥ 85)
  2 → Highly Recommended (score ≥ 65)
  1 → Popular Choice (score ≥ 40)
  0 → Unranked
```

Stored on:
- `restaurant_credibility_log` — full audit trail of each computation
- `halal_restaurants.credibility_score` + `.credibility_tier` — cached for fast queries
- Index: `idx_hr_credibility` for leaderboard sort

Triggered via `api/credibility_scores.php` (Admin/President access).

---

## 13. Admin & Security Features

### Role-Based Access Control (RBAC)

- **Page:** `dashboard/admin/authorization.php`
- Configures what API endpoints and pages each role can access
- Checked via `admin/api/check_permission.php`

### Data Loss Prevention (DLP)

- **Page:** `dashboard/admin/dlp_features.php`
- DLP events logged via `admin/api/log_dlp.php`
- Field-level encryption using `FIELD_ENCRYPT_KEY` from `.env`

### Data Classification

- **Table:** `data_classifications`
- **Page:** `dashboard/admin/data_classification.php`
- Classifies each database column: `public` | `internal` | `confidential` | `restricted`
- Pre-seeded classifications:
  - `users.password` → `restricted`
  - `users.email` → `confidential`
  - `users.google_id` → `restricted`
  - `payments.transaction_reference` → `restricted`
  - `laboratory_requests.analysis_details` → `restricted`

### Session Security

- Configurable timeout via `system_settings.session_timeout_minutes` (default: 30 min)
- Timeout checked on every page load in `config/app.php`
- Session ping keepalive: `admin/api/ping_session.php`
- Active sessions tracked in `active_sessions` table

### Account Lockout

- `MAX_LOGIN_ATTEMPTS = 5` failed attempts → `users.account_locked = 1`
- All attempts logged to `login_attempts` (email, IP, user_agent, success)
- Admin must manually unlock via `dashboard/admin/users.php`

### Audit Trail

- `activity_log` — all login, registration, role application, LOI, and certificate actions
- `admin_activity_log` — admin-specific actions (target_type, target_id)
- `order_status_history` — full order status change audit trail
- `paymongo_webhook_logs` — all payment webhook events

### Password Policy

- Minimum 8 characters
- At least 1 uppercase letter
- At least 1 lowercase letter
- At least 1 digit
- At least 1 special character

---

## 14. API Endpoints

### Public / Authenticated API (`/api/`)

| Endpoint | Method | Auth | Purpose |
|---|---|---|---|
| `api/notifications.php` | GET/POST | Login | Fetch notifications, mark read/all-read |
| `api/process_paymongo_payment.php` | POST | Customer | Create PayMongo checkout session for orders |
| `api/paymongo_webhook.php` | POST | PayMongo HMAC | Receive payment completion events |
| `api/submit_survey.php` | POST | Customer | Submit/update TPB survey; compute means + PI |
| `api/check_survey_status.php` | GET | Customer | Check if current user completed TPB survey |
| `api/upload_requirement.php` | POST | Business Owner | Upload single HDP requirement document |
| `api/ph_locations.php` | GET | Public | PH administrative divisions (regions/provinces/cities/barangays) |
| `api/credibility_scores.php` | GET | Admin/President | Trigger credibility score recomputation |
| `api/halal_directory_metrics.php` | GET | Public/Admin | Aggregate halal directory stats |
| `api/tpb_analytics.php` | GET | Admin/President | Aggregated TPB demand metrics |

### Admin API (`/admin/api/`)

| Endpoint | Method | Auth | Purpose |
|---|---|---|---|
| `admin/api/check_permission.php` | GET | Admin | Check RBAC permission for a user |
| `admin/api/log_dlp.php` | POST | Admin | Log a DLP event |
| `admin/api/ping_session.php` | GET | Login | Session keepalive ping |

### Dashboard APIs (`/dashboard/*/api/`)

| Endpoint | Purpose |
|---|---|
| `dashboard/business_owner/api/hacera_ai_api.php` | Run HaCeRa AI analysis |
| `dashboard/business_owner/api/hacera_api.php` | Save HaCeRa self-assessment responses |
| `dashboard/business_owner/api/ingredient_menu_api.php` | Ingredient-to-menu AI mapping |
| `dashboard/business_owner/api/update_menu_item.php` | Update menu item details |
| `dashboard/business_owner/api/menu_item_approval.php` | Admin approve/reject menu items |
| `dashboard/business_owner/api/chef_action.php` | Chef kitchen management actions |
| `dashboard/business_owner/api/staff_action.php` | Staff activation/deactivation |
| `dashboard/business_owner/api/staff_role_handler.php` | Manage staff role definitions |
| `dashboard/business_owner/api/staff_application_handler.php` | Process staff applications |
| `dashboard/business_owner/api/get_cashier_orders.php` | Real-time POS order feed |
| `dashboard/business_owner/api/confirm_cash_payment.php` | POS cash payment confirmation |
| `dashboard/business_owner/api/modify_order.php` | Modify in-progress order |
| `dashboard/business_owner/api/void_order.php` | Void an order |
| `dashboard/business_owner/api/update_order_status.php` | Update order status |
| `dashboard/business_owner/api/pos_payment_callback.php` | POS PayMongo callback |
| `dashboard/customer/api/place_order.php` | Place a new food order |
| `dashboard/customer/api/get_order_statuses.php` | Poll order status updates |
| `dashboard/customer/api/submit_review.php` | Submit restaurant review |
| `dashboard/customer/api/submit_staff_request.php` | Apply for staff position |
| `dashboard/admin/api/document_screening_api.php` | Document screening operations |
| `dashboard/admin/api/document_screening_preview.php` | Preview screened document |
| `dashboard/admin/api/business_app_detail.php` | Business application detail view |

---

## 15. Database Schema

**Database name:** `halal_system`
**Engine:** InnoDB | **Charset:** utf8mb4 | **Collation:** utf8mb4_general_ci

### Core User & Auth Tables

#### `roles`
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO_INCREMENT | |
| role_name | VARCHAR(100) UNIQUE | |
| role_category | ENUM | `customer`, `business`, `hcb_employee`, `lab_employee` |
| description | TEXT | |
| requires_approval | TINYINT(1) | 0=auto-approve, 1=requires manual approval |
| created_at | TIMESTAMP | |

**Seeded roles (IDs 1–11):** Customer, Business Owner, Evaluator, Auditor-Technical, Auditor-Shariah, Impartial Committee, Decision Committee, President, Receiving Officer, Laboratory Analyst, Administrator

#### `users`
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO_INCREMENT | |
| google_id | VARCHAR(255) UNIQUE | Google OAuth identifier |
| email | VARCHAR(255) UNIQUE NOT NULL | |
| full_name | VARCHAR(255) | |
| first_name / last_name | VARCHAR(100) | |
| avatar | VARCHAR(500) | Profile image URL |
| password | VARCHAR(255) | bcrypt hash |
| phone | VARCHAR(20) | |
| address | TEXT | Legacy field |
| role_id | INT FK→roles.id | Current active role |
| role_status | ENUM | `pending`, `approved`, `rejected`, `none` |
| is_active | TINYINT(1) | |
| is_admin | TINYINT(1) | Admin flag (added by migration) |
| account_locked | TINYINT(1) | Brute-force lockout flag |
| failed_login_attempts | INT | Counter reset on success |
| locked_until | DATETIME | Auto-unlock timestamp |
| email_verified | TINYINT(1) | |
| last_login | TIMESTAMP | |
| religion | ENUM | `Muslim`, `Non-Muslim` |
| food_preference | ENUM | `Halal`, `Non-Pork`, `Muslim-Friendly`, `Any` |
| cuisine_preferences | VARCHAR(255) | Comma-separated list |
| address_line / city / province / postal_code | Various | Detailed address |
| address_region / address_barangay | VARCHAR | PH location fields |
| created_at / updated_at | TIMESTAMP | |

#### `role_applications`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| user_id | INT FK→users.id | Applicant |
| role_id | INT FK→roles.id | Role being applied for |
| application_letter | TEXT | |
| resume_path | VARCHAR(500) | |
| credentials_path | VARCHAR(500) | |
| additional_docs | TEXT | JSON array of paths |
| business_name / address / type | VARCHAR | For business role applications |
| status | ENUM | `pending`, `approved`, `rejected` |
| reviewed_by | INT FK→users.id | Admin who reviewed |
| reviewed_at | TIMESTAMP | |
| created_at | TIMESTAMP | |

#### `login_attempts`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| email | VARCHAR(255) | |
| ip_address | VARCHAR(45) | |
| user_agent | VARCHAR(500) | |
| success | TINYINT(1) | |
| attempted_at | TIMESTAMP | |

#### `active_sessions`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| user_id | INT FK→users.id | |
| session_token | VARCHAR(128) UNIQUE | |
| ip_address | VARCHAR(45) | |
| user_agent | VARCHAR(500) | |
| last_activity | TIMESTAMP | Updated on each request |
| created_at | TIMESTAMP | |

### Halal Certification Tables

#### `letter_of_intent`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| business_owner_id | INT FK→users.id | |
| company_name / address / info | VARCHAR/TEXT | |
| date_of_intent | DATE | |
| menu_list | TEXT | Newline-separated menu items |
| contact_person / email / phone | VARCHAR | |
| letter_content | TEXT | |
| supporting_documents | TEXT | JSON paths |
| status | ENUM | `draft`, `submitted`, `under_review`, `verified`, `returned`, `rejected` |
| remarks | TEXT | Evaluator feedback |
| submitted_at | TIMESTAMP | |

#### `loi_verification`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| loi_id | INT FK→letter_of_intent.id | |
| evaluator_id | INT FK→users.id | |
| verification_status | ENUM | `pending`, `verified`, `returned` |
| feedback | TEXT | |
| verified_letter_path | VARCHAR(500) | |
| notify_inspection | TINYINT(1) | |
| verified_at | TIMESTAMP | |

#### `loi_requirements`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| loi_id | INT FK→letter_of_intent.id | |
| evaluator_id | INT FK→users.id | |
| requirements | TEXT | Full requirement list (17 standard items) |
| sent_at | TIMESTAMP | |

#### `hdp_applications`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| loi_id | INT FK→letter_of_intent.id | |
| business_owner_id | INT FK→users.id | |
| application_form_path | VARCHAR(500) | |
| supporting_papers | TEXT | |
| request_form_path | VARCHAR(500) | |
| reviews_ratings | TEXT | |
| laboratory_report_path | VARCHAR(500) | |
| requirements_complete | TINYINT(1) | |
| status | ENUM | `draft`, `submitted`, `under_review`, `verified`, `incomplete`, `approved` |
| submitted_at | TIMESTAMP | |

#### `application_requirement_uploads`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| application_id | INT FK→hdp_applications.id | |
| requirement_label | VARCHAR(255) | e.g., "2.4 Halal Certificate of meat products" |
| file_path | VARCHAR(500) | Relative to uploads/ |
| uploaded_at | TIMESTAMP | |

#### `application_verification`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| application_id | INT FK→hdp_applications.id | |
| evaluator_id | INT FK→users.id | |
| verification_status | ENUM | `pending`, `verified`, `returned`, `incomplete` |
| feedback | TEXT | |
| verified_requirements | TEXT | |
| verified_at | TIMESTAMP | |

#### `terms_of_reference`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| application_id | INT FK→hdp_applications.id | |
| evaluator_id | INT FK→users.id | |
| enterprise_type | ENUM | `micro`, `small`, `medium` |
| auditor_assignments | TEXT | JSON array of {role, auditor_id, amount} |
| total_fee | DECIMAL(10,2) | |
| status | ENUM | `draft`, `finalized`, `sent` |
| created_at | TIMESTAMP | |

#### `inspection_schedules`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| application_id | INT FK→hdp_applications.id | |
| evaluator_id | INT FK→users.id | |
| tor_id | INT FK→terms_of_reference.id | |
| schedule_date | DATE | |
| schedule_time | TIME | |
| location | TEXT | |
| inspectors | TEXT | JSON array of assigned auditor IDs |
| inspection_fees | DECIMAL(10,2) | |
| status | ENUM | `scheduled`, `confirmed`, `in_progress`, `completed`, `cancelled`, `rescheduled` |
| notes | TEXT | |

#### `inspections`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| schedule_id | INT FK→inspection_schedules.id | |
| application_id | INT FK→hdp_applications.id | |
| auditor_technical_id | INT FK→users.id | |
| auditor_shariah_id | INT FK→users.id | |
| audit_findings | TEXT | |
| conformity_status | ENUM | `conforming`, `non_conforming`, `partial`, `pending` |
| remarks | TEXT | |
| inspection_date | DATE | |
| status | ENUM | `pending`, `in_progress`, `completed` |

#### `ncr_reports`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| inspection_id | INT FK→inspections.id | |
| application_id | INT FK→hdp_applications.id | |
| ncr_number | VARCHAR(50) UNIQUE | |
| finding_details | TEXT | |
| category | ENUM | `major`, `minor`, `observation` |
| corrective_action_required / taken | TEXT | |
| deadline | DATE | |
| status | ENUM | `open`, `in_progress`, `resolved`, `closed` |

#### `corrective_action_reports`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| application_id | INT FK→hdp_applications.id | |
| laboratory_report_id | INT FK→laboratory_reports.id | |
| inspection_id | INT FK→inspections.id | |
| report_content | TEXT | |
| corrective_actions | TEXT | |
| status | ENUM | `draft`, `submitted`, `reviewed`, `approved` |
| prepared_by | INT FK→users.id | |

#### `potential_decisions`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| application_id | INT FK→hdp_applications.id | |
| corrective_action_id | INT FK→corrective_action_reports.id | |
| committee_member_id | INT FK→users.id | |
| evidence_summary | TEXT | |
| review_notes | TEXT | |
| decision | ENUM | `recommend_approve`, `recommend_reject`, `need_more_info`, `pending` |
| notify_payment | TINYINT(1) | |
| reviewed_at | TIMESTAMP | |

#### `final_decisions`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| application_id | INT FK→hdp_applications.id | |
| potential_decision_id | INT FK→potential_decisions.id | |
| committee_member_id | INT FK→users.id | |
| decision | ENUM | `approved`, `rejected`, `deferred`, `pending` |
| decision_notes | TEXT | |
| decided_at | TIMESTAMP | |

#### `halal_certificates`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| application_id | INT FK→hdp_applications.id | |
| final_decision_id | INT FK→final_decisions.id | |
| certificate_number | VARCHAR(100) UNIQUE | |
| business_name | VARCHAR(255) | |
| business_address | TEXT | |
| certificate_type | VARCHAR(100) | |
| issue_date | DATE | |
| expiry_date | DATE | |
| halal_logo_path | VARCHAR(500) | |
| certificate_file_path | VARCHAR(500) | |
| decorated_by | INT FK→users.id | |
| awarded_by | INT FK→users.id | President who awarded |
| status | ENUM | `prepared`, `decorated`, `awarded`, `active`, `expired`, `revoked`, `suspended` |
| payment_amount | DECIMAL(10,2) | |
| payment_status | ENUM | `unpaid`, `paid` |

### Laboratory Tables

#### `laboratory_requests`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| application_id | INT FK→hdp_applications.id | |
| business_owner_id | INT FK→users.id | |
| request_number | VARCHAR(50) | |
| sample_description | TEXT | |
| analysis_requirements | TEXT | |
| payment_testing_fee | DECIMAL(10,2) | |
| payment_status | ENUM | `unpaid`, `paid`, `waived` |
| status | ENUM | `submitted`, `received`, `testing`, `completed`, `rejected` |
| received_by | INT FK→users.id | Receiving officer |
| analyst_id | INT FK→users.id | Lab analyst |
| test_result | ENUM | `pass`, `fail`, `inconclusive` |
| halal_status | ENUM | `halal`, `haram`, `mushbooh` |

#### `laboratory_analyses`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| request_id | INT FK→laboratory_requests.id | |
| analyst_id | INT FK→users.id | |
| analysis_method | TEXT | |
| analysis_results | TEXT | |
| conclusion | TEXT | |
| status | ENUM | `pending`, `in_progress`, `completed`, `failed` |

#### `laboratory_reports`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| laboratory_request_id | INT FK→laboratory_requests.id | |
| application_id | INT FK→hdp_applications.id | |
| analyst_id | INT FK→users.id | |
| report_number | VARCHAR(50) UNIQUE | |
| report_content | TEXT | |
| report_file_path | VARCHAR(500) | |
| halal_status | ENUM | `halal`, `haram`, `mushbooh` |
| status | ENUM | `draft`, `finalized`, `sent` |

### Restaurant & Ordering Tables

#### `halal_restaurants`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| business_owner_id | INT FK→users.id | |
| certificate_id | INT FK→halal_certificates.id | |
| restaurant_name | VARCHAR(255) | |
| description | TEXT | |
| address / city / province | TEXT/VARCHAR | |
| phone / email | VARCHAR | |
| operating_hours | TEXT | |
| cuisine_type | VARCHAR(100) | |
| image_path | VARCHAR(500) | |
| is_active | TINYINT(1) | |
| avg_rating | DECIMAL(3,2) | Computed from reviews |
| total_reviews | INT | |
| hacera_hrp | DECIMAL(5,2) | HaCeRa overall score (0–100) |
| hacera_band | VARCHAR(50) | Readiness band label |
| manager_pin | VARCHAR(10) | POS manager confirmation PIN |
| credibility_score | DECIMAL(5,2) | Composite credibility (0–100) |
| credibility_tier | TINYINT | 0–3 |

#### `menu_items`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| restaurant_id | INT FK→halal_restaurants.id | |
| name | VARCHAR(255) | |
| description | TEXT | |
| price | DECIMAL(10,2) | |
| category | VARCHAR(100) | |
| image_path | VARCHAR(500) | |
| is_available | TINYINT(1) | Manual on/off toggle |
| approval_status | ENUM | `pending`, `approved`, `rejected` |
| operational_status | ENUM | Auto-computed from inventory |
| ingredient_evidence_status | VARCHAR | Halal evidence status |
| serving_type | VARCHAR | `Per serving` / `For sharing` |

#### `orders`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| order_number | VARCHAR(50) UNIQUE | |
| customer_id | INT FK→users.id | |
| restaurant_id | INT FK→halal_restaurants.id | |
| items_json | TEXT | JSON array of {id, name, price, qty} |
| subtotal | DECIMAL(10,2) | |
| delivery_fee | DECIMAL(10,2) | |
| total_amount | DECIMAL(10,2) | |
| delivery_address | TEXT | |
| service_type | VARCHAR | `delivery`, `dine_in`, `takeout` |
| payment_method | ENUM | `cash`, `gcash`, `bank_transfer`, `card` |
| payment_status | ENUM | `pending`, `paid`, `refunded` |
| order_status | ENUM | `pending` → `confirmed` → `preparing` → `ready` → `out_for_delivery` → `delivered` \| `cancelled` |
| cash_received | DECIMAL | POS cash tender amount |
| change_amount | DECIMAL | POS change |
| payment_confirmed_by | INT FK→users.id | Cashier ID |
| notes | TEXT | Customer notes |
| ordered_at | TIMESTAMP | |

#### `reviews`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| customer_id | INT FK→users.id | |
| restaurant_id | INT FK→halal_restaurants.id | |
| order_id | INT FK→orders.id | |
| rating | TINYINT | 1–5 overall |
| food_rating | TINYINT | 1–5 food quality |
| service_rating | TINYINT | 1–5 service |
| comment | TEXT | |
| response | TEXT | Owner response |
| response_by | INT FK→users.id | |
| response_at | TIMESTAMP | |

### Staff Management Tables

#### `restaurant_staff_roles`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| role_key | VARCHAR(50) UNIQUE | `owner`, `ops_manager`, `cashier`, `chef` |
| role_label | VARCHAR(100) | Display name |
| description | TEXT | |

#### `restaurant_staff`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| restaurant_id | INT FK→halal_restaurants.id | |
| user_id | INT FK→users.id | |
| staff_role_id | INT FK→restaurant_staff_roles.id | |
| is_active | TINYINT(1) | |
| assigned_by | INT FK→users.id | Owner who assigned |
| manager_pin | VARCHAR(10) | Staff-specific PIN |

### Business Applications Tables

#### `business_applications`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| user_id | INT FK→users.id | |
| certification_path | ENUM | `certified`, `not_certified` |
| business_name | VARCHAR(255) | |
| description | TEXT | |
| address fields | Various | |
| owner_name / age / religion | VARCHAR | |
| status | ENUM | `pending`, `under_review`, `approved`, `rejected`, `returned` |
| halal_classification | VARCHAR | `halal_certified`, `muslim_friendly`, `non_pork` |

#### `business_application_documents`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| application_id | INT FK→business_applications.id | |
| doc_type | VARCHAR | `halal_certificate`, `business_permit`, etc. |
| original_name | VARCHAR(255) | |
| file_path | VARCHAR(500) | |
| file_size | BIGINT | |
| mime_type | VARCHAR(100) | |

### Survey Tables

#### `tpb_survey_responses`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| user_id | INT FK→users.id UNIQUE | One survey per user |
| religion | ENUM | `Muslim`, `Non-Muslim` |
| food_preference | VARCHAR | |
| questionnaire_set | VARCHAR | Variant of survey presented |
| mean_att | DECIMAL(4,2) | Mean Attitude score (1–5) |
| mean_sn | DECIMAL(4,2) | Mean Subjective Norm score |
| mean_pbc | DECIMAL(4,2) | Mean Perceived Behavioral Control score |
| raw_pi | DECIMAL(4,2) | Mean Purchase Intention score |
| normalized_pi | DECIMAL(5,2) | ((raw_pi - 1) / 4) × 100 |
| submitted_at | TIMESTAMP | |

#### `tpb_survey_answers`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| response_id | INT FK→tpb_survey_responses.id | |
| item_code | VARCHAR(10) | e.g., `ATT1`, `SN3`, `PBC5`, `PI2` |
| score | TINYINT | 1–5 Likert score |

### HaCeRa Tables

#### `business_hacera_responses`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| restaurant_id | INT FK→halal_restaurants.id | |
| checklist_item_id | INT | Module item ID (101–509) |
| response_value | ENUM | `yes`, `no`, `na` |
| notes | TEXT | |
| responded_by | INT FK→users.id | |

#### `hacera_documents`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| restaurant_id | INT FK→halal_restaurants.id | |
| checklist_item_id | INT | |
| file_path | VARCHAR(500) | |
| original_name | VARCHAR(255) | |
| uploaded_by | INT FK→users.id | |

#### `hacera_ai_runs`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| restaurant_id | INT FK→halal_restaurants.id | |
| requested_by | INT FK→users.id | |
| status | ENUM | `queued`, `processing`, `completed`, `failed` |
| input_hash | VARCHAR(64) | SHA-256 of inputs (dedup) |
| provider / model | VARCHAR | AI provider details |
| prompt_version | VARCHAR | |
| summary | TEXT | AI-generated summary |
| report_json | JSON | Full structured report |
| validated_finding_count | INT | |

#### `hacera_ai_findings`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| run_id | INT FK→hacera_ai_runs.id | |
| restaurant_id | INT | |
| module_id | INT | 1–5 |
| checklist_item_id | INT | |
| title | VARCHAR(255) | |
| finding_type | VARCHAR | `gap`, `risk`, `recommendation`, `warning` |
| explanation | TEXT | |
| severity | ENUM | `critical`, `high`, `medium`, `low` |
| criteria_reference | TEXT | HDIP Shari'ah standard reference |
| recommended_corrective_action | TEXT | |

### Document Screening Tables

#### `document_screening_jobs`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| source_type | VARCHAR | `hdp_application`, `hacera_upload` |
| source_document_id | INT | |
| application_id | INT FK→business_applications.id | |
| requested_by | INT FK→users.id | |
| status | ENUM | `queued`, `processing`, `completed`, `failed` |
| document_type | VARCHAR | Detected type |
| classification_confidence | DECIMAL(5,2) | |
| ocr_method | VARCHAR | `poppler`, `tesseract`, etc. |
| ocr_confidence | DECIMAL(5,2) | |
| extracted_text | TEXT | Full OCR output |
| text_retention_until | DATETIME | GDPR retention limit |

#### `document_extracted_fields`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| job_id | INT FK→document_screening_jobs.id | |
| field_key | VARCHAR(100) | e.g., `issuer_name`, `issue_date`, `product_name` |
| raw_value | TEXT | As extracted |
| normalized_value | TEXT | Cleaned/standardized |
| confidence | DECIMAL(5,2) | |
| source_page | INT | Page number in PDF |
| is_uncertain | TINYINT(1) | |

#### `document_validation_findings`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| job_id | INT FK→document_screening_jobs.id | |
| application_id | INT | |
| rule_id | VARCHAR(50) | Validation rule identifier |
| field_key | VARCHAR(100) | |
| outcome | ENUM | `pass`, `fail`, `warning`, `manual_review` |
| severity | ENUM | `critical`, `high`, `medium`, `low` |
| submitted_value | TEXT | What the user submitted |
| extracted_value | TEXT | What OCR found |
| explanation | TEXT | |
| recommendation | TEXT | |
| review_status | ENUM | `pending`, `accepted`, `overridden` |

#### `certifier_recognition_records`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| certifier_name | VARCHAR(255) | |
| normalized_certifier_name | VARCHAR(255) | Lowercase normalized |
| recognition_scheme | VARCHAR | e.g., `IDCP`, `OIC/SMIIC` |
| recognition_status | ENUM | `recognized`, `conditionally_recognized`, `not_recognized` |
| scope_description | TEXT | |
| valid_from / valid_to | DATE | |
| source_reference | TEXT | |

### Ingredient & Inventory Tables

#### `ingredient_inventory_batches`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| restaurant_id | INT FK→halal_restaurants.id | |
| inventory_id | INT FK→kitchen_inventory.id | |
| batch_code | VARCHAR(100) | |
| quantity_received / available / reserved | DECIMAL | Stock tracking |
| unit | VARCHAR(50) | `kg`, `g`, `L`, `pcs`, etc. |
| expiration_date | DATE | |
| batch_status | ENUM | `active`, `quarantined`, `exhausted`, `void` |
| evidence_status | VARCHAR | Halal evidence state |

#### `menu_recipes`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| restaurant_id | INT | |
| menu_item_id | INT FK→menu_items.id | |
| recipe_name | VARCHAR(255) | |
| serving_size | VARCHAR(100) | |
| recipe_status | ENUM | `draft`, `active`, `archived` |
| customer_visibility_status | ENUM | `draft`, `published`, `needs_reapproval` |
| version_no | INT | Version tracking |

#### `recipe_ingredients`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| recipe_id | INT FK→menu_recipes.id | |
| inventory_id | INT FK→kitchen_inventory.id | |
| quantity | DECIMAL | Per serving |
| unit | VARCHAR(50) | |

### Analytics Tables

#### `restaurant_credibility_log`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| restaurant_id | INT FK→halal_restaurants.id | |
| score_foot_traffic | DECIMAL(5,2) | 0–100 |
| score_engagement | DECIMAL(5,2) | 0–100 |
| score_compliance | DECIMAL(5,2) | 0–100 |
| score_popularity | DECIMAL(5,2) | 0–100 |
| total_score | DECIMAL(5,2) | Weighted composite |
| tier | TINYINT | 0–3 |
| computed_at | TIMESTAMP | |

#### `restaurant_revenue_daily`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| restaurant_id | INT FK→halal_restaurants.id | |
| snapshot_date | DATE | |
| total_orders | INT | |
| gross_revenue | DECIMAL(12,2) | |

### System Tables

#### `notifications`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| user_id | INT FK→users.id | |
| title | VARCHAR(255) | |
| message | TEXT | |
| type | ENUM | `info`, `success`, `warning`, `error`, `action_required` |
| link | VARCHAR(500) | Deep-link to relevant page |
| is_read | TINYINT(1) | |
| created_at | TIMESTAMP | |

#### `activity_log`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| user_id | INT FK→users.id | |
| action | VARCHAR(255) | e.g., `Login`, `LOI Created`, `Application Verified` |
| details | TEXT | |
| ip_address | VARCHAR(45) | |
| module | VARCHAR(100) | e.g., `auth`, `loi`, `evaluation` |
| created_at | TIMESTAMP | |

#### `system_settings`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| setting_key | VARCHAR(100) UNIQUE | |
| setting_value | TEXT | |
| setting_type | VARCHAR(50) | `integer`, `string`, `boolean` |
| description | TEXT | |

**Default settings:** `session_timeout_minutes = 30`

#### `data_classifications`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| table_name | VARCHAR(100) | |
| column_name | VARCHAR(100) | |
| classification | ENUM | `public`, `internal`, `confidential`, `restricted` |
| description | TEXT | |
| updated_by | INT FK→users.id | |
| updated_at | TIMESTAMP | |

---

## 16. Foreign Key Relationships

```
users ← roles
  users.role_id → roles.id (ON DELETE SET NULL)

users → role_applications
  role_applications.user_id → users.id (ON DELETE CASCADE)

users → letter_of_intent
  letter_of_intent.business_owner_id → users.id (ON DELETE CASCADE)

letter_of_intent → loi_verification
  loi_verification.loi_id → letter_of_intent.id (ON DELETE CASCADE)

letter_of_intent → loi_requirements
  loi_requirements.loi_id → letter_of_intent.id (ON DELETE CASCADE)

letter_of_intent → hdp_applications
  hdp_applications.loi_id → letter_of_intent.id (ON DELETE CASCADE)

hdp_applications → application_requirement_uploads (ON DELETE CASCADE)
hdp_applications → application_verification (ON DELETE CASCADE)
hdp_applications → terms_of_reference (ON DELETE CASCADE)

terms_of_reference → inspection_schedules
  inspection_schedules.tor_id → terms_of_reference.id (ON DELETE SET NULL)

inspection_schedules → inspections (ON DELETE CASCADE)
hdp_applications → inspections (ON DELETE CASCADE)
inspections → ncr_reports (ON DELETE CASCADE)

hdp_applications → corrective_action_reports (ON DELETE CASCADE)
laboratory_reports → corrective_action_reports (ON DELETE SET NULL)
inspections → corrective_action_reports (ON DELETE SET NULL)

hdp_applications → potential_decisions (ON DELETE CASCADE)
corrective_action_reports → potential_decisions (ON DELETE SET NULL)
potential_decisions → final_decisions (ON DELETE SET NULL)
hdp_applications → final_decisions (ON DELETE CASCADE)

final_decisions → halal_certificates (ON DELETE CASCADE)
hdp_applications → halal_certificates (ON DELETE CASCADE)
halal_certificates → halal_restaurants
  halal_restaurants.certificate_id → halal_certificates.id (ON DELETE SET NULL)

users → halal_restaurants (ON DELETE CASCADE)
halal_restaurants → menu_items (ON DELETE CASCADE)
users → orders (ON DELETE CASCADE)
halal_restaurants → orders (ON DELETE CASCADE)
orders → reviews (ON DELETE SET NULL)
users → reviews (ON DELETE CASCADE)
halal_restaurants → reviews (ON DELETE CASCADE)

hdp_applications → laboratory_requests (ON DELETE CASCADE)
laboratory_requests → laboratory_analyses (ON DELETE CASCADE)
laboratory_requests → laboratory_reports (ON DELETE SET NULL)
hdp_applications → laboratory_reports (ON DELETE CASCADE)

users → notifications (ON DELETE CASCADE)
users → activity_log (ON DELETE SET NULL)
users → payments (ON DELETE CASCADE)

business_applications → business_application_documents (ON DELETE CASCADE)
business_applications → document_screening_jobs (ON DELETE CASCADE)

halal_restaurants → restaurant_staff (ON DELETE CASCADE)
users → restaurant_staff (ON DELETE CASCADE)
restaurant_staff_roles → restaurant_staff (ON DELETE CASCADE)

halal_restaurants → restaurant_revenue_daily (ON DELETE CASCADE)
halal_restaurants → restaurant_credibility_log (ON DELETE CASCADE)

halal_restaurants → business_hacera_responses (via restaurant_id)
halal_restaurants → hacera_documents (via restaurant_id)
halal_restaurants → hacera_ai_runs (ON DELETE CASCADE)
hacera_ai_runs → hacera_ai_findings (ON DELETE CASCADE)

document_screening_jobs → document_extracted_fields (ON DELETE CASCADE)
document_screening_jobs → document_validation_findings (ON DELETE CASCADE)

tpb_survey_responses → tpb_survey_answers (ON DELETE CASCADE)
users → tpb_survey_responses (ON DELETE CASCADE)
```

---

## 17. Data Flow Diagrams

### Overall System Data Flow

```
                        ┌─────────────┐
                        │   Customer  │
                        └──────┬──────┘
                               │ Browse / Order / Review / Survey
                        ┌──────▼──────────────────────┐
                        │     Public Directory         │
                        │  (index.php, restaurants.php)│
                        └──────┬──────────────────────┘
                               │ Orders
                        ┌──────▼──────┐       ┌─────────────────┐
                        │   orders    │◄──────►│    payments     │
                        │   reviews   │       │  (PayMongo)     │
                        └─────────────┘       └─────────────────┘
                               │
                        ┌──────▼──────────────────────────────────┐
                        │         Business Owner                  │
                        │  Restaurant ← Menu ← Staff ← Orders    │
                        │  HaCeRa Assessment                      │
                        │  Halal Certification Pipeline           │
                        └──────┬──────────────────────────────────┘
                               │ LOI → HDP Application
                        ┌──────▼──────────────────────────────────┐
                        │              HCB Workflow               │
                        │  Evaluator → ToR → Auditors → Lab →    │
                        │  Impartial Committee → Decision →       │
                        │  President → Certificate                │
                        └──────┬──────────────────────────────────┘
                               │ Certificate issued
                        ┌──────▼──────┐
                        │  halal_     │
                        │ certificates│
                        └──────┬──────┘
                               │ Linked to
                        ┌──────▼──────┐
                        │  halal_     │
                        │ restaurants │
                        └─────────────┘
```

### Certification Pipeline Data Flow

```
letter_of_intent
       │ loi_id
       ▼
loi_verification ────────────── loi_requirements
       │                               │
       │ (verified)                    │ (sent to owner)
       ▼                               ▼
hdp_applications ◄──── application_requirement_uploads
       │
       ├──── application_verification
       │
       ├──── terms_of_reference
       │           │
       │     inspection_schedules
       │           │
       │       inspections ────── ncr_reports
       │           │
       │   corrective_action_reports
       │           │
       ├──── laboratory_requests ── laboratory_analyses
       │           │
       │   laboratory_reports
       │           │
       ├──── potential_decisions
       │
       ├──── final_decisions
       │
       └──── halal_certificates
                   │
             halal_restaurants
```

### Order Data Flow

```
Customer                   Restaurant                  Payment
   │                           │                          │
place_order.php ──────────► orders table                  │
   │                           │                          │
   │ (online payment)          │              PayMongo checkout
   │ ─────────────────────────────────────────────────────►
   │                           │                          │
   │                           │         webhook received │
   │                           │◄─────────────────────────
   │                           │ payment_status='paid'    │
   │                           │                          │
   │         status updates    │                          │
   │◄──────────────────────────│                          │
   │ (preparing→ready→         │                          │
   │  delivered)               │                          │
   │                           │                          │
reviews.php ──────────────► reviews table                 │
                               │ avg_rating updated       │
                          halal_restaurants               │
```

---

## 18. File & Upload Structure

```
uploads/
├── avatars/           ← User profile pictures
├── certificates/      ← Issued halal certificate PDF files
├── documents/         ← HDP application requirement uploads
│                          (named: {uniqid}_{timestamp}.{ext})
├── restaurant_images/ ← Restaurant cover photos
├── menu_images/       ← Individual menu item photos
├── role_applications/ ← Resume and credential uploads
├── receipts/          ← Payment receipt files
├── staff_applications/← Staff application supporting docs
├── business_docs/     ← Business application documents
│   └── {app_id}/      ← Sub-directory per application
├── hacera_docs/       ← HaCeRa supporting evidence uploads
├── halal_proofs/      ← Halal certificate proofs
├── signatures/        ← Digital signature images
├── products/          ← Product images
├── recipes/           ← Recipe-related uploads
├── requisition_proofs/← Grocery requisition proof images
├── review_photos/     ← Customer review photo attachments
└── expense_receipts/  ← Expense receipt images
```

**File name convention:** `{uniqid()}_{time()}.{ext}` — prevents collisions and guessing.

**Allowed types:**
- Documents: `pdf`, `doc`, `docx`, `jpg`, `jpeg`, `png`, `gif`
- Images: `jpg`, `jpeg`, `png`, `gif`, `webp`
- Max size: **20 MB** per file

---

## 19. Environment Configuration

All secrets and environment-specific settings are stored in `.env` (never committed to git).

```env
# Database
DB_HOST=127.0.0.1
DB_USER=root
DB_PASS=
DB_NAME=halal_system

# Application
APP_SUBFOLDER=halal_final_dev    # Local XAMPP subfolder
APP_ENV=local                    # local | production

# Google OAuth
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...

# PayMongo
PAYMONGO_SECRET_KEY=sk_test_...
PAYMONGO_PUBLIC_KEY=pk_test_...

# Security
FIELD_ENCRYPT_KEY=your-256-bit-key

# AI Services (for HaCeRa AI + Document Screening)
OPENAI_API_KEY=sk-...
AI_PROVIDER=openai               # or compatible endpoint

# Feature Flags
INGREDIENT_MENU_MAPPING_ENABLED=true
DOCUMENT_SCREENING_ENABLED=true
```

### Security Notes

- `.env` is gitignored — never commit credentials
- `FIELD_ENCRYPT_KEY` must be rotated and never default in production
- `PAYMONGO_SECRET_KEY` must use live keys (`sk_live_...`) in production
- Google OAuth redirect URI auto-derives from `BASE_URL` — no manual update needed on deployment
- Change `APP_SUBFOLDER` to empty string (or remove) when deploying to domain root

---

*End of Documentation*

> Generated from codebase analysis of Halal Keeps (`halal_final_dev`) — October 2026.
