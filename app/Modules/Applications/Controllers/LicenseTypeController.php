<?php

namespace App\Modules\Applications\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LicenseType;

class LicenseTypeController extends Controller
{
    public function index()
    {
        $types = LicenseType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'minimum_age', 'validity_years']);

        return $this->successResponse($types, 'License types retrieved successfully.');
    }
}
