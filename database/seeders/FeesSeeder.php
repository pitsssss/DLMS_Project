<?php

namespace Database\Seeders;

use App\Models\Fee;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\TestType;
use App\Modules\Payments\Support\ApplicationFeeCatalog;
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
            $this->upsertFee(
                [
                    'license_type_id' => $licenseType->id,
                    'service_type_id' => $newLicense->id,
                    'code' => 'application_fee',
                ],
                [
                    'name' => 'رسوم تقديم الطلب',
                    'amount' => ApplicationFeeCatalog::amountFor('application_fee'),
                    'currency' => ApplicationFeeCatalog::CURRENCY,
                    'test_type_id' => null,
                    'is_active' => true,
                ]
            );
        }

        $this->upsertFee(
            ['test_type_id' => $vision->id, 'code' => 'vision_test_fee'],
            [
                'name' => 'رسوم اختبار النظر',
                'amount' => ApplicationFeeCatalog::amountFor('vision_test_fee'),
                'currency' => ApplicationFeeCatalog::CURRENCY,
                'license_type_id' => null,
                'service_type_id' => null,
                'is_active' => true,
            ]
        );

        $this->upsertFee(
            ['test_type_id' => $theory->id, 'code' => 'theory_test_fee'],
            [
                'name' => 'رسوم الاختبار النظري',
                'amount' => ApplicationFeeCatalog::amountFor('theory_test_fee'),
                'currency' => ApplicationFeeCatalog::CURRENCY,
                'license_type_id' => null,
                'service_type_id' => null,
                'is_active' => true,
            ]
        );

        $this->upsertFee(
            ['test_type_id' => $practical->id, 'code' => 'practical_test_fee'],
            [
                'name' => 'رسوم الاختبار العملي',
                'amount' => ApplicationFeeCatalog::amountFor('practical_test_fee'),
                'currency' => ApplicationFeeCatalog::CURRENCY,
                'license_type_id' => null,
                'service_type_id' => null,
                'is_active' => true,
            ]
        );

        $this->upsertFee(
            ['service_type_id' => $renew->id, 'code' => 'renewal_fee'],
            [
                'name' => 'رسوم تجديد الرخصة',
                'amount' => ApplicationFeeCatalog::amountFor('renewal_fee'),
                'currency' => ApplicationFeeCatalog::CURRENCY,
                'license_type_id' => null,
                'test_type_id' => null,
                'is_active' => true,
            ]
        );

        $this->upsertFee(
            ['service_type_id' => $lost->id, 'code' => 'lost_replacement_fee'],
            [
                'name' => 'رسوم بدل فاقد',
                'amount' => ApplicationFeeCatalog::amountFor('lost_replacement_fee'),
                'currency' => ApplicationFeeCatalog::CURRENCY,
                'license_type_id' => null,
                'test_type_id' => null,
                'is_active' => true,
            ]
        );

        $this->upsertFee(
            ['service_type_id' => $damaged->id, 'code' => 'damaged_replacement_fee'],
            [
                'name' => 'رسوم بدل تالف',
                'amount' => ApplicationFeeCatalog::amountFor('damaged_replacement_fee'),
                'currency' => ApplicationFeeCatalog::CURRENCY,
                'license_type_id' => null,
                'test_type_id' => null,
                'is_active' => true,
            ]
        );

        $this->upsertFee(
            ['service_type_id' => $unblock->id, 'code' => 'unblock_fee'],
            [
                'name' => 'رسوم فك حظر الرخصة',
                'amount' => ApplicationFeeCatalog::amountFor('unblock_fee'),
                'currency' => ApplicationFeeCatalog::CURRENCY,
                'license_type_id' => null,
                'test_type_id' => null,
                'is_active' => true,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $keys
     * @param  array<string, mixed>  $values
     */
    private function upsertFee(array $keys, array $values): void
    {
        Fee::query()->updateOrCreate($keys, $values);
    }
}
