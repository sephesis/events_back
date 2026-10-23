<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_health_endpoint_is_up(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_api_root_lists_endpoints(): void
    {
        $this->getJson('/api/v1')->assertOk()->assertJsonPath('status', 'ok')->assertJsonFragment(['POST /api/v1/itineraries/plan']);
        $this->get('/')->assertRedirect('/api/v1');
    }
}
