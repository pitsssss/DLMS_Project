<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive index for stale processing / pending recovery lookups (F4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_deliveries', function (Blueprint $table) {
            $table->index(['status', 'last_attempt_at'], 'push_deliveries_status_last_attempt_index');
        });
    }

    public function down(): void
    {
        Schema::table('push_deliveries', function (Blueprint $table) {
            $table->dropIndex('push_deliveries_status_last_attempt_index');
        });
    }
};
