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
            'messages.auth.forgot_sent'
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
            'messages.auth.forgot_otp_verified'
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
            'messages.auth.password_reset'
        );
    }
}
