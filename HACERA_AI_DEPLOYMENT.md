# HaCeRa AI Readiness Insights: Setup and Rollback

HaCeRa AI Readiness Insights is an advisory-only extension. It does not modify `HaCeRaCalculator`, `business_hacera_responses`, published HRP values, customer indicators, or certification decisions.

## Prerequisites

- PHP 8.0+ with cURL and TLS certificate validation enabled.
- MySQL/MariaDB database backup capability.
- An OpenAI-compatible provider endpoint that supports a JSON-object response.
- A scheduled-task facility for the recommended queue worker, or an explicit decision to use development-only inline processing.

Document intelligence is intentionally disabled in this release. The current upload facility accepts PDF/images/DOC/DOCX, but does not provide a hardened OCR/text-extraction sandbox. Only document IDs and the checklist item they belong to are used as evidence references. A later release needs malware scanning, isolated OCR, text-size limits, encrypted temporary storage, and human validation before document text can be sent to a provider.

## Install

1. Back up the database. For example, on XAMPP: `C:\xampp\mysql\bin\mysqldump.exe -u root halal_system > halal_system_before_hacera_ai.sql`.
2. Apply [database/hacera_ai_insights_migration.sql](database/hacera_ai_insights_migration.sql) in phpMyAdmin or with the MySQL CLI.
3. Copy the HaCeRa AI variables from `.env.example` to the server `.env`. Keep `HACERA_AI_ENABLED=false` until testing and access review are complete.
4. Set `HACERA_AI_ENDPOINT`, `HACERA_AI_API_KEY`, and `HACERA_AI_MODEL`. Keep the key in the server environment or `.env`; never put it in JavaScript or source control.
5. Set a conservative `HACERA_AI_MAX_RUNS_PER_DAY` per restaurant. The adapter retries temporary provider/network failures with exponential backoff by default; adjust `HACERA_AI_MAX_RETRIES` (0–4, default 3) only if necessary.
6. Use the queue worker in production. On Windows Task Scheduler, run every one to five minutes:

   ```text
   C:\xampp\php\php.exe C:\xampp\htdocs\halal_final_dev\scripts\process_hacera_ai_queue.php 5
   ```

   `HACERA_AI_PROCESS_INLINE=true` is intended only for development or hosting without a scheduler. It makes the owner request wait for the provider response.
7. Set `HACERA_AI_ENABLED=true` only after the tests below pass. Restart PHP-FPM/Apache if the host caches environment variables.

## Verify

Run the mocked-provider test without live provider credits:

```text
C:\xampp\php\php.exe tests\hacera_ai_unit_test.php
```

Then, in a non-production account, verify:

- An owner can generate an advisory report for only their own restaurant.
- A non-owner/staff account receives `403` from the AI endpoint.
- Invalid CSRF tokens are rejected for generate/action/feedback mutations.
- A new assessment response makes the old report show as outdated.
- Action statuses persist only for the matching restaurant.
- Existing HaCeRa assessment, score publication, certification screens, ordering, and finance screens continue to operate normally.

## Security model

- The browser never receives provider credentials.
- Only the active restaurant owner can call the endpoint; restaurant IDs are never accepted from the browser.
- The request contains no owner/customer contact data, addresses, payment, financial records, document paths, document names, notes, or document contents.
- AI output is treated as untrusted: checklist module/item pairs, criteria references, and evidence IDs are validated against the current authorized assessment before storage.
- Fabricated evidence/criteria, unsupported certification language, and contradictory confirmed gaps are rejected or downgraded to human-review risk.
- Reports are advisory only. High/critical/potential-risk findings require qualified human review.
- Per-restaurant daily limits, input-hash deduplication, queue records, and activity logging control cost and provide traceability.

## Rollback

1. Set `HACERA_AI_ENABLED=false` and restart PHP/Apache if needed. This disables the UI and endpoint without affecting prior HaCeRa behavior.
2. Disable the scheduled worker.
3. If feature-only records must be removed after retaining a backup, run [database/hacera_ai_insights_rollback.sql](database/hacera_ai_insights_rollback.sql). It drops only the five `hacera_ai_*` tables.

No production migration or deployment is performed by this implementation.
