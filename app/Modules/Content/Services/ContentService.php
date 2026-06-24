<?php

namespace App\Modules\Content\Services;

use App\Models\Faq;
use Illuminate\Database\Eloquent\Collection;

class ContentService
{
    /**
     * @return Collection<int, Faq>
     */
    public function faqs(): Collection
    {
        return Faq::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function privacyPolicy(): array
    {
        return config('content.privacy_policy', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function contactInfo(): array
    {
        return config('content.contact_info', []);
    }
}
