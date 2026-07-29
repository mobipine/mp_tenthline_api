<?php

namespace Tests\Unit;

use App\Enums\SupportTicketStatus;
use PHPUnit\Framework\TestCase;

class SupportTicketStatusTest extends TestCase
{
    public function test_resolved_and_closed_are_terminal(): void
    {
        $this->assertTrue(SupportTicketStatus::Resolved->isTerminal());
        $this->assertTrue(SupportTicketStatus::Closed->isTerminal());
    }

    public function test_open_is_not_terminal(): void
    {
        $this->assertFalse(SupportTicketStatus::Open->isTerminal());
    }

    public function test_investigating_is_not_terminal(): void
    {
        $this->assertFalse(SupportTicketStatus::Investigating->isTerminal());
    }

    public function test_waiting_for_customer_is_not_terminal(): void
    {
        $this->assertFalse(SupportTicketStatus::WaitingForCustomer->isTerminal());
    }

    public function test_all_cases_have_a_label(): void
    {
        foreach (SupportTicketStatus::cases() as $status) {
            $label = $status->label();
            $this->assertIsString($label);
            $this->assertNotEmpty($label);
        }
    }
}
