<?php

namespace App\Modules\Content\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Modules\Content\Requests\UpdateContactMessageStatusRequest;
use App\Modules\Content\Resources\ContactMessageDetailResource;
use Illuminate\Http\Request;

class DashboardContactMessageController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', 'in:new,read,in_progress,resolved,closed'],
        ]);

        $paginator = ContactMessage::query()
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 20));

        return $this->successResponse([
            'items' => ContactMessageDetailResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.contact.list_retrieved');
    }

    public function updateStatus(UpdateContactMessageStatusRequest $request, int $contactMessage)
    {
        $message = ContactMessage::query()->findOrFail($contactMessage);
        $message->update(['status' => $request->validated()['status']]);

        return $this->successResponse(
            new ContactMessageDetailResource($message),
            'messages.contact.status_updated'
        );
    }
}
