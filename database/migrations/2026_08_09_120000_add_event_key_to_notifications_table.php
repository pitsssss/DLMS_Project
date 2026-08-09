<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Deterministic business-event identity for deduplication.
            // Historical rows remain null; new emissions set a unique key when available.
            $table->string('event_key', 191)->nullable()->after('data');
            $table->unique('event_key');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique(['event_key']);
            $table->dropColumn('event_key');
        });
    }
};
