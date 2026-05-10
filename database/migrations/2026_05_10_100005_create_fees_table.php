<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_type_id')->nullable()->constrained('license_types')->nullOnDelete();
            $table->foreignId('service_type_id')->nullable()->constrained('service_types')->nullOnDelete();
            $table->foreignId('test_type_id')->nullable()->constrained('test_types')->nullOnDelete();
            $table->string('name');
            $table->string('code');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('SYP');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['license_type_id', 'service_type_id', 'test_type_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fees');
    }
};
