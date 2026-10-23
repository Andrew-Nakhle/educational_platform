<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Every api/* response must be JSON, even when the client sends no
 * Accept: application/json header.
 */
class ApiJsonResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_json_401(): void
    {
        $this->get('/api/auth/profile')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_missing_api_route_returns_json_404(): void
    {
        $this->get('/api/this-route-does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    public function test_missing_model_returns_json_404(): void
    {
        $this->get('/api/student/courses/999')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_validation_error_returns_json_422(): void
    {
        $this->post('/api/auth/login', [])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_server_error_returns_json_500(): void
    {
        config(['app.debug' => false]);

        Route::get('/api/test-server-error', function () {
            throw new RuntimeException('Boom');
        });

        $this->get('/api/test-server-error')
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', 'Server Error');
    }
}
