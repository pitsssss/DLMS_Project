<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\ChangePasswordRequest;
use App\Modules\Auth\Requests\CompleteProfileRequest;
use App\Modules\Auth\Requests\UpdateProfileRequest;
use App\Modules\Auth\Resources\UserResource;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function me(Request $request)
    {
        $user = $request->user()->load('role');

        return $this->successResponse(
            new UserResource($user),
            'Profile retrieved successfully.'
        );
    }

    public function complete(CompleteProfileRequest $request, AuthService $auth)
    {
        $user = $auth->completeProfile($request->user(), $request->validated());

        return $this->successResponse(
            new UserResource($user->load('role')),
            'Profile completed successfully.'
        );
    }

    public function update(UpdateProfileRequest $request, AuthService $auth)
    {
        $user = $auth->updateProfile($request->user(), $request->validated());

        return $this->successResponse(
            new UserResource($user->load('role')),
            'Profile updated successfully.'
        );
    }

    public function changePassword(ChangePasswordRequest $request, AuthService $auth)
    {
        $auth->changePassword($request->user(), $request->validated());

        return $this->successResponse(null, 'Password changed successfully.');
    }
}
