<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Auth\Services\AuthService;

class SettingsService
{
    public function __construct(
        protected AuthService $auth,
    ) {}

    /**
     * @param  array{language?: string|null, theme?: string|null}  $data
     * @return array{language: string, theme: string}
     */
    public function updatePreferences(User $user, array $data): array
    {
        $payload = [];

        if (array_key_exists('language', $data) && $data['language'] !== null) {
            $payload['language'] = $data['language'];
        }

        if (array_key_exists('theme', $data) && $data['theme'] !== null) {
            $payload['theme'] = $data['theme'];
        }

        if (! empty($payload)) {
            $user->fill($payload)->save();
        }

        return [
            'language' => $user->language ?? config('content.defaults.language', 'ar'),
            'theme' => $user->theme ?? config('content.defaults.theme', 'system'),
        ];
    }

    /**
     * @param  array{current_password: string, new_password: string}  $data
     */
    public function changePassword(User $user, array $data): void
    {
        $this->auth->changePassword($user, [
            'current_password' => $data['current_password'],
            'password' => $data['new_password'],
        ]);
    }
}
