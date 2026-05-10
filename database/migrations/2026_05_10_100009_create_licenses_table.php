<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->string('license_number')->unique();
            $table->foreignId('citizen_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('license_type_id')->constrained('license_types')->restrictOnDelete();
            $table->foreignId('application_id')->constrained('license_applications')->restrictOnDelete();
            $table->string('status', 32);
            $table->date('issue_date');
            $table->date('expiry_date');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['citizen_id', 'license_type_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
