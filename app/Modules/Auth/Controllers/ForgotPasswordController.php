<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Requests\ResetPasswordRequest;
use App\Modules\Auth\Requests\VerifyForgotPasswordOtpRequest;
use App\Modules\Auth\Services\AuthService;

class ForgotPasswordController extends Controller
{
    public function forgot(ForgotPasswordRequest $request, AuthService $auth)
    {
        $auth->forgotPassword($request->validated('email'));

        return $this->successResponse(
            null,
            'If the email exists, a verification code has been sent.'
        );
    }

    public function verifyForgotPasswordOtp(VerifyForgotPasswordOtpRequest $request, AuthService $auth)
    {
        $data = $auth->verifyForgotPasswordOtp(
            $request->validated('email'),
            $request->validated('code')
        );

        return $this->successResponse(
            $data,
            'OTP verified successfully.'
        );
    }

    public function resetPassword(ResetPasswordRequest $request, AuthService $auth)
    {
        $validated = $request->validated();

        $auth->resetPassword(
            $validated['email'],
            $validated['reset_token'],
            $validated['password']
        );

        return $this->successResponse(
            null,
            'Password reset successfully. Please login again.'
        );
    }
}
