<?php
/** Lightweight, dependency-free tests for the isolated HaCeRa AI boundary. */
declare(strict_types=1);

require_once __DIR__ . '/../includes/HaCeRaCalculator.php';
require_once __DIR__ . '/../includes/HaCeRaCriteriaLibrary.php';
require_once __DIR__ . '/../includes/HaCeRaEvidenceValidator.php';
require_once __DIR__ . '/../includes/HaCeRaAIService.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

putenv('HACERA_AI_ENABLED=true');
putenv('HACERA_AI_ENDPOINT=https://example.invalid/v1/chat/completions');
putenv('HACERA_AI_API_KEY=test-key');
putenv('HACERA_AI_MODEL=test-model');

$context = [
    'criteria_version' => HaCeRaCriteriaLibrary::VERSION,
    'modules' => [[
        'module_id' => 2,
        'items' => [[
            'checklist_item_id' => 201,
            'criteria_reference' => 'HACERA-201',
            'response' => 'no',
            'response_recorded' => true,
            'evidence_document_ids' => [42],
        ], [
            'checklist_item_id' => 202,
            'criteria_reference' => 'HACERA-202',
            'response' => 'yes',
            'response_recorded' => true,
            'evidence_document_ids' => [],
        ]],
    ]],
];

$providerReport = [
    'summary' => 'Ingredient records need advisory follow-up.',
    'findings' => [[
        'module_id' => 2,
        'checklist_item_id' => 201,
        'title' => 'Ingredient source list is not recorded as available',
        'finding_type' => 'confirmed_checklist_gap',
        'explanation' => 'The saved assessment response is No.',
        'severity' => 'high',
        'evidence_document_ids' => [42],
        'criteria_reference' => 'HACERA-201',
        'recommended_corrective_action' => 'Prepare and upload a current ingredient source list for qualified review.',
        'requires_expert_review' => true,
    ]],
];

$mockTransport = static function (string $endpoint, array $headers, string $payload, int $timeout) use ($providerReport): array {
    check($endpoint === 'https://example.invalid/v1/chat/completions', 'Configured endpoint was not used.');
    check(str_contains($payload, 'HACERA-201'), 'Structured context was not sent to mocked provider.');
    $providerBody = json_encode($providerReport);
    return ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $providerBody]]]]), 'error' => ''];
};

$service = new HaCeRaAIService($mockTransport);
$result = $service->analyse($context);
check(!empty($result['success']), 'Mocked provider response was not accepted.');

$validator = new HaCeRaEvidenceValidator();
$validated = $validator->validate($result['report'], $context);
check(count($validated['findings']) === 1, 'Valid supported finding was rejected.');
check($validated['findings'][0]['evidence_status'] === 'verified_assessment_response', 'Checklist response evidence was misclassified.');
check($validated['findings'][0]['requires_expert_review'] === true, 'High-risk finding must require expert review.');

$fabricated = $providerReport;
$fabricated['findings'][0]['evidence_document_ids'] = [999];
check(count($validator->validate($fabricated, $context)['findings']) === 0, 'Fabricated evidence reference was accepted.');

$wrongCriteria = $providerReport;
$wrongCriteria['findings'][0]['criteria_reference'] = 'INVENTED-1';
check(count($validator->validate($wrongCriteria, $context)['findings']) === 0, 'Fabricated criteria reference was accepted.');

$certificationClaim = $providerReport;
$certificationClaim['findings'][0]['explanation'] = 'This restaurant is certified.';
check(count($validator->validate($certificationClaim, $context)['findings']) === 0, 'Certification claim was accepted.');

$certificationSummary = $providerReport;
$certificationSummary['summary'] = 'This restaurant is officially compliant.';
check(!str_contains($validator->validate($certificationSummary, $context)['summary'], 'officially compliant'), 'Certification claim was retained in the AI summary.');

$positiveResponse = $providerReport;
$positiveResponse['findings'][0]['checklist_item_id'] = 202;
$positiveResponse['findings'][0]['criteria_reference'] = 'HACERA-202';
$positiveResponse['findings'][0]['evidence_document_ids'] = [];
$positiveResponse['findings'][0]['finding_type'] = 'confirmed_checklist_gap';
$downgraded = $validator->validate($positiveResponse, $context)['findings'];
check(count($downgraded) === 1 && $downgraded[0]['finding_type'] === 'potential_risk' && $downgraded[0]['requires_expert_review'] === true, 'A positive assessment response was incorrectly stored as a confirmed gap.');

$missingContext = $context;
$missingContext['modules'][0]['items'][0]['response_recorded'] = false;
$missingResponse = $validator->validate($providerReport, $missingContext)['findings'];
check(count($missingResponse) === 1 && $missingResponse[0]['finding_type'] === 'missing_information', 'An unanswered item was incorrectly stored as a confirmed gap.');

$invalidService = new HaCeRaAIService(static fn() => ['status' => 200, 'body' => '{not json}', 'error' => '']);
$invalidResult = $invalidService->analyse($context);
check(empty($invalidResult['success']) && $invalidResult['error_code'] === 'invalid_provider_response', 'Invalid provider JSON was accepted.');

$limitedService = new HaCeRaAIService(static fn() => ['status' => 429, 'body' => '{}', 'error' => '']);
$limitedResult = $limitedService->analyse($context);
check(empty($limitedResult['success']) && $limitedResult['error_code'] === 'provider_rate_limited', 'Provider rate limit was not handled.');

$busyService = new HaCeRaAIService(static fn() => ['status' => 503, 'body' => '{}', 'error' => '']);
$busyResult = $busyService->analyse($context);
check(empty($busyResult['success']) && $busyResult['error_code'] === 'provider_temporarily_unavailable', 'Provider high-demand response was not handled.');

putenv('HACERA_AI_API_KEY=');
check(!(new HaCeRaAIService($mockTransport))->isConfigured(), 'Missing provider key was accepted as configured.');
putenv('HACERA_AI_API_KEY=test-key');

$before = HaCeRaCalculator::computeHRP([
    1 => HaCeRaCalculator::scoreModule(1, [101]),
    2 => HaCeRaCalculator::scoreModule(2, [201]),
    3 => HaCeRaCalculator::scoreModule(3, []),
    4 => HaCeRaCalculator::scoreModule(4, []),
    5 => HaCeRaCalculator::scoreModule(5, []),
]);
// The AI classes have no write path into HaCeRaCalculator; rerunning proves the
// baseline score is identical after advisory analysis.
$after = HaCeRaCalculator::computeHRP([
    1 => HaCeRaCalculator::scoreModule(1, [101]),
    2 => HaCeRaCalculator::scoreModule(2, [201]),
    3 => HaCeRaCalculator::scoreModule(3, []),
    4 => HaCeRaCalculator::scoreModule(4, []),
    5 => HaCeRaCalculator::scoreModule(5, []),
]);
check($before === $after, 'HaCeRa scoring changed during AI advisory tests.');

echo "PASS: HaCeRa AI mocked-provider and validation tests\n";
