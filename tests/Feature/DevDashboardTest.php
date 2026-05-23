<?php

namespace Tests\Feature;

use Tests\TestCase;

class DevDashboardTest extends TestCase
{
    public function test_dev_dashboard_is_available_in_testing_environment(): void
    {
        $this->get('/dev-dashboard')
            ->assertOk()
            ->assertSee('DLMS API Testing Dashboard', false);
    }

    public function test_dev_dashboard_returns_404_in_production_environment(): void
    {
        $original = $this->app->environment();
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->get('/dev-dashboard')->assertNotFound();
        } finally {
            $this->app->detectEnvironment(fn () => $original);
        }
    }

    public function test_dev_dashboard_ping_action_returns_api_response(): void
    {
        $response = $this->post('/dev-dashboard/action', ['action' => 'ping']);

        $response->assertRedirect(route('dev-dashboard.index'));
        $response->assertSessionHas('dev_last_response');

        $last = session('dev_last_response');
        $this->assertSame('ping', $last['action']);
        $this->assertTrue($last['success']);
    }
}
