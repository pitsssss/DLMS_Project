<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fines\Requests\StoreFineRequest;
use App\Modules\Fines\Requests\UpdateFineRequest;
use App\Modules\Fines\Resources\FineResource;
use App\Modules\Fines\Services\FineService;
use Illuminate\Http\Request;

class FineManagementController extends Controller
{
    public function index(Request $request, FineService $fines)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', 'in:unpaid,paid,cancelled'],
            'citizen_id' => ['sometimes', 'integer', 'exists:users,id'],
        ]);

        $paginator = $fines->paginateForAdmin(
            (int) ($validated['per_page'] ?? 20),
            $validated['status'] ?? null,
            isset($validated['citizen_id']) ? (int) $validated['citizen_id'] : null
        );

        return $this->successResponse([
            'items' => FineResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.fines.list');
    }

    public function store(StoreFineRequest $request, FineService $fines)
    {
        $validated = $request->validated();

        $fine = $fines->create(
            $request->user(),
            (int) $validated['citizen_id'],
            (float) $validated['amount'],
            $validated['reason'],
            isset($validated['license_id']) ? (int) $validated['license_id'] : null
        );

        return $this->successResponse(
            new FineResource($fine->load(['citizen', 'license'])),
            'messages.fines.created',
            201
        );
    }

    public function update(UpdateFineRequest $request, int $fine, FineService $fines)
    {
        $model = $fines->update($request->user(), $fine, $request->validated());

        return $this->successResponse(
            new FineResource($model),
            'messages.fines.updated'
        );
    }
}
