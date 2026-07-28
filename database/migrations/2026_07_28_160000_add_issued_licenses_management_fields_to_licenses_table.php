<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->foreignId('issued_by')
                ->nullable()
                ->after('application_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('previous_license_id')
                ->nullable()
                ->after('issued_by')
                ->constrained('licenses')
                ->nullOnDelete();

            $table->timestamp('blocked_at')->nullable()->after('expiry_date');
            $table->foreignId('blocked_by')
                ->nullable()
                ->after('blocked_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('block_reason')->nullable()->after('blocked_by');

            $table->string('verification_token', 64)->nullable()->after('block_reason');
            $table->timestamp('printed_at')->nullable()->after('verification_token');
            $table->foreignId('printed_by')
                ->nullable()
                ->after('printed_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedInteger('print_count')->default(0)->after('printed_by');

            $table->index(['status', 'expiry_date']);
            $table->index('issue_date');
            $table->index('application_id');
            $table->index(['citizen_id', 'created_at']);
            $table->index('previous_license_id');
            $table->unique('verification_token');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropUnique(['verification_token']);
            $table->dropIndex(['citizen_id', 'created_at']);
            $table->dropIndex(['status', 'expiry_date']);
            $table->dropIndex(['issue_date']);
            $table->dropIndex(['application_id']);
            $table->dropIndex(['previous_license_id']);

            $table->dropConstrainedForeignId('printed_by');
            $table->dropConstrainedForeignId('blocked_by');
            $table->dropConstrainedForeignId('previous_license_id');
            $table->dropConstrainedForeignId('issued_by');

            $table->dropColumn([
                'blocked_at',
                'block_reason',
                'verification_token',
                'printed_at',
                'print_count',
            ]);
        });
    }
};
