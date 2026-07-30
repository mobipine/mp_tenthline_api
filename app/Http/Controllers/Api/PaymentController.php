<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PdfJob;
use App\Models\User;
use App\Notifications\Auth\WelcomeCustomerNotification;
use App\Settings\AppSettings;
use App\Services\MpesaService;
use App\Services\PdfFpdiCompatibilityService;
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
        protected PdfPageCounter $pageCounter,
        protected PdfFpdiCompatibilityService $fpdiCompatibility
    ) {}

    /**
     * Pre-upload price estimate. Amount may differ from actual processing report
     * because OCR quality evaluation may exclude some pages.
     */
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
            return response()->json([
                'message' => 'This PDF appears damaged, corrupted, or unsupported. Please re-export or re-download it and try again.',
                'code' => 'pdf_damaged',
            ], 422);
        }
        if ($pageCount > $this->settings->max_pages) {
            return response()->json([
                'message' => "This PDF has {$pageCount} pages. Max allowed is {$this->settings->max_pages}.",
            ], 422);
        }

        $compatibility = $this->fpdiCompatibility->resolveProcessablePath($file->getRealPath());
        try {
            if (! $compatibility['processable']) {
                return response()->json([
                    'message' => $compatibility['message'] ?? $this->fpdiCompatibility->unsupportedMessage(),
                    'code' => 'pdf_processing_unsupported',
                ], 422);
            }
        } finally {
            $this->fpdiCompatibility->cleanup($compatibility['temporary_path']);
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
            'is_estimate' => true,
        ]);
    }

    /**
     * Initiate M-Pesa payment for a processed job that is awaiting_payment.
     * The exact amount comes from the processing report, not the uploaded page count.
     */
    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => ['required', 'string'],
            'phone' => ['required', 'string', 'regex:/^(?:254[0-9]{9}|0[0-9]{9})$/'],
            'email' => ['required', 'string', 'email'],
        ]);

        $phone = $this->normalizePhone($validated['phone']);
        $email = strtolower($validated['email']);

        $job = PdfJob::findOrFail($validated['job_id']);

        if ($job->status !== 'awaiting_payment') {
            Log::warning('[TenthLine] payment.initiate.job_not_awaiting_payment', [
                'job_id' => $job->id,
                'status' => $job->status,
            ]);
            return response()->json([
                'message' => 'This job is not awaiting payment.',
                'code' => 'job_not_awaiting_payment',
            ], 422);
        }

        if ($job->payment_deadline_at && $job->payment_deadline_at->isPast()) {
            return response()->json([
                'message' => 'The payment deadline for this job has passed.',
                'code' => 'payment_deadline_expired',
            ], 422);
        }

        $report = $job->processingReport;
        if (! $report) {
            Log::error('[TenthLine] payment.initiate.missing_report', ['job_id' => $job->id]);
            return response()->json(['message' => 'Processing report not found.'], 500);
        }

        [$user, $issuedToken, $createdByPayment] = $this->resolveUserForPayment($request, $email, $phone);

        if ($job->user_id && (int) $job->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $amount = (float) $report->total_amount;
        $pageCount = (int) $report->payable_pages;
        $unitPrice = (float) $report->unit_price;
        $reference = Payment::generateReference();
        $paymentsEnabled = (bool) $this->settings->enable_payment;
        $zeroAmountCharge = $amount <= 0.0;

        Log::info('[TenthLine] payment.initiate.received', [
            'job_id' => $job->id,
            'phone' => $phone,
            'email' => $email,
            'user_id' => $user->id,
            'payable_pages' => $pageCount,
            'unit_price' => $unitPrice,
            'amount' => $amount,
            'currency' => $this->settings->currency,
            'enable_payment' => $paymentsEnabled,
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
            'pdf_job_id' => $job->id,
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

            $job->forceFill([
                'status' => 'completed',
                'payment_id' => $payment->id,
            ])->save();

            Log::info('[TenthLine] payment.initiate.zero_amount_auto_completed', [
                'payment_id' => $payment->id,
                'job_id' => $job->id,
            ]);
        } elseif ($paymentsEnabled) {
            $result = $this->mpesa->stkPush($phone, $amount, $reference, $payment->id);
            if (isset($result['CheckoutRequestID'])) {
                $payment->update([
                    'mpesa_merchant_request_id' => $result['MerchantRequestID'] ?? null,
                    'mpesa_checkout_request_id' => $result['CheckoutRequestID'],
                ]);
            }
        } else {
            Log::info('[TenthLine] payment.initiate.simulation_skip_stk', [
                'payment_id' => $payment->id,
                'job_id' => $job->id,
            ]);
        }

        return response()->json([
            'payment_id' => $payment->id,
            'reference' => $reference,
            'message' => $zeroAmountCharge
                ? 'No payment required. Your document is ready.'
                : 'Complete payment on your phone.',
            'created_account' => $createdByPayment,
            'auth_token' => $issuedToken,
            'user' => $this->serializeUser($user),
            'payable_pages' => $pageCount,
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

            // Transition the linked job to completed when payment simulation succeeds
            if ($payment->pdf_job_id) {
                $linkedJob = PdfJob::find($payment->pdf_job_id);
                if ($linkedJob && $linkedJob->status === 'awaiting_payment') {
                    $linkedJob->forceFill(['status' => 'completed'])->save();
                }
            }
        }

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
            }

            $this->ensureCustomerRole($requestUser);
            return [$requestUser, null, false];
        }

        $existingUser = User::where('email', $email)->first();
        if ($existingUser) {
            throw new HttpResponseException(response()->json([
                'message' => 'This email already has an account. Please sign in with OTP to continue.',
                'code' => 'existing_user_sign_in_required',
            ], 409));
        }

        $user = User::create([
            'name' => $this->nameFromEmail($email),
            'email' => $email,
            'phone' => $phone,
            'password' => Str::random(40),
        ]);

        try {
            $user->notify(new WelcomeCustomerNotification);
        } catch (\Throwable $e) {
            Log::warning('[TenthLine] payment.user_creation.welcome_email_failed', [
                'user_id' => $user->id,
                'email' => $user->email,
                'message' => $e->getMessage(),
            ]);
        }

        $this->ensureCustomerRole($user);
        $token = $user->createToken('frontend-session')->plainTextToken;

        return [$user, $token, true];
    }

    protected function nameFromEmail(string $email): string
    {
        $localPart = Str::before($email, '@');
        $label = trim(str_replace(['.', '_', '-'], ' ', $localPart));

        return Str::title($label ?: 'TenthLine Customer');
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
            Log::warning('[TenthLine] payment.user_creation.customer_role_assignment_failed', [
                'user_id' => $user->id,
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
