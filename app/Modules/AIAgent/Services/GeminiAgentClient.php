<?php

namespace App\Modules\AIAgent\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiAgentClient
{
    /**
     * @param  list<array{role: string, parts: array<int, array{text: string}>}>  $contents
     * @return array<string, mixed>|null Parsed model JSON or null on failure.
     */
    public function generateStructuredResponse(string $systemInstruction, array $contents): ?array
    {
        $apiKey = config('ai.gemini.api_key');
        $model = config('ai.gemini.model');
        $baseUrl = rtrim((string) config('ai.gemini.base_url'), '/');

        if (empty($apiKey)) {
            throw new RuntimeException('Gemini API key is not configured.');
        }

        $url = "{$baseUrl}/models/{$model}:generateContent";

        $response = Http::timeout((int) config('ai.gemini.timeout_seconds', 30))
            ->acceptJson()
            ->withQueryParameters(['key' => $apiKey])
            ->post($url, [
                'systemInstruction' => [
                    'parts' => [
                        ['text' => $systemInstruction],
                    ],
                ],
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => (float) config('ai.gemini.temperature', 0.2),
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('Gemini AI agent request failed.', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return null;
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        $decoded = json_decode(trim($text), true);

        return is_array($decoded) ? $decoded : null;
    }
}
