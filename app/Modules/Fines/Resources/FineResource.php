<?php

namespace App\Modules\Fines\Resources;

use App\Enums\FineStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Fine */
class FineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'citizen_id' => $this->citizen_id,
            'license_id' => $this->license_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'is_payable' => $this->status === FineStatus::Unpaid && (float) $this->amount > 0,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'citizen' => $this->whenLoaded('citizen', fn () => [
                'id' => $this->citizen->id,
                'name' => $this->citizen->name,
            ]),
            'license' => $this->whenLoaded('license', fn () => $this->license ? [
                'id' => $this->license->id,
                'license_number' => $this->license->license_number,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
