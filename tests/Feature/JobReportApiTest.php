<?php

namespace Tests\Feature;

use App\Models\PdfJob;
use App\Models\ProcessingReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobReportApiTest extends TestCase
{
    use RefreshDatabase;

    private function createJobWithReport(User $user, array $jobOverrides = [], array $reportOverrides = []): PdfJob
    {
        $job = PdfJob::create(array_merge([
            'id' => \Str::uuid()->toString(),
            'filename' => 'test.pdf',
            'status' => 'awaiting_payment',
            'user_id' => $user->id,
            'total_pages' => 5,
            'processed_pages' => 5,
            'progress' => 100,
            'page_count' => 5,
            'payable_pages' => 4,
            'payment_deadline_at' => now()->addHours(48),
            'line_interval' => 10,
            'margin' => 'right',
            'font_size_pt' => 8,
        ], $jobOverrides));

        $report = ProcessingReport::create(array_merge([
            'id' => \Str::uuid()->toString(),
            'pdf_job_id' => $job->id,
            'uploaded_pages' => 5,
            'successful_pages' => 4,
            'low_confidence_pages' => 0,
            'failed_pages' => 1,
            'payable_pages' => 4,
            'unit_price' => 5.00,
            'total_amount' => 20.00,
            'currency' => 'KES',
            'bill_low_confidence_pages' => false,
        ], $reportOverrides));

        return $job;
    }

    public function test_authenticated_user_can_fetch_their_job_report(): void
    {
        $user = User::factory()->create();
        $job = $this->createJobWithReport($user);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/job/{$job->id}/report");

        $response->assertOk()
            ->assertJsonStructure([
                'report' => [
                    'id', 'uploaded_pages', 'successful_pages', 'low_confidence_pages',
                    'failed_pages', 'payable_pages', 'unit_price', 'total_amount',
                    'currency', 'bill_low_confidence_pages', 'created_at',
                ],
                'pages',
                'payment_deadline_at',
            ]);
    }

    public function test_user_cannot_fetch_another_users_job_report(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $job = $this->createJobWithReport($owner);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/job/{$job->id}/report")
            ->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $user = User::factory()->create();
        $job = $this->createJobWithReport($user);

        $this->getJson("/api/job/{$job->id}/report")
            ->assertUnauthorized();
    }

    public function test_returns_404_when_report_not_yet_available(): void
    {
        $user = User::factory()->create();

        $job = PdfJob::create([
            'id' => \Str::uuid()->toString(),
            'filename' => 'test.pdf',
            'status' => 'processing',
            'user_id' => $user->id,
            'total_pages' => 5,
            'processed_pages' => 2,
            'progress' => 40,
            'page_count' => 5,
            'line_interval' => 10,
            'margin' => 'right',
            'font_size_pt' => 8,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/job/{$job->id}/report")
            ->assertNotFound();
    }

    public function test_report_amounts_are_floats(): void
    {
        $user = User::factory()->create();
        $job = $this->createJobWithReport($user, [], ['unit_price' => 5.50, 'total_amount' => 22.00]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/job/{$job->id}/report")
            ->assertOk();

        $this->assertIsFloat($response->json('report.unit_price'));
        $this->assertIsFloat($response->json('report.total_amount'));
    }
}
