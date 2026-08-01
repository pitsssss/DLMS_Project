<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Requests\DashboardChangePasswordRequest;
use App\Modules\Dashboard\Requests\DashboardForgotPasswordRequest;
use App\Modules\Dashboard\Requests\DashboardLoginRequest;
use App\Modules\Dashboard\Requests\DashboardResetPasswordRequest;
use App\Modules\Dashboard\Requests\DashboardVerifyForgotOtpRequest;
use App\Modules\Dashboard\Services\DashboardAuthService;

class DashboardAuthController extends Controller
{
    public function login(DashboardLoginRequest $request, DashboardAuthService $auth)
    {
        $result = $auth->login(
            $request->validated('email'),
            $request->validated('password'),
            $request
        );

        return $this->successResponse([
            'token' => $result['token'],
            'user' => $auth->me($result['user'])['user'],
        ], 'messages.dashboard.login_success');
    }

    public function logout(DashboardAuthService $auth)
    {
        $auth->logout(request()->user(), request());

        return $this->successResponse(null, 'messages.dashboard.logout_success');
    }

    public function me(DashboardAuthService $auth)
    {
        return $this->successResponse(
            $auth->me(request()->user()),
            'messages.dashboard.me_retrieved'
        );
    }

    public function forgotPassword(DashboardForgotPasswordRequest $request, DashboardAuthService $auth)
    {
        $auth->forgotPassword($request->validated('email'));

        return $this->successResponse(null, 'messages.dashboard.forgot_password_sent');
    }

    public function verifyForgotPasswordOtp(DashboardVerifyForgotOtpRequest $request, DashboardAuthService $auth)
    {
        $data = $auth->verifyForgotPasswordOtp(
            $request->validated('email'),
            $request->validated('code')
        );

        return $this->successResponse($data, 'messages.dashboard.otp_verified');
    }

    public function resetPassword(DashboardResetPasswordRequest $request, DashboardAuthService $auth)
    {
        $auth->resetPassword(
            $request->validated('email'),
            $request->validated('reset_token'),
            $request->validated('password')
        );

        return $this->successResponse(null, 'messages.dashboard.password_reset');
    }

    public function changePassword(DashboardChangePasswordRequest $request, DashboardAuthService $auth)
    {
        $auth->changePassword(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password')
        );

        return $this->successResponse(null, 'messages.auth.password_changed');
    }
}
