<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('service_types', 'description')) {
            return;
        }

        Schema::table('service_types', function (Blueprint $table) {
            $table->text('description')->nullable()->after('code');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('service_types', 'description')) {
            return;
        }

        Schema::table('service_types', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
