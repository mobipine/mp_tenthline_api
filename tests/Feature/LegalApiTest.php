<?php

namespace Tests\Feature;

use App\Settings\LegalContentSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalApiTest extends TestCase
{
    use RefreshDatabase;

    private function bindSettings(array $values): void
    {
        $this->app->bind(LegalContentSettings::class, function () use ($values) {
            $settings = \Mockery::mock(LegalContentSettings::class)->makePartial();
            $settings->terms_and_conditions = $values['terms_and_conditions'] ?? null;
            $settings->privacy_policy = $values['privacy_policy'] ?? null;
            $settings->terms_updated_at = $values['terms_updated_at'] ?? null;
            $settings->privacy_updated_at = $values['privacy_updated_at'] ?? null;
            return $settings;
        });
    }

    public function test_terms_endpoint_returns_content(): void
    {
        $this->bindSettings(['terms_and_conditions' => '<p>Our terms</p>']);

        $this->getJson('/api/legal/terms')
            ->assertOk()
            ->assertJsonStructure(['content', 'updated_at'])
            ->assertJsonPath('content', '<p>Our terms</p>');
    }

    public function test_privacy_endpoint_returns_content(): void
    {
        $this->bindSettings(['privacy_policy' => '<p>Our privacy policy</p>']);

        $this->getJson('/api/legal/privacy')
            ->assertOk()
            ->assertJsonPath('content', '<p>Our privacy policy</p>');
    }

    public function test_terms_endpoint_returns_null_when_not_configured(): void
    {
        $this->bindSettings([]);

        $this->getJson('/api/legal/terms')
            ->assertOk()
            ->assertJsonPath('content', null);
    }
}
