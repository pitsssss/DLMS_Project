<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_number')->unique();
            $table->foreignId('citizen_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('license_type_id')->constrained('license_types')->restrictOnDelete();
            $table->foreignId('service_type_id')->constrained('service_types')->restrictOnDelete();
            $table->string('status', 64);
            $table->foreignId('current_test_type_id')->nullable()->constrained('test_types')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(
                ['citizen_id', 'license_type_id', 'service_type_id', 'status'],
                'license_applications_citizen_license_service_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_applications');
    }
};
