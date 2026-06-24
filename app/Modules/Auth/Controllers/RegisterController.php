<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\RegisterRequest;
use App\Modules\Auth\Requests\VerifyOtpRequest;
use App\Modules\Auth\Resources\UserResource;
use App\Modules\Auth\Services\AuthService;

class RegisterController extends Controller
{
    public function register(RegisterRequest $request, AuthService $auth)
    {
        $payload = $auth->register($request->validated());

        return $this->successResponse(
            $payload,
            'messages.auth.register_success',
            201
        );
    }

    public function verifyOtp(VerifyOtpRequest $request, AuthService $auth)
    {
        $result = $auth->verifyOtp($request->validated());

        return $this->successResponse([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
        ], 'messages.auth.verify_success');
    }
}
