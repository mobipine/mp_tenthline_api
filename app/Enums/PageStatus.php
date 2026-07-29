<?php

namespace App\Enums;

enum PageStatus: string
{
    case Success = 'success';
    case LowConfidence = 'low_confidence';
    case Failed = 'failed';

    public function label(): string
    {
        return match($this) {
            self::Success => 'Success',
            self::LowConfidence => 'Low Confidence',
            self::Failed => 'Failed',
        };
    }

    public function isBillable(bool $billLowConfidence = false): bool
    {
        return match($this) {
            self::Success => true,
            self::LowConfidence => $billLowConfidence,
            self::Failed => false,
        };
    }
}
