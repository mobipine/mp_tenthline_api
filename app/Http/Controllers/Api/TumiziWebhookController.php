<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PdfJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TumiziWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $timestamp = (string) $request->header('X-Tumizi-Timestamp', '');
        $signature = (string) $request->header('X-Tumizi-Signature', '');
        $secret = (string) config('services.tumizi.webhook_secret', '');

        if ($secret !== '') {
            $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
            $age = $this->timestampAge($timestamp);

            if ($timestamp === '' || $signature === '' || $age === null || $age > (int) config('services.tumizi.webhook_tolerance', 300) || ! hash_equals($expected, $signature)) {
                Log::warning('[TenthLine] Tumizi webhook rejected', ['delivery' => $request->header('X-Tumizi-Delivery')]);
                return response()->json(['message' => 'Invalid webhook signature.'], 400);
            }
        } elseif (app()->environment('production') && ! app()->runningUnitTests()) {
            Log::error('[TenthLine] Tumizi webhook secret is not configured.');
            return response()->json(['message' => 'Webhook verification is not configured.'], 500);
        }

        $body = json_decode($rawBody, true);
        if (! is_array($body)) {
            return response()->json(['message' => 'Invalid JSON payload.'], 400);
        }

        $event = (string) ($body['event'] ?? $request->header('X-Tumizi-Event', ''));
        $data = $body['customer_payment'] ?? [];
        $reference = (string) ($data['external_reference'] ?? '');

        if ($event !== 'partner.customer_payment.updated') {
            return response()->json(['received' => true]);
        }

        if ($reference === '') {
            Log::warning('[TenthLine] Tumizi webhook missing external reference');
            return response()->json(['message' => 'Missing external reference.'], 422);
        }

        $payment = Payment::where('reference', $reference)->first();
        if (! $payment) {
            Log::warning('[TenthLine] Tumizi webhook payment not found', ['reference' => $reference]);
            return response()->json(['received' => true]);
        }

        $status = (string) ($data['status'] ?? '');
        $mappedStatus = match ($status) {
            'succeeded' => 'completed',
            'cancelled' => 'cancelled',
            'failed' => 'failed',
            default => 'pending',
        };

        $payment->update([
            'status' => $mappedStatus,
            'tumizi_payment_id' => $data['customer_payment_id'] ?? null,
            'tumizi_status' => $status !== '' ? $status : null,
            'tumizi_receipt_number' => $data['mpesa_receipt_number'] ?? null,
            'tumizi_transaction_id' => $data['transaction_id'] ?? null,
            'tumizi_callback_payload' => $body,
            'mpesa_callback_payload' => $body,
        ]);

        if ($mappedStatus === 'completed' && $payment->pdf_job_id) {
            $job = PdfJob::find($payment->pdf_job_id);
            if ($job && $job->status === 'awaiting_payment') {
                $job->forceFill(['status' => 'completed'])->save();
            }
        }

        Log::info('[TenthLine] Tumizi webhook processed', [
            'payment_id' => $payment->id,
            'reference' => $reference,
            'status' => $status,
            'delivery' => $request->header('X-Tumizi-Delivery'),
        ]);

        return response()->json(['received' => true]);
    }

    protected function timestampAge(string $timestamp): ?int
    {
        try {
            return abs(now()->getTimestamp() - now()->parse($timestamp)->getTimestamp());
        } catch (\Throwable) {
            return null;
        }
    }
}
