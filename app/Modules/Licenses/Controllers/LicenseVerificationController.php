<?php

namespace App\Modules\Licenses\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Licenses\Services\LicenseVerificationService;
use Illuminate\Http\JsonResponse;

class LicenseVerificationController extends Controller
{
    public function show(string $verificationToken, LicenseVerificationService $verification): JsonResponse
    {
        return $this->successResponse(
            $verification->verify($verificationToken),
            'messages.licenses.verification_retrieved'
        );
    }
}
