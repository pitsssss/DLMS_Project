<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_slots', function (Blueprint $table) {
            $table->foreignId('appointment_center_id')
                ->nullable()
                ->after('test_type_id')
                ->constrained('appointment_centers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointment_slots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appointment_center_id');
        });
    }
};
