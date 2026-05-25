<?php

namespace Database\Seeders;

use App\Models\RequiredDocument;
use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class RequiredDocumentsSeeder extends Seeder
{
    public function run(): void
    {
        $newLicense = ServiceType::where('code', 'new_license')->firstOrFail();

        $documents = [
            [
                'name' => 'صورة عن الهوية الشخصية',
                'code' => 'national_id_copy',
            ],
            [
                'name' => 'صورة شخصية',
                'code' => 'personal_photo',
            ],
            [
                'name' => 'شهادة تبرع بالدم',
                'code' => 'blood_donation_certificate',
            ],
            [
                'name' => 'تقرير طبي',
                'code' => 'medical_report',
            ],
        ];

        $extensions = ['jpg', 'jpeg', 'png', 'pdf'];

        foreach ($documents as $doc) {
            RequiredDocument::firstOrCreate(
                [
                    'service_type_id' => $newLicense->id,
                    'license_type_id' => null,
                    'code' => $doc['code'],
                ],
                [
                    'name' => $doc['name'],
                    'is_required' => true,
                    'allowed_extensions' => $extensions,
                    'max_size_kb' => 4096,
                    'is_active' => true,
                ]
            );
        }
    }
}
