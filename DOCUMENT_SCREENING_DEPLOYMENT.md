# Intelligent Document Screening: Deployment and Rollback

This is an additive, advisory-only module. It reuses existing application uploads and never approves, rejects, changes certification status, changes HaCeRa answers, or changes HRP values.

## Local OCR technology

The module uses local **Poppler** and **Tesseract** when explicitly configured:

- `pdftotext` is used first for text-based PDFs.
- `pdftoppm` renders scanned PDFs to a private temporary directory.
- `tesseract` reads JPEG, PNG, and rendered PDF pages, returning OCR text and word-level confidence.

No document content is sent to an OCR API. The rule-based classifier and field extractor are the default privacy-preserving implementation. An optional, disabled-by-default AI layer can generate a neutral explanation from document type, field-presence flags, and deterministic findings only; it never receives files, OCR text, names, addresses, identifiers, or storage paths. Any external AI activation still requires a data-processing, residency, cost, and authorization review.

## Install

1. Back up the database.
2. Apply [database/document_screening_migration.sql](database/document_screening_migration.sql).
3. Install Tesseract and Poppler on the application host. Set the three absolute executable paths in `.env`; do not rely on an untrusted `PATH` lookup.
4. Copy the `DOCUMENT_*` variables from `.env.example`. Keep `DOCUMENT_SCREENING_ENABLED=false` while testing.
5. Confirm PHP can execute the selected local binaries and that their temporary files are stored on an encrypted/restricted server volume.
6. Add a scheduler task every one to five minutes:

   ```text
   C:\xampp\php\php.exe C:\xampp\htdocs\halal_final_dev\scripts\process_document_screening_queue.php 5
   ```

7. Seed `certifier_recognition_records` only from a currently checked authoritative Philippine or JAKIM source. Record the source URL/reference and check date. Philippine and JAKIM entries must use separate `recognition_scheme` values.
8. Leave `DOCUMENT_SCREENING_AI_ENABLED=false` unless the privacy review for the optional redacted contextual-explanation provider is approved and its server-side credentials are configured.
9. Enable `DOCUMENT_SCREENING_ENABLED=true` only after using representative, permitted PDF/JPEG/PNG samples in a non-production environment.

## Security and privacy controls

- Only admins can start screening, access results, request clarification, and preview original documents through the protected handler.
- State-changing API actions require a per-session CSRF token.
- Source references are checked against the requested application; filesystem paths are resolved under `UPLOAD_DIR` to prevent traversal.
- File signatures, MIME types, extension, size, and PDF page count are validated before OCR.
- OCR binary paths must be explicit absolute files; process arguments are escaped.
- OCR text is retained only until `text_retention_until`; the queue worker purges expired text. Structured extracted fields and human review decisions remain auditable.
- OCR failures, unknown classifications, low confidence, and unavailable recognition evidence produce manual-review findings. They never block existing manual review.
- Applicant clarification notifications include only the reviewer-provided request, not internal screening or investigation notes.

## Operational limitations

- This repository's inspected local runtime has no detected Tesseract, Poppler `pdftotext`, or `pdftoppm` binary. OCR cannot be enabled here until they are installed and configured.
- MySQL was unavailable during implementation, so the migration and live queue/API workflow must be verified in staging before deployment.
- Recognition is evidence-based only. When no current local authoritative record exists, the result is `not_independently_verified`, never fake/invalid/fraudulent.
- The initial extractor is designed for legible, structured English-language DTI, SEC, BIR, permit, and Halal certificate layouts. Handwriting, poor scans, unfamiliar layouts, encrypted/password PDFs, QR-only content, and non-English documents require manual review.

## Rollback

1. Set `DOCUMENT_SCREENING_ENABLED=false` and disable the queue task. Existing application review continues unchanged.
2. Retain required audit data according to policy and take a database backup.
3. Run [database/document_screening_rollback.sql](database/document_screening_rollback.sql) only if feature records may be removed. It does not delete uploads, business applications, certificates, or HaCeRa data.
