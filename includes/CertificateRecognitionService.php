<?php
/** Recognition evidence lookup over locally maintained authoritative records. */
declare(strict_types=1);

final class CertificateRecognitionService
{
    /** @return array<int,array<string,mixed>> */
    public function screen(array $fields, DocumentScreeningRepository $repository): array
    {
        $body = trim((string)($fields['certifying_body']['raw_value'] ?? $fields['issuing_authority']['raw_value'] ?? ''));
        if ($body === '') {
            return [$this->finding('certifier_identity', 'missing_information', 'medium', null,
                'The certifying body could not be reliably extracted from the certificate.', 'request_clarification')];
        }
        $records = $repository->recognitionRecords(DocumentFieldExtractor::comparable($body));
        if ($records === []) {
            return [$this->finding('recognition_lookup', 'not_independently_verified', 'medium', $body,
                'No locally maintained authoritative recognition record is available for this certifying body. This does not establish that the certificate is invalid.', 'verify_issuing_authority')];
        }

        $findings = [];
        foreach ($records as $record) {
            $status = (string)$record['recognition_status'];
            $validTo = (string)($record['valid_to'] ?? '');
            if ($status === 'recognized' && $validTo !== '' && $validTo < gmdate('Y-m-d')) $status = 'requires_review';
            $source = trim((string)($record['verification_source'] ?? ''));
            $checked = trim((string)($record['source_checked_at'] ?? ''));
            $description = $status === 'recognized'
                ? 'Recognition evidence is recorded for the applicable scheme' . ($source ? ' from ' . $source : '') . ($checked ? ' (checked ' . $checked . ')' : '') . '.'
                : 'Recognition is not established for the applicable scheme from the available evidence' . ($source ? ' (' . $source . ')' : '') . '.';
            $findings[] = $this->finding(
                'recognition_' . (string)$record['recognition_scheme'],
                $status,
                $status === 'recognized' ? 'low' : 'medium',
                $body,
                $description,
                $status === 'recognized' ? 'continue_review' : 'verify_issuing_authority'
            );
        }
        return $findings;
    }

    /** @return array<string,mixed> */
    private function finding(string $ruleId, string $outcome, string $severity, ?string $extracted, string $explanation, string $recommendation): array
    {
        return [
            'rule_id' => $ruleId,
            'field_key' => 'certifying_body',
            'outcome' => $outcome,
            'severity' => $severity,
            'submitted_value' => null,
            'extracted_value' => $extracted,
            'explanation' => $explanation,
            'recommendation' => $recommendation,
        ];
    }
}
