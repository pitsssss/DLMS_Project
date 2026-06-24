<?php

namespace App\Modules\Applications\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ServiceType;
use App\Modules\Applications\Resources\ServiceTypeResource;

class ServiceTypeController extends Controller
{
    public function index()
    {
        $types = ServiceType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return $this->successResponse(
            ServiceTypeResource::collection($types)->resolve(),
            'messages.applications.service_types'
        );
    }
}
