<?php

namespace App\Modules\Payments\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Requests\CitizenPaymentIndexRequest;
use App\Modules\Payments\Resources\CitizenPaymentResource;
use App\Modules\Payments\Services\CitizenPaymentHistoryService;
use Illuminate\Http\Request;

class CitizenPaymentController extends Controller
{
    public function index(CitizenPaymentIndexRequest $request, CitizenPaymentHistoryService $payments)
    {
        $filters = $request->validated();
        $paginator = $payments->paginateForCitizen($request->user(), $filters);

        return $this->successResponse([
            'items' => collect($paginator->items())
                ->map(fn ($payment) => (new CitizenPaymentResource($payment))->resolve())
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.payments.history_list');
    }

    public function show(Request $request, int $payment, CitizenPaymentHistoryService $payments)
    {
        $model = $payments->findOwnedByCitizen($request->user(), $payment);

        return $this->successResponse(
            (new CitizenPaymentResource($model, true))->resolve(),
            'messages.payments.history_details'
        );
    }
}
