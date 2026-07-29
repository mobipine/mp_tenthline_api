<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_submit_support_ticket(): void
    {
        $response = $this->postJson('/api/support/tickets', [
            'email' => 'user@example.com',
            'subject' => 'Cannot download my PDF',
            'description' => 'After payment the download link is broken.',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['ticket_id', 'reference', 'message']);

        $this->assertDatabaseHas('support_tickets', [
            'email' => 'user@example.com',
            'subject' => 'Cannot download my PDF',
            'status' => 'open',
        ]);
    }

    public function test_ticket_reference_is_unique_and_prefixed(): void
    {
        $this->postJson('/api/support/tickets', [
            'email' => 'a@example.com',
            'subject' => 'Test',
            'description' => 'Test description here.',
        ])->assertCreated();

        $ticket = SupportTicket::first();
        $this->assertStringStartsWith('TKT-', $ticket->reference);
    }

    public function test_validation_requires_email_subject_description(): void
    {
        $this->postJson('/api/support/tickets', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'subject', 'description']);
    }

    public function test_description_max_length_is_enforced(): void
    {
        $this->postJson('/api/support/tickets', [
            'email' => 'a@example.com',
            'subject' => 'Test',
            'description' => str_repeat('x', 5001),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['description']);
    }

    public function test_optional_job_id_is_stored(): void
    {
        $this->postJson('/api/support/tickets', [
            'email' => 'a@example.com',
            'subject' => 'Job issue',
            'description' => 'My job seems stuck in processing.',
            'job_id' => 'some-job-uuid',
        ])->assertCreated();

        $this->assertDatabaseHas('support_tickets', [
            'pdf_job_id' => 'some-job-uuid',
        ]);
    }
}
