<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_applications', function (Blueprint $table) {
            $table->foreignId('related_license_id')
                ->nullable()
                ->after('service_type_id')
                ->constrained('licenses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('license_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('related_license_id');
        });
    }
};
