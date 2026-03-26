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
            Log::warning('[LegalLine] job.show.forbidden_user_mismatch', [
                'job_id' => $job->id,
                'job_user_id' => $job->user_id,
                'request_user_id' => $request->user()->id,
            ]);

            return response()->json(['message' => 'Forbidden'], 403);
        }

        Log::info('[LegalLine] job.show', [
            'job_id' => $job->id,
            'status' => $job->status,
            'progress' => $job->progress,
            'processed_pages' => $job->processed_pages,
            'total_pages' => $job->total_pages,
        ]);

        return response()->json(PdfJobPayloadFactory::fromModel($job));
    }

    public function download(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $job = PdfJob::findOrFail($id);
        if ($request->user() && $job->user_id && (int) $request->user()->id !== (int) $job->user_id) {
            Log::warning('[LegalLine] job.download.forbidden_user_mismatch', [
                'job_id' => $job->id,
                'job_user_id' => $job->user_id,
                'request_user_id' => $request->user()->id,
            ]);

            return response()->json(['message' => 'Forbidden'], 403);
        }

        Log::info('[LegalLine] job.download.requested', [
            'job_id' => $job->id,
            'status' => $job->status,
            'output_path' => $job->output_path,
        ]);

        if ($job->status === 'deleted' || $job->storage_deleted_at) {
            return response()->json(['message' => 'This file has been deleted after retention period.'], 410);
        }

        if ($job->status !== 'completed' || ! $job->output_path) {
            Log::warning('[LegalLine] job.download.not_ready', ['job_id' => $job->id]);
            return response()->json(['message' => 'File not ready for download.'], 404);
        }

        if (! Storage::disk('local')->exists($job->output_path)) {
            Log::warning('[LegalLine] job.download.missing_output', [
                'job_id' => $job->id,
                'output_path' => $job->output_path,
            ]);
            return response()->json(['message' => 'File no longer available.'], 404);
        }

        $filename = 'numbered-' . $job->filename;
        Log::info('[LegalLine] job.download.success', [
            'job_id' => $job->id,
            'filename' => $filename,
        ]);

        return Storage::disk('local')->download(
            $job->output_path,
            $filename,
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
