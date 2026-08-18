<?php

namespace Tests\Feature;

use App\Enums\FineStatus;
use App\Models\Fine;
use App\Models\User;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FineCurrencyFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolesSeeder::class,
            PermissionsSeeder::class,
            LicenseTypesSeeder::class,
            ServiceTypesSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_fines_table_has_non_null_currency_column_defaulting_to_usd(): void
    {
        $this->assertTrue(Schema::hasColumn('fines', 'currency'));

        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);

        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => null,
            'amount' => 12.50,
            'reason' => 'Schema default check',
            'status' => FineStatus::Unpaid,
            'paid_at' => null,
        ]);

        $this->assertSame('USD', $fine->fresh()->currency);
        $this->assertDatabaseHas('fines', [
            'id' => $fine->id,
            'currency' => 'USD',
        ]);
    }

    public function test_employee_create_fine_assigns_usd_without_client_currency(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
        $admin = User::factory()->dashboardAdmin('admin')->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/fines', [
            'citizen_id' => $citizen->id,
            'amount' => 25.00,
            'reason' => 'Speeding',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.amount', '25.00')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.status', FineStatus::Unpaid->value);

        $this->assertDatabaseHas('fines', [
            'id' => (int) $response->json('data.id'),
            'amount' => '25.00',
            'currency' => 'USD',
        ]);
    }

    public function test_client_cannot_override_fine_currency_on_create(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
        $admin = User::factory()->dashboardAdmin('admin')->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/fines', [
            'citizen_id' => $citizen->id,
            'amount' => 30.00,
            'reason' => 'Attempt EUR override',
            'currency' => 'EUR',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);

        $this->assertSame(0, Fine::query()->where('citizen_id', $citizen->id)->count());
    }

    public function test_citizen_fine_list_exposes_amount_and_usd_currency(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);

        Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => null,
            'amount' => 40.00,
            'currency' => 'USD',
            'reason' => 'List currency check',
            'status' => FineStatus::Unpaid,
            'paid_at' => null,
        ]);

        Sanctum::actingAs($citizen);

        $this->getJson('/api/fines')
            ->assertOk()
            ->assertJsonPath('data.0.amount', '40.00')
            ->assertJsonPath('data.0.currency', 'USD');
    }

    public function test_employee_update_does_not_change_currency(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
        $admin = User::factory()->dashboardAdmin('admin')->create();

        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => null,
            'amount' => 20.00,
            'currency' => 'USD',
            'reason' => 'Original reason',
            'status' => FineStatus::Unpaid,
            'paid_at' => null,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/fines/{$fine->id}", [
            'amount' => 22.50,
            'reason' => 'Updated reason',
            'currency' => 'EUR',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);

        $this->putJson("/api/admin/fines/{$fine->id}", [
            'amount' => 22.50,
            'reason' => 'Updated reason',
        ])->assertOk()
            ->assertJsonPath('data.amount', '22.50')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.reason', 'Updated reason');

        $this->assertDatabaseHas('fines', [
            'id' => $fine->id,
            'amount' => '22.50',
            'currency' => 'USD',
        ]);
    }

    public function test_mark_paid_and_cancelled_preserve_currency_and_paid_at_rules(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
        $admin = User::factory()->dashboardAdmin('admin')->create();

        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => null,
            'amount' => 18.00,
            'currency' => 'USD',
            'reason' => 'State transition',
            'status' => FineStatus::Unpaid,
            'paid_at' => null,
        ]);

        Sanctum::actingAs($admin);

        $paidResponse = $this->putJson("/api/admin/fines/{$fine->id}", [
            'status' => FineStatus::Paid->value,
        ])->assertOk()
            ->assertJsonPath('data.status', FineStatus::Paid->value)
            ->assertJsonPath('data.currency', 'USD');

        $this->assertNotEmpty($paidResponse->json('data.paid_at'));
        $this->assertNotNull($fine->fresh()->paid_at);
        $this->assertSame('USD', $fine->fresh()->currency);

        $this->putJson("/api/admin/fines/{$fine->id}", [
            'status' => FineStatus::Cancelled->value,
        ])->assertStatus(422);

        $cancelled = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => null,
            'amount' => 9.00,
            'currency' => 'USD',
            'reason' => 'To cancel',
            'status' => FineStatus::Unpaid,
            'paid_at' => null,
        ]);

        $this->putJson("/api/admin/fines/{$cancelled->id}", [
            'status' => FineStatus::Cancelled->value,
        ])->assertOk()
            ->assertJsonPath('data.status', FineStatus::Cancelled->value)
            ->assertJsonPath('data.currency', 'USD');
    }
}
