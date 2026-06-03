<?php

namespace Tests\Concerns;

use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Laravel\Sanctum\Sanctum;

trait InteractsWithDashboard
{
    protected function seedDashboardRbac(): void
    {
        $this->seed([
            RolesSeeder::class,
            PermissionsSeeder::class,
        ]);
    }

    protected function dashboardLoginAs(User $user, string $password = 'password'): string
    {
        $response = $this->postJson('/api/dashboard/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk()
            ->assertJsonPath('success', true);

        return (string) $response->json('data.token');
    }

    protected function withDashboardToken(User $user, string $password = 'password'): string
    {
        $token = $this->dashboardLoginAs($user, $password);

        return $token;
    }

    protected function actingAsDashboard(User $user, string $password = 'password'): void
    {
        $token = $this->dashboardLoginAs($user, $password);
        Sanctum::actingAs($user, ['*'], 'web');
        $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
