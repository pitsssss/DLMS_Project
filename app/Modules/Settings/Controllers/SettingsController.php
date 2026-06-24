<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Requests\ChangePasswordRequest;
use App\Modules\Settings\Requests\UpdatePreferencesRequest;
use App\Modules\Settings\Resources\SettingsResource;
use App\Modules\Settings\Services\SettingsService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(
        protected SettingsService $settings,
    ) {}

    public function index(Request $request)
    {
        return $this->successResponse(
            new SettingsResource($request->user()),
            'messages.settings.fetched'
        );
    }

    public function updatePreferences(UpdatePreferencesRequest $request)
    {
        $preferences = $this->settings->updatePreferences($request->user(), $request->validated());

        return $this->successResponse($preferences, 'messages.settings.preferences_updated');
    }

    public function changePassword(ChangePasswordRequest $request)
    {
        $this->settings->changePassword($request->user(), $request->validated());

        return $this->successResponse(null, 'messages.settings.password_changed');
    }
}
