<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('license_applications')->cascadeOnDelete();
            $table->foreignId('test_appointment_id')->constrained('test_appointments')->cascadeOnDelete();
            $table->foreignId('test_type_id')->constrained('test_types')->restrictOnDelete();
            $table->string('result', 32);
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['application_id', 'test_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_results');
    }
};
