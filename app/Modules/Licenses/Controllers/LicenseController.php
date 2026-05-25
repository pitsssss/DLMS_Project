<?php

namespace App\Modules\Licenses\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Licenses\Requests\ReplacementLicenseRequest;
use App\Modules\Licenses\Resources\LicenseResource;
use App\Modules\Licenses\Services\LicenseService;
use Illuminate\Http\Request;

class LicenseController extends Controller
{
    public function index(Request $request, LicenseService $licenses)
    {
        $list = $licenses->listForCitizen($request->user());

        return $this->successResponse(
            LicenseResource::collection($list)->resolve(),
            'messages.licenses.list'
        );
    }

    public function show(Request $request, int $license, LicenseService $licenses)
    {
        $model = $licenses->showForCitizen($request->user(), $license);

        return $this->successResponse(
            new LicenseResource($model),
            'messages.licenses.retrieved'
        );
    }

    public function renew(Request $request, int $license, LicenseService $licenses)
    {
        $model = $licenses->renew($request->user(), $license);

        return $this->successResponse(
            new LicenseResource($model),
            'messages.licenses.renewed'
        );
    }

    public function replacement(
        ReplacementLicenseRequest $request,
        int $license,
        LicenseService $licenses
    ) {
        $model = $licenses->replace(
            $request->user(),
            $license,
            $request->validated('type')
        );

        return $this->successResponse(
            new LicenseResource($model),
            'messages.licenses.replacement'
        );
    }

    public function unblockRequest(Request $request, int $license, LicenseService $licenses)
    {
        $data = $licenses->requestUnblock($request->user(), $license);

        return $this->successResponse($data, 'messages.licenses.unblock_submitted');
    }
}
