<?php

namespace App\Services\Quality;

use App\Models\PageProcessingResult;
use App\Models\PdfJob;
use App\Models\ProcessingReport;
use App\Settings\AppSettings;
use App\Settings\OcrQualitySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessingReportGenerator
{
    public function __construct(
        private readonly AppSettings $appSettings,
        private readonly OcrQualitySettings $qualitySettings,
    ) {}

    /**
     * Persist a ProcessingReport and all per-page PageProcessingResult rows for a completed job.
     *
     * @param  list<PageQualityResult>  $pageResults
     */
    public function generate(PdfJob $job, array $pageResults): ProcessingReport
    {
        $uploadedPages = count($pageResults);
        $successfulPages = 0;
        $lowConfidencePages = 0;
        $failedPages = 0;

        foreach ($pageResults as $result) {
            match ($result->status) {
                \App\Enums\PageStatus::Success => $successfulPages++,
                \App\Enums\PageStatus::LowConfidence => $lowConfidencePages++,
                \App\Enums\PageStatus::Failed => $failedPages++,
            };
        }

        $payablePages = $successfulPages + ($this->qualitySettings->bill_low_confidence_pages ? $lowConfidencePages : 0);

        $unitPrice = $this->resolveUnitPrice($job);
        $totalAmount = round($payablePages * $unitPrice, 2);

        $report = DB::transaction(function () use (
            $job, $pageResults, $uploadedPages, $successfulPages,
            $lowConfidencePages, $failedPages, $payablePages, $unitPrice, $totalAmount
        ): ProcessingReport {
            $report = ProcessingReport::create([
                'pdf_job_id' => $job->id,
                'uploaded_pages' => $uploadedPages,
                'successful_pages' => $successfulPages,
                'low_confidence_pages' => $lowConfidencePages,
                'failed_pages' => $failedPages,
                'payable_pages' => $payablePages,
                'unit_price' => $unitPrice,
                'total_amount' => $totalAmount,
                'currency' => $this->appSettings->currency,
                'bill_low_confidence_pages' => $this->qualitySettings->bill_low_confidence_pages,
                'threshold_min_confidence' => $this->qualitySettings->min_ocr_confidence,
                'threshold_min_text_boxes' => $this->qualitySettings->min_text_boxes,
                'threshold_min_chars' => $this->qualitySettings->min_extracted_chars,
                'threshold_min_page_coverage' => $this->qualitySettings->min_page_coverage_pct,
            ]);

            $rows = [];
            foreach ($pageResults as $result) {
                $rows[] = [
                    'processing_report_id' => $report->id,
                    'page_number' => $result->pageNumber,
                    'status' => $result->status->value,
                    'ocr_confidence' => $result->ocrConfidence,
                    'text_box_count' => $result->textBoxCount,
                    'extracted_chars' => $result->extractedChars,
                    'page_coverage_pct' => $result->pageCoveragePct,
                    'placement_mode' => $result->placementMode,
                    'line_labels_applied' => $result->lineLabelsApplied,
                    'is_billable' => $result->isBillable,
                    'notes' => $result->notes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                PageProcessingResult::insert($rows);
            }

            return $report;
        });

        Log::info('[TenthLine] ProcessingReportGenerator: report generated', [
            'pdf_job_id' => $job->id,
            'report_id' => $report->id,
            'uploaded_pages' => $uploadedPages,
            'successful_pages' => $successfulPages,
            'low_confidence_pages' => $lowConfidencePages,
            'failed_pages' => $failedPages,
            'payable_pages' => $payablePages,
            'total_amount' => $totalAmount,
            'currency' => $this->appSettings->currency,
        ]);

        return $report;
    }

    private function resolveUnitPrice(PdfJob $job): float
    {
        $user = $job->user;

        if ($user && $user->price_per_page !== null) {
            return (float) $user->price_per_page;
        }

        return (float) $this->appSettings->price_per_page;
    }
}
