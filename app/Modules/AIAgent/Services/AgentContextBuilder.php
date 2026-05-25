<?php

namespace App\Modules\AIAgent\Services;

use App\Models\LicenseType;
use App\Modules\AIAgent\Models\AIAgentMessage;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentSafetyRules;

class AgentContextBuilder
{
    public function __construct(
        private readonly AgentSessionContextService $sessionContext,
    ) {}

    public function buildSystemInstruction(AIAgentSession $session): string
    {
        $licenseTypes = LicenseType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn ($type) => $type->code.' ('.$type->name.')')
            ->implode(', ');

        $allowedActions = implode(', ', AgentSafetyRules::ALLOWED_PROPOSED_ACTIONS);
        $adminActions = implode(', ', AgentSafetyRules::ADMIN_ONLY_ACTIONS);

        $state = $this->sessionContext->resolveState($session);
        $sessionContextJson = json_encode([
            'previous_intent' => $state['intent'],
            'missing_slots' => $state['missing_slots'],
            'collected_slots' => $state['collected_slots'],
            'service_type_code' => $state['service_type_code'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
You are the SYRTAK/DLMS citizen assistant for driving license services only.

Current session state (use this to continue the conversation; do not reset intent):
{$sessionContextJson}

Rules:
- Respond ONLY with valid JSON matching the schema below. No markdown.
- Never propose or describe admin-only actions: {$adminActions}.
- If the citizen asks for admin work, set intent to "admin_action_denied" and explain an authorized employee is required.
- Phase 9A: NEVER execute actions. Only propose actions for later confirmation.
- Allowed proposed action names: {$allowedActions}.
- For new license applications use intent "create_new_license_application" and collect license_type (private, public, truck, bus).
- When license type is known, propose action create_application with arguments license_type_code and service_type_code (default new_license).
- If previous_intent is create_new_license_application and missing_slots includes license_type, treat short answers like "رخصة خاصة", "خاصة", "private", "عامة", "شاحنة", "حافلة" as the license_type answer. Keep the same intent; do not switch to general_help.
- If collected_slots already contains license_type_code, clear missing_slots and propose create_application with requires_confirmation true.
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

        return $contents;
    }
}
