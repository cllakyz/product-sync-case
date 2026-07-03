<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_public_and_reports_checks()
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => ['status', 'checks' => ['database', 'redis', 'queue'], 'queue_backlog', 'last_sync'],
            ]);
    }
}
