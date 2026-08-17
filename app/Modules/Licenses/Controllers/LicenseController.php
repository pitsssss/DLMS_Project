<?php

namespace App\Modules\Licenses\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Licenses\Requests\ReplacementLicenseRequest;
use App\Modules\Applications\Services\LicenseServiceEligibilityService;
use App\Modules\Licenses\Resources\LicenseResource;
use App\Modules\Licenses\Services\LicensePrintService;
use App\Modules\Licenses\Services\LicenseService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LicenseController extends Controller
{
    public function index(Request $request, LicenseService $licenses, LicenseServiceEligibilityService $eligibility)
    {
        $citizen = $request->user();
        $list = $licenses->listForCitizen($citizen)->map(function ($license) use ($citizen, $eligibility) {
            $flags = $eligibility->flagsForCitizen($citizen, $license);
            $license->can_renew = $flags['can_renew'];
            $license->can_request_lost_replacement = $flags['can_request_lost_replacement'];
            $license->can_request_damaged_replacement = $flags['can_request_damaged_replacement'];
            $license->can_request_unblock = $flags['can_request_unblock'];

            return $license;
        });

        return $this->successResponse(
            LicenseResource::collection($list)->resolve(),
            'messages.licenses.list'
        );
    }

    public function show(Request $request, int $license, LicenseService $licenses, LicenseServiceEligibilityService $eligibility)
    {
        $model = $licenses->showForCitizen($request->user(), $license);
        $flags = $eligibility->flagsForCitizen($request->user(), $model);
        $model->can_renew = $flags['can_renew'];
        $model->can_request_lost_replacement = $flags['can_request_lost_replacement'];
        $model->can_request_damaged_replacement = $flags['can_request_damaged_replacement'];
        $model->can_request_unblock = $flags['can_request_unblock'];

        return $this->successResponse(
            new LicenseResource($model),
            'messages.licenses.retrieved'
        );
    }

    public function download(
        Request $request,
        int $license,
        LicenseService $licenses,
        LicensePrintService $printer
    ): Response {
        $model = $licenses->showForCitizen($request->user(), $license);
        $result = $printer->downloadPdf($request->user(), $model);

        return response($result['binary'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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

    /**
     * @deprecated Use POST /api/applications with service_type_code=license_unblock instead.
     *             This endpoint only acknowledges intent; it does not create an application
     *             or perform license unblocking.
     */
    public function unblockRequest(Request $request, int $license, LicenseService $licenses)
    {
        $data = $licenses->requestUnblock($request->user(), $license);

        return $this->successResponse($data, 'messages.licenses.unblock_submitted');
    }
}
