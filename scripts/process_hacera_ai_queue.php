<?php
/**
 * CLI worker for queued HaCeRa AI advisory analyses.
 * Example: C:\xampp\php\php.exe scripts\process_hacera_ai_queue.php 5
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/HaCeRaContextBuilder.php';
require_once __DIR__ . '/../includes/HaCeRaAIService.php';
require_once __DIR__ . '/../includes/HaCeRaEvidenceValidator.php';
require_once __DIR__ . '/../includes/HaCeRaInsightsRepository.php';
require_once __DIR__ . '/../includes/HaCeRaAIProcessor.php';

if (!HaCeRaAIService::isFeatureEnabled()) {
    fwrite(STDERR, "HaCeRa AI is disabled. No jobs processed.\n");
    exit(0);
}

$service = new HaCeRaAIService();
if (!$service->isConfigured()) {
    fwrite(STDERR, "HaCeRa AI provider is not configured.\n");
    exit(1);
}

$limit = isset($argv[1]) ? max(1, min(50, (int)$argv[1])) : 5;
$repository = new HaCeRaInsightsRepository($conn);
if (!$repository->hasSchema()) {
    fwrite(STDERR, "Run database/hacera_ai_insights_migration.sql before starting the worker.\n");
    exit(1);
}

$processor = new HaCeRaAIProcessor(
    new HaCeRaContextBuilder($conn),
    $service,
    new HaCeRaEvidenceValidator(),
    $repository,
    $conn
);
$count = $processor->processQueued($limit);
fwrite(STDOUT, "Processed {$count} HaCeRa AI job(s).\n");
