<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_documents', function (Blueprint $table) {
            $table->string('rejection_reason_code', 64)->nullable()->after('rejection_reason');
            $table->text('rejection_details')->nullable()->after('rejection_reason_code');
        });

        $knownCodes = [
            'unclear_document',
            'wrong_document',
            'expired_document',
            'incomplete_document',
            'other',
        ];

        DB::table('application_documents')
            ->whereNotNull('rejection_reason')
            ->where('rejection_reason', '!=', '')
            ->whereNull('rejection_reason_code')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($knownCodes): void {
                foreach ($rows as $row) {
                    $text = trim((string) $row->rejection_reason);

                    if ($text === '') {
                        continue;
                    }

                    if (in_array($text, $knownCodes, true)) {
                        DB::table('application_documents')
                            ->where('id', $row->id)
                            ->update([
                                'rejection_reason_code' => $text,
                                'rejection_details' => $text === 'other' ? $text : null,
                            ]);

                        continue;
                    }

                    DB::table('application_documents')
                        ->where('id', $row->id)
                        ->update([
                            'rejection_reason_code' => 'other',
                            'rejection_details' => $text,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('application_documents', function (Blueprint $table) {
            $table->dropColumn(['rejection_reason_code', 'rejection_details']);
        });
    }
};
