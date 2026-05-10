<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('license_applications')->cascadeOnDelete();
            $table->foreignId('citizen_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('appointment_slot_id')->constrained('appointment_slots')->restrictOnDelete();
            $table->foreignId('test_type_id')->constrained('test_types')->restrictOnDelete();
            $table->string('status', 32);
            $table->timestamp('scheduled_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['application_id', 'test_type_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_appointments');
    }
};
