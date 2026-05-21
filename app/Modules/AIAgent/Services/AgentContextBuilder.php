<?php

namespace App\Modules\AIAgent\Services;

use App\Models\LicenseType;
use App\Modules\AIAgent\Models\AIAgentMessage;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentSafetyRules;

class AgentContextBuilder
{
    public function buildSystemInstruction(): string
    {
        $licenseTypes = LicenseType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn ($type) => $type->code.' ('.$type->name.')')
            ->implode(', ');

        $allowedActions = implode(', ', AgentSafetyRules::ALLOWED_PROPOSED_ACTIONS);
        $adminActions = implode(', ', AgentSafetyRules::ADMIN_ONLY_ACTIONS);

        return <<<PROMPT
You are the SYRTAK/DLMS citizen assistant for driving license services only.

Rules:
- Respond ONLY with valid JSON matching the schema below. No markdown.
- Never propose or describe admin-only actions: {$adminActions}.
- If the citizen asks for admin work, set intent to "admin_action_denied" and explain an authorized employee is required.
- Phase 9A: NEVER execute actions. Only propose actions for later confirmation.
- Allowed proposed action names: {$allowedActions}.
- For new license applications use intent "create_new_license_application" and collect license_type (private, public, truck, bus).
- When license type is known and citizen confirmed readiness, propose action create_application with arguments license_type_code and service_type_code (default new_license).
- If confidence is low or message unclear, ask a clarification question in the citizen's language.
- If out of driving-license scope, set intent "out_of_scope".
- Use Arabic for Arabic messages and English for English messages.

Available license types: {$licenseTypes}.

JSON schema:
{
  "intent": "string",
  "confidence": 0.0,
  "language": "ar|en",
  "reply": "string",
  "missing_slots": ["string"],
  "proposed_action": null | {"name": "string", "arguments": {}},
  "requires_confirmation": false,
  "safety_status": "safe|blocked",
  "requires_human_support": false
}
PROMPT;
    }

    /**
     * @return list<array{role: string, parts: array<int, array{text: string}>}>
     */
    public function buildGeminiContents(AIAgentSession $session): array
    {
        $limit = (int) config('ai.agent.max_history_messages', 10);

        $messages = AIAgentMessage::query()
            ->where('session_id', $session->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values();

        $contents = [];

        foreach ($messages as $message) {
            $role = $message->role->value === 'assistant' ? 'model' : 'user';
            if ($message->role->value === 'system') {
                continue;
            }

            $contents[] = [
                'role' => $role,
                'parts' => [
                    ['text' => $message->content],
                ],
            ];
        }

        $context = $session->context ?? [];
        if (! empty($context)) {
            $contents[] = [
                'role' => 'user',
                'parts' => [
                    ['text' => 'Session context: '.json_encode($context, JSON_UNESCAPED_UNICODE)],
                ],
            ];
        }

        return $contents;
    }
}
