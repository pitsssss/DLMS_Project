<?php

namespace App\Modules\Dashboard\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AuditLog */
class DashboardEmployeeSessionAuditLogResource extends JsonResource
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
            'old_values' => $this->safeValues($this->old_values),
            'new_values' => $this->safeValues($this->new_values),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function actionLabel(string $action): string
    {
        $key = 'messages.employee_sessions.audit_actions.'.$action;
        $label = __($key);

        return $label !== $key ? $label : $action;
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

        $blocked = [
            'token', 'plain_text_token', 'password', 'password_confirmation',
            'authorization', 'cookie', 'session_id', 'hashed_session_identifier',
            'personal_access_token_id', 'token_hash',
        ];

        return collect($values)
            ->reject(function ($v, $k) use ($blocked) {
                $key = strtolower((string) $k);

                return in_array($key, $blocked, true)
                    || str_contains($key, 'password')
                    || str_contains($key, 'token')
                    || str_contains($key, 'authorization')
                    || str_contains($key, 'secret');
            })
            ->all();
    }
}
