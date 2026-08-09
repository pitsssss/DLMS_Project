<?php

namespace App\Modules\Notifications\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Resources\NotificationResource;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationService $notifications)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'unread_only' => ['sometimes', 'boolean'],
        ]);

        $paginator = $notifications->paginateForUser(
            $request->user(),
            (int) ($validated['per_page'] ?? 20),
            isset($validated['unread_only']) ? filter_var($validated['unread_only'], FILTER_VALIDATE_BOOLEAN) : null
        );

        return $this->successResponse([
            'items' => NotificationResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.notifications.list');
    }

    public function unreadCount(Request $request, NotificationService $notifications)
    {
        return $this->successResponse([
            'unread_count' => $notifications->unreadCountForUser($request->user()),
        ], 'messages.notifications.unread_count');
    }

    public function markRead(Request $request, int $notification, NotificationService $notifications)
    {
        $model = $notifications->markAsRead($request->user(), $notification);

        return $this->successResponse(
            new NotificationResource($model),
            'messages.notifications.read'
        );
    }

    public function markAllRead(Request $request, NotificationService $notifications)
    {
        $result = $notifications->markAllAsRead($request->user());

        return $this->successResponse($result, 'messages.notifications.read_all');
    }
}
