<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Settings\LegalContentSettings;
use Illuminate\Http\JsonResponse;

class LegalController extends Controller
{
    public function __construct(
        protected LegalContentSettings $legal,
    ) {}

    public function terms(): JsonResponse
    {
        return response()->json([
            'content' => $this->legal->terms_and_conditions,
            'updated_at' => $this->legal->terms_updated_at,
        ]);
    }

    public function privacy(): JsonResponse
    {
        return response()->json([
            'content' => $this->legal->privacy_policy,
            'updated_at' => $this->legal->privacy_updated_at,
        ]);
    }
}
