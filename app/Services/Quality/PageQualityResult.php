<?php

namespace App\Services\Quality;

use App\Enums\PageStatus;

final class PageQualityResult
{
    public function __construct(
        public readonly int $pageNumber,
        public readonly PageStatus $status,
        public readonly float $ocrConfidence,
        public readonly int $textBoxCount,
        public readonly int $extractedChars,
        public readonly float $pageCoveragePct,
        public readonly string $placementMode,
        public readonly int $lineLabelsApplied,
        public readonly bool $isBillable,
        public readonly ?string $notes = null,
        public readonly ?array $rawDiagnostics = null,
    ) {}

    public function toArray(): array
    {
        return [
            'page_number' => $this->pageNumber,
            'status' => $this->status->value,
            'ocr_confidence' => $this->ocrConfidence,
            'text_box_count' => $this->textBoxCount,
            'extracted_chars' => $this->extractedChars,
            'page_coverage_pct' => $this->pageCoveragePct,
            'placement_mode' => $this->placementMode,
            'line_labels_applied' => $this->lineLabelsApplied,
            'is_billable' => $this->isBillable,
            'notes' => $this->notes,
        ];
    }
}
