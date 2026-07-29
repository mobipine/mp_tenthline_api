<?php

namespace App\Support;

use App\Models\PdfJob;

class PdfJobPayloadFactory
{
    public static function fromModel(PdfJob $job): array
    {
        $payload = [
            'id' => $job->id,
            'status' => $job->status,
            'progress' => (int) $job->progress,
            'processed_pages' => (int) $job->processed_pages,
            'total_pages' => (int) $job->total_pages,
            'eta_seconds' => $job->eta_seconds !== null ? (int) $job->eta_seconds : null,
            'error_message' => $job->error_message,
            'error_code' => $job->error_code?->value,
            'payable_pages' => $job->payable_pages,
            'payment_deadline_at' => optional($job->payment_deadline_at)->toIso8601String(),
            'storage_deleted_at' => optional($job->storage_deleted_at)->toIso8601String(),
            'updated_at' => optional($job->updated_at)->toIso8601String(),
            'download_url' => $job->status === 'completed' && $job->output_path
                ? url("/api/job/{$job->id}/download")
                : null,
            'report_url' => $job->processingReport
                ? url("/api/job/{$job->id}/report")
                : null,
        ];

        return self::withProcessingState($payload, is_array($job->ocr_diagnostics) ? $job->ocr_diagnostics : []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $ocrDiagnostics
     * @return array<string, mixed>
     */
    public static function withProcessingState(array $payload, array $ocrDiagnostics = []): array
    {
        $live = is_array($ocrDiagnostics['live'] ?? null) ? $ocrDiagnostics['live'] : [];
        $resolved = self::resolveProcessingState($payload, $live);

        return array_merge($payload, $resolved);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $live
     * @return array<string, mixed>
     */
    private static function resolveProcessingState(array $payload, array $live): array
    {
        $status = (string) ($payload['status'] ?? 'processing');
        $progress = (int) ($payload['progress'] ?? 0);
        $processedPages = (int) ($payload['processed_pages'] ?? 0);
        $totalPages = (int) ($payload['total_pages'] ?? 0);

        if ($live !== []) {
            return [
                'processing_stage' => self::stringOrNull($live['phase'] ?? null),
                'processing_label' => self::stringOrNull($live['label'] ?? null),
                'processing_message' => self::stringOrNull($live['message'] ?? null),
                'processing_detail' => self::stringOrNull($live['detail'] ?? null),
            ];
        }

        if ($status === 'completed') {
            return [
                'processing_stage' => 'completed',
                'processing_label' => 'Document ready',
                'processing_message' => 'Your PDF has been numbered and is ready to download.',
                'processing_detail' => null,
            ];
        }

        if ($status === 'awaiting_payment') {
            return [
                'processing_stage' => 'awaiting_payment',
                'processing_label' => 'Awaiting payment',
                'processing_message' => 'Processing complete. Review your report and complete payment to download.',
                'processing_detail' => null,
            ];
        }

        if ($status === 'failed') {
            return [
                'processing_stage' => 'failed',
                'processing_label' => 'Processing failed',
                'processing_message' => 'Something went wrong while processing the document.',
                'processing_detail' => null,
            ];
        }

        if ($processedPages > 0 || $progress >= 12) {
            return [
                'processing_stage' => 'adding_line_numbers',
                'processing_label' => 'Adding line numbers',
                'processing_message' => 'We are placing line numbers across the document.',
                'processing_detail' => $totalPages > 0
                    ? "Page {$processedPages} of {$totalPages}"
                    : null,
            ];
        }

        return [
            'processing_stage' => 'analyzing_document',
            'processing_label' => 'Analyzing document',
            'processing_message' => 'We are reading the PDF and preparing it for numbering.',
            'processing_detail' => $totalPages > 0 ? "{$totalPages} pages detected" : null,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
