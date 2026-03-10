<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MpesaService
{
    protected ?string $accessToken = null;

    protected function consumerKey(): string
    {
        return config('services.mpesa.consumer_key', '');
    }

    protected function consumerSecret(): string
    {
        return config('services.mpesa.consumer_secret', '');
    }

    protected function shortcode(): string
    {
        return config('services.mpesa.shortcode', '');
    }

    protected function passkey(): string
    {
        return config('services.mpesa.passkey', '');
    }

    protected function callbackUrl(): string
    {
        return config('services.mpesa.callback_url', '');
    }

    protected function environment(): string
    {
        return config('services.mpesa.environment', 'sandbox');
    }

    protected function getAuthUrl(): string
    {
        return $this->environment() === 'production'
            ? 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'
            : 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    }

    protected function getStkPushUrl(): string
    {
        return $this->environment() === 'production'
            ? 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
            : 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    }

    protected function getAccessToken(): ?string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        if (! $this->consumerKey() || ! $this->consumerSecret()) {
            Log::warning('M-Pesa credentials not configured. STK Push skipped.');
            return null;
        }

        $response = Http::withBasicAuth($this->consumerKey(), $this->consumerSecret())
            ->get($this->getAuthUrl());

        if (! $response->successful()) {
            Log::error('M-Pesa auth failed', ['body' => $response->body()]);
            return null;
        }

        $data = $response->json();
        $this->accessToken = $data['access_token'] ?? null;

        return $this->accessToken;
    }

    /**
     * Initiate STK Push. Returns array with CheckoutRequestID and MerchantRequestID on success.
     */
    public function stkPush(string $phone, float $amount, string $reference, string $paymentId): array
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return [];
        }

        $timestamp = date('YmdHis');
        $password = base64_encode($this->shortcode() . $this->passkey() . $timestamp);

        $payload = [
            'BusinessShortCode' => (int) $this->shortcode(),
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => (int) round($amount),
            'PartyA' => (int) $phone,
            'PartyB' => (int) $this->shortcode(),
            'PhoneNumber' => (int) $phone,
            'CallBackURL' => rtrim($this->callbackUrl(), '/') . '/api/webhooks/mpesa',
            'AccountReference' => $reference,
            'TransactionDesc' => 'LegalLine PDF processing',
        ];

        Log::info('Initiating M-Pesa STK Push', json_encode($payload));

        $response = Http::withToken($token)
            ->post($this->getStkPushUrl(), $payload);

        if (! $response->successful()) {
            Log::error('M-Pesa STK Push failed', ['body' => $response->body()]);
            return [];
        }

        return $response->json() ?? [];
    }
}
