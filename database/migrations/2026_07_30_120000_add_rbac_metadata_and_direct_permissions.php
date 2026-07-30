<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            if (! Schema::hasColumn('roles', 'description')) {
                $table->text('description')->nullable()->after('display_name');
            }
            if (! Schema::hasColumn('roles', 'is_system')) {
                $table->boolean('is_system')->default(false)->after('description');
            }
            if (! Schema::hasColumn('roles', 'is_protected')) {
                $table->boolean('is_protected')->default(false)->after('is_system');
            }
            if (! Schema::hasColumn('roles', 'is_assignable')) {
                $table->boolean('is_assignable')->default(true)->after('is_protected');
            }
            if (! Schema::hasColumn('roles', 'is_archived')) {
                $table->boolean('is_archived')->default(false)->after('is_assignable');
            }
            if (! Schema::hasColumn('roles', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('is_archived');
            }
            if (! Schema::hasColumn('roles', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('version')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('roles', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('roles', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('updated_by');
            }
        });

        Schema::create('permission_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'permission_id']);
            $table->index('permission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_user');

        Schema::table('roles', function (Blueprint $table): void {
            foreach (['archived_at', 'updated_by', 'created_by', 'version', 'is_archived', 'is_assignable', 'is_protected', 'is_system', 'description'] as $column) {
                if (Schema::hasColumn('roles', $column)) {
                    if (in_array($column, ['created_by', 'updated_by'], true)) {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });
    }
};
