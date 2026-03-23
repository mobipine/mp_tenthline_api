<?php

namespace Tests\Concerns;

use setasign\Fpdi\Fpdi;

trait CreatesLineNumberingPdfs
{
    /**
     * @param  list<array<string, mixed>>  $pages
     */
    protected function createPdf(string $path, array $pages, float $pageWidth = 612.0, float $pageHeight = 792.0): void
    {
        $pdf = new Fpdi('P', 'pt');

        foreach ($pages as $page) {
            $pdf->AddPage('P', [$pageWidth, $pageHeight]);
            $pdf->SetFont('Helvetica', '', (int) ($page['font_size'] ?? 12));

            foreach (($page['texts'] ?? []) as $text) {
                $pdf->Text(
                    (float) ($text['x'] ?? 72.0),
                    (float) ($text['y'] ?? 72.0),
                    (string) ($text['text'] ?? '')
                );
            }
        }

        $pdf->Output('F', $path);
    }

    /**
     * @param  list<string>  $bodyLines
     * @return array<string, mixed>
     */
    protected function legalPage(array $bodyLines, string $header, string $footer, int $pageNumber): array
    {
        $texts = [
            ['x' => 72.0, 'y' => 42.0, 'text' => $header],
            ['x' => 72.0, 'y' => 58.0, 'text' => 'IN THE HIGH COURT'],
        ];

        foreach ($bodyLines as $index => $line) {
            $texts[] = [
                'x' => 72.0,
                'y' => 96.0 + (($index + 1) * 20.0),
                'text' => $line,
            ];
        }

        $texts[] = ['x' => 72.0, 'y' => 742.0, 'text' => $footer];
        $texts[] = ['x' => 300.0, 'y' => 742.0, 'text' => 'Page ' . $pageNumber];

        return [
            'texts' => $texts,
            'font_size' => 12,
        ];
    }

    protected function bboxXml(string $pdfPath): string
    {
        $xml = shell_exec(sprintf('pdftotext -bbox-layout -enc UTF-8 %s - 2>/dev/null', escapeshellarg($pdfPath)));

        return is_string($xml) ? $xml : '';
    }

    /**
     * @return array{xMin: float, xMax: float, yMin: float, yMax: float}|null
     */
    protected function findWordBox(string $bboxXml, int $pageNo, string $word): ?array
    {
        if (trim($bboxXml) === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($bboxXml);
        if (! $loaded) {
            $loaded = $dom->loadHTML($bboxXml);
        }
        libxml_clear_errors();

        if (! $loaded) {
            return null;
        }

        $xpath = new \DOMXPath($dom);
        $pageNodes = $xpath->query('//*[local-name()="page"]');
        if (! $pageNodes instanceof \DOMNodeList || $pageNodes->length < $pageNo) {
            return null;
        }

        $pageNode = $pageNodes->item($pageNo - 1);
        if (! $pageNode instanceof \DOMElement) {
            return null;
        }

        $wordNodes = $xpath->query('.//*[local-name()="word"]', $pageNode);
        if (! $wordNodes instanceof \DOMNodeList) {
            return null;
        }

        foreach ($wordNodes as $wordNode) {
            if (! $wordNode instanceof \DOMElement) {
                continue;
            }

            if (trim((string) $wordNode->textContent) !== $word) {
                continue;
            }

            return [
                'xMin' => (float) $wordNode->getAttribute('xMin'),
                'xMax' => (float) $wordNode->getAttribute('xMax'),
                'yMin' => (float) $wordNode->getAttribute('yMin'),
                'yMax' => (float) $wordNode->getAttribute('yMax'),
            ];
        }

        return null;
    }

    protected function wordMidY(array $box): float
    {
        return ($box['yMin'] + $box['yMax']) / 2;
    }

    protected function commandExists(string $command): bool
    {
        $path = shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($command)));

        return is_string($path) && trim($path) !== '';
    }

    protected function commandPath(string $command): string
    {
        $path = shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($command)));

        return is_string($path) ? trim($path) : '';
    }
}
