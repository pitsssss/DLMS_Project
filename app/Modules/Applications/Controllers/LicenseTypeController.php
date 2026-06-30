<?php

namespace App\Modules\Applications\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LicenseType;
use App\Modules\Applications\Resources\LicenseTypeResource;

class LicenseTypeController extends Controller
{
    public function index()
    {
        $types = LicenseType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'minimum_age', 'validity_years']);

        return $this->successResponse(
            LicenseTypeResource::collection($types)->resolve(),
            'messages.applications.license_types'
        ); }
}
