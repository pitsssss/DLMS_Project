<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->foreignId('push_device_id')->nullable()->constrained('push_devices')->nullOnDelete();
            $table->string('delivery_key', 128);
            $table->string('status', 32);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('provider_message_id', 255)->nullable();
            $table->string('last_error_category', 64)->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique('delivery_key');
            $table->index(['status', 'id']);
            $table->index('notification_id');
            $table->index('push_device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_deliveries');
    }
};
