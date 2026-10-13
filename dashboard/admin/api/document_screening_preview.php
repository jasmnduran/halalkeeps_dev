<?php
/** Streams a referenced application upload only after administrative authorization. */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/DocumentScreeningRepository.php';

requireLogin();
requireRole([ROLE_ADMIN]);

$applicationId = (int)($_GET['application_id'] ?? 0);
$sourceType = trim((string)($_GET['source_type'] ?? ''));
$sourceId = (int)($_GET['source_document_id'] ?? 0);
if ($applicationId < 1 || $sourceType === '' || $sourceId < 1) {
    http_response_code(400);
    exit('Invalid document reference.');
}
$repository = new DocumentScreeningRepository($conn);
$document = $repository->getApplicationDocument($applicationId, $sourceType, $sourceId);
$path = $document ? $repository->resolveUploadPath((string)$document['file_path']) : null;
if ($path === null) {
    http_response_code(404);
    exit('Document not found.');
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($path);
if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
    http_response_code(415);
    exit('Document format is not previewable.');
}
$name = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename((string)($document['original_name'] ?? 'document'))) ?: 'document';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
