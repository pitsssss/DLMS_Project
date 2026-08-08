<?php

namespace App\Modules\Applications\Resources;

use App\Support\CitizenCatalogLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TestType */
class TestTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => CitizenCatalogLabel::testType((string) $this->code, $this->name),
            'code' => $this->code,
        ];
    }
}
