<?php

namespace App\Enums;

enum SupportTicketStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case WaitingForCustomer = 'waiting_for_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match($this) {
            self::Open => 'Open',
            self::Investigating => 'Investigating',
            self::WaitingForCustomer => 'Waiting for Customer',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Open => 'warning',
            self::Investigating => 'info',
            self::WaitingForCustomer => 'gray',
            self::Resolved => 'success',
            self::Closed => 'gray',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Resolved, self::Closed]);
    }
}
