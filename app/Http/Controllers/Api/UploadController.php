<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPdfJob;
use App\Models\PdfJob;
use App\Settings\AppSettings;
use App\Services\PdfFpdiCompatibilityService;
use App\Services\PdfPageCounter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UploadController extends Controller
{
    public function __construct(
        protected PdfPageCounter $pageCounter,
        protected PdfFpdiCompatibilityService $fpdiCompatibility
    ) {}

    public function store(Request $request, AppSettings $settings): JsonResponse
    {
        Log::info('[TenthLine] upload.store.received', [
            'line_interval' => $request->input('line_interval'),
            'margin' => $request->input('margin'),
            'font_size_pt' => $request->input('font_size_pt'),
            'has_file' => $request->hasFile('file'),
        ]);

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:' . ($settings->max_file_size_mb * 1024)],
            'line_interval' => ['sometimes', 'integer', 'in:5,10'],
            'margin' => ['sometimes', 'string', 'in:left,right'],
            'font_size_pt' => ['sometimes', 'integer', 'in:8,9,10'],
        ]);

        $file = $request->file('file');

        if ($file->getClientOriginalExtension() !== 'pdf') {
            return response()->json(['message' => 'Only PDF files are allowed.'], 422);
        }

        $pageCount = $this->pageCounter->countPages($file->getRealPath());
        if ($pageCount < 1) {
            return response()->json([
                'message' => 'This PDF appears damaged, corrupted, or unsupported. Please re-export or re-download it and try again.',
                'code' => 'pdf_damaged',
            ], 422);
        }
        if ($pageCount > $settings->max_pages) {
            return response()->json([
                'message' => "This PDF has {$pageCount} pages. Max allowed is {$settings->max_pages}.",
            ], 422);
        }

        $compatibility = $this->fpdiCompatibility->resolveProcessablePath($file->getRealPath());
        try {
            if (! $compatibility['processable']) {
                return response()->json([
                    'message' => $compatibility['message'] ?? $this->fpdiCompatibility->unsupportedMessage(),
                    'code' => 'pdf_processing_unsupported',
                ], 422);
            }
        } finally {
            $this->fpdiCompatibility->cleanup($compatibility['temporary_path']);
        }

        $requestUser = $request->user();

        $job = new PdfJob([
            'filename' => $file->getClientOriginalName(),
            'status' => 'pending',
            'user_id' => $requestUser?->id,
            'line_interval' => (int) $request->input('line_interval', 10),
            'page_count' => $pageCount,
            'total_pages' => $pageCount,
            'margin' => $request->input('margin', 'right'),
            'font_size_pt' => (int) $request->input('font_size_pt', 8),
        ]);
        $job->save();

        Log::info('[TenthLine] upload.store.job_created', [
            'job_id' => $job->id,
            'user_id' => $requestUser?->id,
            'filename' => $job->filename,
            'page_count' => $job->page_count,
        ]);

        $dir = "pdf-jobs/{$job->id}";
        $file->storeAs($dir, 'input.pdf', 'local');

        ProcessPdfJob::dispatch($job->id);

        Log::info('[TenthLine] upload.store.dispatched', ['job_id' => $job->id]);

        return response()->json([
            'job_id' => $job->id,
            'status' => 'pending',
        ], 201);
    }
}
