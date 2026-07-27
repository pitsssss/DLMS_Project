<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('deactivated_at')->nullable()->after('is_active');
            $table->foreignId('deactivated_by')->nullable()->after('deactivated_at')
                ->constrained('users')->nullOnDelete();
            $table->text('deactivation_reason')->nullable()->after('deactivated_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn(['deactivated_at', 'deactivation_reason']);
        });
    }
};
