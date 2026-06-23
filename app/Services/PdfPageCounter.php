<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Fpdi;
use Smalot\PdfParser\Parser as SmalotPdfParser;

class PdfPageCounter
{
    public function countPages(string $filePath): int
    {
        $pageCount = $this->countWithFpdi($filePath);
        if ($pageCount > 0) {
            Log::info('[TenthLine] PDF page count resolved with FPDI', [
                'file' => $filePath,
                'page_count' => $pageCount,
            ]);

            return $pageCount;
        }

        $pageCount = $this->countWithPdfInfo($filePath);
        if ($pageCount > 0) {
            Log::info('[TenthLine] PDF page count resolved with pdfinfo fallback', [
                'file' => $filePath,
                'page_count' => $pageCount,
            ]);

            return $pageCount;
        }

        $pageCount = $this->countWithSmalotParser($filePath);
        if ($pageCount > 0) {
            Log::info('[TenthLine] PDF page count resolved with Smalot parser fallback', [
                'file' => $filePath,
                'page_count' => $pageCount,
            ]);

            return $pageCount;
        }

        Log::warning('[TenthLine] PDF page count could not be determined', [
            'file' => $filePath,
        ]);

        return 0;
    }

    protected function countWithFpdi(string $filePath): int
    {
        try {
            $pdf = new Fpdi('P', 'pt');
            $pageCount = $pdf->setSourceFile($filePath);

            return (int) $pageCount;
        } catch (\Throwable $e) {
            Log::warning('[TenthLine] FPDI failed to count PDF pages', [
                'file' => $filePath,
                'message' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    protected function countWithSmalotParser(string $filePath): int
    {
        try {
            $parser = new SmalotPdfParser();
            $document = $parser->parseFile($filePath);
            $pages = $document->getPages();

            return is_array($pages) ? count($pages) : 0;
        } catch (\Throwable $e) {
            Log::warning('[TenthLine] Smalot parser failed to count PDF pages', [
                'file' => $filePath,
                'message' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    protected function countWithPdfInfo(string $filePath): int
    {
        $binary = trim((string) @shell_exec('command -v pdfinfo 2>/dev/null'));
        if ($binary === '') {
            return 0;
        }

        $cmd = sprintf('%s %s 2>/dev/null', escapeshellcmd($binary), escapeshellarg($filePath));
        $output = @shell_exec($cmd);
        if (! is_string($output) || $output === '') {
            return 0;
        }

        if (preg_match('/^Pages:\\s+(\\d+)$/mi', $output, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }
}
