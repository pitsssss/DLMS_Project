<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('license_applications')->nullOnDelete();
            $table->foreignId('fine_id')->nullable()->constrained('fines')->nullOnDelete();
            $table->foreignId('fee_id')->nullable()->constrained('fees')->nullOnDelete();
            $table->nullableMorphs('payable');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('SYP');
            $table->string('status', 32);
            $table->string('provider', 32)->default('mock');
            $table->string('provider_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
