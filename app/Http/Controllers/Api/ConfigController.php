<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Settings\AppSettings;
use Illuminate\Http\JsonResponse;

class ConfigController extends Controller
{
    public function show(AppSettings $settings): JsonResponse
    {
        $user = auth('sanctum')->user();
        $defaultPricePerPage = max(0.0, (float) $settings->price_per_page);
        $pricePerPage = $user
            ? $user->getEffectivePricePerPage($defaultPricePerPage)
            : $defaultPricePerPage;

        return response()->json([
            'enable_payment' => $settings->enable_payment,
            'price_per_page' => (float) $pricePerPage,
            'currency' => $settings->currency,
            'max_file_size_mb' => $settings->max_file_size_mb,
            'max_pages' => $settings->max_pages,
        ]);
    }
}
