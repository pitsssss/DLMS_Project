<?php

namespace App\Modules\Applications\Resources;

use App\Support\CitizenCatalogLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ServiceType */
class ServiceTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $code = (string) $this->code;

        return [
            'id' => $this->id,
            'name' => CitizenCatalogLabel::serviceType($code, $this->name),
            'code' => $this->code,
            'description' => CitizenCatalogLabel::serviceTypeDescription($code, $this->description ?? ''),
        ];
    }
}
