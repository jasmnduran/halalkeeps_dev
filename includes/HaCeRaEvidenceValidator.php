<?php
/**
 * Treats AI output as untrusted input. Only findings tied to this restaurant's
 * current assessment, evidence references, and approved criteria are stored.
 */
declare(strict_types=1);

final class HaCeRaEvidenceValidator
{
    private const FINDING_TYPES = ['confirmed_checklist_gap', 'missing_information', 'potential_risk', 'evidence_recommendation'];
    private const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $context
     * @return array{summary:string,findings:array<int,array<string,mixed>>,actions:array<int,array<string,mixed>>,rejected_count:int}
     */
    public function validate(array $report, array $context): array
    {
        $items = $this->indexContextItems($context);
        $summary = $this->cleanText($report['summary'] ?? '', 3000);
        $findings = [];
        $rejected = 0;

        $rawFindings = $report['findings'] ?? [];
        if (!is_array($rawFindings)) $rawFindings = [];
        foreach ($rawFindings as $candidate) {
            if (!is_array($candidate)) {
                $rejected++;
                continue;
            }

            $itemId = (int)($candidate['checklist_item_id'] ?? 0);
            $moduleId = (int)($candidate['module_id'] ?? 0);
            if (!isset($items[$itemId]) || $items[$itemId]['module_id'] !== $moduleId) {
                $rejected++;
                continue;
            }
            $item = $items[$itemId];

            $criteriaReference = strtoupper(trim((string)($candidate['criteria_reference'] ?? '')));
            $type = strtolower(trim((string)($candidate['finding_type'] ?? '')));
            $severity = strtolower(trim((string)($candidate['severity'] ?? '')));
            if ($criteriaReference !== $item['criteria_reference']
                || !in_array($type, self::FINDING_TYPES, true)
                || !in_array($severity, self::SEVERITIES, true)) {
                $rejected++;
                continue;
            }

            $title = $this->cleanText($candidate['title'] ?? '', 180);
            $explanation = $this->cleanText($candidate['explanation'] ?? '', 1800);
            $recommendation = $this->cleanText($candidate['recommended_corrective_action'] ?? '', 1800);
            if ($title === '' || $explanation === '' || $recommendation === ''
                || $this->containsCertificationClaim($title . ' ' . $explanation . ' ' . $recommendation)) {
                $rejected++;
                continue;
            }

            $evidenceIds = [];
            $candidateEvidence = $candidate['evidence_document_ids'] ?? [];
            if (!is_array($candidateEvidence)) {
                $rejected++;
                continue;
            }
            foreach ($candidateEvidence as $documentId) {
                $documentId = (int)$documentId;
                if ($documentId > 0 && in_array($documentId, $item['evidence_document_ids'], true)) {
                    $evidenceIds[] = $documentId;
                } elseif ($documentId > 0) {
                    // Never accept a fabricated, mismatched, or cross-restaurant reference.
                    $rejected++;
                    continue 2;
                }
            }
            $evidenceIds = array_values(array_unique($evidenceIds));

            // A positive answer may be a potential risk but cannot become a
            // confirmed checklist gap from AI text alone.
            $forceReview = false;
            if ($type === 'confirmed_checklist_gap' && (!$item['response_recorded'] || $item['response'] !== 'no')) {
                $type = (!$item['response_recorded'] || $item['response'] === 'na') ? 'missing_information' : 'potential_risk';
                $forceReview = true;
            }

            $requiresExpertReview = $forceReview
                || !empty($candidate['requires_expert_review'])
                || $type === 'potential_risk'
                || in_array($severity, ['high', 'critical'], true);
            $findings[] = [
                'module_id' => $moduleId,
                'checklist_item_id' => $itemId,
                'title' => $title,
                'finding_type' => $type,
                'explanation' => $explanation,
                'severity' => $severity,
                'criteria_reference' => $criteriaReference,
                'recommended_corrective_action' => $recommendation,
                'evidence_document_ids' => $evidenceIds,
                'evidence_status' => $item['response_recorded'] && $item['response'] === 'no'
                    ? 'verified_assessment_response'
                    : (count($evidenceIds) ? 'evidence_reference_present' : 'missing_information'),
                'requires_expert_review' => $requiresExpertReview,
            ];
        }

        $actions = [];
        foreach ($findings as $finding) {
            $actions[] = [
                'finding_checklist_item_id' => $finding['checklist_item_id'],
                'title' => $finding['title'],
                'description' => $finding['recommended_corrective_action'],
                'priority' => $this->priorityFor($finding['severity']),
                'requires_expert_review' => $finding['requires_expert_review'],
            ];
        }

        if ($summary === '' || $this->containsCertificationClaim($summary)) {
            if ($summary !== '') $rejected++;
            $summary = 'This advisory report is based on the current HaCeRa responses and evidence references. It is not an official certification or compliance decision.';
        }

        return ['summary' => $summary, 'findings' => $findings, 'actions' => $actions, 'rejected_count' => $rejected];
    }

    /** @param array<string,mixed> $context @return array<int,array<string,mixed>> */
    private function indexContextItems(array $context): array
    {
        $indexed = [];
        foreach (($context['modules'] ?? []) as $module) {
            foreach (($module['items'] ?? []) as $item) {
                $itemId = (int)($item['checklist_item_id'] ?? 0);
                if ($itemId > 0) {
                    $indexed[$itemId] = [
                        'module_id' => (int)($module['module_id'] ?? 0),
                        'criteria_reference' => (string)($item['criteria_reference'] ?? ''),
                        'response' => (string)($item['response'] ?? 'no'),
                        'response_recorded' => !empty($item['response_recorded']),
                        'evidence_document_ids' => array_map('intval', $item['evidence_document_ids'] ?? []),
                    ];
                }
            }
        }
        return $indexed;
    }

    private function priorityFor(string $severity): string
    {
        return match ($severity) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            default => 'low',
        };
    }

    private function cleanText(mixed $value, int $limit): string
    {
        $text = trim(strip_tags((string)$value));
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        return mb_substr($text, 0, $limit, 'UTF-8');
    }

    private function containsCertificationClaim(string $text): bool
    {
        return (bool)preg_match('/\b(certified|officially compliant|approved for certification|certification decision|issue (?:a )?certificate)\b/i', $text);
    }
}
