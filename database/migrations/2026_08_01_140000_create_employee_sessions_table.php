<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('auth_driver', 32)->default('sanctum');
            $table->unsignedBigInteger('personal_access_token_id')->nullable();
            $table->string('hashed_session_identifier', 64)->nullable();

            $table->timestamp('logged_in_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('logged_out_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 500)->nullable();
            $table->string('ended_reason', 64)->nullable();

            $table->string('initial_ip_address', 45)->nullable();
            $table->string('last_ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_type', 32)->nullable();
            $table->string('operating_system', 64)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('browser_version', 32)->nullable();

            $table->timestamps();

            $table->foreign('personal_access_token_id')
                ->references('id')
                ->on('personal_access_tokens')
                ->nullOnDelete();

            $table->index('user_id');
            $table->index('personal_access_token_id');
            $table->index('logged_in_at');
            $table->index('last_seen_at');
            $table->index('revoked_at');
            $table->index('logged_out_at');
            $table->index('expires_at');
            $table->index(['user_id', 'last_seen_at']);
            $table->index(['user_id', 'revoked_at', 'logged_out_at', 'expires_at'], 'employee_sessions_lifecycle_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_sessions');
    }
};
