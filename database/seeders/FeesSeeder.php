<?php

namespace Database\Seeders;

use App\Models\Fee;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\TestType;
use App\Modules\Payments\Support\ApplicationFeeCatalog;
use App\Modules\Payments\Support\FeeIdentity;
use Illuminate\Database\Seeder;

class FeesSeeder extends Seeder
{
    public function run(): void
    {
        $newLicense = ServiceType::where('code', 'new_license')->firstOrFail();
        $renew = ServiceType::where('code', 'renew_license')->firstOrFail();
        $lost = ServiceType::where('code', 'lost_replacement')->firstOrFail();
        $damaged = ServiceType::where('code', 'damaged_replacement')->firstOrFail();
        $unblock = ServiceType::where('code', 'license_unblock')->firstOrFail();

        $vision = TestType::where('code', 'vision')->firstOrFail();
        $theory = TestType::where('code', 'theory')->firstOrFail();
        $practical = TestType::where('code', 'practical')->firstOrFail();

        foreach (LicenseType::all() as $licenseType) {
            $this->ensureFee([
                'license_type_id' => $licenseType->id,
                'service_type_id' => $newLicense->id,
                'test_type_id' => null,
                'code' => 'application_fee',
                'name' => ApplicationFeeCatalog::seedDefaultName('application_fee'),
                'amount' => ApplicationFeeCatalog::seedDefaultAmount('application_fee'),
                'currency' => ApplicationFeeCatalog::CURRENCY,
                'is_active' => true,
            ]);
        }

        $this->ensureFee([
            'test_type_id' => $vision->id,
            'code' => 'vision_test_fee',
            'name' => ApplicationFeeCatalog::seedDefaultName('vision_test_fee'),
            'amount' => ApplicationFeeCatalog::seedDefaultAmount('vision_test_fee'),
            'currency' => ApplicationFeeCatalog::CURRENCY,
            'license_type_id' => null,
            'service_type_id' => null,
            'is_active' => true,
        ]);

        $this->ensureFee([
            'test_type_id' => $theory->id,
            'code' => 'theory_test_fee',
            'name' => ApplicationFeeCatalog::seedDefaultName('theory_test_fee'),
            'amount' => ApplicationFeeCatalog::seedDefaultAmount('theory_test_fee'),
            'currency' => ApplicationFeeCatalog::CURRENCY,
            'license_type_id' => null,
            'service_type_id' => null,
            'is_active' => true,
        ]);

        $this->ensureFee([
            'test_type_id' => $practical->id,
            'code' => 'practical_test_fee',
            'name' => ApplicationFeeCatalog::seedDefaultName('practical_test_fee'),
            'amount' => ApplicationFeeCatalog::seedDefaultAmount('practical_test_fee'),
            'currency' => ApplicationFeeCatalog::CURRENCY,
            'license_type_id' => null,
            'service_type_id' => null,
            'is_active' => true,
        ]);

        $this->ensureFee([
            'service_type_id' => $renew->id,
            'code' => 'renewal_fee',
            'name' => ApplicationFeeCatalog::seedDefaultName('renewal_fee'),
            'amount' => ApplicationFeeCatalog::seedDefaultAmount('renewal_fee'),
            'currency' => ApplicationFeeCatalog::CURRENCY,
            'license_type_id' => null,
            'test_type_id' => null,
            'is_active' => true,
        ]);

        $this->ensureFee([
            'service_type_id' => $lost->id,
            'code' => 'lost_replacement_fee',
            'name' => ApplicationFeeCatalog::seedDefaultName('lost_replacement_fee'),
            'amount' => ApplicationFeeCatalog::seedDefaultAmount('lost_replacement_fee'),
            'currency' => ApplicationFeeCatalog::CURRENCY,
            'license_type_id' => null,
            'test_type_id' => null,
            'is_active' => true,
        ]);

        $this->ensureFee([
            'service_type_id' => $damaged->id,
            'code' => 'damaged_replacement_fee',
            'name' => ApplicationFeeCatalog::seedDefaultName('damaged_replacement_fee'),
            'amount' => ApplicationFeeCatalog::seedDefaultAmount('damaged_replacement_fee'),
            'currency' => ApplicationFeeCatalog::CURRENCY,
            'license_type_id' => null,
            'test_type_id' => null,
            'is_active' => true,
        ]);

        $this->ensureFee([
            'service_type_id' => $unblock->id,
            'code' => 'unblock_fee',
            'name' => ApplicationFeeCatalog::seedDefaultName('unblock_fee'),
            'amount' => ApplicationFeeCatalog::seedDefaultAmount('unblock_fee'),
            'currency' => ApplicationFeeCatalog::CURRENCY,
            'license_type_id' => null,
            'test_type_id' => null,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ensureFee(array $attributes): void
    {
        $identityKey = FeeIdentity::buildKey(
            (string) $attributes['code'],
            $attributes['license_type_id'] ?? null,
            $attributes['service_type_id'] ?? null,
            $attributes['test_type_id'] ?? null,
        );

        if (Fee::query()->where('identity_key', $identityKey)->exists()) {
            return;
        }

        Fee::query()->create(array_merge($attributes, [
            'identity_key' => $identityKey,
            'version' => 1,
        ]));
    }
}
