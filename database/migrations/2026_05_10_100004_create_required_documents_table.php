<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('required_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_type_id')->nullable()->constrained('license_types')->nullOnDelete();
            $table->foreignId('service_type_id')->nullable()->constrained('service_types')->nullOnDelete();
            $table->string('name');
            $table->string('code');
            $table->boolean('is_required')->default(true);
            $table->json('allowed_extensions')->nullable();
            $table->unsignedInteger('max_size_kb')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['license_type_id', 'service_type_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('required_documents');
    }
};
