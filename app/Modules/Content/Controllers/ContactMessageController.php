<?php

namespace App\Modules\Content\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Requests\StoreContactMessageRequest;
use App\Modules\Content\Resources\ContactMessageResource;
use App\Modules\Content\Services\ContactMessageService;

class ContactMessageController extends Controller
{
    public function __construct(
        protected ContactMessageService $contactMessages,
    ) {}

    public function store(StoreContactMessageRequest $request)
    {
        $message = $this->contactMessages->store(
            $request->validated(),
            $request->user('sanctum')
        );

        return $this->successResponse(
            new ContactMessageResource($message),
            'messages.contact.sent',
            201
        );
    }
}
