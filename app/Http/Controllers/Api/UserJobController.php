<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PdfJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserJobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $jobs = PdfJob::query()
            ->with('payment:id,reference,amount,currency,status,email')
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(function (PdfJob $job): array {
                return [
                    'id' => $job->id,
                    'filename' => $job->filename,
                    'status' => $job->status,
                    'progress' => $job->progress,
                    'processed_pages' => $job->processed_pages,
                    'total_pages' => $job->total_pages,
                    'eta_seconds' => $job->eta_seconds,
                    'error_message' => $job->error_message,
                    'created_at' => optional($job->created_at)->toIso8601String(),
                    'download_url' => $job->status === 'completed' && $job->output_path
                        ? url("/api/job/{$job->id}/download")
                        : null,
                    'payment' => $job->payment ? [
                        'reference' => $job->payment->reference,
                        'amount' => (float) $job->payment->amount,
                        'currency' => $job->payment->currency,
                        'status' => $job->payment->status,
                        'email' => $job->payment->email,
                    ] : null,
                ];
            });

        return response()->json([
            'jobs' => $jobs,
        ]);
    }
}
