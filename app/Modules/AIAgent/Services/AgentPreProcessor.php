<?php

namespace App\Modules\AIAgent\Services;

class AgentPreProcessor
{
    public function __construct(
        private readonly AgentLanguageDetector $languageDetector,
    ) {}

    /**
     * @return array{
     *   message: string,
     *   language_detection: array{
     *     locale: string,
     *     confidence: float,
     *     source: string,
     *     is_explicit: bool
     *   },
     *   flags: array<string, bool>
     * }
     */
    public function process(string $message, ?string $sessionLocale = null): array
    {
        $trimmed = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);

        $detection = $this->languageDetector->detect($trimmed, $sessionLocale);

        return [
            'message' => $trimmed,
            'language_detection' => $detection,
            'flags' => [
                'empty' => $trimmed === '',
            ],
        ];
    }
}
