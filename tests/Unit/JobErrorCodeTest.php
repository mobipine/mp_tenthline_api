<?php

namespace Tests\Unit;

use App\Enums\JobErrorCode;
use PHPUnit\Framework\TestCase;

class JobErrorCodeTest extends TestCase
{
    public function test_all_cases_have_a_user_message(): void
    {
        foreach (JobErrorCode::cases() as $case) {
            $message = $case->userMessage();
            $this->assertIsString($message);
            $this->assertNotEmpty($message, "No user message for {$case->value}");
        }
    }

    public function test_payment_deadline_expired_message_mentions_reupload(): void
    {
        $message = JobErrorCode::PaymentDeadlineExpired->userMessage();
        $this->assertStringContainsStringIgnoringCase('re-upload', $message);
    }

    public function test_zero_successful_pages_message_mentions_scan(): void
    {
        $message = JobErrorCode::ZeroSuccessfulPages->userMessage();
        $this->assertStringContainsStringIgnoringCase('scan', $message);
    }

    public function test_enum_values_match_expected_strings(): void
    {
        $this->assertSame('zero_successful_pages', JobErrorCode::ZeroSuccessfulPages->value);
        $this->assertSame('payment_deadline_expired', JobErrorCode::PaymentDeadlineExpired->value);
        $this->assertSame('ocr_unavailable', JobErrorCode::OcrUnavailable->value);
    }
}
