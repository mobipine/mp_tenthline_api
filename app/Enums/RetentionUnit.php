<?php

namespace App\Enums;

enum RetentionUnit: string
{
    case Hours = 'hours';
    case Days = 'days';
    case Weeks = 'weeks';

    public function toHours(int $value): int
    {
        return match($this) {
            self::Hours => $value,
            self::Days => $value * 24,
            self::Weeks => $value * 24 * 7,
        };
    }

    public function label(): string
    {
        return match($this) {
            self::Hours => 'Hours',
            self::Days => 'Days',
            self::Weeks => 'Weeks',
        };
    }
}
