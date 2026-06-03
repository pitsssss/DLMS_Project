<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\ChangePasswordRequest;
use App\Modules\Auth\Requests\CompleteProfileRequest;
use App\Modules\Auth\Requests\UpdateProfileRequest;
use App\Modules\Auth\Resources\ProfileStatusResource;
use App\Modules\Auth\Resources\UserResource;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\ProfileService;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function me(Request $request)
    {
        $user = $request->user()->load('role');

        return $this->successResponse(
            new UserResource($user),
            'messages.auth.profile_retrieved'
        );
    }

    public function status(Request $request, ProfileService $profiles)
    {
        return $this->successResponse(
            new ProfileStatusResource($profiles->statusPayload($request->user())),
            'messages.profile.status_retrieved'
        );
    }

    public function complete(CompleteProfileRequest $request, AuthService $auth, ProfileService $profiles)
    {
        $user = $auth->completeProfile($request->user(), $request->validated());

        return $this->successResponse(
            new ProfileStatusResource($profiles->statusPayload($user)),
            'messages.profile.submitted_for_review'
        );
    }

    public function update(UpdateProfileRequest $request, AuthService $auth, ProfileService $profiles)
    {
        $result = $auth->updateProfile($request->user(), $request->validated());

        return $this->successResponse(
            new ProfileStatusResource($profiles->statusPayload($result['user'])),
            $result['submitted_for_review']
                ? 'messages.profile.updated_and_submitted'
                : 'messages.auth.profile_updated'
        );
    }

    public function changePassword(ChangePasswordRequest $request, AuthService $auth)
    {
        $auth->changePassword($request->user(), $request->validated());

        return $this->successResponse(null, 'messages.auth.password_changed');
    }
}
