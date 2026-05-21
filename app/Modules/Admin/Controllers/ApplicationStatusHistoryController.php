<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ApplicationStatusHistory;
use App\Models\LicenseApplication;
use App\Modules\Admin\Resources\ApplicationStatusHistoryResource;
use App\Exceptions\ApiException;

class ApplicationStatusHistoryController extends Controller
{
    public function index(int $application)
    {
        $exists = LicenseApplication::query()->whereKey($application)->exists();
        if (! $exists) {
            throw new ApiException('Application not found.', 404);
        }

        $histories = ApplicationStatusHistory::query()
            ->where('application_id', $application)
            ->with('changedByUser')
            ->orderBy('id')
            ->get();

        return $this->successResponse(
            ApplicationStatusHistoryResource::collection($histories)->resolve(),
            'Application status history retrieved successfully.'
        );
    }
}
