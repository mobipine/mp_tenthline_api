<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TumiziWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_success_webhook_completes_payment(): void
    {
        config(['services.tumizi.webhook_secret' => 'test-secret']);
        $payment = Payment::create([
            'amount' => 150,
            'currency' => 'KES',
            'phone' => '254700000000',
            'reference' => 'pay_TUMIZI_SUCCESS',
            'status' => 'pending',
        ]);

        $body = json_encode([
            'event' => 'partner.customer_payment.updated',
            'customer_payment' => [
                'customer_payment_id' => 123,
                'external_reference' => $payment->reference,
                'status' => 'succeeded',
                'mpesa_receipt_number' => 'SB123',
                'transaction_id' => 456,
            ],
        ], JSON_UNESCAPED_SLASHES);
        $timestamp = now()->toIso8601String();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'test-secret');

        $this->call('POST', '/api/tumizi/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TUMIZI_EVENT' => 'partner.customer_payment.updated',
            'HTTP_X_TUMIZI_TIMESTAMP' => $timestamp,
            'HTTP_X_TUMIZI_SIGNATURE' => $signature,
        ], $body)
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'completed',
            'tumizi_payment_id' => '123',
            'tumizi_receipt_number' => 'SB123',
        ]);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        config(['services.tumizi.webhook_secret' => 'test-secret']);

        $this->withHeaders([
            'X-Tumizi-Timestamp' => now()->toIso8601String(),
            'X-Tumizi-Signature' => 'invalid',
        ])->postJson('/api/tumizi/webhook', [
            'event' => 'partner.customer_payment.updated',
        ])->assertStatus(400);
    }
}
