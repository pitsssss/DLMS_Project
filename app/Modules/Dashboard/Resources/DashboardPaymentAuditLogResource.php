<?php

namespace App\Modules\Dashboard\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AuditLog */
class DashboardPaymentAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'action_label' => $this->actionLabel($this->action),
            'actor' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'source' => $this->new_values['source'] ?? null,
            'old_values' => $this->safeValues($this->old_values),
            'new_values' => $this->safeValues($this->new_values),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'payment.created' => 'إنشاء عملية دفع',
            'payment.initiated' => 'بدء عملية دفع',
            'payment.completed' => 'إكمال دفع',
            'payment.failed' => 'فشل دفع',
            'payment.under_verification' => 'دفع قيد التحقق',
            'payment.verified' => 'تحقق من دفع',
            'payment.reconciled' => 'مطابقة دفع',
            default => $action,
        };
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function safeValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $blocked = ['checkout_url', 'success_url', 'cancel_url', 'signature', 'secret', 'authorization', 'raw', 'payload'];

        return collect($values)
            ->reject(fn ($v, $k) => in_array(strtolower((string) $k), $blocked, true)
                || str_contains(strtolower((string) $k), 'secret')
                || str_contains(strtolower((string) $k), 'signature'))
            ->all();
    }
}
