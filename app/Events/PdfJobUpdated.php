<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PdfJobUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public array $payload
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('job.' . $this->payload['id'])];
    }

    public function broadcastAs(): string
    {
        return 'pdf.job.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
