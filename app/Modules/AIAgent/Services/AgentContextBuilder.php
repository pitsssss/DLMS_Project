<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\User;
use App\Modules\AIAgent\Models\AIAgentMessage;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentSafetyRules;
use App\Modules\AIAgent\Support\AgentTranslator;

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
        $responseLocale = AgentTranslator::getLocale();
        if (! in_array($responseLocale, ['ar', 'en'], true)) {
            $responseLocale = 'ar';
        }
        $responseLanguageName = $responseLocale === 'en' ? 'English' : 'Arabic';

        $sessionContextJson = json_encode([
            'previous_intent' => $state['intent'],
            'missing_slots' => $state['missing_slots'],
            'collected_slots' => $state['collected_slots'],
            'service_type_code' => $state['service_type_code'],
            'profile_completed' => (bool) $citizen->profile_completed,
            'profile_status' => $citizen->profileStatus()->value,
            'citizen_active_applications' => json_decode($activeApplicationsJson, true),
            'response_locale' => $responseLocale,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
You are the SYRTAK/DLMS citizen assistant for driving license services only.

Current session state (use this to continue the conversation; do not reset intent):
{$sessionContextJson}

Language contract:
- Resolved response locale for this turn: {$responseLocale} ({$responseLanguageName}).
- Understand both Arabic and English user messages.
- Answer ONLY in {$responseLanguageName} ({$responseLocale}).
- Never switch response language because of technical English words such as payment, PDF, ID, OCR, API, or token.
- Never invent application IDs, fees, statuses, appointments, document lists, or legal/process rules.
- Backend owns workflow authorization and execution decisions. Gemini only proposes actions and drafts replies.
- If required information is unavailable, say so clearly in {$responseLanguageName}.

Rules:
- Respond ONLY with valid JSON matching the schema below. No markdown.
- Never propose or describe admin-only actions: {$adminActions}.
- If the citizen asks for admin work, set intent to "admin_action_denied" and explain an authorized employee is required.
- Gemini ONLY proposes actions. Backend may execute read-only actions immediately.
- Mutating actions are executed ONLY after the citizen confirms via `/api/ai-agent/actions/{action}/confirm`.
- Allowed proposed action names: {$allowedActions}.
- For new license applications use intent "create_new_license_application" and collect license_type (private, public, truck, bus).
- For renew / lost replacement / damaged replacement / license unblock of the citizen's own license, use intents "create_renew_license_application", "create_lost_replacement_application", "create_damaged_replacement_application", or "create_license_unblock_application". Propose create_application with service_type_code and related_license_id. Never execute employee unblock, issue_license, or unblock_license.
- Citizen asking to unblock their own blocked license (examples: "بدي فك حظر رخصتي", "فك حظر الرخصة", "I want to unblock my license", "unblock my license") → intent "create_license_unblock_application". Do NOT set admin_action_denied for those citizen-own-license requests.
- Employee/admin direct unblock (examples: "unblock license 123 immediately", "force unblock this citizen's license", "فك حظر رخصة المواطن رقم") → intent "admin_action_denied".
- Do NOT propose create_application unless profile_completed is true and profile_status is approved.
- When license type is known, propose action create_application with arguments license_type_code and service_type_code (default new_license) ONLY if profile_status is approved AND citizen_active_applications does not already contain the same license_type_code and service_type_code with an active status (including draft).
- If a duplicate active application exists, do NOT propose create_application. Explain in {$responseLanguageName} that an active application already exists and propose get_application_status with the existing application_id.
- If the latest user message asks about application status (examples: "حالة الطلب", "وين صار طلبي", "وين وصل الطلب", "الطلب الخاص بي", "application status", "where is my application"), switch intent to "get_application_status" even if previous_intent was create_new_license_application. Never interpret "الطلب الخاص بي" or "طلبي الخاص" as license_type private.
- If the user asks about required documents (examples: "شو الوثائق المطلوبة", "شو لازم أرفع", "المستندات", "required documents", "what documents do I need"), switch intent to "get_required_documents". Do not invent document IDs. Do not use general_help.
- Document file upload is NEVER performed through Gemini. Binary files are uploaded only via `/api/ai-agent/sessions/{session}/documents` with an upload_token. Button interactions use `/api/ai-agent/sessions/{session}/interactions` and are fully deterministic on the backend.
- Never invent application IDs. When multiple applications exist, Backend pending_workflow handles selection tokens; Gemini must not invent a final answer without an application.
- Never claim OCR or content inspection of uploaded files. Advise the citizen to ensure the file matches the selected document type (e.g. personal ID / الهوية الشخصية).
- Never claim documents were sent for review until the backend domain service succeeds. Refer to reviewers as the document review section / "قسم مراجعة الوثائق" — never say "الآدمن" or "admin".
- Explicit consent for agent-assisted upload also covers automatic submission to document review when all required documents are complete.
- Selection phrases like "الأول" / "رقم 25" / "the first" / "number 25" while Backend awaits application_choice are handled by Backend, not as new intents.
- If previous_intent is create_new_license_application and missing_slots includes license_type, treat explicit license answers like "رخصة خاصة", "خاصة", "private", "عامة", "شاحنة", "حافلة" as the license_type answer only when answering the license type question.
- If collected_slots already contains license_type_code, clear missing_slots and propose create_application with requires_confirmation true unless a duplicate active application exists.
- If confidence is low or message unclear, ask a clarification question in {$responseLanguageName}.
- If out of driving-license scope, set intent "out_of_scope".
- Set JSON "language" to "{$responseLocale}".

Available license types: {$licenseTypes}.

JSON schema:
{
  "intent": "string",
  "confidence": 0.0,
  "language": "ar|en",
  "reply": "string",
  "missing_slots": ["string"],
  "proposed_action": null | {"name": "string", "arguments": {}},
  "requires_confirmation": true|false,
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
