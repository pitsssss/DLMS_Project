<?php

namespace App\Modules\Applications\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TestType;
use App\Modules\Applications\Resources\TestTypeResource;

class TestTypeController extends Controller
{
    public function index()
    {
        $types = TestType::query()
            ->orderBy('name')
            ->get();

        return $this->successResponse(
            TestTypeResource::collection($types)->resolve(),
            'messages.applications.test_types'
        );
    }
}
