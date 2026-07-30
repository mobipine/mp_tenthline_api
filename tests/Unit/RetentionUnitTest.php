<?php

namespace Tests\Unit;

use App\Enums\RetentionUnit;
use PHPUnit\Framework\TestCase;

class RetentionUnitTest extends TestCase
{
    public function test_hours_to_hours_is_identity(): void
    {
        $this->assertSame(24, RetentionUnit::Hours->toHours(24));
    }

    public function test_days_converts_to_hours(): void
    {
        $this->assertSame(48, RetentionUnit::Days->toHours(2));
    }

    public function test_weeks_converts_to_hours(): void
    {
        $this->assertSame(336, RetentionUnit::Weeks->toHours(2));
    }

    public function test_retention_settings_helper_uses_enum(): void
    {
        $this->assertSame(168, RetentionUnit::Weeks->toHours(1));
    }
}
