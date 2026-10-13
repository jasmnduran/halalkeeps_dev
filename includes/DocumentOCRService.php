<?php
/**
 * Local-only OCR boundary for uploaded business documents.
 *
 * No document is transmitted outside the server. Tesseract/Poppler paths are
 * opt-in environment values so a host cannot accidentally execute an unknown
 * binary from PATH. The service returns an actionable failure when no approved
 * local extractor is installed; callers must preserve manual review access.
 */
declare(strict_types=1);

final class DocumentOCRService
{
    public const ENGINE_VERSION = 'local-tesseract-poppler-v1';

    public static function isFeatureEnabled(): bool
    {
        return filter_var(getenv('DOCUMENT_SCREENING_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    public static function shouldProcessInline(): bool
    {
        return filter_var(getenv('DOCUMENT_SCREENING_PROCESS_INLINE') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string,mixed> */
    public function extract(string $absolutePath, ?string $declaredMime = null): array
    {
        $safety = $this->validateFile($absolutePath, $declaredMime);
        if (empty($safety['success'])) return $safety;

        $extension = (string)$safety['extension'];
        $mime = (string)$safety['mime_type'];
        if ($extension === 'pdf') {
            return $this->extractPdf($absolutePath, $mime, (int)$safety['page_count']);
        }
        return $this->extractImage($absolutePath, $mime, 1);
    }

    /** @return array<string,mixed> */
    public function validateFile(string $absolutePath, ?string $declaredMime = null): array
    {
        $path = realpath($absolutePath);
        if ($path === false || !is_file($path) || !is_readable($path)) {
            return $this->failure('file_unavailable', 'The original document is no longer available to the screening service.');
        }
        $size = filesize($path);
        $maxSize = max(1024 * 1024, min(50 * 1024 * 1024, (int)(getenv('DOCUMENT_OCR_MAX_FILE_BYTES') ?: 20 * 1024 * 1024)));
        if ($size === false || $size < 1 || $size > $maxSize) {
            return $this->failure('invalid_file_size', 'The document exceeds the screening size limit or is empty.');
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            return $this->failure('unsupported_format', 'Only PDF, JPEG, and PNG documents can be screened.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($path);
        $allowed = [
            'pdf' => ['application/pdf', 'application/x-pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
        ];
        if (!in_array($mime, $allowed[$extension], true) || !$this->validSignature($path, $extension)) {
            return $this->failure('unsafe_file_type', 'The document content does not match an approved PDF or image signature.');
        }
        if ($declaredMime !== null && $declaredMime !== '' && !in_array($declaredMime, $allowed[$extension], true)) {
            return $this->failure('declared_type_mismatch', 'The stored document type is inconsistent with an approved file format.');
        }

        $pageCount = $extension === 'pdf' ? $this->pdfPageCount($path) : 1;
        $maxPages = max(1, min(50, (int)(getenv('DOCUMENT_OCR_MAX_PAGES') ?: 10)));
        if ($pageCount < 1 || $pageCount > $maxPages) {
            return $this->failure('invalid_page_count', 'The document has an unsupported or excessive number of pages.');
        }
        return [
            'success' => true,
            'absolute_path' => $path,
            'extension' => $extension,
            'mime_type' => $mime,
            'file_size' => (int)$size,
            'page_count' => $pageCount,
        ];
    }

    /** @return array<string,mixed> */
    private function extractPdf(string $path, string $mime, int $pageCount): array
    {
        // Text PDFs are processed before OCR so they retain cleaner text and do
        // not require rendering potentially complex PDF content to an image.
        $pdfText = $this->extractPdfText($path);
        if ($pdfText !== null && mb_strlen($pdfText, 'UTF-8') >= 20) {
            return $this->success($pdfText, 'pdftotext', 1.0, $pageCount, []);
        }

        $renderer = $this->binary('DOCUMENT_OCR_PDFTOPPM_BIN');
        if ($renderer === null || $this->binary('DOCUMENT_OCR_TESSERACT_BIN') === null) {
            return $this->failure('ocr_engine_unavailable', 'A local PDF text extractor or scanned-PDF OCR engine has not been configured.');
        }
        $tempRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'halal-keeps-doc-screening';
        $tempDir = $tempRoot . DIRECTORY_SEPARATOR . bin2hex(random_bytes(12));
        if (!@mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
            return $this->failure('temporary_storage_unavailable', 'The server cannot prepare a secure temporary OCR workspace.');
        }

        try {
            $prefix = $tempDir . DIRECTORY_SEPARATOR . 'page';
            $run = $this->run([$renderer, '-f', '1', '-l', (string)$pageCount, '-png', $path, $prefix]);
            if (!$run['success']) return $this->failure('pdf_render_failed', 'The scanned PDF could not be safely rendered for OCR.');

            $texts = [];
            $locations = [];
            $confidences = [];
            for ($page = 1; $page <= $pageCount; $page++) {
                $image = $prefix . '-' . $page . '.png';
                if (!is_file($image)) continue;
                $pageResult = $this->extractImage($image, 'image/png', $page);
                if (empty($pageResult['success'])) continue;
                $texts[] = "\f" . (string)$pageResult['text'];
                $locations = array_merge($locations, $pageResult['locations'] ?? []);
                if (isset($pageResult['confidence'])) $confidences[] = (float)$pageResult['confidence'];
            }
            $text = trim(implode("\n", $texts));
            if ($text === '') return $this->failure('unreadable', 'No reliable text could be read from this scanned PDF.');
            $confidence = $confidences === [] ? null : array_sum($confidences) / count($confidences);
            return $this->success($text, 'tesseract_scanned_pdf', $confidence, $pageCount, $locations);
        } finally {
            $this->removeTemporaryDirectory($tempDir);
        }
    }

    /** @return array<string,mixed> */
    private function extractImage(string $path, string $mime, int $page): array
    {
        $binary = $this->binary('DOCUMENT_OCR_TESSERACT_BIN');
        if ($binary === null) {
            return $this->failure('ocr_engine_unavailable', 'A local Tesseract OCR engine has not been configured.');
        }
        $language = trim((string)(getenv('DOCUMENT_OCR_LANGUAGE') ?: 'eng'));
        if (!preg_match('/^[a-z_+]{2,40}$/i', $language)) $language = 'eng';
        $run = $this->run([$binary, $path, 'stdout', '-l', $language, '--psm', '6', 'tsv']);
        if (!$run['success']) return $this->failure('ocr_execution_failed', 'The local OCR engine could not process the document.');

        $parsed = $this->parseTsv((string)$run['output'], $page);
        if ($parsed['text'] === '') return $this->failure('unreadable', 'No reliable text could be read from this document.');
        // Certificates commonly mix large headings with small date and serial
        // text near seals. Sparse-text mode supplements the normal document
        // layout pass so small printed fields are less likely to be omitted.
        $secondaryPsm = (int)(getenv('DOCUMENT_OCR_SECONDARY_PSM') ?: 11);
        if ($secondaryPsm >= 3 && $secondaryPsm <= 13 && $secondaryPsm !== 6) {
            $secondaryRun = $this->run([$binary, $path, 'stdout', '-l', $language, '--psm', (string)$secondaryPsm, 'tsv']);
            if ($secondaryRun['success']) {
                $secondary = $this->parseTsv((string)$secondaryRun['output'], $page);
                if ($secondary['text'] !== '') $parsed = $this->mergeOcrPasses($parsed, $secondary);
            }
        }
        return $this->success($parsed['text'], 'tesseract_multi_pass', $parsed['confidence'], $page, $parsed['locations']);
    }

    /** @return array{text:string,confidence:?float,locations:array<int,array<string,mixed>>} */
    private function mergeOcrPasses(array $primary, array $secondary): array
    {
        $known = [];
        foreach (preg_split('/\R/', (string)$primary['text']) ?: [] as $line) {
            $key = $this->ocrLineKey($line);
            if ($key !== '') $known[$key] = true;
        }
        $supplemental = [];
        foreach (preg_split('/\R/', (string)$secondary['text']) ?: [] as $line) {
            $key = $this->ocrLineKey($line);
            if ($key === '' || isset($known[$key])) continue;
            $known[$key] = true;
            $supplemental[] = $line;
        }
        $text = trim((string)$primary['text'] . ($supplemental === [] ? '' : "\n" . implode("\n", $supplemental)));
        $first = $primary['confidence'];
        $second = $secondary['confidence'];
        $confidence = $first === null ? $second : ($second === null ? $first : ((float)$first + (float)$second) / 2);
        return [
            'text' => $this->normaliseText($text),
            'confidence' => $confidence,
            'locations' => array_merge($primary['locations'], $secondary['locations']),
        ];
    }

    private function ocrLineKey(string $line): string
    {
        $line = mb_strtolower($line, 'UTF-8');
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $line) ?? '');
    }

    private function extractPdfText(string $path): ?string
    {
        $binary = $this->binary('DOCUMENT_OCR_PDFTOTEXT_BIN');
        if ($binary === null) return null;
        $run = $this->run([$binary, '-layout', '-enc', 'UTF-8', $path, '-']);
        if (!$run['success']) return null;
        $text = $this->normaliseText((string)$run['output']);
        return $text === '' ? null : $text;
    }

    private function binary(string $environmentKey): ?string
    {
        $path = trim((string)(getenv($environmentKey) ?: ''));
        if ($path === '' || str_contains($path, "\0")) return null;
        $real = realpath($path);
        if ($real === false || !is_file($real)) return null;
        return $real;
    }

    /** @return array{success:bool,output:string} */
    private function run(array $arguments): array
    {
        if (!function_exists('exec')) return ['success' => false, 'output' => ''];
        $command = implode(' ', array_map('escapeshellarg', $arguments));
        $output = [];
        $exitCode = 1;
        @exec($command . ' 2>&1', $output, $exitCode);
        return ['success' => $exitCode === 0, 'output' => implode("\n", $output)];
    }

    /** @return array{text:string,confidence:?float,locations:array<int,array<string,mixed>>} */
    private function parseTsv(string $tsv, int $page): array
    {
        $words = [];
        $confidences = [];
        $locations = [];
        $groupedLines = [];
        $lines = preg_split('/\R/', $tsv) ?: [];
        array_shift($lines);
        foreach ($lines as $line) {
            $parts = explode("\t", $line);
            if (count($parts) < 12) continue;
            $text = trim((string)$parts[11]);
            $confidence = is_numeric($parts[10]) ? ((float)$parts[10] / 100) : -1;
            if ($text === '' || $confidence < 0) continue;
            $lineKey = implode(':', [$parts[1], $parts[2], $parts[3], $parts[4]]);
            $groupedLines[$lineKey][] = $text;
            $confidences[] = max(0, min(1, $confidence));
            $locations[] = [
                'page' => $page,
                'location' => sprintf('x:%s y:%s w:%s h:%s', $parts[6], $parts[7], $parts[8], $parts[9]),
                'text' => $text,
                'confidence' => max(0, min(1, $confidence)),
            ];
        }
        foreach ($groupedLines as $lineWords) $words[] = implode(' ', $lineWords);
        return [
            'text' => $this->normaliseText(implode("\n", $words)),
            'confidence' => $confidences === [] ? null : array_sum($confidences) / count($confidences),
            'locations' => $locations,
        ];
    }

    private function normaliseText(string $text): string
    {
        $text = preg_replace('/\x{0000}/u', '', $text) ?? '';
        $text = preg_replace('/[\t ]+/', ' ', $text) ?? '';
        $text = preg_replace('/\R{3,}/', "\n\n", $text) ?? '';
        return trim($text);
    }

    private function validSignature(string $path, string $extension): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) return false;
        $head = (string)fread($handle, 16);
        fclose($handle);
        return match ($extension) {
            'pdf' => str_starts_with($head, '%PDF-'),
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'png' => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
            default => false,
        };
    }

    private function pdfPageCount(string $path): int
    {
        $contents = @file_get_contents($path, false, null, 0, 5 * 1024 * 1024);
        if (!is_string($contents) || $contents === '') return 0;
        $count = preg_match_all('/\/Type\s*\/Page\b/', $contents, $matches);
        return max(1, (int)$count);
    }

    /** @return array<string,mixed> */
    private function success(string $text, string $method, ?float $confidence, int $pageCount, array $locations): array
    {
        return [
            'success' => true,
            'text' => $this->normaliseText($text),
            'method' => $method,
            'confidence' => $confidence,
            'page_count' => $pageCount,
            'locations' => $locations,
        ];
    }

    /** @return array<string,mixed> */
    private function failure(string $code, string $message): array
    {
        return ['success' => false, 'error_code' => $code, 'message' => $message];
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) @unlink($file);
        }
        @rmdir($directory);
    }
}
