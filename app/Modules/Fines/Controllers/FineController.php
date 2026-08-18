<?php

namespace App\Modules\Fines\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fines\Resources\FineResource;
use App\Modules\Fines\Services\FineService;
use Illuminate\Http\Request;

class FineController extends Controller
{
    public function index(Request $request, FineService $fines)
    {
        $list = $fines->listForCitizen($request->user());

        return $this->successResponse(
            FineResource::collection($list)->resolve(),
            'messages.fines.list'
        );
    }

    public function show(Request $request, int $fine, FineService $fines)
    {
        $model = $fines->findOwnedByCitizen($request->user(), $fine);

        return $this->successResponse(
            new FineResource($model),
            'messages.fines.retrieved'
        );
    }
}
