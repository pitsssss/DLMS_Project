<?php

namespace App\Modules\Applications\Resources;

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
        return [
            'id' => $this->id,
            // Database name is canonical (seeded + dashboard-editable).
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description ?? '',
        ];
    }
}
