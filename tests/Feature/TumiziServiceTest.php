<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Services\TumiziService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TumiziServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_payment_uses_tumizi_partner_api_contract(): void
    {
        config([
            'services.tumizi.api_key' => 'tipk_test_fake',
            'services.tumizi.base_url' => 'https://api.sandbox.tumizi.africa/api/partner/v1',
        ]);

        Http::fake([
            'https://api.sandbox.tumizi.africa/*' => Http::response([
                'success' => true,
                'data' => [
                    'customer_payment_id' => 55,
                    'status' => 'initiated',
                ],
            ], 201),
        ]);

        $payment = Payment::create([
            'amount' => 150,
            'currency' => 'KES',
            'phone' => '254700000000',
            'reference' => 'pay_TUMIZI_API_TEST',
            'status' => 'pending',
        ]);

        $result = app(TumiziService::class)->initiateCustomerPayment($payment);

        $this->assertSame(55, $result['customer_payment_id']);
        Http::assertSent(function ($request) use ($payment): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.sandbox.tumizi.africa/api/partner/v1/me/customer-payments'
                && $request->hasHeader('Authorization', 'Bearer tipk_test_fake')
                && $request->hasHeader('Idempotency-Key')
                && $payload['external_reference'] === $payment->reference
                && strlen($payload['account_reference']) <= 12
                && $payload['payer']['phone_number'] === '254700000000';
        });
    }
}
