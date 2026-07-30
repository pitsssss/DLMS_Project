<?php

namespace App\Console\Commands;

use App\Modules\Dashboard\Services\RbacBootstrapService;
use Illuminate\Console\Command;

class RbacRepairDocumentReviewerCommand extends Command
{
    protected $signature = 'rbac:repair-document-reviewer
                            {--dry-run : Show intended changes without applying}
                            {--apply : Apply the Document Reviewer permission correction}';

    protected $description = 'Repair profile_document_reviewer permissions to the safe baseline (excludes view_applications)';

    public function handle(RbacBootstrapService $rbac): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        if (! $apply && ! $this->option('dry-run')) {
            $this->warn('No mode selected. Defaulting to --dry-run. Pass --apply to write changes.');
        }

        $result = $rbac->repairDocumentReviewer($apply);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
