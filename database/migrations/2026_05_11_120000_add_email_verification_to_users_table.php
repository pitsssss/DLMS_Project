<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            });
        }

        if (Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['phone']);
            });

            Schema::table('users', function (Blueprint $table) {
                $table->string('phone')->nullable()->unique()->change();
                $table->string('email')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'phone')) {
            if (Schema::hasColumn('users', 'email_verified_at')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropColumn('email_verified_at');
                });
            }

            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['phone']);
            });

            Schema::table('users', function (Blueprint $table) {
                $table->string('phone')->nullable(false)->unique()->change();
                $table->string('email')->nullable()->change();
            });
        }
    }
};
