<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\Auth\WelcomeCustomerNotification;
use App\Settings\AppSettings;
use App\Services\MpesaService;
use App\Services\PdfPageCounter;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class PaymentController extends Controller
{
    public function __construct(
        protected AppSettings $settings,
        protected MpesaService $mpesa,
        protected PdfPageCounter $pageCounter
    ) {}

    public function quote(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:' . ($this->settings->max_file_size_mb * 1024)],
        ]);

        $file = $request->file('file');
        if (! $file || $file->getClientOriginalExtension() !== 'pdf') {
            return response()->json(['message' => 'Only PDF files are allowed.'], 422);
        }

        $pageCount = $this->pageCounter->countPages($file->getRealPath());
        if ($pageCount < 1) {
            return response()->json(['message' => 'Could not read pages from this PDF.'], 422);
        }
        if ($pageCount > $this->settings->max_pages) {
            return response()->json([
                'message' => "This PDF has {$pageCount} pages. Max allowed is {$this->settings->max_pages}.",
            ], 422);
        }

        $user = auth('sanctum')->user();
        $defaultPricePerPage = max(0.0, (float) $this->settings->price_per_page);
        $unitPrice = $user
            ? $user->getEffectivePricePerPage($defaultPricePerPage)
            : $defaultPricePerPage;
        $amount = round($unitPrice * $pageCount, 2);

        return response()->json([
            'page_count' => $pageCount,
            'unit_price' => $unitPrice,
            'amount' => $amount,
            'currency' => $this->settings->currency,
        ]);
    }

    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^(?:254[0-9]{9}|0[0-9]{9})$/'],
            'email' => ['required', 'string', 'email'],
            'page_count' => ['required', 'integer', 'min:1', 'max:' . $this->settings->max_pages],
        ]);

        $phone = $this->normalizePhone($validated['phone']);
        $email = strtolower($validated['email']);
        $pageCount = (int) $validated['page_count'];
        [$user, $issuedToken, $createdByPayment] = $this->resolveUserForPayment($request, $email, $phone);
        $defaultPricePerPage = max(0.0, (float) $this->settings->price_per_page);
        $unitPrice = $user->getEffectivePricePerPage($defaultPricePerPage);
        $amount = round($unitPrice * $pageCount, 2);
        $reference = Payment::generateReference();
        $paymentsEnabled = (bool) $this->settings->enable_payment;
        $zeroAmountCharge = $amount <= 0.0;

        Log::info('[LegalLine] payment.initiate.received', [
            'phone' => $phone,
            'email' => $email,
            'user_id' => $user->id,
            'created_by_payment' => $createdByPayment,
            'page_count' => $pageCount,
            'unit_price' => $unitPrice,
            'amount' => $amount,
            'currency' => $this->settings->currency,
            'enable_payment' => $paymentsEnabled,
            'simulation_mode' => ! $paymentsEnabled,
        ]);

        $payment = Payment::create([
            'amount' => $amount,
            'currency' => $this->settings->currency,
            'page_count' => $pageCount,
            'user_id' => $user->id,
            'email' => $email,
            'phone' => $phone,
            'reference' => $reference,
            'status' => 'pending',
        ]);

        Log::info('[LegalLine] payment.initiate.created', [
            'payment_id' => $payment->id,
            'reference' => $reference,
            'user_id' => $user->id,
        ]);

        if ($zeroAmountCharge) {
            $payment->update([
                'status' => 'completed',
                'mpesa_result_code' => '0',
                'mpesa_callback_payload' => [
                    'simulated' => true,
                    'mode' => 'zero_amount_auto_completed',
                    'completed_at' => now()->toIso8601String(),
                ],
            ]);
            $payment->refresh();

            Log::info('[LegalLine] payment.initiate.zero_amount_auto_completed', [
                'payment_id' => $payment->id,
                'reference' => $reference,
                'user_id' => $user->id,
            ]);
        } elseif ($paymentsEnabled) {
            $result = $this->mpesa->stkPush($phone, $amount, $reference, $payment->id);
            Log::info('[LegalLine] payment.initiate.stk_response', [
                'payment_id' => $payment->id,
                'reference' => $reference,
                'has_checkout_request_id' => isset($result['CheckoutRequestID']),
            ]);

            if (isset($result['CheckoutRequestID'])) {
                $payment->update([
                    'mpesa_merchant_request_id' => $result['MerchantRequestID'] ?? null,
                    'mpesa_checkout_request_id' => $result['CheckoutRequestID'],
                ]);
            }
        } else {
            Log::info('[LegalLine] payment.initiate.simulation_skip_stk_enable_payment_disabled', [
                'payment_id' => $payment->id,
                'reference' => $reference,
            ]);
        }

        return response()->json([
            'payment_id' => $payment->id,
            'reference' => $reference,
            'message' => $zeroAmountCharge ? 'No payment required. Proceeding to upload.' : 'Complete payment on your phone.',
            'created_account' => $createdByPayment,
            'auth_token' => $issuedToken,
            'user' => $this->serializeUser($user),
            'page_count' => $pageCount,
            'unit_price' => $unitPrice,
            'amount' => $amount,
            'currency' => $this->settings->currency,
        ]);
    }

    public function status(Request $request, string $reference): JsonResponse
    {
        $payment = Payment::where('reference', $reference)->firstOrFail();
        $paymentsEnabled = (bool) $this->settings->enable_payment;
        $elapsedSeconds = $payment->created_at
            ? abs(now()->timestamp - $payment->created_at->timestamp)
            : null;
        $requestUser = auth('sanctum')->user();

        if ($requestUser && $payment->user_id && (int) $requestUser->id !== (int) $payment->user_id) {
            Log::warning('[LegalLine] payment.status.forbidden', [
                'reference' => $reference,
                'payment_user_id' => $payment->user_id,
                'request_user_id' => $requestUser->id,
            ]);

            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($payment->status === 'pending' && (float) $payment->amount <= 0.0) {
            $payment->update([
                'status' => 'completed',
                'mpesa_result_code' => '0',
                'mpesa_callback_payload' => [
                    'simulated' => true,
                    'mode' => 'zero_amount_auto_completed',
                    'completed_at' => now()->toIso8601String(),
                ],
            ]);
            $payment->refresh();

            Log::info('[LegalLine] payment.status.zero_amount_auto_completed', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'user_id' => $payment->user_id,
            ]);
        } elseif (
            ! $paymentsEnabled
            && $payment->status === 'pending'
            && $elapsedSeconds !== null
            && $elapsedSeconds >= 5
        ) {
            $payment->update([
                'status' => 'completed',
                'mpesa_result_code' => '0',
                'mpesa_callback_payload' => [
                    'simulated' => true,
                    'mode' => 'enable_payment_disabled',
                    'completed_at' => now()->toIso8601String(),
                ],
            ]);
            $payment->refresh();

            Log::info('[LegalLine] payment.status.simulated_completed', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'user_id' => $payment->user_id,
            ]);
        }

        // Log::info('[LegalLine] payment.status.response', [
        //     'payment_id' => $payment->id,
        //     'reference' => $payment->reference,
        //     'status' => $payment->status,
        //     'enable_payment' => $paymentsEnabled,
        //     'simulation_mode' => ! $paymentsEnabled,
        //     'elapsed_seconds' => $elapsedSeconds,
        // ]);

        return response()->json([
            'status' => $payment->status,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'page_count' => (int) $payment->page_count,
        ]);
    }

    /**
     * @return array{0: User, 1: string|null, 2: bool}
     */
    protected function resolveUserForPayment(Request $request, string $email, string $phone): array
    {
        $requestUser = auth('sanctum')->user();

        if ($requestUser) {
            if ($requestUser->phone !== $phone) {
                $requestUser->forceFill(['phone' => $phone])->save();

                Log::info('[LegalLine] payment.initiate.auth_user_phone_updated', [
                    'user_id' => $requestUser->id,
                    'email' => $requestUser->email,
                    'phone' => $phone,
                ]);
            }

            $this->ensureCustomerRole($requestUser);
            return [$requestUser, null, false];
        }

        $existingUser = User::where('email', $email)->first();
        if ($existingUser) {
            Log::info('[LegalLine] payment.initiate.existing_user_requires_sign_in', [
                'email' => $email,
                'user_id' => $existingUser->id,
            ]);

            throw new HttpResponseException(response()->json([
                'message' => 'This email already has an account. Please sign in with OTP to continue.',
                'code' => 'existing_user_sign_in_required',
            ], 409));
        }

        $createdByPayment = true;
        $user = User::create([
            'name' => $this->nameFromEmail($email),
            'email' => $email,
            'phone' => $phone,
            'password' => Str::random(40),
        ]);

        try {
            $user->notify(new WelcomeCustomerNotification);
        } catch (\Throwable $e) {
            Log::warning('[LegalLine] payment.user_creation.welcome_email_failed', [
                'user_id' => $user->id,
                'email' => $user->email,
                'message' => $e->getMessage(),
            ]);
        }

        $this->ensureCustomerRole($user);

        $token = $user->createToken('frontend-session')->plainTextToken;

        return [$user, $token, $createdByPayment];
    }

    protected function nameFromEmail(string $email): string
    {
        $localPart = Str::before($email, '@');
        $label = trim(str_replace(['.', '_', '-'], ' ', $localPart));

        return Str::title($label ?: 'LegalLine Customer');
    }

    protected function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
        ];
    }

    protected function ensureCustomerRole(User $user): void
    {
        if ($user->hasRole('super_admin') || $user->hasRole('customer')) {
            return;
        }

        try {
            $customerRole = Role::findOrCreate('customer');
            $user->assignRole($customerRole);
        } catch (\Throwable $e) {
            Log::warning('[LegalLine] payment.user_creation.customer_role_assignment_failed', [
                'user_id' => $user->id,
                'email' => $user->email,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function normalizePhone(string $rawPhone): string
    {
        $digits = preg_replace('/\D+/', '', $rawPhone) ?? '';
        $lastNine = substr($digits, -9);

        return '254' . $lastNine;
    }
}
