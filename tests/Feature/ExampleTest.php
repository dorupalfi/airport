<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // Minimal application bootstrap smoke test retained from the Laravel starter project.
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
