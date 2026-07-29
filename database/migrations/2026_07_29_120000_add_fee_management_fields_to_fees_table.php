<?php

use App\Models\Fee;
use App\Modules\Payments\Support\FeeIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fees', function (Blueprint $table): void {
            $table->string('identity_key', 128)->nullable()->after('code');
            $table->foreignId('created_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable()->after('updated_by');
            $table->foreignId('deactivated_by')->nullable()->after('deactivated_at')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1)->after('deactivated_by');
        });

        Fee::query()->orderBy('id')->each(function (Fee $fee): void {
            $key = FeeIdentity::keyForFee($fee);
            DB::table('fees')->where('id', $fee->id)->update(['identity_key' => $key]);
        });

        $duplicates = DB::table('fees')
            ->select('identity_key', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('identity_key')
            ->groupBy('identity_key')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $keys = $duplicates->pluck('identity_key')->implode(', ');
            throw new RuntimeException(
                'Cannot apply unique fee identity constraint: duplicate identity_key values found: '.$keys
            );
        }

        Schema::table('fees', function (Blueprint $table): void {
            $table->unique('identity_key');
        });
    }

    public function down(): void
    {
        Schema::table('fees', function (Blueprint $table): void {
            $table->dropUnique(['identity_key']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn(['identity_key', 'deactivated_at', 'version']);
        });
    }
};
