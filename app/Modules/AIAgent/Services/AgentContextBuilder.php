<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\User;
use App\Modules\AIAgent\Models\AIAgentMessage;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentSafetyRules;

class AgentContextBuilder
{
    public function __construct(
        private readonly AgentSessionContextService $sessionContext,
    ) {}

    public function buildSystemInstruction(AIAgentSession $session, User $citizen): string
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
        $activeApplicationsJson = $this->buildActiveApplicationsContext($citizen);

        $sessionContextJson = json_encode([
            'previous_intent' => $state['intent'],
            'missing_slots' => $state['missing_slots'],
            'collected_slots' => $state['collected_slots'],
            'service_type_code' => $state['service_type_code'],
            'profile_completed' => (bool) $citizen->profile_completed,
            'profile_status' => $citizen->profileStatus()->value,
            'citizen_active_applications' => json_decode($activeApplicationsJson, true),
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
- Do NOT propose create_application unless profile_completed is true and profile_status is approved.
- When license type is known, propose action create_application with arguments license_type_code and service_type_code (default new_license) ONLY if profile_status is approved AND citizen_active_applications does not already contain the same license_type_code and service_type_code with an active status (including draft).
- If a duplicate active application exists, do NOT propose create_application. Explain in Arabic that an active application already exists and propose get_application_status with the existing application_id.
- If the latest user message asks about application status (examples: "حالة الطلب", "وين صار طلبي", "وين وصل الطلب", "الطلب الخاص بي"), switch intent to "get_application_status" even if previous_intent was create_new_license_application. Never interpret "الطلب الخاص بي" or "طلبي الخاص" as license_type private.
- If the user asks about required documents (examples: "شو الوثائق المطلوبة", "شو لازم أرفع", "المستندات"), switch intent to "get_required_documents" and propose get_required_documents with application_id when known. Do not use general_help.
- If previous_intent is create_new_license_application and missing_slots includes license_type, treat explicit license answers like "رخصة خاصة", "خاصة", "private", "عامة", "شاحنة", "حافلة" as the license_type answer only when answering the license type question.
- If collected_slots already contains license_type_code, clear missing_slots and propose create_application with requires_confirmation true unless a duplicate active application exists.
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
  "safety_status": "safe",
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

    private function buildActiveApplicationsContext(User $citizen): string
    {
        $applications = LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->whereIn('status', ApplicationStatus::activeValues())
            ->with(['licenseType', 'serviceType'])
            ->orderByDesc('id')
            ->get()
            ->map(static fn (LicenseApplication $application) => [
                'application_id' => $application->id,
                'application_number' => $application->application_number,
                'status' => $application->status->value,
                'license_type_code' => $application->licenseType?->code,
                'service_type_code' => $application->serviceType?->code,
            ])
            ->values()
            ->all();

        return json_encode($applications, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '[]';
    }
}
