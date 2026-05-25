<?php

namespace App\Modules\Applications\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ServiceType;

class ServiceTypeController extends Controller
{
    public function index()
    {
        $types = ServiceType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'description']);

        return $this->successResponse($types, 'messages.applications.service_types');
    }
}
