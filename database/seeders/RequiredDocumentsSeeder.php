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
                'name' => 'National ID Copy',
                'code' => 'national_id_copy',
            ],
            [
                'name' => 'Personal Photo',
                'code' => 'personal_photo',
            ],
            [
                'name' => 'Blood Donation Certificate',
                'code' => 'blood_donation_certificate',
            ],
            [
                'name' => 'Medical Report',
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
