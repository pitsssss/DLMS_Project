<?php

namespace App\Modules\Content\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Resources\FaqResource;
use App\Modules\Content\Services\ContentService;

class ContentController extends Controller
{
    public function __construct(
        protected ContentService $content,
    ) {}

    public function faqs()
    {
        return $this->successResponse(
            FaqResource::collection($this->content->faqs()),
            'messages.content.faqs_fetched'
        );
    }

    public function privacyPolicy()
    {
        return $this->successResponse(
            $this->content->privacyPolicy(),
            'messages.content.privacy_policy_fetched'
        );
    }

    public function contactInfo()
    {
        return $this->successResponse(
            $this->content->contactInfo(),
            'messages.content.contact_info_fetched'
        );
    }
}
