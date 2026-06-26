<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LicenseApplicationSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('license_applications')->truncate();
        DB::table('license_types')->truncate();
        DB::table('service_types')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $licenseTypes = [
            ['id' => 1, 'name' => 'Private License', 'code' => 'private', 'minimum_age' => 18, 'validity_years' => 5],
            ['id' => 2, 'name' => 'Public License', 'code' => 'public', 'minimum_age' => 21, 'validity_years' => 5],
            ['id' => 3, 'name' => 'Truck License', 'code' => 'truck', 'minimum_age' => 21, 'validity_years' => 5],
            ['id' => 4, 'name' => 'Bus License', 'code' => 'bus', 'minimum_age' => 21, 'validity_years' => 5],
        ];

        foreach ($licenseTypes as $type) {
            DB::table('license_types')->insert([
                'id'             => $type['id'],
                'name'           => $type['name'],
                'code'           => $type['code'],
                'minimum_age'    => $type['minimum_age'],
                'validity_years' => $type['validity_years'],
                'is_active'      => 1,
                'created_at'     => '2026-05-14 14:43:18',
                'updated_at'     => '2026-05-14 14:43:18',
            ]);
        }

        $serviceTypes = [
            ['id' => 1, 'name' => 'New License', 'code' => 'new_license'],
            ['id' => 2, 'name' => 'Renew License', 'code' => 'renew_license'],
            ['id' => 3, 'name' => 'Lost Replacement', 'code' => 'lost_replacement'],
            ['id' => 4, 'name' => 'Damaged Replacement', 'code' => 'damaged_replacement'],
            ['id' => 5, 'name' => 'License Unblock', 'code' => 'license_unblock'],
        ];

        foreach ($serviceTypes as $service) {
            DB::table('service_types')->insert([
                'id'          => $service['id'],
                'name'        => $service['name'],
                'code'        => $service['code'],
                'description' => null,
                'is_active'   => 1,
                'created_at'  => '2026-05-14 14:43:18',
                'updated_at'  => '2026-05-14 14:43:18',
            ]);
        }


        $citizenIds = DB::table('users')->where('user_type', 'citizen')->pluck('id')->toArray();
        if (empty($citizenIds)) {
            $citizenIds = DB::table('users')->take(10)->pluck('id')->toArray();
        }

        $citizen1 = $citizenIds[0] ?? 1;
        $citizen2 = $citizenIds[1] ?? $citizen1;
        $citizen3 = $citizenIds[2] ?? $citizen1;

        $applications = [
            [
                'application_number' => 'APP-10241',
                'citizen_id'         => $citizen1,
                'license_type_id'    => 1, // Private License
                'service_type_id'    => 1,
                'status'             => 'license_issued', // 👈 تم التصحيح هنا
                'submitted_at'       => '2026-05-02 14:43:18',
                'created_at'         => '2026-05-02 14:43:18',
            ],
            [
                'application_number' => 'APP-10242',
                'citizen_id'         => $citizen2,
                'license_type_id'    => 4, // Bus License
                'service_type_id'    => 1,
                'status'             => 'documents_under_review', // 👈 تم التصحيح هنا
                'submitted_at'       => '2026-05-02 14:43:18',
                'created_at'         => '2026-05-02 14:43:18',
            ],
            [
                'application_number' => 'APP-10243',
                'citizen_id'         => $citizen3,
                'license_type_id'    => 3, // Truck License
                'service_type_id'    => 2,
                'status'             => 'rejected', // 👈 تم التصحيح هنا
                'submitted_at'       => '2026-05-02 14:43:18',
                'created_at'         => '2026-05-02 14:43:18',
            ],
        ];

        // Insert applications one by one so we can create matching audit log entries that reference
        // the inserted application IDs.
        foreach ($applications as $app) {
            $now = $app['created_at'] ?? date('Y-m-d H:i:s');

            $insertData = [
                'application_number' => $app['application_number'],
                'citizen_id' => $app['citizen_id'],
                'license_type_id' => $app['license_type_id'],
                'service_type_id' => $app['service_type_id'],
                'status' => $app['status'] ?? null,
                'submitted_at' => $app['submitted_at'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $applicationId = DB::table('license_applications')->insertGetId($insertData);

            // Create an initial audit log entry for the created application.
            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'created',
                'entity_type' => 'license_application',
                'entity_id' => $applicationId,
                'old_values' => null,
                'new_values' => json_encode([
                    'application_number' => $app['application_number'],
                    'citizen_id' => $app['citizen_id'],
                    'license_type_id' => $app['license_type_id'],
                    'service_type_id' => $app['service_type_id'],
                    'status' => $app['status'] ?? null,
                ]),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'seeder',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
