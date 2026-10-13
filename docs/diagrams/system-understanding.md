# Halal Keeps: Implemented System Understanding

## Document status and investigation scope

This document records the implementation observed in the repository and the local `halal_system` database on 2026-10-11. It is intended to be reused for later diagrams. Source code is treated as the primary evidence; `README.md`, `SYSTEM.md`, `SYSTEM_ARCHITECTURE.md`, and `CODEBASE.md` were used only as orientation and were checked against the current PHP implementation.

The investigation covered the root PHP application, `config/`, `auth/`, `api/`, `includes/`, `dashboard/customer/`, `dashboard/business_owner/`, `dashboard/admin/`, `admin/`, `scripts/`, the SQL migrations, and the current database table/column inventory. Vendored dependencies, uploaded user files, generated content, and the nested `halal_final/node_modules/` dependency tree were not inspected exhaustively. No secret values were read into this document.

Status terms used below:

- **Verified implementation**: directly supported by current source and, for important persistence paths, by the current local schema.
- **Partially verified**: source exists, but the end-to-end path has a missing consumer, inconsistent schema, missing route, or environment-dependent component.
- **Planned/incomplete**: names, tables, static UI, or fragments exist, but the repository does not contain a currently usable end-to-end implementation.

## 1. Project overview

Halal Keeps is a server-rendered PHP web application for a halal-oriented restaurant marketplace and restaurant operations platform. The implemented core connects customers, restaurant owners and restaurant staff, and system administrators. Its principal capabilities are:

- customer registration, login, Google sign-in, profile/demographic capture, and session-based access control;
- public/customer discovery of active restaurant profiles, approved menus, halal certificate information, ratings, readiness, and credibility indicators;
- customer business-registration applications with branches, uploaded evidence, and raw-material records, followed by administrative review and restaurant provisioning;
- customer demand surveys based on the Theory of Planned Behavior (TPB), with results used as a prerequisite and analytical input for customer orders;
- customer and point-of-sale orders, cash confirmation, PayMongo checkout integration, restaurant order handling, and post-order reviews;
- restaurant profile, menu, staff, kitchen, inventory, grocery/requisition, expense, other-inflow, and reporting functions;
- HaCeRa readiness self-assessment, evidence uploads, weighted scoring, PDF portfolio generation, published readiness scores, and optional AI advisory insights;
- notifications, operational activity logging, administrative user/security views, and configuration-backed session timeout.

The runtime is PHP 8.0+ (the local CLI is PHP 8.2), MySQL/MariaDB through MySQLi, browser HTML/CSS/JavaScript, and filesystem uploads. mPDF is the only Composer dependency and is used for HaCeRa PDF generation. The application is structured as page controllers and JSON/form endpoints rather than a framework-based MVC application. `config/app.php` starts the session, loads database/configuration/helpers, applies inactivity timeout, refreshes staff role state, and ensures upload directories exist.

Implemented external integrations are Google OAuth 2.0, PayMongo, and a configurable OpenAI-compatible endpoint for optional HaCeRa advisory analysis. Google Fonts, Font Awesome/CDN assets, Tailwind CDN use, and Chart.js affect presentation but are not core system data-flow participants.

### Implemented versus incomplete certification behavior

The currently verifiable business-onboarding path is the customer business application reviewed in `dashboard/admin/business_applications.php`. Approval promotes the applicant, creates an active restaurant, and can provision an active certificate when an uploaded certificate path and expiry date are present.

A separate legacy HDP certification pipeline is represented by `hdp_applications`, `letter_of_intent`, inspection/laboratory/committee-related references, and portions of the business-owner application pages. It is **not verified as an operable end-to-end workflow in this worktree**: the evaluator, auditor, committee, president, receiving-officer, and laboratory dashboard files referenced by routing and menus are absent/deleted, the business-owner letter-of-intent entry page is absent, and certificate pages link to absent payment/PDF endpoints. Those fragments must not be shown as a functioning certification pipeline.

## 2. System boundaries and actors

### Internal application boundary

Inside the Halal Keeps boundary are the PHP pages/endpoints, shared PHP services, the MySQL database, local upload storage, PHP sessions, the HaCeRa scoring/advisory validation code, and locally generated PDF output.

### Verified human actors

- **Visitor**: views the landing page and can request public TPB aggregate analytics; signs up or starts Google sign-in.
- **Customer**: browses restaurants and menus, completes/retakes the TPB survey, places orders, tracks eligible orders, submits one review per delivered/completed order, applies to register a business, and can apply for a restaurant staff role.
- **Business owner**: owns the provisioned `halal_restaurants` record; manages the restaurant, staff, owner reporting, HaCeRa, and owner-level actions. An actual owner is represented by `ROLE_BUSINESS_OWNER` with no `staff_role_key` assignment.
- **Operations manager**: a per-restaurant staff assignment. Manages profile/menu review, reviews and replies, grocery/requisition review, expenses, other inflows, and backdated/logged orders.
- **Cashier**: a per-restaurant staff assignment. Uses the POS, confirms eligible cash payments, and can work with the restaurant order queue.
- **Chef/head cook**: a per-restaurant staff assignment. Manages inventory, suppliers, recipe mappings, grocery/requisition submissions, receiving/inspection operations, menu submissions, and menu availability.
- **System administrator**: reviews customer business applications, provisions approved restaurants, can handle role/user administration, and views/configures security/logging/DLP-related records through two admin surfaces.

The current role table and constants also name evaluator, technical auditor, Shariah auditor, impartial committee, decision committee, president, receiving officer, and laboratory analyst. They are not treated as implemented actors for the current Level 1 diagram because their routed dashboard implementations are absent in this worktree.

### Verified external systems

- **Google OAuth**: supplies an authorization code, access token, and profile data for sign-in/registration.
- **PayMongo**: creates/retrieves checkout sessions and redirects users for GCash/PayMaya-style payment. Some customer payment confirmation paths are inconsistent; see uncertainties.
- **Configurable AI provider**: receives a restricted structured HaCeRa context and returns a JSON advisory report only when the feature is enabled and credentials/model/endpoint are configured.

Local MySQL and upload directories are data stores within the deployed application boundary, not external actors.

## 3. Architecture and major components

### Bootstrap, configuration, and shared services

- `config/app.php` is the common request bootstrap. It starts sessions, loads `database.php`, constants, general helpers, and staff RBAC; enforces a database-configured inactivity timeout; refreshes a business user's active staff role; and prepares upload directories.
- `config/database.php` creates the MySQLi connection and supplies idempotent schema helpers (`db_add_column`, `db_add_unique_key`, and foreign-key helpers).
- `config/constants.php` defines role IDs, staff role keys, file limits, controlled vocabularies, service/menu/expense/inflow values, and external integration settings loaded from environment variables.
- `includes/functions.php` supplies login/role guards, file uploads, notifications, activity logging, dashboard routing, staff/restaurant resolution, Google OAuth calls, PayMongo checkout calls, business-view analytics, credibility scoring, and shared expense queries.
- `includes/staff_rbac.php` and staff functions in `includes/functions.php` enforce per-restaurant staff assignments and owner-only versus role-specific workspaces.

Many page controllers perform idempotent `CREATE TABLE IF NOT EXISTS` or column-add operations at request time. SQL migrations also exist. Therefore, schema ownership is distributed between migration files and runtime PHP rather than centralized in one migration system.

### Authentication and access component

- `auth/register.php` validates local customer registration, hashes passwords, stores demographic/address preferences, assigns the approved Customer role, and writes an activity event.
- `auth/login.php` validates passwords, increments a per-user failure counter, permanently sets `account_locked` after five failures, creates a session, and routes by system/staff role. Despite older documentation and an unused `LOCKOUT_DURATION` constant, this login path does not automatically unlock after 15 minutes.
- `auth/google_callback.php` verifies OAuth state, exchanges the authorization code, fetches the Google profile, upserts/updates the user, assigns Customer when needed, and creates the application session.
- `createUserSession()` resolves an active restaurant staff assignment so the same system role (`ROLE_BUSINESS_OWNER`) can route to owner, operations-manager, cashier, or chef workspaces.

### Customer marketplace component

- `dashboard/customer/restaurants.php` and `restaurant_view.php` query active restaurants, certificates, approved menu items, review/traffic data, HaCeRa publication fields, and credibility data. Opening a restaurant detail records a `business_views` row.
- Only menu items with `approval_status='approved'` are displayed; unavailable approved items can be shown as sold out and cannot be added to the cart.
- `dashboard/customer/api/submit_review.php` permits a single review for a customer-owned delivered/completed order, stores optional photos, and recalculates restaurant rating aggregates.
- `computeCredibilityScore()` combines non-cancelled dine-in/takeout orders, ratings/review count, active certification plus published HaCeRa score, and profile views. It persists a cached score and audit row; `getCredibilityScore()` recomputes when the cache is absent or older than 24 hours.

### Business onboarding and administrative review component

- `dashboard/customer/apply_business.php` collects the certification path, owner/business data, one or more branches, path-dependent documents, and raw-material data. It writes the application, branches, branch documents, and raw materials transactionally and notifies active administrators.
- `dashboard/admin/business_applications.php` lets a role-11 administrator mark an application under review, return it, reject it, or approve it. Approval promotes the applicant to Business Owner and creates one active `halal_restaurants` record (using the main branch address) if the owner does not already have one.
- For an already-certified application containing an uploaded certificate path and expiry, approval can directly create and link a `halal_certificates` row. This is the verified certificate bypass path.
- `dashboard/admin/api/business_app_detail.php` supplies the application detail data used by the review UI.

### Restaurant staff and workspace component

- A customer applies to a restaurant role through `dashboard/customer/api/submit_staff_request.php`, producing a pending `staff_applications` record and uploaded supporting files.
- The owner reviews it with `dashboard/business_owner/api/staff_app_action.php`. Approval creates/reactivates `restaurant_staff`, assigns a platform or restaurant-specific role, changes the user's system role to Business Owner, and notifies the applicant with the staff workspace URL.
- Owners can create/manage custom restaurant staff roles and assignments through the staff pages/APIs. Runtime access still has special, hard-coded workspaces for `ops_manager`, `cashier`, and `chef`; custom roles do not have equally defined workspace routing.

### Menu, kitchen, and restaurant operations component

- Owners publish menu items directly. Chef and operations-manager submissions start pending. `menu_item_approval.php` allows the owner or operations manager to approve/reject them and notifies the submitter.
- `chef_action.php` owns a broad operational API: kitchen inventory, halal meat suppliers, ingredient/requisition records, recipe-to-inventory mapping, availability synchronization, grocery lists, approvals/rejections, receiving, purchase/inspection state, and related uploads.
- The operations-manager page updates the restaurant profile, reviews/replies, reviews menu submissions and grocery lists, records itemized expenses, manages other inflows, and exposes a logged/backdated-order flow.
- Grocery/requisition flows store item-level quantities/costs and can feed inventory and expense records. The source contains overlapping action branches for some grocery-list actions, so the exact branch used depends on request shape/order in `chef_action.php`.

### Survey and analytics component

- `dashboard/customer/tpb_survey.php` selects one of two 15-item questionnaires based on `users.religion`: Muslim Set 1 or Non-Muslim Set 2.
- `api/submit_survey.php` validates ATT, SN, and PBC item scores, computes the three construct means, raw purchase intention, and normalized purchase intention, then upserts one response per user and replaces the 15 item answers.
- `api/tpb_analytics.php` exposes public overall/group aggregates and authenticated owner/admin item-level aggregates. These owner aggregates are platform-wide, not scoped to a particular restaurant.
- A current TPB response is required before the customer order endpoint accepts an order. `survey_order_tracking` links that response to the order and restaurant.

### Ordering, payment, and fulfillment component

- `dashboard/customer/api/place_order.php` accepts dine-in, takeout, or delivery orders, requires an existing TPB response, checks restaurant/menu availability, records the order and survey link, and notifies the owner, cashiers, and customer. Delivery cannot use cash.
- Customer cash orders use `awaiting_cash`/`pending_payment`; an owner or assigned cashier confirms the received amount through `confirm_cash_payment.php`, which records change, payment verifier/time, status history, and notifications.
- Customer online orders create a PayMongo checkout session and a pending `payments` row, then redirect to PayMongo. The current customer return callback marks the order paid when returned with `status=success` without retrieving the checkout session. The generic webhook follows a different schema contract and is not verified as functional with this order path.
- The POS verifies current approved menu prices from the database. Cash/card POS orders are immediately paid/confirmed. GCash/bank transfer uses PayMongo and a POS callback that attempts to retrieve and verify the checkout session.
- `manage_orders.php` implements the visible primary fulfillment lifecycle: dine-in/takeout proceed to `delivered` (labelled served/picked up), while delivery proceeds through `out_for_delivery` to `delivered`.
- A separate `update_order_status.php` API uses a `completed` terminal state and a different transition table. Both `completed` and `delivered` exist in the current database enum, so this is an implementation inconsistency rather than a single canonical lifecycle.

### HaCeRa readiness and AI advisory component

- `HaCeRaCalculator.php` defines five implemented modules and their weighted checklist items. An item is complete only when its saved response is `yes`; document presence is supporting evidence and does not itself earn score.
- `dashboard/business_owner/api/hacera_api.php` saves yes/no/N/A responses, uploads/deletes evidence, calculates module scores and mean HRP, generates an mPDF portfolio, and publishes HRP/band/timestamp to the restaurant. These routes resolve the actual owner restaurant and are not intended as ordinary staff functions.
- The optional AI subsystem builds a minimal structured context without customer/payment/financial/profile data and without uploaded file contents, names, or paths. It sends that context to a configured OpenAI-compatible endpoint, treats the response as untrusted, validates references and claims, persists only validated findings/actions, supports owner feedback, and never changes scoring, published HRP, certification, restaurant, or order data.
- AI processing can run inline or create queued runs consumed by `scripts/process_hacera_ai_queue.php`. A scheduler/service definition was not found, so automatic invocation of the CLI worker is not verified.

### Reporting, notification, and administration component

- Owner/operations financial views aggregate paid orders, approved other inflows, expenses, and delivery-fee amounts for selected periods. Data is queried on demand; no verified scheduler populating the optional daily revenue snapshot table was found.
- Notifications are stored in `notifications` and retrieved/marked read through `api/notifications.php`; many onboarding, ordering, staff, menu, and HaCeRa-related actions write them.
- General activity is stored in `activity_log`. The legacy admin surface additionally uses `admin_activity_log`, login-attempt, classification, settings, and DLP-related tables/helpers.
- `dashboard/admin/authorization.php` displays a static permission matrix; it is not a persisted permission editor. The `admin/` pages use the `is_admin` session flag, while `dashboard/admin/` generally uses system role 11. These are related but distinct authorization checks.

## 4. Data and persistence

The current local database contains the tables below; runtime PHP and migrations may add columns idempotently.

### Accounts, access, and administration

- `users`, `roles`, `restaurant_staff_roles`, `restaurant_staff`, `staff_applications`, `role_applications`
- `notifications`, `activity_log`, `admin_activity_log`, `admin_activity_logs`, `login_attempts`, `system_settings`, `data_classifications`

`users.role_id` is the system-wide role. Restaurant workspaces are determined separately through `restaurant_staff -> restaurant_staff_roles`. Staff approval currently promotes the user's system role to Business Owner as well as creating the per-restaurant assignment.

### Business registry and certification records

- `business_applications`, `business_branches`, `branch_documents`, `business_application_documents`, `raw_material_items`
- `halal_restaurants`, `halal_certificates`
- legacy/partial records such as `hdp_applications`, application requirement/review tables, and related certification records

The verified onboarding flow writes `branch_documents`; the older `business_application_documents` migration/table remains but is not the primary store used by the current multi-branch form. Admin approval provisions at most one restaurant row per applicant/owner, even when an application has multiple branches.

### Marketplace, survey, orders, and engagement

- `menu_items`, `business_views`, `restaurant_credibility_log`
- `tpb_survey_responses`, `tpb_survey_answers`, `survey_order_tracking`
- `orders`, `payments`, `order_status_history`, `cashier_notifications`
- `reviews`, `review_photos`

Order line items are stored as JSON in `orders.items_json`, not in a normalized order-items table. The customer endpoint currently accepts client-supplied prices/subtotal while checking only item IDs/availability; the POS path reloads database prices. This distinction is implementation evidence, not a recommended design.

### Restaurant operations and finance

- `kitchen_inventory`, `halal_meat_suppliers`, `menu_recipe_ingredients`
- `ingredient_requests`, `ingredient_request_items`, `ingredients_category`
- `grocery_lists`, `grocery_list_items`, `grocery_list_recipes`
- `restaurant_expenses`, `restaurant_expense_items`, `restaurant_other_inflows`

### HaCeRa and AI advisory

- `business_hacera_responses`, `hacera_documents`, `hacera_modules`, `hacera_checklists`
- `hacera_ai_runs`, `hacera_ai_findings`, `hacera_ai_evidence_links`, `hacera_ai_actions`, `hacera_ai_feedback`

HaCeRa module definitions used for scoring are hard-coded in `HaCeRaCalculator.php`; the module/checklist tables are created but are not the authoritative scoring definition in the traced path.

### Filesystem persistence

Uploads are stored below `uploads/` in purpose-specific directories for business documents, products/raw-material images, restaurant/menu images, HaCeRa evidence, staff applications, expense/inflow/requisition evidence, review photos, and other legacy certification files. The database normally stores relative paths. HaCeRa PDFs are generated for download; no persistent PDF artifact is required by the traced API.

## 5. Important implemented workflows

### A. Account creation and access

1. A visitor registers locally or authenticates through Google.
2. The application creates/updates `users`, assigns Customer when no approved role exists, logs activity, and creates a session.
3. The role and optional restaurant staff assignment determine the destination workspace.
4. Protected requests apply system-role guards and, where relevant, restaurant/staff ownership checks.

### B. Customer business onboarding

1. A customer submits business/owner data, certification path, branches, required uploads, and raw materials.
2. A transaction writes application, branch, document, and raw-material records and notifies administrators.
3. An administrator reviews and changes status.
4. Approval promotes the customer, provisions the restaurant from the main branch, optionally provisions a pre-existing certificate, and notifies/logs the outcome.

### C. Staff onboarding

1. A customer selects a restaurant role and submits supporting data/files.
2. The owner approves or rejects the application.
3. Approval creates/reactivates the staff assignment, sets its restaurant role, promotes the system role, and routes the user to the matching workspace.

### D. Menu to marketplace

1. An owner, operations manager, or chef submits an item.
2. Owner submissions are approved immediately; staff submissions await owner/operations-manager review.
3. Approval makes the item eligible for marketplace and POS queries; inventory/chef actions can make it unavailable.
4. Customers see approved items, with unavailable ones marked sold out.

### E. Survey, order, payment, fulfillment, and review

1. A customer completes/updates the religion-selected TPB survey.
2. The customer builds an order from an active restaurant's approved available menu.
3. The server requires the survey, writes order/survey tracking, and notifies participants.
4. Cash is confirmed by restaurant personnel; online payment is sent to PayMongo, subject to the confirmation limitations recorded below.
5. Restaurant personnel move the order through the service-type-specific fulfillment states.
6. After delivered/completed status, the customer can submit one review and photos; restaurant rating aggregates update.

### F. POS and logged orders

1. Owner/cashier selects database-priced approved menu items for dine-in/takeout.
2. In-person cash/card is immediately recorded paid/confirmed; online methods create a PayMongo checkout.
3. A separate owner/operations-manager endpoint records backdated paid orders for reporting when authorized by its access checks.

### G. Kitchen, grocery, and expense flow

1. Chef maintains inventory/suppliers/recipes and submits ingredient requisitions or grocery lists.
2. Operations manager acknowledges, approves with actual costs, or rejects.
3. Receiving/inspection actions can create/update inventory; approved spending can create expense data.
4. Recipe/inventory availability checks can disable menu items when requirements are not satisfied.

### H. Financial and demand reporting

1. Paid order data, approved inflows, expenses, traffic, reviews, and TPB data remain in their operational stores.
2. Owner/manager dashboards query and aggregate those records by requested period.
3. TPB owner analytics are platform-wide; credibility is restaurant-scoped and cached on the restaurant.

### I. HaCeRa readiness

1. Owner saves responses and optional supporting documents.
2. The application calculates five module scores and their arithmetic mean.
3. Owner may generate a PDF portfolio and explicitly publish the current score/band.
4. If enabled, owner may request AI advisory analysis. Validated advisory findings/actions are isolated in AI tables and do not alter official or readiness data.

## 6. External integrations and interfaces

### Google OAuth 2.0 — verified source integration

Direction: browser/application -> Google authorization; application -> Google token and user-info endpoints; returned profile -> `users`/session. Evidence: `getGoogleAuthUrl()`, `getGoogleAccessToken()`, `getGoogleUserInfo()`, and `auth/google_callback.php`.

The callback validates the single-use state value. The helper currently disables TLS peer verification for the token and user-info cURL calls; this is implemented behavior but not represented as a DFD flow distinction.

### PayMongo — partially verified end-to-end

Direction: application -> PayMongo checkout/session APIs; browser -> hosted checkout and return callback; optional PayMongo event -> webhook endpoint.

Checkout creation and POS session retrieval are implemented. Customer order payment confirmation is not consistently wired:

- the customer callback trusts the `status=success` return parameter instead of retrieving the checkout session;
- `api/paymongo_webhook.php` queries `orders.paymongo_payment_intent_id` and updates `paymongo_payment_status`, but those columns are absent from the current local `orders` schema and are not written by the active `place_order.php` path;
- `api/process_paymongo_payment.php` writes those fields but no application reference to this endpoint was found, and it also targets missing order columns/routes;
- the current `payments.reference_type` enum contains `order` but not `pos_order`, while the POS code writes/queries `pos_order`.

The Level 1 DFD may show the verified checkout request/redirect and a qualified “payment result/return” flow, but must not imply that the generic webhook is a fully verified confirmation path.

### Configurable AI provider — conditional implementation

Direction: HaCeRa owner request -> internal context builder -> external JSON endpoint -> validator -> AI insight tables. The provider is used only when environment flags and endpoint/key/model are configured. Uploaded file contents and personal/financial/customer data are explicitly excluded by `HaCeRaContextBuilder`. The database schema is installed locally, but environment enablement and a running queue scheduler were not verified.

### mPDF — internal library interface

The local Composer package converts generated HaCeRa HTML to a downloadable PDF. It is within the application deployment boundary and does not require an external network service.

## 7. Source evidence and uncertainties

### Key evidence map

| Finding | Primary implementation evidence |
|---|---|
| Bootstrap, DB, session timeout, runtime schema helpers | `config/app.php`, `config/database.php`, `config/constants.php` |
| Login, registration, Google identity | `auth/register.php`, `auth/login.php`, `auth/google_callback.php`, OAuth/session helpers in `includes/functions.php` |
| Role/staff routing and restaurant ownership | `includes/functions.php` (`getDashboardUrl`, `requireStaffRole`, `requireStaffAccess`, `getSessionRestaurantId`, `createUserSession`), `includes/staff_rbac.php` |
| Customer business application | `dashboard/customer/apply_business.php` |
| Admin review and restaurant/certificate provisioning | `dashboard/admin/business_applications.php`, `dashboard/admin/api/business_app_detail.php` |
| Directory, views, menu visibility, credibility | `dashboard/customer/restaurants.php`, `dashboard/customer/restaurant_view.php`, `includes/functions.php` (`logBusinessView`, `computeCredibilityScore`) |
| TPB survey and aggregates | `dashboard/customer/tpb_survey.php`, `api/submit_survey.php`, `api/tpb_analytics.php` |
| Customer ordering and cash confirmation | `dashboard/customer/api/place_order.php`, `dashboard/business_owner/api/confirm_cash_payment.php` |
| PayMongo checkout/callback/webhook paths | PayMongo helpers in `includes/functions.php`, `dashboard/customer/api/order_payment_callback.php`, `dashboard/business_owner/api/pos_payment_callback.php`, `api/paymongo_webhook.php`, `api/process_paymongo_payment.php` |
| Order fulfillment | `dashboard/business_owner/manage_orders.php`, `dashboard/business_owner/api/update_order_status.php`, `dashboard/customer/api/confirm_received.php` |
| POS and backdated orders | `dashboard/business_owner/pos_order_processing.php`, `dashboard/business_owner/api/log_backdated_order.php` |
| Reviews | `dashboard/customer/api/submit_review.php`, review queries/replies in `dashboard/business_owner/operations_manager.php` |
| Staff applications and assignment | `dashboard/customer/api/submit_staff_request.php`, `dashboard/business_owner/api/staff_app_action.php`, staff pages/APIs |
| Menu approval | `dashboard/business_owner/api/add_menu_item.php`, `update_menu_item.php`, `menu_item_approval.php` |
| Kitchen/grocery/inventory | `dashboard/business_owner/chef_dashboard.php`, `dashboard/business_owner/api/chef_action.php` |
| Expenses, inflows, traffic, reports | `dashboard/business_owner/operations_manager.php`, `owner_dashboard.php`, shared helpers in `includes/functions.php` |
| HaCeRa scoring and publishing | `includes/HaCeRaCalculator.php`, `dashboard/business_owner/hacera.php`, `dashboard/business_owner/api/hacera_api.php` |
| HaCeRa AI advisory | `HaCeRaContextBuilder.php`, `HaCeRaAIService.php`, `HaCeRaEvidenceValidator.php`, `HaCeRaInsightsRepository.php`, `HaCeRaAIProcessor.php`, `hacera_ai_api.php`, `scripts/process_hacera_ai_queue.php` |
| Admin/security surfaces | `dashboard/admin/`, `admin/`, `admin/admin_functions.php`, `includes/check_access.php` |
| Current table/column existence | read-only inspection of local MySQL `halal_system`; migrations under `database/`, `database_migrations/`, and `admin/admin_migration.sql` |

### Material uncertainties and discrepancies

1. **Legacy certification pipeline incomplete:** role constants, database tables, static permissions, business-owner application fragments, and menu links remain, but most specialist dashboard implementations and several linked endpoints are absent. It is excluded from the verified DFD.
2. **Payment confirmation mismatch:** active checkout creation, customer return, POS return, generic webhook, and the live schema do not share one consistent contract. Only the individual verified writes/calls should be shown; a reliable webhook settlement flow must not be inferred.
3. **Order lifecycle mismatch:** the main management page uses `delivered`; another API uses `completed`; customer confirmation expects `out_for_delivery`. The database accepts both terminal labels.
4. **Role-application documentation is stale:** `auth/apply_role.php` is admin-only in current code and immediately approves selected roles; ordinary users cannot use it as a normal approval-request path. `dashboard/admin/approve_roles.php` still handles pending records if they exist.
5. **Login lock behavior differs from documentation:** the traced login locks the account after five failed attempts and does not perform timed automatic unlock.
6. **Custom staff roles are only partially generalized:** the database and application form accept restaurant-specific roles, but session routing and major workspace guards are hard-coded for owner/operations manager/cashier/chef.
7. **Runtime schema mutation:** many normal requests create/alter tables. The local schema was inspected, but behavior on an unmigrated or differently versioned database may differ.
8. **Background execution not established:** the HaCeRa queue worker exists, but no scheduler definition was found. The optional daily revenue snapshot table likewise has no verified population job.
9. **Repository state:** the worktree already contains extensive user changes, untracked files, and staged/deleted legacy dashboard files. This investigation treated the working tree as the current implementation and did not modify application code.

## 8. Current diagram request

### Selected diagram

- **Type:** Data Flow Diagram (DFD)
- **Level:** Level 1
- **Scope assumption:** system-wide Level 1 view of the currently implemented Halal Keeps application. This follows the user's selection of Level 1 without naming a narrower module.

### Proposed Level 1 processes

The diagram should decompose Halal Keeps into these high-level implemented processes:

1. **Manage Accounts and Access** — registration/login/OAuth, sessions, system roles, staff-role resolution.
2. **Process Business and Staff Onboarding** — business applications/admin decisions/restaurant provisioning and staff applications/owner decisions.
3. **Manage Restaurant Catalogue and Operations** — restaurant profile, menu approval/availability, inventory, suppliers, recipes, groceries/requisitions.
4. **Capture Demand and Marketplace Engagement** — TPB responses/analytics, restaurant browsing/views, credibility, and reviews.
5. **Process Orders and Payments** — customer/POS order capture, survey linkage, cash confirmation, PayMongo checkout/qualified return, order status, notifications.
6. **Manage Finance and Reporting** — expenses, other inflows, paid-order reporting, traffic/demand/financial dashboard queries.
7. **Assess Halal Readiness** — HaCeRa responses/evidence, scoring, PDF output, publication, optional validated AI advisory.
8. **Administer and Monitor the Platform** — business review decisions, user/security settings, logs, classifications, and notification/audit records where not already emitted by a domain process.

### External entities to include

- Visitor/Customer
- Business Owner
- Restaurant Staff (with operations manager, cashier, and chef noted as sub-roles where useful)
- System Administrator
- Google OAuth
- PayMongo
- Configured AI Provider (conditional)

### Logical data stores to include

To keep a system-wide Level 1 DFD readable, physical tables should be grouped into logical stores:

- **D1 Accounts, Roles, and Staff** — users, roles, staff roles/assignments/applications, sessions as transient state
- **D2 Business Registry and Certificates** — business applications/branches/documents/materials, restaurants, certificates
- **D3 Catalogue, Engagement, and Reviews** — menu items, business views, reviews/photos, credibility cache/log
- **D4 Survey and Demand Data** — TPB responses/answers and survey-order tracking
- **D5 Orders, Payments, Status, and Notifications** — orders, payments, status history, cashier/user notifications
- **D6 Kitchen and Financial Operations** — inventory, suppliers, recipes, grocery/requisition records, expenses, inflows
- **D7 HaCeRa and AI Insights** — readiness responses/evidence, published restaurant readiness fields, AI runs/findings/actions/feedback
- **D8 Audit and Configuration** — activity/admin/login logs, settings, classifications, DLP-related records
- **F1 Upload Storage** — business, staff, menu, review, expense, kitchen, and HaCeRa files

### Principal flows to show

- identity/profile credentials and OAuth identity data;
- application/branch/document/raw-material submission and administrative decision;
- staff application and assignment decision;
- restaurant/profile/menu/availability data to marketplace browsing;
- TPB answers to survey results/analytics and survey verification into ordering;
- cart/POS order data, cash/payment request, PayMongo checkout URL/qualified result, fulfillment updates, and notifications;
- review/traffic inputs into ratings and credibility;
- inventory/grocery/expense/inflow inputs into operational and financial reports;
- HaCeRa answers/evidence into scores/publication/PDF, plus conditional structured advisory context/results;
- settings and domain events into monitoring/audit stores.

### Explicit exclusions

- The incomplete legacy HDP evaluator/auditor/laboratory/committee/president certification pipeline.
- Missing routes such as the legacy letter-of-intent entry, certificate payment, and certificate PDF endpoints.
- The generic PayMongo webhook as a confirmed working settlement path; the diagram should label the verified external flow conservatively.
- Browser layout, CSS/JavaScript widgets, CDN assets, every individual API endpoint, every table, and field-level CRUD.
- Future features, intended workflows from documentation, or relationships not supported by current code.

### Notation and layout requirements

- Use a left-to-right system-wide Level 1 layout with external entities outside a clearly marked Halal Keeps boundary.
- Number processes `1.0` through `8.0` and label all directed data flows with data nouns rather than control-only phrases.
- Use distinct visual forms for external entities, processes, logical data stores, and local file storage.
- Mark the AI provider/flow as conditional and payment-result flow as partially verified/qualified.
- PlantUML has no native standardized DFD notation. The future `.puml` should use consistent shapes and arrows inspired by common DFD conventions, but should not claim strict Yourdon, DeMarco, or Gane–Sarson compliance.
- The requested artifact is one coherent diagram only. No PlantUML source or rendered preview is authorized until this document is explicitly approved.
