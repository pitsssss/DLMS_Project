<?php

use App\Modules\Payments\Support\ApplicationFeeCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fees', function (Blueprint $table): void {
            $table->string('currency', 8)->default('USD')->change();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('currency', 8)->default('USD')->change();
        });

        foreach (ApplicationFeeCatalog::catalogCodes() as $code) {
            DB::table('fees')
                ->where('code', $code)
                ->where('currency', 'SYP')
                ->update([
                    'currency' => ApplicationFeeCatalog::CURRENCY,
                    'amount' => ApplicationFeeCatalog::amountFor($code),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('fees', function (Blueprint $table): void {
            $table->string('currency', 8)->default('SYP')->change();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('currency', 8)->default('SYP')->change();
        });
    }
};
