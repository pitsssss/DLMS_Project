<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\LicenseStatus;
use App\Enums\ServiceCode;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LostReplacementTestCitizenSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $email = 'lost.replacement.citizen@syrtak.test';
            $password = 'password123';

            $licenseType = LicenseType::query()->where('code', 'private')->first();
            if ($licenseType === null) {
                $this->command->warn('License type "private" not found; skipping LostReplacementTestCitizenSeeder.');
                return;
            }

            $lostService = ServiceType::query()->where('code', 'lost_replacement')->first();
            if ($lostService === null) {
                $this->command->warn('Service type "lost_replacement" not found; skipping LostReplacementTestCitizenSeeder.');
                return;
            }

            $user = User::updateOrCreate([
                'email' => $email,
            ], [
                'name' => 'مواطن اختبار بدل فاقد',
                'phone' => '0997000001',
                'national_id' => 'LRTEST0000001',
                'password' => Hash::make($password),
                'user_type' => 'citizen',
                'role_id' => null,
                'birth_date' => now()->subYears(30)->toDateString(),
                'governorate' => 'دمشق',
                'address' => 'عنوان تجريبي',
                'profile_completed' => true,
                'profile_status' => 'approved',
                'profile_submitted_at' => now(),
                'profile_reviewed_at' => now(),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            // Ensure there is a historical original issuance application for the license
            $originalAppNumber = 'APP-LR-'.now()->format('Y').'-0001';

            $originalApplication = LicenseApplication::updateOrCreate([
                'application_number' => $originalAppNumber,
            ], [
                'citizen_id' => $user->id,
                'license_type_id' => $licenseType->id,
                'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
                'status' => ApplicationStatus::LicenseIssued,
                'submitted_at' => now()->subYears(5),
                'approved_at' => now()->subYears(5),
                'issued_at' => now()->subYears(5),
            ]);

            $licenseNumber = 'LIC-LR-'.now()->format('Y').'-0001';

            $license = License::updateOrCreate([
                'license_number' => $licenseNumber,
            ], [
                'citizen_id' => $user->id,
                'license_type_id' => $licenseType->id,
                'application_id' => $originalApplication->id,
                'status' => LicenseStatus::Active,
                'issue_date' => now()->subYears(4)->toDateString(),
                'expiry_date' => now()->addYears(2)->toDateString(),
            ]);

            // Cancel any existing active lost-replacement application for this user+license
            $appRepo = app(\App\Modules\Applications\Repositories\ApplicationRepository::class);
            $existing = $appRepo->findActiveForCitizenByRelatedLicense($user, $lostService->id, $license->id);
            if ($existing !== null) {
                $appRepo->transitionStatus($existing, ApplicationStatus::Cancelled, null, 'Seed: cancelling pre-existing active lost-replacement');
            }

            $this->command->info('Lost replacement test citizen seeded: '.$email.' (password: '.$password.')');
        });
    }
}
