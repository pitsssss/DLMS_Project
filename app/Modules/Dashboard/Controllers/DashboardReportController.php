<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Requests\DashboardReportFilterRequest;
use App\Modules\Dashboard\Services\Reports\DashboardReportApplicationsService;
use App\Modules\Dashboard\Services\Reports\DashboardReportAppointmentsService;
use App\Modules\Dashboard\Services\Reports\DashboardReportEmployeesService;
use App\Modules\Dashboard\Services\Reports\DashboardReportFinesService;
use App\Modules\Dashboard\Services\Reports\DashboardReportLicensesService;
use App\Modules\Dashboard\Services\Reports\DashboardReportOptionsService;
use App\Modules\Dashboard\Services\Reports\DashboardReportSummaryService;
use App\Modules\Dashboard\Services\Reports\DashboardReportTestsService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class DashboardReportController extends Controller
{
    public function options(DashboardReportOptionsService $options): JsonResponse
    {
        return $this->successResponse(
            $options->build(request()->user()),
            'messages.reports.options_retrieved'
        );
    }

    public function summary(
        DashboardReportFilterRequest $request,
        DashboardReportSummaryService $reports,
    ): JsonResponse {
        return $this->respond($request, fn () => $reports->build($request->user(), $request->filters()));
    }

    public function applications(
        DashboardReportFilterRequest $request,
        DashboardReportApplicationsService $reports,
    ): JsonResponse {
        return $this->respond($request, fn () => $reports->build($request->filters()));
    }

    public function tests(
        DashboardReportFilterRequest $request,
        DashboardReportTestsService $reports,
    ): JsonResponse {
        return $this->respond($request, fn () => $reports->build($request->filters()));
    }

    public function appointments(
        DashboardReportFilterRequest $request,
        DashboardReportAppointmentsService $reports,
    ): JsonResponse {
        return $this->respond($request, fn () => $reports->build($request->filters()));
    }

    public function licenses(
        DashboardReportFilterRequest $request,
        DashboardReportLicensesService $reports,
    ): JsonResponse {
        return $this->respond($request, fn () => $reports->build($request->filters()));
    }

    public function fines(
        DashboardReportFilterRequest $request,
        DashboardReportFinesService $reports,
    ): JsonResponse {
        return $this->respond($request, fn () => $reports->build($request->filters()));
    }

    public function employees(
        DashboardReportFilterRequest $request,
        DashboardReportEmployeesService $reports,
    ): JsonResponse {
        return $this->respond(
            $request,
            fn () => $reports->build($request->user(), $request->filters())
        );
    }

    private function respond(DashboardReportFilterRequest $request, callable $builder): JsonResponse
    {
        try {
            return $this->successResponse($builder(), 'messages.reports.retrieved');
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse('messages.reports.invalid_period', [
                'period' => [$e->getMessage()],
            ], 422);
        }
    }
}
