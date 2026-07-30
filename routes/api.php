<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\LegalController;
use App\Http\Controllers\Api\MpesaWebhookController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserJobController;
use Illuminate\Support\Facades\Route;

Route::get('config', [ConfigController::class, 'show']);

Route::get('legal/terms', [LegalController::class, 'terms']);
Route::get('legal/privacy', [LegalController::class, 'privacy']);

Route::post('support/tickets', [SupportTicketController::class, 'store'])->middleware(['auth:sanctum', 'throttle:10,1']);

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('request-otp', [AuthController::class, 'requestOtp'])->middleware('throttle:10,1');
    Route::post('verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:30,1');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware('auth:sanctum')->prefix('auth')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
});

Route::middleware('throttle:30,1')->group(function () {
    Route::post('payments/quote', [PaymentController::class, 'quote']);
    Route::post('payments/initiate', [PaymentController::class, 'initiate']);
    Route::get('payments/{reference}/status', [PaymentController::class, 'status']);
});

Route::post('webhooks/mpesa', [MpesaWebhookController::class, 'handle']);
Route::get('job/{id}/download/signed', [JobController::class, 'downloadSigned'])
    ->middleware('signed')
    ->name('jobs.download.signed');

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::post('upload', [UploadController::class, 'store']);
    Route::get('job/{id}', [JobController::class, 'show']);
    Route::get('job/{id}/report', [JobController::class, 'report']);
    Route::get('job/{id}/download', [JobController::class, 'download']);
    Route::get('me/jobs', [UserJobController::class, 'index']);
});
