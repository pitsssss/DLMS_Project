<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\Models\AIAgentSession;
use Carbon\Carbon;

/**
 * Manages locale storage and retrieval in AI Agent sessions.
 */
class AgentSessionLocaleManager
{
    /**
     * Get the preferred locale from session context.
     */
    public function getSessionLocale(AIAgentSession $session): ?string
    {
        $context = $session->context ?? [];
        $locale = $context['locale'] ?? null;

        if (is_array($locale)) {
            return $locale['preferred'] ?? null;
        }

        // Legacy: direct string locale
        if (is_string($locale)) {
            return $locale;
        }

        return null;
    }

    /**
     * Store locale in session context.
     * 
     * @param array{
     *   locale: string,
     *   confidence: float,
     *   source: string,
     *   is_explicit: bool
     * } $detection
     */
    public function storeSessionLocale(
        AIAgentSession $session,
        array $detection
    ): void {
        $context = $session->context ?? [];

        $context['locale'] = [
            'preferred' => $detection['locale'],
            'last_detected' => $detection['locale'],
            'last_detection_confidence' => $detection['confidence'],
            'source' => $detection['source'],
            'is_explicit' => $detection['is_explicit'],
            'updated_at' => Carbon::now()->toIso8601String(),
        ];

        $session->context = $context;
        $session->save();
    }

    /**
     * Update session locale only if detection is confident or explicit.
     * 
     * @param array{
     *   locale: string,
     *   confidence: float,
     *   source: string,
     *   is_explicit: bool
     * } $detection
     */
    public function updateIfConfident(
        AIAgentSession $session,
        array $detection
    ): void {
        // Always update if explicit request
        if ($detection['is_explicit']) {
            $this->storeSessionLocale($session, $detection);
            return;
        }

        // Update if high confidence (>= 0.7)
        if ($detection['confidence'] >= 0.7) {
            $this->storeSessionLocale($session, $detection);
            return;
        }

        // Don't update on ambiguous or low-confidence detection
    }

    /**
     * Get locale for the current request, considering session memory.
     * 
     * @param array{
     *   locale: string,
     *   confidence: float,
     *   source: string,
     *   is_explicit: bool
     * } $detection
     */
    public function resolveLocaleForRequest(
        AIAgentSession $session,
        array $detection
    ): string {
        // Explicit request always wins
        if ($detection['is_explicit']) {
            return $detection['locale'];
        }

        // High confidence detection
        if ($detection['confidence'] >= 0.7) {
            return $detection['locale'];
        }

        // Ambiguous - use session locale or default
        $sessionLocale = $this->getSessionLocale($session);
        
        return $sessionLocale ?? AgentLocaleContext::getDefaultLocale();
    }
}
