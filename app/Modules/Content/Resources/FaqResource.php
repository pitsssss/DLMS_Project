<?php

namespace App\Modules\Content\Resources;

use App\Support\CitizenContentLocalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Faq */
class FaqResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $localized = CitizenContentLocalizer::faq($this->resource);

        return [
            'id' => $this->id,
            'category' => $localized['category'],
            'question' => $localized['question'],
            'answer' => $localized['answer'],
        ];
    }
}
