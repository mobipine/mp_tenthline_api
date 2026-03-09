<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPdfJob;
use App\Models\Payment;
use App\Models\PdfJob;
use App\Settings\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    private const FIXED_LINE_INTERVAL = 10;

    public function store(Request $request, AppSettings $settings): JsonResponse
    {
        $paymentsEnabled = (bool) $settings->enable_payment;
        Log::info('[LegalLine] upload.store.received', [
            'enable_payment' => $paymentsEnabled,
            'simulation_mode' => ! $paymentsEnabled,
            'line_interval' => self::FIXED_LINE_INTERVAL,
            'margin' => $request->input('margin'),
            'font_size_pt' => $request->input('font_size_pt'),
            'has_file' => $request->hasFile('file'),
        ]);

        // A completed payment reference is always required. When enable_payment=false,
        // completion is simulated by the payment status endpoint.
        $reference = $request->input('payment_reference');
        if (! $reference) {
            Log::warning('[LegalLine] upload.store.missing_payment_reference');
            return response()->json(['message' => 'Payment reference required.'], 422);
        }
        $payment = Payment::where('reference', $reference)->first();
        if (! $payment || $payment->status !== 'completed') {
            Log::warning('[LegalLine] upload.store.invalid_payment', [
                'reference' => $reference,
                'payment_found' => (bool) $payment,
                'payment_status' => $payment?->status,
            ]);
            return response()->json(['message' => 'Valid payment required.'], 422);
        }

        if ($request->user() && $payment->user_id && (int) $request->user()->id !== (int) $payment->user_id) {
            Log::warning('[LegalLine] upload.store.forbidden_payment_user_mismatch', [
                'reference' => $reference,
                'payment_user_id' => $payment->user_id,
                'request_user_id' => $request->user()->id,
            ]);
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($payment->pdf_job_id) {
            Log::warning('[LegalLine] upload.store.payment_already_used', [
                'reference' => $reference,
                'payment_id' => $payment->id,
                'pdf_job_id' => $payment->pdf_job_id,
            ]);
            return response()->json(['message' => 'This payment has already been used.'], 422);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:' . ($settings->max_file_size_mb * 1024)],
            'margin' => ['sometimes', 'string', 'in:left,right'],
            'font_size_pt' => ['sometimes', 'integer', 'in:8,9,10'],
        ]);

        $file = $request->file('file');

        if ($file->getClientOriginalExtension() !== 'pdf') {
            Log::warning('[LegalLine] upload.store.invalid_extension', [
                'reference' => $reference,
                'extension' => $file->getClientOriginalExtension(),
            ]);
            return response()->json(['message' => 'Only PDF files are allowed.'], 422);
        }

        $job = new PdfJob([
            'filename' => $file->getClientOriginalName(),
            'status' => 'pending',
            'user_id' => $payment->user_id ?? $request->user()?->id,
            'line_interval' => self::FIXED_LINE_INTERVAL,
            'margin' => $request->input('margin', 'left'),
            'font_size_pt' => (int) $request->input('font_size_pt', 8),
        ]);
        $job->payment_id = $payment->id;
        $job->save();
        Log::info('[LegalLine] upload.store.job_created', [
            'job_id' => $job->id,
            'payment_id' => $payment->id,
            'reference' => $reference,
            'filename' => $job->filename,
            'line_interval' => $job->line_interval,
            'margin' => $job->margin,
            'font_size_pt' => $job->font_size_pt,
        ]);

        $dir = "pdf-jobs/{$job->id}";
        $storedPath = $file->storeAs($dir, 'input.pdf', 'local');
        Log::info('[LegalLine] upload.store.file_saved', [
            'job_id' => $job->id,
            'stored_path' => $storedPath,
        ]);

        $job->update(['status' => 'pending']);

        $payment->update(['pdf_job_id' => $job->id]);
        Log::info('[LegalLine] upload.store.payment_linked_to_job', [
            'payment_id' => $payment->id,
            'job_id' => $job->id,
        ]);

        if (! $paymentsEnabled) {
            Log::info('[LegalLine] upload.store.dispatch_sync_enable_payment_disabled', ['job_id' => $job->id]);
            try {
                ProcessPdfJob::dispatchSync($job->id);
            } catch (\Throwable $e) {
                Log::error('[LegalLine] upload.store.dispatch_sync_failed', [
                    'job_id' => $job->id,
                    'message' => $e->getMessage(),
                ]);
                $job->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }
        } else {
            ProcessPdfJob::dispatch($job->id);
            Log::info('[LegalLine] upload.store.dispatch_async', ['job_id' => $job->id]);
        }

        return response()->json([
            'job_id' => $job->id,
            'status' => $job->fresh()->status,
        ], 201);
    }
}
