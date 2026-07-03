<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MpesaWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        Log::info('[TenthLine] mpesa.webhook.received', $request->all());

        $body = $request->all();
        $callback = $body['Body'] ?? $body;
        $stkCallback = $callback['stkCallback'] ?? null;

        if (! $stkCallback) {
            Log::warning('[TenthLine] mpesa.webhook.missing_stk_callback');
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }

        $checkoutRequestId = $stkCallback['CheckoutRequestID'] ?? null;
        $resultCode = (int) ($stkCallback['ResultCode'] ?? 1);
        $callbackMetadata = $stkCallback['CallbackMetadata'] ?? null;

        $payment = Payment::where('mpesa_checkout_request_id', $checkoutRequestId)->first();

        if (! $payment) {
            Log::warning('[TenthLine] mpesa.webhook.payment_not_found', [
                'checkout_request_id' => $checkoutRequestId,
                'result_code' => $resultCode,
            ]);
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }

        if ($resultCode === 0 && $callbackMetadata) {
            $payment->update([
                'status' => 'completed',
                'mpesa_result_code' => (string) $resultCode,
                'mpesa_callback_payload' => $body,
            ]);
            Log::info('[TenthLine] mpesa.webhook.payment_completed', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
            ]);
        } else {
            $payment->update([
                'status' => 'failed',
                'mpesa_result_code' => (string) $resultCode,
                'mpesa_callback_payload' => $body,
            ]);
            Log::warning('[TenthLine] mpesa.webhook.payment_failed', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'result_code' => $resultCode,
            ]);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
