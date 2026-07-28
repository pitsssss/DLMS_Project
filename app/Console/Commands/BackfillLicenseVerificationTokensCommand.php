<?php

namespace App\Console\Commands;

use App\Models\License;
use App\Modules\Licenses\Services\LicenseLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillLicenseVerificationTokensCommand extends Command
{
    protected $signature = 'licenses:backfill-verification-tokens
                            {--chunk=200 : Number of licenses processed per chunk}';

    protected $description = 'Backfill opaque verification tokens for licenses missing them';

    public function handle(LicenseLifecycleService $lifecycle): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $filled = 0;

        License::query()
            ->whereNull('verification_token')
            ->orderBy('id')
            ->chunkById($chunk, function ($licenses) use ($lifecycle, &$filled): void {
                foreach ($licenses as $license) {
                    DB::transaction(function () use ($license, $lifecycle, &$filled): void {
                        $locked = License::query()->whereKey($license->id)->lockForUpdate()->first();
                        if ($locked === null || $locked->verification_token) {
                            return;
                        }
                        $locked->verification_token = $lifecycle->generateVerificationToken();
                        $locked->save();
                        $filled++;
                    });
                }
            });

        $this->info("Filled={$filled}");

        return self::SUCCESS;
    }
}
