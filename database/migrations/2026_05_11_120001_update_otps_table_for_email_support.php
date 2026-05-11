<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            $table->dropIndex(['phone', 'purpose']);
        });

        Schema::table('otps', function (Blueprint $table) {
            $table->string('email')->nullable()->after('id');
            $table->string('phone')->nullable()->change();
            $table->string('code', 255)->change();
        });

        Schema::table('otps', function (Blueprint $table) {
            $table->index(['email', 'purpose'], 'otps_email_purpose_idx');
            $table->index(['phone', 'purpose'], 'otps_phone_purpose_idx');
        });
    }

    public function down(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            $table->dropIndex('otps_email_purpose_idx');
            $table->dropIndex('otps_phone_purpose_idx');
        });

        Schema::table('otps', function (Blueprint $table) {
            $table->dropColumn('email');
            $table->string('phone')->nullable(false)->change();
            $table->string('code', 32)->change();
        });

        Schema::table('otps', function (Blueprint $table) {
            $table->index(['phone', 'purpose']);
        });
    }
};
