<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('profile_status')->default('incomplete')->after('profile_completed');
            $table->text('profile_rejection_reason')->nullable()->after('profile_status');
            $table->foreignId('profile_reviewed_by')->nullable()->after('profile_rejection_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('profile_reviewed_at')->nullable()->after('profile_reviewed_by');
            $table->timestamp('profile_submitted_at')->nullable()->after('profile_reviewed_at');
        });

        DB::table('users')
            ->where('profile_completed', true)
            ->update(['profile_status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['profile_reviewed_by']);
            $table->dropColumn([
                'profile_status',
                'profile_rejection_reason',
                'profile_reviewed_by',
                'profile_reviewed_at',
                'profile_submitted_at',
            ]);
        });
    }
};
