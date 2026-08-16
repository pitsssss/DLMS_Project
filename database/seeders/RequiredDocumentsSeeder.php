<?php

namespace Database\Seeders;

use App\Models\RequiredDocument;
use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class RequiredDocumentsSeeder extends Seeder
{
    public function run(): void
    {
        $extensions = ['jpg', 'jpeg', 'png', 'pdf'];

        $this->seedForService('new_license', [
            ['name' => 'صورة عن الهوية الشخصية', 'code' => 'national_id_copy'],
            ['name' => 'صورة شخصية', 'code' => 'personal_photo'],
            ['name' => 'شهادة تبرع بالدم', 'code' => 'blood_donation_certificate'],
            ['name' => 'تقرير طبي', 'code' => 'medical_report'],
        ], $extensions);

        $this->seedForService('renew_license', [
            ['name' => 'صورة عن الهوية الشخصية', 'code' => 'national_id_copy'],
            ['name' => 'صورة شخصية حديثة', 'code' => 'recent_personal_photo'],
            ['name' => 'تقرير طبي إن وجد ضمن متطلبات التجديد', 'code' => 'medical_report_renewal'],
        ], $extensions);

        $this->seedForService('lost_replacement', [
            ['name' => 'صورة عن الهوية الشخصية', 'code' => 'national_id_copy'],
            ['name' => 'تصريح فقدان أو تعهد فقدان', 'code' => 'loss_declaration'],
            ['name' => 'صورة شخصية حديثة', 'code' => 'recent_personal_photo'],
        ], $extensions);

        $this->seedForService('damaged_replacement', [
            ['name' => 'صورة عن الهوية الشخصية', 'code' => 'national_id_copy'],
            ['name' => 'صورة الرخصة التالفة أو إثبات التلف', 'code' => 'damaged_license_proof'],
            ['name' => 'صورة شخصية حديثة', 'code' => 'recent_personal_photo'],
        ], $extensions);

        $this->seedForService('license_unblock', [
            ['name' => 'صورة عن الهوية الشخصية', 'code' => 'national_id_copy'],
            ['name' => 'إشعار تسوية الغرامات أو ما يثبت انتفاء المانع', 'code' => 'fine_clearance'],
        ], $extensions);
    }

    /**
     * @param  list<array{name: string, code: string}>  $documents
     * @param  list<string>  $extensions
     */
    private function seedForService(string $serviceCode, array $documents, array $extensions): void
    {
        $serviceType = ServiceType::where('code', $serviceCode)->firstOrFail();

        foreach ($documents as $doc) {
            RequiredDocument::updateOrCreate(
                [
                    'service_type_id' => $serviceType->id,
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
