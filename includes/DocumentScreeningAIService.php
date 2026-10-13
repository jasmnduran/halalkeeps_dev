<?php
/**
 * Optional, privacy-preserving AI layer for contextual review explanations.
 * It receives no files, OCR text, raw field values, paths, names, addresses,
 * certificate numbers, or registration numbers. Deterministic rules remain the
 * source of all outcomes, comparisons, expiry checks, and priorities.
 */
declare(strict_types=1);

final class DocumentScreeningAIService
{
    /** @var callable|null */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function isEnabled(): bool
    {
        return filter_var(getenv('DOCUMENT_SCREENING_AI_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    public function isConfigured(): bool
    {
        return trim((string)getenv('DOCUMENT_SCREENING_AI_ENDPOINT')) !== ''
            && trim((string)getenv('DOCUMENT_SCREENING_AI_API_KEY')) !== ''
            && trim((string)getenv('DOCUMENT_SCREENING_AI_MODEL')) !== '';
    }

    public function model(): string
    {
        return trim((string)(getenv('DOCUMENT_SCREENING_AI_MODEL') ?: ''));
    }

    /** @return array<string,mixed>|null */
    public function contextualExplanation(array $classification, array $fields, array $findings): ?array
    {
        if (!$this->isEnabled() || !$this->isConfigured() || (!function_exists('curl_init') && $this->transport === null)) return null;
        $context = [
            'document_type' => (string)($classification['document_type'] ?? 'unknown_document'),
            'classification_confidence' => (float)($classification['confidence'] ?? 0),
            // Only presence flags are included. Values from documents are never
            // included in external AI context.
            'field_presence' => array_map(static fn(array $field): bool => !empty($field['raw_value']), $fields),
            'deterministic_findings' => array_map(static fn(array $finding): array => [
                'rule_id' => (string)($finding['rule_id'] ?? ''),
                'outcome' => (string)($finding['outcome'] ?? ''),
                'severity' => (string)($finding['severity'] ?? ''),
                'recommendation' => (string)($finding['recommendation'] ?? ''),
            ], $findings),
        ];
        try {
            $payload = json_encode([
                'model' => $this->model(),
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => 'Return JSON only: {"explanation":"one concise neutral administrative explanation"}. Use only the supplied deterministic outcomes. Never claim a document is authentic, fake, forged, invalid, certified, approved, rejected, or fraudulent. Do not invent facts or instructions.'],
                    ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . trim((string)getenv('DOCUMENT_SCREENING_AI_API_KEY'))];
        $response = $this->transport !== null
            ? ($this->transport)(trim((string)getenv('DOCUMENT_SCREENING_AI_ENDPOINT')), $headers, $payload, $this->timeout())
            : $this->curlPost(trim((string)getenv('DOCUMENT_SCREENING_AI_ENDPOINT')), $headers, $payload, $this->timeout());
        if ((int)($response['status'] ?? 0) < 200 || (int)($response['status'] ?? 0) >= 300 || !empty($response['error'])) return null;
        $decoded = $this->decode((string)($response['body'] ?? ''));
        $explanation = isset($decoded['explanation']) && is_string($decoded['explanation']) ? trim($decoded['explanation']) : '';
        if ($explanation === '' || mb_strlen($explanation, 'UTF-8') > 700) return null;
        if (preg_match('/\b(authentic|fake|forged|fraud|fraudulent|invalid|certified|approved|rejected)\b/i', $explanation) === 1) return null;
        return ['explanation' => strip_tags($explanation), 'model' => $this->model()];
    }

    /** @return array<string,mixed> */
    private function curlPost(string $endpoint, array $headers, string $payload, int $timeout): array
    {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
    }

    /** @return array<string,mixed> */
    private function decode(string $raw): array
    {
        try {
            $outer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($outer)) return [];
            if (isset($outer['explanation'])) return $outer;
            $content = $outer['choices'][0]['message']['content'] ?? $outer['output_text'] ?? '';
            if (!is_string($content)) return [];
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    private function timeout(): int
    {
        return max(5, min(60, (int)(getenv('DOCUMENT_SCREENING_AI_TIMEOUT_SECONDS') ?: 20)));
    }
}
