<?php
/** Dependency-free unit tests for deterministic document-screening rules. */
declare(strict_types=1);

require_once __DIR__ . '/../includes/DocumentClassificationService.php';
require_once __DIR__ . '/../includes/DocumentFieldExtractor.php';
require_once __DIR__ . '/../includes/DocumentConsistencyValidator.php';
require_once __DIR__ . '/../includes/DocumentScreeningAIService.php';

function document_screening_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$classifier = new DocumentClassificationService();
$dti = $classifier->classify('Department of Trade and Industry\nCertificate of Business Name Registration\nDTI');
document_screening_check($dti['document_type'] === 'dti_registration', 'DTI document was not classified correctly.');

$certificate = $classifier->classify('HALAL CERTIFICATE\nHALAL CERTIFICATION\nCertified Halal', 'business_permit');
document_screening_check($certificate['document_type'] === 'halal_certificate', 'Halal certificate was not classified correctly.');
document_screening_check($certificate['unexpected_type'] === true, 'Unexpected uploaded category was not flagged.');

$extractor = new DocumentFieldExtractor();
$text = "Department of Trade and Industry\nBusiness Name: Al Noor Foods, Inc.\nRegistration No: DTI-00123\nBusiness Address: Barangay 1, Davao City\nIssue Date: 2025-01-15\n";
$extracted = $extractor->extract($text, $dti, 0.92);
document_screening_check($extracted['fields']['business_name']['raw_value'] === 'Al Noor Foods, Inc.', 'Business name extraction failed.');
document_screening_check($extracted['fields']['registration_number']['normalized_value'] === 'DTI00123', 'Registration number normalization failed.');
document_screening_check($extracted['fields']['issue_date']['normalized_value'] === '2025-01-15', 'Issue date normalization failed.');
document_screening_check($extracted['fields']['expiration_date']['raw_value'] === null, 'Missing values must be null rather than fabricated.');

$application = [
    'business_name' => 'Al Noor Foods Incorporated',
    'registration_number' => 'DTI-00123',
    'branches' => [[
        'address_street' => '', 'address_barangay' => 'Barangay 1', 'address_city' => 'Davao City',
        'address_province' => 'Davao del Sur', 'address_region' => '', 'address_zip' => '',
    ]],
];
$validator = new DocumentConsistencyValidator();
$findings = $validator->validate($application, $extracted['fields'], 'dti_registration');
$byRule = [];
foreach ($findings as $finding) $byRule[$finding['rule_id']] = $finding;
document_screening_check($byRule['business_name_match']['outcome'] === 'matched', 'Legal-form business-name variation should match.');
document_screening_check($byRule['registration_number_match']['outcome'] === 'matched', 'Matching registration number was not accepted.');

$different = $extracted['fields'];
$different['registration_number']['raw_value'] = 'DTI-00999';
$mismatch = $validator->validate($application, $different, 'dti_registration');
$registrationFinding = array_values(array_filter($mismatch, static fn(array $f): bool => $f['rule_id'] === 'registration_number_match'))[0];
document_screening_check($registrationFinding['outcome'] === 'mismatch', 'Registration mismatch was not flagged.');
document_screening_check(str_contains($registrationFinding['explanation'], 'not proof'), 'Mismatch language must not make an authenticity claim.');

$expiredFields = $extracted['fields'];
$expiredFields['expiration_date'] = [
    'raw_value' => '2020-01-01', 'normalized_value' => '2020-01-01', 'confidence' => 0.9,
    'source_page' => 1, 'source_location' => 'test', 'is_uncertain' => false,
];
$expired = $validator->validate($application, $expiredFields, 'dti_registration');
document_screening_check(count(array_filter($expired, static fn(array $f): bool => $f['outcome'] === 'expired')) === 1, 'Expired document was not flagged.');

$unknown = $classifier->classify('unstructured handwriting without recognizable issuer');
document_screening_check($unknown['document_type'] === 'unknown_document' && $unknown['requires_manual_review'], 'Unknown document must require manual review.');

$birNoticeText = "This establishment MUST issue\nRECEIPT/INVOICE\nAl Noor Foods Inc.\nBusiness Name\nAl Noor Foods Incorporated\nRegistered Name\n123-456-789-000\nTIN and Branch Code\n";
$birNotice = $classifier->classify($birNoticeText, 'bir_notice');
document_screening_check($birNotice['document_type'] === 'bir_invoice_notice', 'BIR invoice notice was not classified correctly.');
$birFields = $extractor->extract($birNoticeText, $birNotice, 0.90)['fields'];
document_screening_check($birFields['business_name']['raw_value'] === 'Al Noor Foods Inc.', 'BIR Business Name extraction failed.');
document_screening_check($birFields['registered_name']['raw_value'] === 'Al Noor Foods Incorporated', 'BIR Registered Name extraction failed.');
document_screening_check($birFields['tin_branch_code']['normalized_value'] === '123456789000', 'BIR TIN and Branch Code extraction failed.');
document_screening_check($birFields['bir_invoice_notice_markers']['raw_value'] !== null, 'Required BIR invoice-notice layout markers were not recognised.');

$halalCertificateText = "Certificate of Registration\nThis certificate of registration is awarded to\nTINHAT Boutique Hotel and Restaurant\n407-B, Artiaga St. Barangay 36-D, Davao City, Philippines\nPrime Certification and Inspection Asia Pacific Inc. certifies that the above Organization has been assessed\nUAE.S 2055-1: 2015 and\nPhilippine National Standard 2067:2008\nHalal Food-General Guideline\nScope of Certification\nProviding Halal Hospitality Room Services and Preparation of Halal Food\nCertificate Number: NCMF-055-23-21-02-0000\nIssue Date: 16 January 2023\nValid up to: 15 January 2026\n";
$halalClass = $classifier->classify($halalCertificateText, 'halal_certificate');
document_screening_check($halalClass['document_type'] === 'halal_certificate', 'Halal certificate layout was not classified correctly.');
$halalFields = $extractor->extract($halalCertificateText, $halalClass, 0.91)['fields'];
document_screening_check($halalFields['certified_establishment']['raw_value'] === 'TINHAT Boutique Hotel and Restaurant', 'Certified establishment extraction failed.');
document_screening_check($halalFields['certifying_body']['raw_value'] === 'Prime Certification and Inspection Asia Pacific Inc.', 'Certifying body extraction failed.');
document_screening_check($halalFields['certificate_number']['normalized_value'] === 'NCMF0552321020000', 'Certificate number extraction failed.');
document_screening_check($halalFields['expiration_date']['normalized_value'] === '2026-01-15', 'Valid-up-to date extraction failed.');
document_screening_check(str_contains((string)$halalFields['certificate_scope']['raw_value'], 'Providing Halal Hospitality Room Services'), 'Certificate-scope extraction failed.');
document_screening_check(str_contains((string)$halalFields['certification_scheme']['raw_value'], 'UAE.S 2055-1'), 'Certificate-standard extraction failed.');

// Small certificate text near seals is commonly read with trailing characters
// or a split label/value. The fallback must keep those values reviewable.
$noisyHalalCertificateText = "Certificate of Registration\nHalal Food-General Guideline\nCertificate Number: NCMF-055-23-21-02-0000h\nIssue Date: 16 January 20235\nValid Up to\n15 January 2026\n";
$noisyHalalClass = $classifier->classify($noisyHalalCertificateText, 'halal_certificate');
$noisyHalalFields = $extractor->extract($noisyHalalCertificateText, $noisyHalalClass, 0.75)['fields'];
document_screening_check($noisyHalalFields['certificate_number']['normalized_value'] === 'NCMF0552321020000', 'Noisy certificate-number fallback failed.');
document_screening_check($noisyHalalFields['issue_date']['normalized_value'] === '2023-01-16', 'Noisy issue-date fallback failed.');
document_screening_check($noisyHalalFields['expiration_date']['normalized_value'] === '2026-01-15', 'Split valid-up-to date fallback failed.');

putenv('DOCUMENT_SCREENING_AI_ENABLED=true');
putenv('DOCUMENT_SCREENING_AI_ENDPOINT=https://example.invalid/v1/chat/completions');
putenv('DOCUMENT_SCREENING_AI_API_KEY=test-key');
putenv('DOCUMENT_SCREENING_AI_MODEL=test-model');
$ai = new DocumentScreeningAIService(static function (string $endpoint, array $headers, string $payload, int $timeout): array {
    document_screening_check(!str_contains($payload, 'Al Noor Foods'), 'AI context must not include a business name.');
    document_screening_check(!str_contains($payload, 'DTI00123'), 'AI context must not include a registration number.');
    return ['status' => 200, 'body' => json_encode(['explanation' => 'The deterministic comparison findings should be reviewed alongside the original document.']), 'error' => ''];
});
$aiContext = $ai->contextualExplanation($dti, $extracted['fields'], $findings);
document_screening_check($aiContext !== null && $aiContext['model'] === 'test-model', 'Safe AI contextual explanation was not accepted.');
$unsafeAi = new DocumentScreeningAIService(static fn(): array => ['status' => 200, 'body' => json_encode(['explanation' => 'This document is fake.']), 'error' => '']);
document_screening_check($unsafeAi->contextualExplanation($dti, $extracted['fields'], $findings) === null, 'Unsafe AI authenticity claim was accepted.');
putenv('DOCUMENT_SCREENING_AI_ENABLED=false');

echo "PASS: Document screening classification, extraction, and consistency rules\n";
