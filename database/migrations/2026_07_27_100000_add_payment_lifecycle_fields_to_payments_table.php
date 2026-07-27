<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('failure_code', 64)->nullable()->after('metadata');
            $table->string('failure_message', 500)->nullable()->after('failure_code');
            $table->timestamp('failed_at')->nullable()->after('failure_message');
            $table->timestamp('last_verified_at')->nullable()->after('failed_at');
            $table->string('settled_obligation_key', 128)->nullable()->after('last_verified_at');
            $table->string('active_obligation_key', 128)->nullable()->after('settled_obligation_key');
        });

        // Backfill settlement / active keys for existing rows.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement("UPDATE payments SET settled_obligation_key = 'application:' || application_id || ':fee:' || fee_id WHERE status = 'completed' AND fine_id IS NULL AND application_id IS NOT NULL AND fee_id IS NOT NULL");
            DB::statement("UPDATE payments SET active_obligation_key = 'application:' || application_id || ':fee:' || fee_id WHERE status IN ('pending', 'under_verification') AND fine_id IS NULL AND application_id IS NOT NULL AND fee_id IS NOT NULL");
        } else {
            DB::statement("UPDATE payments SET settled_obligation_key = CONCAT('application:', application_id, ':fee:', fee_id) WHERE status = 'completed' AND fine_id IS NULL AND application_id IS NOT NULL AND fee_id IS NOT NULL");
            DB::statement("UPDATE payments SET active_obligation_key = CONCAT('application:', application_id, ':fee:', fee_id) WHERE status IN ('pending', 'under_verification') AND fine_id IS NULL AND application_id IS NOT NULL AND fee_id IS NOT NULL");
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->unique('settled_obligation_key', 'payments_settled_obligation_key_unique');
            $table->unique('active_obligation_key', 'payments_active_obligation_key_unique');
            $table->unique(['provider', 'provider_reference'], 'payments_provider_provider_reference_unique');
            $table->index(['fine_id', 'status', 'created_at'], 'payments_fine_status_created_index');
            $table->index(['status', 'provider', 'created_at'], 'payments_status_provider_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique('payments_settled_obligation_key_unique');
            $table->dropUnique('payments_active_obligation_key_unique');
            $table->dropUnique('payments_provider_provider_reference_unique');
            $table->dropIndex('payments_fine_status_created_index');
            $table->dropIndex('payments_status_provider_created_index');
            $table->dropColumn([
                'failure_code',
                'failure_message',
                'failed_at',
                'last_verified_at',
                'settled_obligation_key',
                'active_obligation_key',
            ]);
        });
    }
};
