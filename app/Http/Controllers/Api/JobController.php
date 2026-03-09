<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PdfJob;
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

        $data = [
            'id' => $job->id,
            'status' => $job->status,
            'progress' => $job->progress,
            'processed_pages' => $job->processed_pages,
            'total_pages' => $job->total_pages,
            'eta_seconds' => $job->eta_seconds,
            'error_message' => $job->error_message,
        ];

        if ($job->status === 'completed' && $job->output_path) {
            $data['download_url'] = url("/api/job/{$job->id}/download");
        }

        return response()->json($data);
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
}
