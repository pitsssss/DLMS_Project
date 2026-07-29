<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppointmentSlot;
use App\Modules\Dashboard\Requests\DeactivateDashboardAppointmentSlotRequest;
use App\Modules\Dashboard\Requests\ListDashboardAppointmentSlotBookingsRequest;
use App\Modules\Dashboard\Requests\ListDashboardAppointmentSlotsRequest;
use App\Modules\Dashboard\Requests\StoreDashboardAppointmentSlotRequest;
use App\Modules\Dashboard\Requests\UpdateDashboardAppointmentSlotRequest;
use App\Modules\Dashboard\Resources\DashboardAppointmentSlotBookingResource;
use App\Modules\Dashboard\Resources\DashboardAppointmentSlotResource;
use App\Modules\Dashboard\Services\DashboardAppointmentSlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardAppointmentSlotController extends Controller
{
    public function __construct(
        private readonly DashboardAppointmentSlotService $slots,
    ) {}

    public function index(ListDashboardAppointmentSlotsRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $paginator = $this->slots->paginate($filters, $request->user());

        return $this->successResponse([
            'items' => DashboardAppointmentSlotResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ], 'messages.appointment_slots.list_retrieved');
    }

    public function stats(ListDashboardAppointmentSlotsRequest $request): JsonResponse
    {
        $filters = $request->filters();
        unset($filters['page'], $filters['per_page']);

        return $this->successResponse(
            $this->slots->stats($filters),
            'messages.appointment_slots.stats_retrieved'
        );
    }

    public function options(): JsonResponse
    {
        return $this->successResponse(
            $this->slots->options(),
            'messages.appointment_slots.options_retrieved'
        );
    }

    public function show(int $slot): JsonResponse
    {
        $model = $this->slots->get($slot);

        return $this->successResponse(
            DashboardAppointmentSlotResource::detail($model)->resolve(),
            'messages.appointment_slots.details_retrieved'
        );
    }

    public function store(StoreDashboardAppointmentSlotRequest $request): JsonResponse
    {
        $model = $this->slots->create($request->validated(), $request->user(), $request);

        return $this->successResponse(
            DashboardAppointmentSlotResource::detail($model)->resolve(),
            'messages.appointment_slots.created',
            201
        );
    }

    public function update(UpdateDashboardAppointmentSlotRequest $request, int $slot): JsonResponse
    {
        $model = $this->slots->update(
            $this->slots->get($slot),
            $request->validated(),
            $request->user(),
            $request
        );

        return $this->successResponse(
            DashboardAppointmentSlotResource::detail($model)->resolve(),
            'messages.appointment_slots.updated'
        );
    }

    public function activate(Request $request, int $slot): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $model = $this->slots->activate(
            $this->slots->get($slot),
            $request->user(),
            $request,
            $validated['reason'] ?? null
        );

        return $this->successResponse(
            DashboardAppointmentSlotResource::detail($model)->resolve(),
            'messages.appointment_slots.activated'
        );
    }

    public function deactivate(DeactivateDashboardAppointmentSlotRequest $request, int $slot): JsonResponse
    {
        $model = $this->slots->deactivate(
            $this->slots->get($slot),
            $request->user(),
            $request,
            $request->validated('reason')
        );

        return $this->successResponse(
            DashboardAppointmentSlotResource::detail($model)->resolve(),
            'messages.appointment_slots.deactivated'
        );
    }

    public function bookings(ListDashboardAppointmentSlotBookingsRequest $request, int $slot): JsonResponse
    {
        $model = $this->slots->get($slot);
        $paginator = $this->slots->paginateBookings($model, $request->filters(), $request->user());

        return $this->successResponse([
            'items' => DashboardAppointmentSlotBookingResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ], 'messages.appointment_slots.bookings_retrieved');
    }

    public function auditLogs(Request $request, int $slot): JsonResponse
    {
        if (! $request->user()?->hasPermission('view_audit_logs')) {
            return $this->errorResponse('messages.dashboard.forbidden', [], 403);
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $model = AppointmentSlot::query()->findOrFail($slot);
        $paginator = $this->slots->paginateAuditLogs($model, (int) ($validated['per_page'] ?? 20));

        return $this->successResponse([
            'items' => collect($paginator->items())
                ->map(fn ($row) => $this->slots->transformAuditItem($row))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.appointment_slots.audit_logs_retrieved');
    }
}
