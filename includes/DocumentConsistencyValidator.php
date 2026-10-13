<?php
/** Rule-based consistency checks. Findings express review needs, never authenticity. */
declare(strict_types=1);

final class DocumentConsistencyValidator
{
    public const VERSION = 'consistency-rules-v1';

    /** @param array<string,mixed> $application @param array<string,array<string,mixed>> $fields @return array<int,array<string,mixed>> */
    public function validate(array $application, array $fields, string $documentType): array
    {
        $findings = [];
        $findings[] = $this->compareBusinessName($application, $fields['business_name'] ?? null);
        $findings[] = $this->compareRegistrationNumber($application, $fields['registration_number'] ?? null);
        $findings[] = $this->compareAddress($application, $fields['business_address'] ?? null);
        $findings = array_merge($findings, $this->validateDates($fields));
        $findings = array_merge($findings, $this->validateRequiredFields($fields, $documentType));
        return $findings;
    }

    /** @return array<string,mixed> */
    private function compareBusinessName(array $application, ?array $field): array
    {
        $submitted = trim((string)($application['business_name'] ?? ''));
        $extracted = trim((string)($field['raw_value'] ?? ''));
        if ($submitted === '' || $extracted === '') {
            return $this->finding('business_name_match', 'business_name', 'missing_information', 'medium', $submitted ?: null, $extracted ?: null,
                'A business-name comparison could not be completed because one of the values is unavailable.', 'request_clarification');
        }
        $score = $this->similarity($this->businessNameComparable($submitted), $this->businessNameComparable($extracted));
        if ($score >= 0.90) {
            return $this->finding('business_name_match', 'business_name', 'matched', 'low', $submitted, $extracted,
                'The submitted and extracted business names are materially consistent.', 'continue_review');
        }
        if ($score >= 0.72) {
            return $this->finding('business_name_match', 'business_name', 'requires_review', 'medium', $submitted, $extracted,
                'The business names are similar but contain a variation that requires reviewer confirmation.', 'request_clarification');
        }
        return $this->finding('business_name_match', 'business_name', 'mismatch', 'high', $submitted, $extracted,
            'The extracted business name materially differs from the submitted business name. This is an inconsistency for review, not proof of document inauthenticity.', 'request_clarification');
    }

    /** @return array<string,mixed> */
    private function compareRegistrationNumber(array $application, ?array $field): array
    {
        $submitted = trim((string)($application['registration_number'] ?? $application['business_registration_number'] ?? ''));
        $extracted = trim((string)($field['raw_value'] ?? ''));
        if ($submitted === '') {
            return $this->finding('registration_number_match', 'registration_number', 'not_applicable', 'low', null, $extracted ?: null,
                'The application record does not contain a registration number for deterministic comparison.', 'continue_review');
        }
        if ($extracted === '') {
            return $this->finding('registration_number_match', 'registration_number', 'missing_information', 'high', $submitted, null,
                'The required registration or permit number could not be reliably extracted.', 'request_clarification');
        }
        $a = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $submitted) ?? '');
        $b = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $extracted) ?? '');
        if (hash_equals($a, $b)) {
            return $this->finding('registration_number_match', 'registration_number', 'matched', 'low', $submitted, $extracted,
                'The extracted registration or permit number matches the submitted record.', 'continue_review');
        }
        return $this->finding('registration_number_match', 'registration_number', 'mismatch', 'high', $submitted, $extracted,
            'The extracted registration or permit number differs from the submitted record. OCR uncertainty must be checked before drawing any conclusion; this mismatch is not proof of document inauthenticity.', 'verify_issuing_authority');
    }

    /** @return array<string,mixed> */
    private function compareAddress(array $application, ?array $field): array
    {
        $submitted = $this->submittedAddress($application);
        $extracted = trim((string)($field['raw_value'] ?? ''));
        if ($submitted === '' || $extracted === '') {
            return $this->finding('address_match', 'business_address', 'missing_information', 'medium', $submitted ?: null, $extracted ?: null,
                'A business-address comparison could not be completed because one of the values is unavailable.', 'request_clarification');
        }
        $score = $this->addressSimilarity($submitted, $extracted);
        if ($score >= 0.70) {
            return $this->finding('address_match', 'business_address', 'matched', 'low', $submitted, $extracted,
                'Available address elements are materially consistent after normalizing formatting.', 'continue_review');
        }
        if ($score >= 0.42) {
            return $this->finding('address_match', 'business_address', 'requires_review', 'medium', $submitted, $extracted,
                'Only some submitted and extracted address elements align. Confirm the applicable branch and formatting.', 'request_clarification');
        }
        return $this->finding('address_match', 'business_address', 'mismatch', 'high', $submitted, $extracted,
            'The extracted address is materially inconsistent with the submitted address. This requires clarification, not an automatic application decision.', 'request_clarification');
    }

    /** @return array<int,array<string,mixed>> */
    private function validateDates(array $fields): array
    {
        $issue = trim((string)($fields['issue_date']['normalized_value'] ?? ''));
        $expiry = trim((string)($fields['expiration_date']['normalized_value'] ?? ''));
        $findings = [];
        if ($expiry !== '') {
            if ($expiry < gmdate('Y-m-d')) {
                $findings[] = $this->finding('expiration_date_validity', 'expiration_date', 'expired', 'high', null, $expiry,
                    'The extracted expiration date has passed. Confirm the current document status with the applicant or issuing authority.', 'request_updated_document');
            } else {
                $findings[] = $this->finding('expiration_date_validity', 'expiration_date', 'matched', 'low', null, $expiry,
                    'The extracted expiration date is not in the past.', 'continue_review');
            }
        } else {
            $findings[] = $this->finding('expiration_date_validity', 'expiration_date', 'not_applicable', 'low', null, null,
                'No reliable expiration date was extracted; some document types legitimately have no expiry date.', 'continue_review');
        }
        if ($issue !== '' && $expiry !== '' && $issue > $expiry) {
            $findings[] = $this->finding('date_sequence', 'issue_date', 'requires_review', 'high', $issue, $expiry,
                'The extracted issue date is later than the extracted expiration date and should be manually checked.', 'request_clarification');
        }
        return $findings;
    }

    /** @return array<int,array<string,mixed>> */
    private function validateRequiredFields(array $fields, string $documentType): array
    {
        $required = match ($documentType) {
            'dti_registration', 'sec_registration' => ['business_name', 'registration_number', 'issuing_authority'],
            'bir_registration' => ['business_name', 'registration_number', 'issuing_authority'],
            'bir_invoice_notice' => ['business_name', 'registered_name', 'tin_branch_code', 'bir_invoice_notice_markers'],
            'mayors_permit', 'business_permit', 'sanitary_permit' => ['business_name', 'registration_number', 'issuing_authority'],
            'halal_certificate' => ['certificate_number', 'certified_establishment', 'certifying_body', 'issue_date', 'expiration_date', 'certificate_scope', 'certification_scheme'],
            default => [],
        };
        $findings = [];
        foreach ($required as $field) {
            if (trim((string)($fields[$field]['raw_value'] ?? '')) !== '') continue;
            $explanation = $field === 'bir_invoice_notice_markers'
                ? 'The upload does not contain all required BIR invoice-notice labels: RECEIPT/INVOICE, Business Name, Registered Name, and TIN and Branch Code.'
                : 'The required ' . $this->fieldLabel($field) . ' could not be reliably extracted from the uploaded document. Review the original document or record a corrected transcription in this screening record.';
            $findings[] = $this->finding('required_field_' . $field, $field, 'missing_information', 'medium', null, null,
                $explanation, 'request_clarification');
        }
        return $findings;
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'certificate_number' => 'certificate number',
            'certified_establishment' => 'certified establishment',
            'certifying_body' => 'certifying body',
            'issue_date' => 'issue date',
            'expiration_date' => 'expiration date',
            'certificate_scope' => 'certificate scope',
            'certification_scheme' => 'certification scheme',
            default => str_replace('_', ' ', $field),
        };
    }

    private function submittedAddress(array $application): string
    {
        $branches = $application['branches'] ?? [];
        $branch = $branches[0] ?? [];
        $parts = [
            $branch['address_street'] ?? $application['address_street'] ?? '',
            $branch['address_barangay'] ?? $application['address_barangay'] ?? '',
            $branch['address_city'] ?? $application['address_city'] ?? '',
            $branch['address_province'] ?? $application['address_province'] ?? '',
            $branch['address_region'] ?? $application['address_region'] ?? '',
            $branch['address_zip'] ?? $application['address_zip'] ?? '',
        ];
        return implode(', ', array_filter(array_map('trim', $parts)));
    }

    private function businessNameComparable(string $value): string
    {
        $value = DocumentFieldExtractor::comparable($value);
        // Legal-form suffixes often differ between a registration document and
        // a business-form entry. Removing only these suffixes avoids treating a
        // documented trading style as a material identity discrepancy.
        $value = preg_replace('/\b(?:inc|incorporated|corp|corporation|co|company|ltd|limited|llc)\b/u', '', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function similarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') return 0.0;
        if ($left === $right) return 1.0;
        $max = max(strlen($left), strlen($right));
        $distance = levenshtein($left, $right);
        return max(0.0, 1 - ($distance / max(1, $max)));
    }

    private function addressSimilarity(string $left, string $right): float
    {
        $a = array_values(array_filter(explode(' ', DocumentFieldExtractor::comparable($left)), static fn($v) => mb_strlen($v, 'UTF-8') > 2));
        $b = array_values(array_filter(explode(' ', DocumentFieldExtractor::comparable($right)), static fn($v) => mb_strlen($v, 'UTF-8') > 2));
        if ($a === [] || $b === []) return 0.0;
        $intersection = count(array_intersect($a, $b));
        return (2 * $intersection) / (count($a) + count($b));
    }

    /** @return array<string,mixed> */
    private function finding(string $ruleId, string $fieldKey, string $outcome, string $severity, ?string $submitted, ?string $extracted, string $explanation, string $recommendation): array
    {
        return [
            'rule_id' => $ruleId,
            'field_key' => $fieldKey,
            'outcome' => $outcome,
            'severity' => $severity,
            'submitted_value' => $submitted,
            'extracted_value' => $extracted,
            'explanation' => $explanation,
            'recommendation' => $recommendation,
        ];
    }
}
