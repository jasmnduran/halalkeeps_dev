<?php
/** CLI worker for queued document screening. Example: php scripts/process_document_screening_queue.php 5 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/DocumentOCRService.php';
require_once __DIR__ . '/../includes/DocumentScreeningRepository.php';
require_once __DIR__ . '/../includes/DocumentClassificationService.php';
require_once __DIR__ . '/../includes/DocumentFieldExtractor.php';
require_once __DIR__ . '/../includes/DocumentConsistencyValidator.php';
require_once __DIR__ . '/../includes/CertificateRecognitionService.php';
require_once __DIR__ . '/../includes/DocumentScreeningAIService.php';
require_once __DIR__ . '/../includes/DocumentScreeningService.php';

if (!DocumentOCRService::isFeatureEnabled()) {
    fwrite(STDERR, "Document Screening is disabled. No jobs processed.\n");
    exit(0);
}
$repository = new DocumentScreeningRepository($conn);
if (!$repository->hasSchema()) {
    fwrite(STDERR, "Run database/document_screening_migration.sql before starting the worker.\n");
    exit(1);
}
$limit = isset($argv[1]) ? max(1, min(50, (int)$argv[1])) : 5;
$processed = (new DocumentScreeningService($repository))->processQueued($limit);
$purged = $repository->purgeExpiredText();
fwrite(STDOUT, "Processed {$processed} document-screening job(s); purged {$purged} expired OCR text record(s).\n");
