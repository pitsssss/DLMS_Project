<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('ai_agent_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action_name', 64);
            $table->json('arguments')->nullable();
            $table->string('status', 32)->default('pending');
            $table->boolean('requires_confirmation')->default(true);
            $table->text('confirmation_message')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
            $table->index(['session_id', 'status']);
            $table->index(['user_id', 'action_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_actions');
    }
};
