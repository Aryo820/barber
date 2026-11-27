<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_ok_and_cors_header(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
        ])->getJson('/api/health');

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'barberflow-api',
                'database' => 'connected',
            ])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}
