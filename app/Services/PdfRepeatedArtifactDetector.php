<?php

namespace App\Services;

class PdfRepeatedArtifactDetector
{
    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array<string, string>>
     */
    public function detect(array $pages): array
    {
        $pageCount = count($pages);
        if ($pageCount < 2) {
            return [];
        }

        $repeatThreshold = max(
            2,
            (int) ceil($pageCount * $this->headerFooterRepeatThreshold())
        );
        $positionTolerance = max(8.0, (float) config('line_numbering.header_footer_position_tolerance_pt', 18.0));

        $groups = [];

        foreach ($pages as $pageNo => $page) {
            $pageHeight = max(1.0, (float) ($page['page_height'] ?? 0.0));
            $rawLines = is_array($page['raw_lines'] ?? null) ? $page['raw_lines'] : [];

            foreach ($rawLines as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $lineId = (string) ($line['id'] ?? '');
                if ($lineId === '') {
                    continue;
                }

                $region = $this->candidateRegion($line, $pageHeight);
                if ($region === null) {
                    continue;
                }

                foreach ($this->normalizedKeys((string) ($line['text'] ?? '')) as $key) {
                    $groups[$region . '|' . $key][] = [
                        'page_no' => (int) $pageNo,
                        'line_id' => $lineId,
                        'y' => (float) ($line['y'] ?? 0.0),
                    ];
                }
            }
        }

        $detected = [];

        foreach ($groups as $groupKey => $items) {
            $uniquePages = array_values(array_unique(array_map(
                static fn (array $item): int => $item['page_no'],
                $items
            )));

            if (count($uniquePages) < $repeatThreshold) {
                continue;
            }

            [$region] = explode('|', $groupKey, 2);
            $medianY = $this->median(array_map(
                static fn (array $item): float => (float) $item['y'],
                $items
            ));

            $stableItems = array_values(array_filter($items, static function (array $item) use ($medianY, $positionTolerance): bool {
                return abs(((float) $item['y']) - $medianY) <= $positionTolerance;
            }));

            $stablePages = array_values(array_unique(array_map(
                static fn (array $item): int => $item['page_no'],
                $stableItems
            )));

            if (count($stablePages) < $repeatThreshold) {
                continue;
            }

            foreach ($stableItems as $item) {
                $detected[$item['page_no']][$item['line_id']] = $region;
            }
        }

        return $detected;
    }

    private function candidateRegion(array $line, float $pageHeight): ?string
    {
        if ($pageHeight <= 0) {
            return null;
        }

        $headerRatio = max(0.05, min(0.25, (float) config('line_numbering.header_region_ratio', 0.12)));
        $footerRatio = max(0.05, min(0.25, (float) config('line_numbering.footer_region_ratio', 0.12)));

        $top = (float) ($line['top'] ?? $line['y'] ?? 0.0);
        $bottom = (float) ($line['bottom'] ?? $line['y'] ?? 0.0);

        if ($top >= ($pageHeight * (1.0 - $headerRatio))) {
            return 'header';
        }

        if ($bottom <= ($pageHeight * $footerRatio)) {
            return 'footer';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function normalizedKeys(string $text): array
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return [];
        }

        $keys = [$normalized];

        $alphaNumeric = trim((string) preg_replace('/\d+/u', ' ', $normalized));
        $alphaNumeric = trim((string) preg_replace('/\s+/u', ' ', $alphaNumeric));
        if ($alphaNumeric !== '') {
            $keys[] = $alphaNumeric;
        }

        $lettersOnly = trim((string) preg_replace('/[^a-z]+/u', ' ', $normalized));
        $lettersOnly = trim((string) preg_replace('/\s+/u', ' ', $lettersOnly));
        if ($lettersOnly !== '') {
            $keys[] = $lettersOnly;
        }

        if ($lettersOnly === '' && preg_match('/^[\d\W]+$/u', $normalized) === 1) {
            $keys[] = '__numeric_page_marker__';
        }

        return array_values(array_unique(array_filter($keys)));
    }

    private function normalizeText(string $text): string
    {
        $normalized = mb_strtolower($text);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? '';
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? '';

        return trim((string) preg_replace('/\s+/u', ' ', $normalized));
    }

    private function headerFooterRepeatThreshold(): float
    {
        return max(0.20, min(0.95, (float) config('line_numbering.header_footer_repeat_threshold', 0.35)));
    }

    /**
     * @param  list<float>  $numbers
     */
    private function median(array $numbers): float
    {
        if ($numbers === []) {
            return 0.0;
        }

        sort($numbers, SORT_NUMERIC);
        $count = count($numbers);
        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($numbers[$mid - 1] + $numbers[$mid]) / 2;
        }

        return $numbers[$mid];
    }
}
