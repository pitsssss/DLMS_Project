<?php

namespace App\Modules\Applications\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Requests\StoreApplicationRequest;
use App\Modules\Applications\Resources\ApplicationResource;
use App\Modules\Applications\Services\ApplicationService;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function index(Request $request, ApplicationService $applications)
    {
        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(1, min(50, $perPage));

        $paginator = $applications->paginateForCitizen($request->user(), $perPage);

        $items = collect($paginator->items())
            ->map(fn ($model) => (new ApplicationResource($model))->resolve())
            ->values()
            ->all();

        return $this->successResponse([
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.applications.list_success');
    }

    public function store(StoreApplicationRequest $request, ApplicationService $applications)
    {
        $application = $applications->createFromPayload(
            $request->user(),
            $request->validated()
        );

        return $this->successResponse(
            new ApplicationResource($application),
            'messages.applications.created'
        );
    }

    public function show(Request $request, int $application, ApplicationService $applications)
    {
        $model = $applications->getForCitizen($request->user(), $application);
        $model->load([
            'applicationDocuments' => function ($q): void {
                $q->orderByDesc('id')->with('requiredDocument');
            },
        ]);

        return $this->successResponse(
            new ApplicationResource($model),
            'messages.applications.retrieved'
        );
    }
}
