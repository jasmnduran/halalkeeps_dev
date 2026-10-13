<?php
/** Deterministic, explainable first-pass document classification. */
declare(strict_types=1);

final class DocumentClassificationService
{
    /** @return array<string,mixed> */
    public function classify(string $text, ?string $uploadedType = null): array
    {
        $haystack = mb_strtolower($text, 'UTF-8');
        $rules = [
            // This is the BIR "This establishment MUST issue RECEIPT/INVOICE"
            // notice posted at a business. It is distinct from BIR Form 2303.
            'bir_invoice_notice' => ['this establishment must issue', 'receipt/invoice', 'tin and branch code', 'bureau of internal revenue'],
            'dti_registration' => ['department of trade and industry', 'dti', 'certificate of business name registration'],
            'sec_registration' => ['securities and exchange commission', 'sec registration', 'certificate of incorporation'],
            'bir_registration' => ['bureau of internal revenue', 'bir', 'certificate of registration', 'form 2303'],
            'mayors_permit' => ["mayor's permit", 'business permit', 'business license', 'municipal permit'],
            'sanitary_permit' => ['sanitary permit', 'sanitation permit', 'health and sanitation'],
            'halal_certificate' => ['halal certificate', 'halal certification', 'certified halal', 'halal certifying body', 'halal food', 'scope of certification', 'certificate of registration'],
            'other_supporting_document' => ['license to operate', 'lto', 'supporting document'],
        ];
        $bestType = 'unknown_document';
        $bestHits = [];
        foreach ($rules as $type => $phrases) {
            $hits = [];
            foreach ($phrases as $phrase) {
                if (str_contains($haystack, $phrase)) $hits[] = $phrase;
            }
            if (count($hits) > count($bestHits)) {
                $bestType = $type;
                $bestHits = $hits;
            }
        }

        $confidence = $bestHits === [] ? 0.10 : min(0.96, 0.40 + count($bestHits) * 0.22);
        $expected = $this->fromUploadCategory($uploadedType);
        $unexpected = $expected !== null && $bestType !== 'unknown_document' && $expected !== $bestType;
        if ($unexpected) $confidence = min($confidence, 0.75);

        return [
            'document_type' => $bestType,
            'confidence' => round($confidence, 4),
            'indicators' => $bestHits,
            'expected_type' => $expected,
            'unexpected_type' => $unexpected,
            'requires_manual_review' => $bestType === 'unknown_document' || $unexpected || $confidence < 0.65,
        ];
    }

    private function fromUploadCategory(?string $type): ?string
    {
        return match ((string)$type) {
            'dti_license' => 'dti_registration',
            'bir_notice' => 'bir_invoice_notice',
            'business_permit', 'mayors_barangay_permit' => 'mayors_permit',
            'sanitary_permit' => 'sanitary_permit',
            'halal_certificate', 'proof_of_halal', 'halal_cert_raw_materials' => 'halal_certificate',
            'lto_permit' => 'other_supporting_document',
            default => null,
        };
    }
}
