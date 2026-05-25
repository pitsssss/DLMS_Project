<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Licenses\Resources\LicenseResource;
use App\Modules\Licenses\Services\LicenseService;

class ApplicationLicenseController extends Controller
{
    public function issue(int $application, LicenseService $licenses)
    {
        $license = $licenses->issueForApplication(request()->user(), $application);

        return $this->successResponse(
            new LicenseResource($license),
            'messages.licenses.issued'
        );
    }
}
