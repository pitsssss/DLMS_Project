<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('ai_agent_sessions')->cascadeOnDelete();
            $table->string('role', 16);
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_messages');
    }
};
