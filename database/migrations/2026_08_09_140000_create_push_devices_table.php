<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('device_id', 128);
            $table->string('platform', 16);
            $table->text('token');
            $table->string('token_hash', 64);
            $table->timestamp('last_registered_at');
            $table->timestamps();

            $table->unique('token_hash');
            $table->unique(['user_id', 'device_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
