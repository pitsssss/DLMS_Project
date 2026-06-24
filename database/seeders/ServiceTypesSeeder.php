<?php

namespace Database\Seeders;

use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class ServiceTypesSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            [
                'name' => 'إصدار رخصة جديدة',
                'code' => 'new_license',
                'description' => 'قدّم طلب رخصة جديدة وتابع جميع مراحله إلكترونياً.',
            ],
            [
                'name' => 'تجديد رخصة',
                'code' => 'renew_license',
                'description' => 'جدّد رخصتك بسهولة قبل انتهاء صلاحيتها أو خلال فترة السماح.',
            ],
            [
                'name' => 'بدل فاقد',
                'code' => 'lost_replacement',
                'description' => 'اطلب نسخة جديدة عند فقدان رخصتك.',
            ],
            [
                'name' => 'بدل تالف',
                'code' => 'damaged_replacement',
                'description' => 'استبدل رخصتك التالفة بنسخة جديدة.',
            ],
            [
                'name' => 'فك حظر رخصة',
                'code' => 'license_unblock',
                'description' => 'قدّم طلب فك الحظر عن رخصتك بعد استيفاء الشروط المطلوبة.',
            ],
        ];

        foreach ($services as $service) {
            ServiceType::updateOrCreate(
                ['code' => $service['code']],
                [
                    'name' => $service['name'],
                    'description' => $service['description'],
                    'is_active' => true,
                ]
            );
        }
    }
}
