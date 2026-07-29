<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PdfJob;
use App\Support\PdfJobPayloadFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JobController extends Controller
{
    public function show(Request $request, string $id): JsonResponse
    {
        $job = PdfJob::findOrFail($id);
        if ($request->user() && $job->user_id && (int) $request->user()->id !== (int) $job->user_id) {
            Log::warning('[TenthLine] job.show.forbidden_user_mismatch', [
                'job_id' => $job->id,
                'job_user_id' => $job->user_id,
                'request_user_id' => $request->user()->id,
            ]);

            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(PdfJobPayloadFactory::fromModel($job));
    }

    public function report(Request $request, string $id): JsonResponse
    {
        $job = PdfJob::with(['processingReport.pageResults'])->findOrFail($id);

        if ($request->user() && $job->user_id && (int) $request->user()->id !== (int) $job->user_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $report = $job->processingReport;

        if (! $report) {
            return response()->json(['message' => 'Processing report not available yet.'], 404);
        }

        return response()->json([
            'report' => [
                'id' => $report->id,
                'uploaded_pages' => $report->uploaded_pages,
                'successful_pages' => $report->successful_pages,
                'low_confidence_pages' => $report->low_confidence_pages,
                'failed_pages' => $report->failed_pages,
                'payable_pages' => $report->payable_pages,
                'unit_price' => (float) $report->unit_price,
                'total_amount' => (float) $report->total_amount,
                'currency' => $report->currency,
                'bill_low_confidence_pages' => (bool) $report->bill_low_confidence_pages,
                'created_at' => $report->created_at?->toIso8601String(),
            ],
            'pages' => $report->pageResults->map(fn ($p) => [
                'page_number' => $p->page_number,
                'status' => $p->status->value,
                'ocr_confidence' => $p->ocr_confidence !== null ? round((float) $p->ocr_confidence, 4) : null,
                'text_box_count' => $p->text_box_count,
                'extracted_chars' => $p->extracted_chars,
                'page_coverage_pct' => $p->page_coverage_pct !== null ? round((float) $p->page_coverage_pct, 4) : null,
                'placement_mode' => $p->placement_mode,
                'line_labels_applied' => $p->line_labels_applied,
                'is_billable' => (bool) $p->is_billable,
                'notes' => $p->notes,
            ])->values(),
            'payment_deadline_at' => $job->payment_deadline_at?->toIso8601String(),
        ]);
    }

    public function download(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $job = PdfJob::findOrFail($id);
        if ($request->user() && $job->user_id && (int) $request->user()->id !== (int) $job->user_id) {
            Log::warning('[TenthLine] job.download.forbidden_user_mismatch', [
                'job_id' => $job->id,
                'job_user_id' => $job->user_id,
                'request_user_id' => $request->user()->id,
            ]);

            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($job->status === 'deleted' || $job->storage_deleted_at) {
            return response()->json(['message' => 'This file has been deleted after retention period.'], 410);
        }

        if ($job->status !== 'completed' || ! $job->output_path) {
            return response()->json(['message' => 'File not ready for download.'], 404);
        }

        if (! Storage::disk('local')->exists($job->output_path)) {
            return response()->json(['message' => 'File no longer available.'], 404);
        }

        return Storage::disk('local')->download(
            $job->output_path,
            'numbered-' . $job->filename,
            ['Content-Type' => 'application/pdf']
        );
    }

    public function downloadSigned(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $job = PdfJob::findOrFail($id);

        if ($job->status === 'deleted' || $job->storage_deleted_at) {
            return response()->json(['message' => 'This file has been deleted after retention period.'], 410);
        }

        if ($job->status !== 'completed' || ! $job->output_path) {
            return response()->json(['message' => 'File not ready for download.'], 404);
        }

        if (! Storage::disk('local')->exists($job->output_path)) {
            return response()->json(['message' => 'File no longer available.'], 404);
        }

        return Storage::disk('local')->download(
            $job->output_path,
            'numbered-' . $job->filename,
            ['Content-Type' => 'application/pdf']
        );
    }
}
