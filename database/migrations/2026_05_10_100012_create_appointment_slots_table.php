<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_type_id')->constrained('test_types')->cascadeOnDelete();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('booked_count')->default(0);
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['test_type_id', 'date', 'start_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_slots');
    }
};
