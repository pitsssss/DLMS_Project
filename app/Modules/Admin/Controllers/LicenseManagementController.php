<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Licenses\Requests\BlockLicenseRequest;
use App\Modules\Licenses\Resources\LicenseResource;
use App\Modules\Licenses\Services\LicenseService;

class LicenseManagementController extends Controller
{
    public function block(BlockLicenseRequest $request, int $license, LicenseService $licenses)
    {
        $model = $licenses->block(
            $request->user(),
            $license,
            $request->validated('reason')
        );

        return $this->successResponse(
            new LicenseResource($model),
            'messages.licenses.blocked'
        );
    }

    public function unblock(int $license, LicenseService $licenses)
    {
        $model = $licenses->unblock(request()->user(), $license);

        return $this->successResponse(
            new LicenseResource($model),
            'messages.licenses.unblocked'
        );
    }
}
