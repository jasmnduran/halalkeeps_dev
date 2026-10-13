# HaCeRa System Reference

## Purpose and scope

HaCeRa (Halal Certification Readiness Assessment) is the Halal Keeps self-assessment tool for an active restaurant. It helps the restaurant owner record readiness against a fixed Halal checklist, identify gaps, calculate a **Halal Readiness Progress (HRP)** score, retain supporting files, produce an inspection-readiness portfolio, and optionally publish the current readiness result to the restaurant's public profile.

This document describes the deterministic assessment system only. It deliberately excludes all AI advisory/insights functionality.

HaCeRa is a readiness and gap-tracking tool. It does **not** certify a restaurant, approve documents, or make an official compliance decision.

## Entry points and components

| Component | Responsibility |
| --- | --- |
| `dashboard/business_owner/hacera.php` | Owner-facing five-module assessment wizard, live summary, gap display, publish action, and PDF download control. |
| `dashboard/business_owner/api/hacera_api.php` | Authenticated JSON endpoint for answers, evidence documents, scores, PDF generation, and publishing. |
| `includes/HaCeRaCalculator.php` | Authoritative checklist definitions, weighted scoring, readiness bands, and gap sorting. |
| `includes/functions.php` | Shared file-upload and activity-logging helpers. |
| `halal_restaurants` | Stores the published HRP, band, and publication date for display elsewhere in the platform. |

## Access and ownership

- The assessment is available only to a logged-in user whose role is `ROLE_BUSINESS_OWNER`.
- Restaurant staff accounts are blocked, including cashier, chef, and operations-manager sub-roles. The session's `staff_role_key` must be `null`.
- The endpoint resolves one active restaurant where `halal_restaurants.business_owner_id` matches the logged-in user. The assessment data is therefore restaurant-scoped.
- Document deletion verifies that the document belongs to the current restaurant before removing its database row and attempting to remove its stored file.
- Uploaded documents and publishing events are written to the activity log. Saving an answer does not create a separate activity-log record.

## Assessment workflow

```text
Business owner
  -> Open HaCeRa assessment wizard
  -> Complete the five checklist modules
       -> Each answer is saved immediately
       -> Supporting evidence can be uploaded per checklist item
  -> Review module results, overall HRP, and prioritised gaps
  -> Optional: download Inspection Readiness Portfolio (PDF)
  -> Optional: publish the current HRP and readiness band
       -> Owner dashboard and customer-facing restaurant pages show the published result
```

The wizard has six steps: one for each of the five modules and a results step. Answers may be revisited and changed; the API recalculates the score from the current saved responses after every relevant action.

## Checklist model

Each checklist item has an ID, module, label, type, weight, and optional flag.

- `yesno` items ask the owner to confirm a condition.
- `upload` items describe a document or record that may be uploaded as supporting evidence. They still have a saved `yes`, `no`, or `na` response.
- Weights are `3` (High), `2` (Standard), and `1` (Informational).
- Some items are marked optional in the checklist metadata. In the current implementation, their weights are still included in score denominators; the flag is displayed as an advisory label in the portfolio.

### Module 1 — Conditions of Certification

| ID | Checklist item | Type | Weight |
| --- | --- | --- | --- |
| 101 | DTI / SEC / CDA Business Registration | upload | 2 |
| 102 | Mayor's Business Permit (LGU) | upload | 2 |
| 103 | Barangay Clearance / Permit | upload | 2 |
| 104 | Sanitary Permit | upload | 2 |
| 105 | Fire Safety Inspection Certificate | upload | 2 |
| 106 | BIR Certificate of Registration | upload | 2 |
| 107 | FDA License to Operate (if applicable) | upload, optional | 1 |
| 108 | DENR / Environmental Compliance Certificate | upload, optional | 1 |

Total available weight: **14**.

### Module 2 — Raw Materials & Ingredients

| ID | Checklist item | Type | Weight |
| --- | --- | --- | --- |
| 201 | Full Ingredients / Raw Materials List with Sources | upload | 3 |
| 202 | Halal Certificates for All Meat & Poultry Products | upload | 3 |
| 203 | Halal Certificates for Imported Ingredients | upload | 3 |
| 204 | Raw Materials / Ingredients Traceability Matrix | upload | 3 |
| 205 | No Alcohol / Intoxicants Used in Recipes | yesno | 3 |
| 206 | No Cross-Contamination with Non-Halal Sources | yesno | 3 |
| 207 | Packaging Materials Halal Certificates (if needed) | upload | 2 |
| 208 | Supplier Halal Verification Records | upload | 2 |

Total available weight: **22**.

### Module 3 — Processing & Logistics

| ID | Checklist item | Type | Weight |
| --- | --- | --- | --- |
| 301 | Dedicated Halal Transport (no shared haram loads) | yesno | 3 |
| 302 | Processing Tools Free from Animal Hair / Impurities | yesno | 3 |
| 303 | Alcohol and Liquor Prohibition in Kitchen | yesno | 3 |
| 304 | Flow Chart of Product Processing | upload | 2 |
| 305 | Waste Disposal Management Plan | upload | 2 |
| 306 | Pest Control Programme Documentation | upload | 2 |
| 307 | GMP / HACCP / SSOP / ISO Certificates | upload, optional | 1 |
| 308 | Warehouse Halal Storage System Description | upload | 2 |
| 309 | Cold-Chain Integrity for Halal Meat | yesno | 2 |

Total available weight: **20**.

### Module 4 — Packaging & Labelling

| ID | Checklist item | Type | Weight |
| --- | --- | --- | --- |
| 401 | Halal Logo/Mark Displayed on Packaging | yesno | 3 |
| 402 | Product Name in Arabic and English | yesno | 3 |
| 403 | Complete Ingredient List on Label | yesno | 3 |
| 404 | Net Weight / Volume Declared | yesno | 2 |
| 405 | Manufacturer / Distributor Name & Address | yesno | 2 |
| 406 | Country of Origin Declared | yesno | 2 |
| 407 | Production / Expiry Dates Shown | yesno | 2 |
| 408 | Allergen Declarations Present | yesno | 2 |
| 409 | Lot / Batch Number for Traceability | yesno | 2 |
| 410 | Label Artwork / Packaging Samples Submitted | upload | 2 |

Total available weight: **23**.

### Module 5 — Premises & Welfare

| ID | Checklist item | Type | Weight |
| --- | --- | --- | --- |
| 501 | Premises Fenced / Secured from External Contamination | yesno | 2 |
| 502 | Dedicated Ablution (Wudhu) Facilities | yesno | 3 |
| 503 | Prayer Room / Musalla Available for Staff | yesno | 3 |
| 504 | Separate Changing Rooms (Male / Female) | yesno | 2 |
| 505 | At Least 2 Muslim Cooks Employed | yesno | 3 |
| 506 | At Least 2 Muslim Crew Members Employed | yesno | 3 |
| 507 | Kitchen Layout Plan Submitted | upload | 2 |
| 508 | Halal Assurance System (HAS) Manual | upload | 2 |
| 509 | Proof of Dedicated Halal Prayer Room | upload, optional | 1 |

Total available weight: **21**.

## Answers, documents, and score eligibility

### Saved responses

Each restaurant can have one response per checklist item. Valid values are:

| Value | Score effect |
| --- | --- |
| `yes` | The item is complete and earns its full weight. |
| `no` | The item is a gap and earns no weight. |
| `na` | The item is a gap and earns no weight. |

The answer is saved through an upsert, so a later response replaces the prior response and updates its notes, responder, and timestamp.

### Supporting evidence

Evidence files are attached to a checklist item and are kept independently of the response. Multiple files may be stored for the same item. The accepted extensions are `pdf`, `png`, `jpg`, `jpeg`, `docx`, and `doc`; the shared upload helper limits each file to 10 MB.

Files are stored under `uploads/hacera_docs/`, while their path, original filename, item ID, uploader, and upload time are recorded in the database.

> Important: in the current calculator, a document upload does not mark an item complete and does not increase HRP. Only a saved `yes` response earns weight. Documents are supporting references for the owner and PDF portfolio; they are not automatically verified.

## Scoring and readiness bands

The calculator has no AI or external scoring dependency. It uses the fixed checklist above.

For a module:

```text
Module readiness (%) =
  sum(weights of items answered "yes") / sum(weights of all items in module) * 100
```

The score is rounded to one decimal place. The overall HRP is the arithmetic mean of the five module percentages, also rounded to one decimal place:

```text
HRP = (Module 1 + Module 2 + Module 3 + Module 4 + Module 5) / 5
```

It is not a single total of weights across every module. This gives every module the same overall influence despite their different numbers of items and weights.

| HRP range | Readiness band |
| --- | --- |
| 90.0–100.0% | Inspection Ready |
| 70.0–89.9% | Near Ready |
| 50.0–69.9% | In Progress |
| 25.0–49.9% | Early Stage |
| Below 25.0% | Not Started |

The results view also creates a cross-module gap list ordered by weight, so High (`3`) gaps appear before Standard (`2`) and Informational (`1`) gaps.

## Data storage

The API creates the following tables idempotently if they are not already present.

| Table | Purpose | Key fields / constraints |
| --- | --- | --- |
| `hacera_modules` | Database representation reserved for module metadata. | `id` primary key, title, description, sort order. The live checklist is currently defined in PHP. |
| `hacera_checklists` | Database representation reserved for checklist metadata. | `id` primary key; module, type, weight, optional flag, sort order. The live checklist is currently defined in PHP. |
| `business_hacera_responses` | Restaurant answers and optional notes. | Unique `(restaurant_id, checklist_item_id)`; records responder and created/updated timestamps. |
| `hacera_documents` | Evidence-file metadata. | Restaurant/item indexes; permits multiple documents for a single item. |

Publishing also adds these fields to `halal_restaurants`, if absent:

| Column | Meaning |
| --- | --- |
| `hacera_hrp` | Published HRP, `DECIMAL(5,1)`. |
| `hacera_band` | Published band label. |
| `hacera_published_at` | Timestamp of the owner's most recent publish action. |

## API actions

All actions use `dashboard/business_owner/api/hacera_api.php`, run as the authenticated restaurant owner, and return JSON except the PDF download.

| Action | Request | Result |
| --- | --- | --- |
| `save_response` | POST `checklist_item_id`, `response_value`, optional `notes` | Validates the item and value, upserts the response, and returns the refreshed HRP and module scores. |
| `upload_document` | Multipart POST `checklist_item_id`, `doc_file` | Validates and stores a permitted file, logs the upload, and returns document metadata plus refreshed scores. |
| `delete_document` | POST `doc_id` | Checks restaurant ownership, deletes the metadata row, attempts file removal, and returns refreshed scores. |
| `get_scores` | GET or POST action | Returns the current HRP, band, and per-module scores. |
| `generate_pdf` | GET action | Streams the Inspection Readiness Portfolio as a PDF. |
| `publish_score` | POST action | Persists the currently calculated HRP, band, and publish timestamp to the active restaurant record; logs the action. |

## PDF portfolio

The Inspection Readiness Portfolio is generated with mPDF (`mpdf/mpdf`). It contains:

- the restaurant name, address, generation date, overall HRP, and readiness band;
- a module summary with score and completed-item count;
- a page for each module with its score, earned and total weight, every checklist item, and its status;
- saved yes/no/na responses and notes for condition items;
- filenames and upload dates for document evidence; and
- an outstanding-gap list ordered by priority within each module.

The generated filename follows this format:

```text
HaCeRa_Portfolio_<restaurant_name>_<YYYYMMDD>.pdf
```

## Publishing and platform visibility

Publishing is deliberate: completing or changing an assessment updates the live calculation but does not automatically update the public restaurant record. When the owner selects **Publish Score**, the current HRP and band are copied to `halal_restaurants`.

The published fields are shown in:

- the business owner's dashboard; and
- customer restaurant listings and the customer restaurant detail page.

The HRP also contributes to the platform's restaurant credibility-score calculation alongside certification status. A published readiness percentage is an informational readiness measure, not a certification result.

## Operational notes and limitations

- The fixed checklist in `HaCeRaCalculator::getModules()` is the source of truth used by validation and scoring. The `hacera_modules` and `hacera_checklists` tables are created for schema support but are not read by the current scoring engine.
- The endpoint returns a JSON error for an unknown action, invalid response value, unknown checklist item, missing active restaurant, or unauthorised user.
- If mPDF is unavailable or PDF generation fails, the endpoint returns a JSON error rather than a downloadable PDF.
- A previously published value remains on the public profile until the owner publishes again; it does not automatically reflect later unpublished edits.
- The system records owner assertions and uploaded file references. It does not, by itself, validate a file's authenticity or issue Halal certification.

## Maintenance reference

To change the assessment design, update `includes/HaCeRaCalculator.php` first. Changes there affect item validation, scores, bands, results, PDF contents, and gap reporting. Any checklist change should be assessed carefully because existing saved responses are linked to checklist item IDs and historical published HRPs are not versioned.
