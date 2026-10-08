<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TumiziService
{
    public function initiateCustomerPayment(Payment $payment): array
    {
        $apiKey = (string) config('services.tumizi.api_key');

        if ($apiKey === '') {
            throw new \RuntimeException('Tumizi API key is not configured.');
        }

        $payload = [
            'external_reference' => $payment->reference,
            'payer' => ['phone_number' => $payment->phone],
            'amount' => (float) $payment->amount,
            // Tumizi limits account_reference to 12 characters; the full
            // payment reference remains the idempotent external_reference.
            'account_reference' => 'TL'.Str::upper(Str::substr($payment->reference, -10)),
            'description' => 'TenthLine PDF processing',
        ];

        $response = Http::timeout((int) config('services.tumizi.timeout', 20))
            ->acceptJson()
            ->withToken($apiKey)
            ->withHeaders([
                'Idempotency-Key' => (string) Str::uuid(),
                'X-Correlation-Id' => $payment->reference,
            ])
            ->post(rtrim((string) config('services.tumizi.base_url'), '/').'/me/customer-payments', $payload);

        if (! $response->successful()) {
            Log::error('[TenthLine] Tumizi customer payment failed', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            throw new \RuntimeException('Tumizi could not initiate the payment.');
        }

        return $response->json('data', $response->json() ?? []);
    }

    public function wallet(): array
    {
        $response = Http::timeout((int) config('services.tumizi.timeout', 20))
            ->acceptJson()
            ->withToken((string) config('services.tumizi.api_key'))
            ->get(rtrim((string) config('services.tumizi.base_url'), '/').'/me/wallet');

        $response->throw();

        return $response->json('data', $response->json() ?? []);
    }
}
