<?php

namespace App\Modules\Auth\Repositories;

use App\Models\PasswordResetToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordResetTokenRepository
{
    public function invalidateAllPendingForEmail(string $email): void
    {
        PasswordResetToken::query()
            ->where('email', $email)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }

    /**
     * @return array{record: PasswordResetToken, plain_token: string}
     */
    public function createToken(string $email): array
    {
        $plain = Str::random(64);

        $record = PasswordResetToken::query()->create([
            'email' => $email,
            'token' => Hash::make($plain),
            'expires_at' => now()->addMinutes(config('password_reset.token_expires_minutes', 15)),
        ]);

        return ['record' => $record, 'plain_token' => $plain];
    }

    public function findMatchingValidToken(string $email, string $plainToken): ?PasswordResetToken
    {
        $candidates = PasswordResetToken::query()
            ->where('email', $email)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $row) {
            if (Hash::check($plainToken, $row->token)) {
                return $row;
            }
        }

        return null;
    }

    public function markUsed(PasswordResetToken $token): void
    {
        $token->used_at = now();
        $token->save();
    }
}
