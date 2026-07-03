<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailOtp;
use App\Models\User;
use App\Notifications\Auth\LoginOtpNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    private const OTP_EXPIRY_MINUTES = 10;

    public function register(Request $request): JsonResponse
    {
        return $this->requestOtpForMode($request, 'register');
    }

    public function login(Request $request): JsonResponse
    {
        return $this->requestOtpForMode($request, 'login');
    }

    public function requestOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:login,register'],
        ]);

        return $this->requestOtpForMode($request, $validated['mode']);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'digits:6'],
        ]);

        $email = strtolower($validated['email']);
        $otp = EmailOtp::query()
            ->where('email', $email)
            ->where('purpose', 'auth')
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if (! $otp) {
            return response()->json([
                'message' => 'OTP not found. Request a new code.',
            ], 422);
        }

        if ($otp->expires_at->isPast()) {
            $otp->forceFill(['used_at' => now()])->save();

            return response()->json([
                'message' => 'OTP expired. Request a new code.',
            ], 422);
        }

        if ($otp->attempts >= $otp->max_attempts) {
            return response()->json([
                'message' => 'OTP attempts exceeded. Request a new code.',
            ], 422);
        }

        if (! Hash::check($validated['code'], $otp->code_hash)) {
            $attempts = $otp->attempts + 1;
            $otp->forceFill([
                'attempts' => $attempts,
                'used_at' => $attempts >= $otp->max_attempts ? now() : null,
            ])->save();

            return response()->json([
                'message' => 'Invalid OTP code.',
            ], 422);
        }

        $otp->forceFill(['used_at' => now()])->save();

        $createdAccount = false;
        $user = User::where('email', $email)->first();

        if (! $user) {
            $createdAccount = true;
            $user = User::create([
                'name' => $this->nameFromEmail($email),
                'email' => $email,
                'password' => Str::random(40),
            ]);
        }

        $this->ensureCustomerRole($user);

        $token = $user->createToken('frontend-session')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->serializeUser($user),
            'created_account' => $createdAccount,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->serializeUser($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function forgotPassword(): JsonResponse
    {
        return response()->json([
            'message' => 'Password authentication is disabled. Use one-time email codes.',
        ], 410);
    }

    public function resetPassword(): JsonResponse
    {
        return response()->json([
            'message' => 'Password authentication is disabled. Use one-time email codes.',
        ], 410);
    }

    private function requestOtpForMode(Request $request, string $mode): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $email = strtolower($validated['email']);
        $existingUser = User::where('email', $email)->first();

        if ($mode === 'login' && ! $existingUser) {
            return response()->json([
                'message' => 'No account found for this email. Please sign up first.',
                'code' => 'account_not_found',
            ], 404);
        }

        if ($mode === 'register' && $existingUser) {
            return response()->json([
                'message' => 'This email already has an account. Please sign in.',
                'code' => 'account_exists',
            ], 409);
        }

        $otpCode = (string) random_int(100000, 999999);

        EmailOtp::query()
            ->where('email', $email)
            ->where('purpose', 'auth')
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        EmailOtp::create([
            'email' => $email,
            'purpose' => 'auth',
            'code_hash' => Hash::make($otpCode),
            'expires_at' => now()->addMinutes(self::OTP_EXPIRY_MINUTES),
            'attempts' => 0,
            'max_attempts' => 5,
        ]);

        $recipientName = $existingUser?->name ?: $this->nameFromEmail($email);
        $notification = new LoginOtpNotification($otpCode, self::OTP_EXPIRY_MINUTES, $recipientName);

        try {
            if ($existingUser) {
                $existingUser->notify($notification);
            } else {
                Notification::route('mail', $email)->notify($notification);
            }
        } catch (\Throwable $e) {
            Log::warning('[TenthLine] auth.otp.send_failed', [
                'email' => $email,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Could not send OTP email right now. Please try again.',
            ], 422);
        }

        return response()->json([
            'message' => 'A one-time code has been sent to your email.',
            'otp_expires_in_seconds' => self::OTP_EXPIRY_MINUTES * 60,
            'user_exists' => (bool) $existingUser,
            'mode' => $mode,
        ]);
    }

    private function serializeUser(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
        ];
    }

    private function ensureCustomerRole(User $user): void
    {
        if ($user->hasRole('super_admin') || $user->hasRole('customer')) {
            return;
        }

        try {
            $customerRole = Role::findOrCreate('customer');
            $user->assignRole($customerRole);
        } catch (\Throwable $e) {
            Log::warning('[TenthLine] auth.customer_role_assignment_failed', [
                'user_id' => $user->id,
                'email' => $user->email,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function nameFromEmail(string $email): string
    {
        $localPart = Str::before($email, '@');
        $label = trim(str_replace(['.', '_', '-'], ' ', $localPart));

        return Str::title($label ?: 'TenthLine Customer');
    }
}
