<?php

use App\Models\AppointmentSlot;
use App\Modules\Appointments\Support\SlotIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_slots', function (Blueprint $table): void {
            $table->string('identity_key', 160)->nullable()->after('test_type_id');
            $table->foreignId('created_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable()->after('updated_by');
            $table->foreignId('deactivated_by')->nullable()->after('deactivated_at')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1)->after('deactivated_by');
        });

        AppointmentSlot::query()->orderBy('id')->each(function (AppointmentSlot $slot): void {
            $key = SlotIdentity::keyForSlot($slot);
            DB::table('appointment_slots')->where('id', $slot->id)->update(['identity_key' => $key]);
        });

        $duplicates = DB::table('appointment_slots')
            ->select('identity_key', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('identity_key')
            ->groupBy('identity_key')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $keys = $duplicates->pluck('identity_key')->implode(', ');
            throw new RuntimeException(
                'Cannot apply unique appointment slot identity constraint: duplicate identity_key values found: '.$keys
            );
        }

        Schema::table('appointment_slots', function (Blueprint $table): void {
            $table->unique('identity_key');
            $table->index(['date', 'is_active']);
            $table->index(['appointment_center_id', 'date']);
        });

        Schema::table('test_appointments', function (Blueprint $table): void {
            $table->index(['appointment_slot_id', 'status']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('test_appointments', function (Blueprint $table): void {
            $table->dropIndex(['appointment_slot_id', 'status']);
            $table->dropIndex(['status', 'scheduled_at']);
        });

        Schema::table('appointment_slots', function (Blueprint $table): void {
            $table->dropUnique(['identity_key']);
            $table->dropIndex(['date', 'is_active']);
            $table->dropIndex(['appointment_center_id', 'date']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn(['identity_key', 'deactivated_at', 'version']);
        });
    }
};
