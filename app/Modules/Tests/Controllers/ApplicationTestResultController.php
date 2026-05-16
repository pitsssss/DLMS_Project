<?php

namespace App\Modules\Tests\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tests\Resources\TestResultResource;
use App\Modules\Tests\Services\TestResultService;
use Illuminate\Http\Request;

class ApplicationTestResultController extends Controller
{
    public function index(Request $request, int $application, TestResultService $tests)
    {
        $results = $tests->listForApplication($request->user(), $application);

        return $this->successResponse(
            TestResultResource::collection($results)->resolve(),
            'Test results retrieved successfully.'
        );
    }
}
