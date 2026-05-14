<?php

namespace App\Modules\Applications\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Requests\StoreApplicationDocumentRequest;
use App\Modules\Applications\Resources\ApplicationDocumentResource;
use App\Modules\Applications\Services\ApplicationDocumentService;
use Illuminate\Http\Request;

class ApplicationDocumentController extends Controller
{
    public function requiredDocuments(Request $request, int $application, ApplicationDocumentService $documents)
    {
        $checklist = $documents->requiredChecklist($request->user(), $application);

        return $this->successResponse($checklist, 'Required documents retrieved successfully.');
    }

    public function index(Request $request, int $application, ApplicationDocumentService $documents)
    {
        $list = $documents->listDocuments($request->user(), $application);

        return $this->successResponse(
            ApplicationDocumentResource::collection($list)->resolve(),
            'Application documents retrieved successfully.'
        );
    }

    public function store(
        StoreApplicationDocumentRequest $request,
        int $application,
        ApplicationDocumentService $documents
    ) {
        $validated = $request->validated();

        $model = $documents->upload(
            $request->user(),
            $application,
            (int) $validated['required_document_id'],
            $request->file('file')
        );

        return $this->successResponse(
            new ApplicationDocumentResource($model),
            'Document uploaded successfully.'
        );
    }

    public function submit(Request $request, int $application, ApplicationDocumentService $documents)
    {
        $applicationModel = $documents->submitForReview($request->user(), $application);

        return $this->successResponse(
            [
                'id' => $applicationModel->id,
                'application_number' => $applicationModel->application_number,
                'status' => $applicationModel->status->value,
                'submitted_at' => $applicationModel->submitted_at?->toIso8601String(),
            ],
            'Application submitted for document review.'
        );
    }
}
