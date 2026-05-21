<?php

namespace App\Modules\AIAgent\Services;

class AgentPreProcessor
{
    /**
     * @return array{message: string, language_hint: string|null, flags: array<string, bool>}
     */
    public function process(string $message): array
    {
        $trimmed = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);

        return [
            'message' => $trimmed,
            'language_hint' => $this->detectLanguageHint($trimmed),
            'flags' => [
                'empty' => $trimmed === '',
            ],
        ];
    }

    private function detectLanguageHint(string $message): ?string
    {
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $message)) {
            return 'ar';
        }

        if (preg_match('/[a-zA-Z]/', $message)) {
            return 'en';
        }

        return null;
    }
}
