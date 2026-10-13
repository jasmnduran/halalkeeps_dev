<?php
/**
 * Extracts declared fields through transparent label-based patterns. Values not
 * supported by text are deliberately represented as null; no value is inferred.
 */
declare(strict_types=1);

final class DocumentFieldExtractor
{
    public const VERSION = 'field-extractor-v2';

    /** @return array<string,mixed> */
    public function extract(string $text, array $classification, ?float $ocrConfidence = null): array
    {
        $pages = preg_split('/\f/', $text) ?: [$text];
        $baseConfidence = $ocrConfidence === null ? 0.65 : max(0.20, min(0.98, $ocrConfidence));
        $fields = [
            'business_name' => $this->extractLabel($pages, ['registered business name', 'business name', 'trade name', 'name of business', 'establishment name'], $baseConfidence),
            'trade_name' => $this->extractLabel($pages, ['trade name', 'business style'], $baseConfidence),
            'registered_name' => $this->extractLabel($pages, ['registered name', 'legal registered name'], $baseConfidence),
            'tin_branch_code' => $this->extractLabel($pages, ['tin and branch code', 'tin\s*(?:no\.?|number)?\s*(?:and|&)\s*branch code', 'taxpayer identification number'], $baseConfidence, 'identifier'),
            'business_owner' => $this->extractLabel($pages, ['owner(?:\'s)? name', 'proprietor', 'legal representative', 'authorized representative'], $baseConfidence),
            'registration_number' => $this->extractLabel($pages, ['registration (?:no\.?|number)', 'business name no\.?', 'sec no\.?', 'permit no\.?', 'license no\.?'], $baseConfidence, 'identifier'),
            'business_address' => $this->extractLabel($pages, ['business address', 'registered address', 'address of business', 'address'], $baseConfidence),
            'issuing_authority' => $this->extractLabel($pages, ['issuing authority', 'issued by', 'bureau of', 'department of', 'city of', 'municipality of'], $baseConfidence),
            'issue_date' => $this->extractDate($pages, ['issue date', 'date issued', 'issued on', 'date of issue'], $baseConfidence),
            'expiration_date' => $this->extractDate($pages, ['expiry date', 'expiration date', 'valid until', 'valid up to', 'valid thru', 'valid through', 'date of expiry'], $baseConfidence),
            'certificate_number' => $this->extractLabel($pages, ['certificate (?:no\.?|number)', 'certification (?:no\.?|number)'], $baseConfidence, 'identifier'),
            'certified_establishment' => $this->extractLabel($pages, ['certified (?:business|establishment|company)', 'name of establishment', 'company name'], $baseConfidence),
            'certifying_body' => $this->extractLabel($pages, ['halal certifying body', 'certification body', 'certified by', 'issued by'], $baseConfidence),
            'certificate_scope' => $this->extractLabel($pages, ['scope of certification', 'scope', 'products covered', 'operational scope'], $baseConfidence),
            'certification_scheme' => $this->extractLabel($pages, ['certification scheme', 'scheme', 'standard'], $baseConfidence),
            'recognition_claims' => $this->extractLabel($pages, ['recognized by', 'recognition', 'accredited by', 'jakim'], $baseConfidence),
            'verification_reference' => $this->extractVerificationReference($pages, $baseConfidence),
        ];
        if ((string)$classification['document_type'] === 'bir_invoice_notice') {
            $birLabels = [
                'business_name' => 'business name',
                'registered_name' => 'registered name',
                'tin_branch_code' => 'tin and branch code',
            ];
            foreach ($birLabels as $fieldName => $label) {
                $aboveLabel = $this->extractValueAboveLabel($pages, $label, $baseConfidence,
                    $fieldName === 'tin_branch_code' ? 'identifier' : 'text');
                // In the prescribed BIR notice layout the label sits below its
                // value, so prefer that geometry over a generic next-line match.
                if ($aboveLabel['raw_value'] !== null) $fields[$fieldName] = $aboveLabel;
            }
            $fields['bir_invoice_notice_markers'] = $this->birInvoiceNoticeMarkers($pages, $baseConfidence);
        }
        if ((string)$classification['document_type'] === 'halal_certificate') {
            $awarded = $this->extractAwardedEstablishment($pages, $baseConfidence);
            if ($awarded['raw_value'] !== null) {
                $fields['certified_establishment'] = $awarded;
                if ($fields['business_name']['raw_value'] === null) $fields['business_name'] = $awarded;
            }
            if ($fields['business_address']['raw_value'] === null && $fields['certified_establishment']['raw_value'] !== null) {
                $fields['business_address'] = $this->extractAddressBelowEstablishment($pages, (string)$fields['certified_establishment']['raw_value'], $baseConfidence);
            }
            $certifier = $this->extractCertificateCertifier($pages, $baseConfidence);
            if ($certifier['raw_value'] !== null) $fields['certifying_body'] = $certifier;
            $scheme = $this->extractCertificateStandards($pages, $baseConfidence);
            if ($scheme['raw_value'] !== null) $fields['certification_scheme'] = $scheme;
            $scope = $this->extractHalalCertificateScope($pages, $baseConfidence);
            if ($scope['raw_value'] !== null) $fields['certificate_scope'] = $scope;
            $certificateNumber = $this->extractHalalCertificateNumber($pages, $baseConfidence);
            if ($certificateNumber['raw_value'] !== null) $fields['certificate_number'] = $certificateNumber;
            $certificateDates = $this->extractHalalCertificateDates($pages, $baseConfidence);
            if ($certificateDates['issue_date']['raw_value'] !== null) $fields['issue_date'] = $certificateDates['issue_date'];
            if ($certificateDates['expiration_date']['raw_value'] !== null) $fields['expiration_date'] = $certificateDates['expiration_date'];
        }

        // Issuing bodies frequently place their name in a header without a label.
        if ($fields['issuing_authority']['raw_value'] === null) {
            $fields['issuing_authority'] = $this->knownAuthority($pages, $baseConfidence);
        }
        if ($fields['certifying_body']['raw_value'] === null && $classification['document_type'] === 'halal_certificate') {
            $fields['certifying_body'] = $this->knownHalalBody($pages, $baseConfidence);
        }

        $missing = [];
        $uncertain = [];
        foreach ($fields as $name => $field) {
            if ($field['raw_value'] === null) $missing[] = $name;
            if (!empty($field['is_uncertain'])) $uncertain[] = $name;
        }
        return [
            'document_type' => (string)$classification['document_type'],
            'fields' => $fields,
            'missing_fields' => $missing,
            'uncertain_fields' => $uncertain,
        ];
    }

    public static function comparable(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /** @return array<string,mixed> */
    private function extractLabel(array $pages, array $labels, float $confidence, string $kind = 'text'): array
    {
        foreach ($pages as $index => $page) {
            foreach ($labels as $label) {
                $pattern = '/(?:^|\n|\r)\s*(?:' . $label . ')\s*(?:[:#\-]|\bis\b)?\s*([^\n\r]{2,220})/iu';
                if (preg_match($pattern, $page, $match) !== 1) continue;
                $raw = $this->cleanValue((string)$match[1]);
                if ($raw === '' || $this->looksLikeLabel($raw)) continue;
                $normal = $kind === 'identifier' ? $this->normaliseIdentifier($raw) : self::comparable($raw);
                return $this->field($raw, $normal, $confidence, $index + 1, 'label:' . strip_tags($label));
            }
        }
        return $this->field(null, null, null, null, null, true);
    }

    /** @return array<string,mixed> */
    private function extractDate(array $pages, array $labels, float $confidence): array
    {
        $field = $this->extractLabel($pages, $labels, $confidence);
        if ($field['raw_value'] === null) return $field;
        $date = $this->normaliseDate((string)$field['raw_value']);
        if ($date === null) {
            $field['normalized_value'] = null;
            $field['is_uncertain'] = true;
            return $field;
        }
        $field['normalized_value'] = $date;
        return $field;
    }

    /** @return array<string,mixed> */
    private function extractVerificationReference(array $pages, float $confidence): array
    {
        foreach ($pages as $index => $page) {
            if (preg_match('/\b(?:verify|verification|qr|reference)\s*(?:code|no\.?|number)?\s*[:#-]?\s*([A-Z0-9][A-Z0-9\/-]{4,80})/iu', $page, $match) === 1) {
                $raw = $this->cleanValue((string)$match[1]);
                return $this->field($raw, $this->normaliseIdentifier($raw), $confidence, $index + 1, 'verification reference');
            }
        }
        return $this->field(null, null, null, null, null, true);
    }

    /** @return array<string,mixed> */
    private function knownAuthority(array $pages, float $confidence): array
    {
        $known = ['Department of Trade and Industry', 'Securities and Exchange Commission', 'Bureau of Internal Revenue', 'Food and Drug Administration'];
        foreach ($pages as $index => $page) {
            foreach ($known as $authority) {
                if (stripos($page, $authority) !== false) {
                    return $this->field($authority, self::comparable($authority), $confidence, $index + 1, 'known issuing authority');
                }
            }
        }
        return $this->field(null, null, null, null, null, true);
    }

    /** @return array<string,mixed> */
    private function knownHalalBody(array $pages, float $confidence): array
    {
        foreach ($pages as $index => $page) {
            if (preg_match('/(?:halal certification body|certified by)\s*[:\-]?\s*([^\n\r]{3,180})/iu', $page, $match) === 1) {
                $raw = $this->cleanValue((string)$match[1]);
                if ($raw !== '') return $this->field($raw, self::comparable($raw), $confidence, $index + 1, 'certifying-body label');
            }
        }
        return $this->field(null, null, null, null, null, true);
    }

    /** @return array<string,mixed> */
    private function birInvoiceNoticeMarkers(array $pages, float $confidence): array
    {
        $allText = mb_strtolower(implode("\n", $pages), 'UTF-8');
        $markers = [
            'this establishment must issue' => str_contains($allText, 'this establishment must issue'),
            'receipt/invoice' => str_contains($allText, 'receipt/invoice') || (str_contains($allText, 'receipt') && str_contains($allText, 'invoice')),
            'business name label' => str_contains($allText, 'business name'),
            'registered name label' => str_contains($allText, 'registered name'),
            'tin and branch code label' => str_contains($allText, 'tin and branch code'),
        ];
        $present = array_keys(array_filter($markers));
        if (count($present) < 4) {
            return $this->field(null, null, $confidence, 1, 'BIR invoice-notice markers', true);
        }
        $value = implode('; ', $present);
        return $this->field($value, self::comparable($value), $confidence, 1, 'BIR invoice-notice markers');
    }

    /**
     * The official BIR invoice notice template places each fillable value in a
     * white box immediately above its caption. OCR therefore often returns the
     * value on the preceding line rather than after the label.
     *
     * @return array<string,mixed>
     */
    private function extractValueAboveLabel(array $pages, string $label, float $confidence, string $kind): array
    {
        foreach ($pages as $pageIndex => $page) {
            $lines = preg_split('/\R/', $page) ?: [];
            foreach ($lines as $index => $line) {
                if (stripos($line, $label) === false) continue;
                for ($previous = $index - 1; $previous >= max(0, $index - 2); $previous--) {
                    $raw = $this->cleanValue((string)$lines[$previous]);
                    if ($raw === '' || stripos($raw, 'receipt/invoice') !== false || $this->looksLikeLabel($raw)) continue;
                    $normal = $kind === 'identifier' ? $this->normaliseIdentifier($raw) : self::comparable($raw);
                    return $this->field($raw, $normal, $confidence, $pageIndex + 1, 'value above label:' . $label);
                }
            }
        }
        return $this->field(null, null, null, null, null, true);
    }

    /** @return array<string,mixed> */
    private function extractAwardedEstablishment(array $pages, float $confidence): array
    {
        foreach ($pages as $pageIndex => $page) {
            if (preg_match('/(?:certificate\s+of\s+registration\s+is\s+)?awarded\s+to\s*(?:\R\s*)?([^\r\n]{3,180})/iu', $page, $match) === 1) {
                $raw = $this->cleanValue((string)$match[1]);
                if ($raw !== '') return $this->field($raw, self::comparable($raw), $confidence, $pageIndex + 1, 'awarded to');
            }
        }
        return $this->field(null, null, null, null, null, true);
    }

    /** @return array<string,mixed> */
    private function extractAddressBelowEstablishment(array $pages, string $establishment, float $confidence): array
    {
        $needle = self::comparable($establishment);
        foreach ($pages as $pageIndex => $page) {
            $lines = preg_split('/\R/', $page) ?: [];
            foreach ($lines as $index => $line) {
                if (!str_contains(self::comparable((string)$line), $needle)) continue;
                for ($next = $index + 1; $next <= min(count($lines) - 1, $index + 2); $next++) {
                    $raw = $this->cleanValue((string)$lines[$next]);
                    if ($raw === '') continue;
                    if (preg_match('/\b(?:street|st\.?|barangay|city|province|philippines|road|ave\.?|building|#|\d{1,5})\b/iu', $raw) === 1) {
                        return $this->field($raw, self::comparable($raw), $confidence, $pageIndex + 1, 'line below certified establishment');
                    }
                }
            }
        }
        return $this->field(null, null, null, null, null, true);
    }

    /** @return array<string,mixed> */
    private function extractCertificateCertifier(array $pages, float $confidence): array
    {
        foreach ($pages as $pageIndex => $page) {
            if (preg_match('/([A-Z][A-Za-z&,.\- ]{3,160}(?:Inc\.?|Ltd\.?|LLC|Corporation|Association))\s+certifies\b/u', $page, $match) === 1) {
                $raw = $this->cleanValue((string)$match[1]);
                return $this->field($raw, self::comparable($raw), $confidence, $pageIndex + 1, 'certifies that');
            }
        }
        return $this->field(null, null, null, null, null, true);
    }

    /** @return array<string,mixed> */
    private function extractCertificateStandards(array $pages, float $confidence): array
    {
        $standards = [];
        $pageNumber = null;
        foreach ($pages as $pageIndex => $page) {
            foreach (preg_split('/\R/', $page) ?: [] as $line) {
                if (preg_match('/(?:UAE\.?\s*S\s*\d+|Philippine\s+National\s+Standard|PNS\s*\d+)/iu', $line) !== 1) continue;
                $value = $this->cleanValue((string)$line);
                if ($value !== '') $standards[] = $value;
                $pageNumber = $pageIndex + 1;
            }
        }
        if ($standards === []) return $this->field(null, null, null, null, null, true);
        $raw = implode('; ', array_values(array_unique($standards)));
        return $this->field($raw, self::comparable($raw), $confidence, $pageNumber, 'certificate standards');
    }

    /** @return array<string,mixed> */
    private function extractHalalCertificateScope(array $pages, float $confidence): array
    {
        foreach ($pages as $pageIndex => $page) {
            $lines = preg_split('/\R/', $page) ?: [];
            foreach ($lines as $index => $line) {
                if (stripos($line, 'scope of certification') === false) continue;
                $scopeLines = [];
                for ($next = $index + 1; $next <= min(count($lines) - 1, $index + 8); $next++) {
                    $candidate = $this->cleanScopeLine((string)$lines[$next]);
                    if ($candidate === '') continue;
                    if (preg_match('/\b(?:providing|preparation|production|manufactur\w*|processing|food|hospitality|service\w*|product\w*)\b/iu', $candidate) !== 1) continue;
                    $scopeLines[] = $candidate;
                }
                if ($scopeLines === []) continue;
                $raw = implode(' ', array_values(array_unique($scopeLines)));
                return $this->field($raw, self::comparable($raw), $confidence, $pageIndex + 1, 'scope below certification heading');
            }
        }
        return $this->field(null, null, null, null, null, true);
    }

    private function cleanScopeLine(string $line): string
    {
        if (preg_match('/\b(?:providing|service\w*)\b/iu', $line) !== 1
            && preg_match('/\b(and\s+(?:preparation|production|manufactur\w*|processing)\b.*)$/iu', $line, $match) === 1) {
            $line = (string)$match[1];
        }
        $line = preg_replace('/^[^\p{L}]+/u', '', $line) ?? '';
        $line = preg_replace('/[|]+/u', ' ', $line) ?? '';
        return $this->cleanValue($line);
    }

    /** @return array<string,mixed> */
    private function extractHalalCertificateNumber(array $pages, float $confidence): array
    {
        foreach ($pages as $pageIndex => $page) {
            // Structured certificate numbers such as NCMF-055-23-21-02-0000
            // survive OCR more reliably than the neighbouring label and seals.
            if (preg_match('/\b([A-Z]{2,10}(?:-\d{2,}){2,})(?=[^\d]|$)/iu', $page, $match, PREG_OFFSET_CAPTURE) !== 1) continue;
            $raw = (string)$match[1][0];
            $end = (int)$match[1][1] + strlen($raw);
            $following = substr($page, $end, 1);
            return $this->field(
                $raw,
                $this->normaliseIdentifier($raw),
                $confidence,
                $pageIndex + 1,
                'certificate number pattern',
                $following !== '' && ctype_alpha($following)
            );
        }
        return $this->field(null, null, null, null, null, true);
    }

    /** @return array{issue_date:array<string,mixed>,expiration_date:array<string,mixed>} */
    private function extractHalalCertificateDates(array $pages, float $confidence): array
    {
        $dates = [];
        $month = 'January|February|March|April|May|June|July|August|September|October|November|December';
        foreach ($pages as $pageIndex => $page) {
            if (preg_match_all('/\b([0-3]?\d)\s+(' . $month . ')\s+(\d{4})(?:\d{1,2})?(?!\d)/iu', $page, $matches, PREG_SET_ORDER) !== false) {
                foreach ($matches as $match) {
                    $normalised = $this->normaliseDate($match[1] . ' ' . $match[2] . ' ' . $match[3]);
                    if ($normalised === null) continue;
                    $dates[$normalised] = $this->field(
                        $match[1] . ' ' . $match[2] . ' ' . $match[3],
                        $normalised,
                        $confidence,
                        $pageIndex + 1,
                        'certificate date token',
                        true
                    );
                }
            }
        }
        ksort($dates);
        $values = array_values($dates);
        if (count($values) < 2) {
            return [
                'issue_date' => $this->field(null, null, null, null, null, true),
                'expiration_date' => $this->field(null, null, null, null, null, true),
            ];
        }
        return ['issue_date' => $values[0], 'expiration_date' => $values[count($values) - 1]];
    }

    /** @return array<string,mixed> */
    private function field(?string $raw, ?string $normal, ?float $confidence, ?int $page, ?string $location, bool $uncertain = false): array
    {
        return [
            'raw_value' => $raw,
            'normalized_value' => $normal,
            'confidence' => $confidence,
            'source_page' => $page,
            'source_location' => $location,
            'is_uncertain' => $uncertain,
        ];
    }

    private function cleanValue(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        return trim(mb_substr($value, 0, 220, 'UTF-8'), " \t\n\r\0\x0B:;,");
    }

    private function looksLikeLabel(string $value): bool
    {
        return mb_strlen($value, 'UTF-8') < 2 || preg_match('/^(?:issue|expiry|address|name|number|date)$/iu', $value) === 1;
    }

    private function normaliseIdentifier(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');
    }

    private function normaliseDate(string $value): ?string
    {
        if (preg_match('/(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})/', $value, $m) === 1) {
            return $this->validDate((int)$m[1], (int)$m[2], (int)$m[3]);
        }
        if (preg_match('/(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})/', $value, $m) === 1) {
            $year = (int)$m[3] < 100 ? 2000 + (int)$m[3] : (int)$m[3];
            return $this->validDate($year, (int)$m[1], (int)$m[2]);
        }
        $timestamp = strtotime($value);
        // A document date is a calendar value, not an instant in time.  Format
        // it in PHP's configured local timezone so UTC conversion cannot shift
        // a Philippine-issued date to the preceding day.
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    private function validDate(int $year, int $month, int $day): ?string
    {
        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }
}
