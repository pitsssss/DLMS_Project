<?php

namespace Database\Seeders;

use App\Models\TestType;
use Illuminate\Database\Seeder;

class TestTypesSeeder extends Seeder
{
    public function run(): void
    {
        $tests = [
            ['name' => 'اختبار النظر', 'code' => 'vision', 'sequence_order' => 1],
            ['name' => 'الاختبار النظري', 'code' => 'theory', 'sequence_order' => 2],
            ['name' => 'الاختبار العملي', 'code' => 'practical', 'sequence_order' => 3],
        ];

        foreach ($tests as $test) {
            TestType::firstOrCreate(
                ['code' => $test['code']],
                [
                    'name' => $test['name'],
                    'sequence_order' => $test['sequence_order'],
                    'max_attempts' => 3,
                    'is_required' => true,
                    'is_active' => true,
                ]
            );
        }
    }
}
