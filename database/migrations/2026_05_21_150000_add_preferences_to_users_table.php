<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'language')) {
                $table->string('language', 5)->default('ar')->after('address');
            }

            if (! Schema::hasColumn('users', 'theme')) {
                $table->string('theme', 10)->default('system')->after('language');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'theme')) {
                $table->dropColumn('theme');
            }

            if (Schema::hasColumn('users', 'language')) {
                $table->dropColumn('language');
            }
        });
    }
};
