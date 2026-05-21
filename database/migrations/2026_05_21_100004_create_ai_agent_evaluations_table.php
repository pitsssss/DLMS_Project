<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('ai_agent_sessions')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('ai_agent_messages')->nullOnDelete();
            $table->string('intent')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->decimal('safety_score', 5, 4)->nullable();
            $table->string('tool_selected')->nullable();
            $table->boolean('requires_human_support')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('model_used')->nullable();
            $table->boolean('was_fallback')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_evaluations');
    }
};
