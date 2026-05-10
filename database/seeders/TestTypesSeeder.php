<?php

namespace Database\Seeders;

use App\Models\TestType;
use Illuminate\Database\Seeder;

class TestTypesSeeder extends Seeder
{
    public function run(): void
    {
        $tests = [
            ['name' => 'Vision Test', 'code' => 'vision', 'sequence_order' => 1],
            ['name' => 'Theory Test', 'code' => 'theory', 'sequence_order' => 2],
            ['name' => 'Practical Test', 'code' => 'practical', 'sequence_order' => 3],
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
