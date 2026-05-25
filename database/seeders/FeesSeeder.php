<?php

namespace Database\Seeders;

use App\Models\Fee;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\TestType;
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
            Fee::firstOrCreate(
                [
                    'license_type_id' => $licenseType->id,
                    'service_type_id' => $newLicense->id,
                    'code' => 'application_fee',
                ],
                [
                    'name' => 'رسوم تقديم الطلب',
                    'amount' => 50000,
                    'currency' => 'SYP',
                    'is_active' => true,
                ]
            );
        }

        Fee::firstOrCreate(
            ['test_type_id' => $vision->id, 'code' => 'vision_test_fee'],
            [
                'name' => 'رسوم اختبار النظر',
                'amount' => 10000,
                'currency' => 'SYP',
                'license_type_id' => null,
                'service_type_id' => null,
                'is_active' => true,
            ]
        );

        Fee::firstOrCreate(
            ['test_type_id' => $theory->id, 'code' => 'theory_test_fee'],
            [
                'name' => 'رسوم الاختبار النظري',
                'amount' => 15000,
                'currency' => 'SYP',
                'license_type_id' => null,
                'service_type_id' => null,
                'is_active' => true,
            ]
        );

        Fee::firstOrCreate(
            ['test_type_id' => $practical->id, 'code' => 'practical_test_fee'],
            [
                'name' => 'رسوم الاختبار العملي',
                'amount' => 20000,
                'currency' => 'SYP',
                'license_type_id' => null,
                'service_type_id' => null,
                'is_active' => true,
            ]
        );

        Fee::firstOrCreate(
            ['service_type_id' => $renew->id, 'code' => 'renewal_fee'],
            [
                'name' => 'رسوم تجديد الرخصة',
                'amount' => 40000,
                'currency' => 'SYP',
                'license_type_id' => null,
                'test_type_id' => null,
                'is_active' => true,
            ]
        );

        Fee::firstOrCreate(
            ['service_type_id' => $lost->id, 'code' => 'lost_replacement_fee'],
            [
                'name' => 'رسوم بدل فاقد',
                'amount' => 25000,
                'currency' => 'SYP',
                'license_type_id' => null,
                'test_type_id' => null,
                'is_active' => true,
            ]
        );

        Fee::firstOrCreate(
            ['service_type_id' => $damaged->id, 'code' => 'damaged_replacement_fee'],
            [
                'name' => 'رسوم بدل تالف',
                'amount' => 25000,
                'currency' => 'SYP',
                'license_type_id' => null,
                'test_type_id' => null,
                'is_active' => true,
            ]
        );

        Fee::firstOrCreate(
            ['service_type_id' => $unblock->id, 'code' => 'unblock_fee'],
            [
                'name' => 'رسوم فك حظر الرخصة',
                'amount' => 30000,
                'currency' => 'SYP',
                'license_type_id' => null,
                'test_type_id' => null,
                'is_active' => true,
            ]
        );
    }
}
