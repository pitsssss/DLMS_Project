<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fines', function (Blueprint $table): void {
            // Non-null with default; canonical electronic fine currency for this DLMS version.
            $table->string('currency', 8)->default('USD')->after('amount');
        });

        // Metadata-only backfill — no amount conversion or FX inference.
        DB::table('fines')->update([
            'currency' => 'USD',
        ]);
    }

    public function down(): void
    {
        Schema::table('fines', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
    }
};
