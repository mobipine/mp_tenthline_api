<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Fpdi;

class PdfFpdiCompatibilityService
{
    public const UNSUPPORTED_MESSAGE = 'This PDF uses a compression or structure that our line-numbering processor cannot read yet. Please re-export or print it as a standard PDF and try again.';

    /**
     * @return array{
     *     processable: bool,
     *     path: string,
     *     normalized: bool,
     *     temporary_path: string|null,
     *     message: string|null
     * }
     */
    public function resolveProcessablePath(string $inputPath): array
    {
        if ($this->canOpenWithFpdi($inputPath, 'original')) {
            return [
                'processable' => true,
                'path' => $inputPath,
                'normalized' => false,
                'temporary_path' => null,
                'message' => null,
            ];
        }

        $normalizedPath = $this->normalizeWithQpdf($inputPath);
        if ($normalizedPath !== null && $this->canOpenWithFpdi($normalizedPath, 'normalized')) {
            Log::info('[TenthLine] PDF normalized for FPDI compatibility', [
                'input_path' => $inputPath,
                'normalized_path' => $normalizedPath,
            ]);

            return [
                'processable' => true,
                'path' => $normalizedPath,
                'normalized' => true,
                'temporary_path' => $normalizedPath,
                'message' => null,
            ];
        }

        $this->cleanup($normalizedPath);

        return [
            'processable' => false,
            'path' => $inputPath,
            'normalized' => false,
            'temporary_path' => null,
            'message' => self::UNSUPPORTED_MESSAGE,
        ];
    }

    public function unsupportedMessage(): string
    {
        return self::UNSUPPORTED_MESSAGE;
    }

    public function cleanup(?string $temporaryPath): void
    {
        if (! is_string($temporaryPath) || $temporaryPath === '') {
            return;
        }

        if (is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
    }

    private function canOpenWithFpdi(string $filePath, string $context): bool
    {
        try {
            $pdf = new Fpdi('P', 'pt');
            $pageCount = $pdf->setSourceFile($filePath);

            Log::debug('[TenthLine] FPDI compatibility check passed', [
                'file' => $filePath,
                'context' => $context,
                'page_count' => $pageCount,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[TenthLine] FPDI compatibility check failed', [
                'file' => $filePath,
                'context' => $context,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function normalizeWithQpdf(string $inputPath): ?string
    {
        $binary = $this->resolveQpdfBinary();
        if ($binary === '') {
            Log::warning('[TenthLine] qpdf not available for PDF normalization', [
                'input_path' => $inputPath,
            ]);

            return null;
        }

        $temporaryBase = tempnam(sys_get_temp_dir(), 'tenthline-qpdf-');
        if ($temporaryBase === false) {
            Log::warning('[TenthLine] Failed to create temp file for qpdf normalization', [
                'input_path' => $inputPath,
            ]);

            return null;
        }

        @unlink($temporaryBase);
        $outputPath = $temporaryBase . '.pdf';

        $command = sprintf(
            '%s %s --qdf --object-streams=disable %s 2>&1',
            escapeshellcmd($binary),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );

        $commandOutput = [];
        $exitCode = 0;
        @exec($command, $commandOutput, $exitCode);

        if (in_array($exitCode, [0, 3], true) && is_file($outputPath) && (filesize($outputPath) ?: 0) > 0) {
            if ($commandOutput !== []) {
                Log::info('[TenthLine] qpdf normalized PDF with warnings', [
                    'input_path' => $inputPath,
                    'normalized_path' => $outputPath,
                    'output' => implode("\n", $commandOutput),
                ]);
            }

            return $outputPath;
        }

        Log::warning('[TenthLine] qpdf failed to normalize PDF', [
            'input_path' => $inputPath,
            'normalized_path' => $outputPath,
            'exit_code' => $exitCode,
            'output' => implode("\n", $commandOutput),
        ]);

        $this->cleanup($outputPath);

        return null;
    }

    private function resolveQpdfBinary(): string
    {
        $configured = trim((string) config('line_numbering.qpdf_binary', 'qpdf'));
        if ($configured === '') {
            return '';
        }

        if (str_contains($configured, DIRECTORY_SEPARATOR)) {
            return is_executable($configured) ? $configured : '';
        }

        $resolved = trim((string) @shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($configured))));

        return $resolved;
    }
}
