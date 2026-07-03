<?php

namespace App\Console\Commands;

use App\Services\PdfLineNumberService;
use Illuminate\Console\Command;

class DiagnoseLineNumberingCommand extends Command
{
    protected $signature = 'tenthline:diagnose
        {input : Path to the source PDF}
        {--output= : Where to write the numbered PDF (defaults to <input>.numbered.pdf)}
        {--interval=10 : Line interval to number on}
        {--margin=right : left or right}
        {--expected= : Path to a JSON file mapping page number => expected text-line count}
        {--json : Print the full diagnostics payload as JSON}';

    protected $description = 'Run line numbering on a PDF and report per-page diagnostics for accuracy tuning';

    public function handle(PdfLineNumberService $service): int
    {
        $input = (string) $this->argument('input');
        if (! is_file($input)) {
            $this->error("Input PDF not found: {$input}");

            return self::FAILURE;
        }

        $output = (string) ($this->option('output') ?: $input . '.numbered.pdf');
        $expected = $this->loadExpected();

        $this->info("Numbering: {$input}");
        $this->line("Output:    {$output}");

        try {
            $service->addLineNumbers(
                $input,
                $output,
                (int) $this->option('interval'),
                (string) $this->option('margin'),
                8,
                null,
                ['pdf_job_id' => 'diagnose-' . substr(sha1($input), 0, 8)]
            );
        } catch (\Throwable $e) {
            $this->error('Line numbering failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $diagnostics = $service->getLastRunDiagnostics();

        if ($this->option('json')) {
            $this->line((string) json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderSummary($diagnostics, $expected);

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>|null
     */
    private function loadExpected(): ?array
    {
        $path = $this->option('expected');
        if (! is_string($path) || $path === '') {
            return null;
        }

        if (! is_file($path)) {
            $this->warn("Expected file not found, skipping accuracy check: {$path}");

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            $this->warn('Expected file is not valid JSON, skipping accuracy check.');

            return null;
        }

        $map = [];
        foreach ($decoded as $page => $count) {
            $map[(int) $page] = (int) $count;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @param  array<int, int>|null  $expected
     */
    private function renderSummary(array $diagnostics, ?array $expected): void
    {
        $pages = is_array($diagnostics['pages'] ?? null) ? $diagnostics['pages'] : [];

        $rows = [];
        $totalDriftPages = 0;
        $withinTolerance = 0;
        $comparablePages = 0;

        foreach ($pages as $pageNo => $page) {
            $pageDiag = is_array($page['diagnostics'] ?? null) ? $page['diagnostics'] : [];
            $lines = (int) ($page['lines_on_page'] ?? 0);

            $expectedCount = $expected[$pageNo] ?? null;
            $drift = $expectedCount !== null ? ($lines - $expectedCount) : null;
            if ($drift !== null) {
                $comparablePages++;
                if ($drift !== 0) {
                    $totalDriftPages++;
                }
                if (abs($drift) <= 0) {
                    $withinTolerance++;
                }
            }

            $rows[] = [
                $pageNo,
                $pageDiag['engine'] ?? '?',
                $pageDiag['ocr_provider'] ?? '-',
                $page['placement_mode'] ?? '?',
                (int) ($pageDiag['raw_lines_detected'] ?? 0),
                $lines,
                (int) ($page['labels_drawn'] ?? 0),
                number_format((float) ($page['page_confidence'] ?? 0.0), 2),
                $expectedCount ?? '-',
                $drift === null ? '-' : ($drift > 0 ? "+{$drift}" : (string) $drift),
            ];
        }

        $this->table(
            ['Pg', 'Engine', 'OCR', 'Mode', 'Raw', 'Lines', 'Labels', 'Conf', 'Exp', 'Drift'],
            $rows
        );

        $this->line('Total labels drawn: ' . (int) ($diagnostics['total_labels_drawn'] ?? 0));
        $this->line('Fallback pages:     ' . (int) ($diagnostics['fallback_pages'] ?? 0));

        if ($comparablePages > 0) {
            $exact = round(($withinTolerance / $comparablePages) * 100, 1);
            $this->newLine();
            $this->info("Exact-count pages: {$withinTolerance}/{$comparablePages} ({$exact}%)");
            $this->line("Pages with drift:  {$totalDriftPages}");
        } else {
            $this->comment('Pass --expected=<map.json> to score line-count accuracy.');
        }
    }
}
