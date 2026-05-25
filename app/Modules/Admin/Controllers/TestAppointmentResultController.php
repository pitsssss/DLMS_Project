<?php

namespace App\Modules\Admin\Controllers;

use App\Enums\TestResultStatus;
use App\Http\Controllers\Controller;
use App\Modules\Tests\Requests\RecordTestResultRequest;
use App\Modules\Tests\Resources\TestResultResource;
use App\Modules\Tests\Services\TestResultService;

class TestAppointmentResultController extends Controller
{
    public function store(
        RecordTestResultRequest $request,
        int $appointment,
        TestResultService $tests
    ) {
        $result = TestResultStatus::from($request->validated('result'));

        $model = $tests->recordForAppointment(
            $request->user(),
            $appointment,
            $result,
            $request->validated('notes')
        );

        return $this->successResponse(
            new TestResultResource($model),
            'messages.tests.recorded'
        );
    }
}
