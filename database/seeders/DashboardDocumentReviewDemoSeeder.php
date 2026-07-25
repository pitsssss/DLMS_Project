<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserType;
use App\Models\ApplicationDocument;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\RequiredDocument;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class DashboardDocumentReviewDemoSeeder extends Seeder
{
    public function run(): void
    {
        $citizens = $this->demoCitizens();
        $reviewer = $this->reviewer();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();
        $requiredDocuments = $this->requiredDocuments($serviceType);

        $this->seedApplication(
            applicationNumber: 'APP-DOC-REVIEW-001',
            citizen: $citizens[0],
            licenseType: $licenseType,
            serviceType: $serviceType,
            status: ApplicationStatus::DocumentsUnderReview,
            submittedAt: now()->subDay(),
            documentStatuses: [
                'national_id_copy' => DocumentStatus::PendingReview,
                'personal_photo' => DocumentStatus::PendingReview,
                'blood_donation_certificate' => DocumentStatus::PendingReview,
                'medical_report' => DocumentStatus::PendingReview,
            ],
            requiredDocuments: $requiredDocuments,
            reviewer: $reviewer
        );

        $this->seedApplication(
            applicationNumber: 'APP-DOC-REVIEW-002',
            citizen: $citizens[1],
            licenseType: $licenseType,
            serviceType: $serviceType,
            status: ApplicationStatus::DocumentsUnderReview,
            submittedAt: now()->subDays(2),
            documentStatuses: [
                'national_id_copy' => DocumentStatus::Approved,
                'personal_photo' => DocumentStatus::Approved,
                'blood_donation_certificate' => DocumentStatus::PendingReview,
                'medical_report' => DocumentStatus::PendingReview,
            ],
            requiredDocuments: $requiredDocuments,
            reviewer: $reviewer
        );

        $this->seedApplication(
            applicationNumber: 'APP-DOC-LATE-001',
            citizen: $citizens[2],
            licenseType: $licenseType,
            serviceType: $serviceType,
            status: ApplicationStatus::DocumentsUnderReview,
            submittedAt: now()->subDays(5),
            documentStatuses: [
                'national_id_copy' => DocumentStatus::PendingReview,
                'personal_photo' => DocumentStatus::PendingReview,
                'blood_donation_certificate' => DocumentStatus::PendingReview,
                'medical_report' => DocumentStatus::PendingReview,
            ],
            requiredDocuments: $requiredDocuments,
            reviewer: $reviewer
        );

        $this->seedApplication(
            applicationNumber: 'APP-DOC-REJECTED-001',
            citizen: $citizens[3],
            licenseType: $licenseType,
            serviceType: $serviceType,
            status: ApplicationStatus::DocumentsRejected,
            submittedAt: now()->subDays(3),
            documentStatuses: [
                'national_id_copy' => DocumentStatus::Approved,
                'personal_photo' => DocumentStatus::Rejected,
                'blood_donation_certificate' => DocumentStatus::PendingReview,
                'medical_report' => DocumentStatus::PendingReview,
            ],
            requiredDocuments: $requiredDocuments,
            reviewer: $reviewer,
            rejectionReason: 'الصورة الشخصية غير واضحة. يرجى إعادة رفع صورة أوضح.'
        );

        $this->seedApplication(
            applicationNumber: 'APP-DOC-COMPLETED-001',
            citizen: $citizens[4],
            licenseType: $licenseType,
            serviceType: $serviceType,
            status: ApplicationStatus::PaymentPending,
            submittedAt: now()->subDays(4),
            documentStatuses: [
                'national_id_copy' => DocumentStatus::Approved,
                'personal_photo' => DocumentStatus::Approved,
                'blood_donation_certificate' => DocumentStatus::Approved,
                'medical_report' => DocumentStatus::Approved,
            ],
            requiredDocuments: $requiredDocuments,
            reviewer: $reviewer
        );
    }

    /**
     * @return list<User>
     */
    private function demoCitizens(): array
    {
        $role = Role::query()->where('name', 'citizen')->firstOrFail();

        $records = [
            ['name' => 'محمد خالد العلي', 'email' => 'doc.review.citizen1@example.com', 'phone' => '0991000001', 'national_id' => 'DOCREV00001'],
            ['name' => 'أحمد محمود', 'email' => 'doc.review.citizen2@example.com', 'phone' => '0991000002', 'national_id' => 'DOCREV00002'],
            ['name' => 'سارة علي محمد', 'email' => 'doc.review.citizen3@example.com', 'phone' => '0991000003', 'national_id' => 'DOCREV00003'],
            ['name' => 'ليلى حسن', 'email' => 'doc.review.citizen4@example.com', 'phone' => '0991000004', 'national_id' => 'DOCREV00004'],
            ['name' => 'عمر سامي', 'email' => 'doc.review.citizen5@example.com', 'phone' => '0991000005', 'national_id' => 'DOCREV00005'],
        ];

        return array_map(
            fn (array $record): User => User::query()->updateOrCreate(
                ['email' => $record['email']],
                [
                    'name' => $record['name'],
                    'phone' => $record['phone'],
                    'national_id' => $record['national_id'],
                    'password' => Hash::make('password'),
                    'role_id' => $role->id,
                    'user_type' => UserType::Citizen,
                    'birth_date' => '1995-05-20',
                    'governorate' => 'Damascus',
                    'address' => 'Demo dashboard document review address',
                    'profile_completed' => true,
                    'profile_status' => ProfileStatus::Approved,
                    'profile_submitted_at' => now()->subDays(10),
                    'profile_reviewed_at' => now()->subDays(9),
                    'is_active' => true,
                    'email_verified_at' => now()->subDays(10),
                    'phone_verified_at' => now()->subDays(10),
                ]
            ),
            $records
        );
    }

    private function reviewer(): ?User
    {
        return User::query()
            ->whereHas('role', fn ($query) => $query->where('name', 'profile_document_reviewer'))
            ->first();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, RequiredDocument>
     */
    private function requiredDocuments(ServiceType $serviceType)
    {
        $definitions = [
            ['name' => 'صورة عن الهوية الشخصية', 'code' => 'national_id_copy'],
            ['name' => 'أربع صور شخصية', 'code' => 'personal_photo'],
            ['name' => 'وثيقة تبرع بالدم', 'code' => 'blood_donation_certificate'],
            ['name' => 'تقرير طبي', 'code' => 'medical_report'],
        ];

        foreach ($definitions as $definition) {
            RequiredDocument::query()->updateOrCreate(
                [
                    'service_type_id' => $serviceType->id,
                    'license_type_id' => null,
                    'code' => $definition['code'],
                ],
                [
                    'name' => $definition['name'],
                    'is_required' => true,
                    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf'],
                    'max_size_kb' => 4096,
                    'is_active' => true,
                ]
            );
        }

        return RequiredDocument::query()
            ->where('service_type_id', $serviceType->id)
            ->whereIn('code', array_column($definitions, 'code'))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, DocumentStatus>  $documentStatuses
     * @param  \Illuminate\Database\Eloquent\Collection<int, RequiredDocument>  $requiredDocuments
     */
    private function seedApplication(
        string $applicationNumber,
        User $citizen,
        LicenseType $licenseType,
        ServiceType $serviceType,
        ApplicationStatus $status,
        Carbon $submittedAt,
        array $documentStatuses,
        $requiredDocuments,
        ?User $reviewer,
        ?string $rejectionReason = null,
    ): void {
        $application = LicenseApplication::query()->updateOrCreate(
            ['application_number' => $applicationNumber],
            [
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'service_type_id' => $serviceType->id,
                'status' => $status,
                'current_test_type_id' => null,
                'rejection_reason' => $status === ApplicationStatus::DocumentsRejected ? $rejectionReason : null,
                'submitted_at' => $submittedAt,
                'approved_at' => null,
                'issued_at' => null,
                'created_at' => $submittedAt->copy()->subHours(2),
                'updated_at' => $submittedAt,
            ]
        );

        foreach ($requiredDocuments as $requiredDocument) {
            $documentStatus = $documentStatuses[$requiredDocument->code] ?? DocumentStatus::PendingReview;
            $filePath = $this->putDemoFile($application, $requiredDocument);
            $reviewed = $documentStatus !== DocumentStatus::PendingReview;

            ApplicationDocument::query()->withTrashed()->updateOrCreate(
                [
                    'application_id' => $application->id,
                    'required_document_id' => $requiredDocument->id,
                ],
                [
                    'file_path' => $filePath,
                    'original_name' => $this->originalFileName($requiredDocument),
                    'mime_type' => 'application/pdf',
                    'size' => Storage::disk('local')->size($filePath),
                    'status' => $documentStatus,
                    'rejection_reason' => $documentStatus === DocumentStatus::Rejected ? $rejectionReason : null,
                    'rejection_reason_code' => $documentStatus === DocumentStatus::Rejected ? 'other' : null,
                    'rejection_details' => $documentStatus === DocumentStatus::Rejected ? $rejectionReason : null,
                    'reviewed_by' => $reviewed ? $reviewer?->id : null,
                    'reviewed_at' => $reviewed ? $submittedAt->copy()->addHours(4) : null,
                    'deleted_at' => null,
                    'created_at' => $submittedAt->copy()->subHour(),
                    'updated_at' => $submittedAt,
                ]
            );
        }
    }

    private function putDemoFile(LicenseApplication $application, RequiredDocument $requiredDocument): string
    {
        $path = 'application_documents/'.$application->id.'/demo-'.$requiredDocument->code.'.pdf';

        Storage::disk('local')->put($path, $this->demoPdfContent($application, $requiredDocument));

        return $path;
    }

    private function originalFileName(RequiredDocument $requiredDocument): string
    {
        return match ($requiredDocument->code) {
            'national_id_copy' => 'صورة الهوية الشخصية.pdf',
            'personal_photo' => 'أربع صور شخصية.pdf',
            'blood_donation_certificate' => 'وثيقة تبرع بالدم.pdf',
            'medical_report' => 'تقرير طبي.pdf',
            default => $requiredDocument->name.'.pdf',
        };
    }

    private function demoPdfContent(LicenseApplication $application, RequiredDocument $requiredDocument): string
    {
        $title = $requiredDocument->code.' - '.$application->application_number;

        return "%PDF-1.4\n"
            ."1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            ."2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            ."3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n"
            ."4 0 obj << /Length ".(75 + strlen($title))." >> stream\n"
            ."BT /F1 18 Tf 72 720 Td (Demo document) Tj 0 -28 Td ({$title}) Tj ET\n"
            ."endstream endobj\n"
            ."5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n"
            ."xref\n0 6\n0000000000 65535 f \n"
            ."trailer << /Root 1 0 R /Size 6 >>\nstartxref\n0\n%%EOF\n";
    }
}
