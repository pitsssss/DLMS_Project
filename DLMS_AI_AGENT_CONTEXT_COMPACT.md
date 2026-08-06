# DLMS / SYRTAK — AI Agent Context (Compact)

> **Goal:** give a new IDE enough context to review/modify only the AI Agent.  
> **Source of truth:** current Laravel code + `php artisan route:list --path=ai-agent`; older README/audit notes may be stale.

## 1) What it is

Citizen-facing transactional AI Agent inside Laravel; Flutter is the client.

- Model: **Gemini 2.5 Flash** via `GeminiAgentClient` (REST, structured JSON).
- Architecture: **Hybrid** = LLM understanding + deterministic Arabic/English rules + slots/state + guardrails + domain actions.
- **Not RAG:** no embeddings, vector DB, chunking, or document retrieval.
- Dual citizen path remains: manual REST flow or AI Agent.
- Agent acts for the citizen, but **every state-changing action requires explicit confirm**.
- Read-only actions execute immediately.
- Agent never performs employee/admin actions.

```text
Flutter → AI Agent API → AIAgentService
→ preprocess/context → Gemini or deterministic fallback
→ intent/slots/workflow → safety/policy
→ read action OR pending action
→ confirm → AgentActionExecutor → existing Domain Service → DB/audit/notification
```

## 2) Main code map

```text
app/Modules/AIAgent/
├─ Controllers/AIAgentController.php
├─ Routes/ai-agent.php
├─ Models/                 # Session, Message, Action, Evaluation
├─ Enums/                  # intents/action statuses/...
├─ Resources/
├─ Requests/
├─ DTO/AgentWorkflowContext.php
├─ Services/
│  ├─ AIAgentService.php
│  ├─ GeminiAgentClient.php
│  ├─ AIAgentActionService.php
│  ├─ AgentActionExecutor.php
│  ├─ AgentActionReplyBuilder.php
│  ├─ AgentContextBuilder.php
│  ├─ AgentDocumentUploadService.php
│  └─ domain intent handlers
└─ Support/
   ├─ AgentSafetyRules.php
   ├─ AgentWorkflowOrchestrator.php
   ├─ AgentWorkflowPhraseMatcher.php
   ├─ AgentWorkflowIntentCatalog.php
   ├─ AgentSlotFiller.php
   └─ preprocess/postprocess/policies
```

Related:

```text
config/ai.php
routes/api.php
database/migrations/*ai_agent*
tests/Feature/*AIAgent*
DLMS_API_Postman_Collection.json
resources/lang/ar/messages.php
```

DB tables:

```text
ai_agent_sessions      # current_intent, context/slots, status
ai_agent_messages      # user/assistant history
ai_agent_actions       # proposed/confirmed/executed/failed/cancelled
ai_agent_evaluations   # intent, confidence, latency, model/fallback, safety
```

## 3) Confirmed APIs

All are `auth:sanctum + citizen`; ownership is enforced. Standard envelope:

```json
{"success":true,"message":"...","data":{}}
```

| Method | Route | Purpose |
|---|---|---|
| POST | `/api/ai-agent/message` | Send text; create/continue session. Body: `message` (1–4000), optional `session_id`. **Text only; never multipart.** |
| GET | `/api/ai-agent/sessions` | Citizen session list; paginated. |
| GET | `/api/ai-agent/sessions/{id}` | Owned session + conversation/actions. |
| POST | `/api/ai-agent/actions/{id}/confirm` | Revalidate stale state, then execute mutating pending action. |
| POST | `/api/ai-agent/actions/{id}/cancel` | Cancel pending action; no domain state/audit/notification side effects. |
| POST | `/api/ai-agent/sessions/{session}/documents` | Multipart document upload inside chat session. |

Upload body:

```text
application_id:int
required_document_id:int
file: jpg|jpeg|png|pdf, max 5120 KB
```

A later Phase 2.3 report mentions **7 AI-Agent endpoints**, while the exact prior reports expose the 6 above. Before changing the API surface, run:

```bash
php artisan route:list --path=ai-agent
```

Document the exact seventh route; do not invent or duplicate one.

## 4) Message/action flow

1. Load/create owned session; store user message.
2. Preprocess language/text.
3. Build prompt from recent history + session state + live DB context.
4. Gemini returns structured JSON; on failure use deterministic fallback.
5. Phrase/workflow rules may override wrong LLM intent.
6. Fill slots (`license_type`, `application_id`, `application_choice`, `appointment_slot_id`, etc.).
7. Apply safety/profile/duplicate/current-state policies.
8. Read-only action executes now through `AgentActionExecutor`.
9. Mutating action is persisted as pending and returned to Flutter.
10. Citizen confirms/cancels through action endpoint.
11. Confirm re-checks ownership and current domain state (**stale guard**) before execution.
12. Executor calls existing domain services; it must not write SQL or bypass business rules.

Multi-application flows:

- When several applications match, return `missing_slots: ["application_choice"]`.
- Preserve the original intent in session context.
- Return selectable applications in `ui_payload.applications`.
- Next citizen message selects one application and resumes the original workflow.

## 5) Supported functional scope

### Implemented read actions

```text
get_application_status
get_application_next_step
get_required_documents
get_application_fee
get_profile_status
get_fines
get_licenses
get_available_tests
get_appointment_slots
get_current_appointments
get_test_results
```

### Implemented mutating actions (confirmation required)

```text
create_application
  ├─ new license
  ├─ renewal
  ├─ lost replacement
  └─ damaged replacement
start_payment          # creates pending payment / checkout flow
book_appointment
submit_documents_for_review
```

### Conversation/control intents

```text
general_help
out_of_scope
admin_action_denied
clarify_application_selection
unknown/fallback
```

### Not confirmed complete / verify before claiming support

```text
reschedule_appointment
cancel_appointment
license_unblock
fine payment
human handoff workflow
admin monitoring/analytics APIs (Phase 9C)
```

## 6) Domain service rule

The Agent is an orchestration layer only:

```text
create application      → ApplicationService
document upload/submit  → ApplicationDocumentService
status/next step        → application services/policies
fees/payment            → ApplicationPaymentService
appointments            → AppointmentService
fines                    → FineService
licenses                 → LicenseService
test results             → TestResultService
```

Never duplicate validation/business transitions inside the Agent. Manual REST and Agent must reach the same services and state machine.

## 7) Document workflow

```text
POST session/documents
→ validate session/application/document ownership
→ ApplicationDocumentService::upload()
→ private local storage; UUID filename
→ DB status pending_review
→ update only session context:
   last_application_id
   last_required_document_id
→ return checklist
```

Important:

- File binary/base64/path/content **never goes to Gemini**.
- No OCR/parsing/LLM document analysis.
- Upload alone does **not** change application status.
- After all required docs are uploaded:  
  `message → submit_documents_for_review pending action → confirm`  
  then domain service changes application to `DocumentsUnderReview`, emits audit/notification, and exposes it in the shared reviewer queue.
- Cancel leaves application unchanged.
- Re-upload while `DocumentsRejected` is allowed.
- Upload while `DocumentsUnderReview` or `PaymentPending` is rejected (`422`).
- Ownership failures use safe `404`; invalid required-document relation uses `422`.
- Review queue is shared by employees with `review_documents`; no individual assignment.

## 8) Safety invariants

- Citizen-only routes; employee/admin rejected.
- Session/action/application ownership required.
- Admin actions blocked: approving documents, issuing licenses, recording results, creating fines, viewing audit logs, etc.
- Mutations require confirm; reads do not.
- Confirm must revalidate current application status/checklist to prevent stale execution.
- Repeated/invalid transitions must fail safely.
- No raw Gemini key, file content, private paths, tokens, or secrets in responses/logs.
- Gemini failure must not break core supported flows; deterministic fallback exists.
- Agent must not bypass profile approval, duplicate-application checks, sequence rules, payment rules, test order, or ownership.

## 9) Response/UI expectations

`/message` should provide enough structured data for Flutter:

```text
session
assistant reply
intent
missing_slots
pending_action (id/name/status/requires_confirmation)
ui_payload (e.g. applications, choices, checklist, slots, checkout_url)
fallback / human-support metadata when available
```

Use the exact current Resource/Postman contract; do not introduce guessed aliases. File upload remains a separate endpoint.

## 10) Current quality status

Latest known AI-Agent milestone:

```text
AIAgent suite: 161 passed / 1017 assertions
Full suite at that milestone: 673 passed / 3722 assertions
Routes: reported structurally unchanged; pending/multi-app workflow expanded
```

Earlier Phase 2 review: PASS WITH MINOR ISSUES; upload and submit/test-result flows were integrated and tested.

## 11) Known risks/backlog to verify in current code

```text
- real MIME/finfo validation vs client MIME
- malicious double-extension and zero-byte tests
- approved-document replacement guard
- document version/history lineage (old record currently may be hard-deleted)
- checklist semantics: pending_review also counted completed
- context naming: last_required_document_id vs old spec last_uploaded_document_id
- idempotency keys/action TTL/concurrent confirm hardening
- stable Flutter message_type/error-code contract
- session-message pagination
- Gemini retry/rate-limit/redaction hardening
- broader Syrian Arabic/Arabizi regression dataset
- remaining appointment mutations / license unblock
- admin monitoring APIs
```

Do not assume these are still open: inspect current code/tests first.

## 12) Editing rules for the new IDE

1. Audit first; code is source of truth.
2. Run route list and AI-Agent tests before proposing API consolidation.
3. Keep `/message` text-only and upload separate unless there is measured evidence to change.
4. Prefer one message endpoint for all text intents; actions are internal names, not separate public endpoints.
5. Do not add one public API per intent.
6. Keep confirm/cancel separate because they are explicit security transitions.
7. Never bypass/reimplement domain services.
8. Preserve manual citizen flow.
9. Any new mutating action needs: intent/slots → safety → pending action → stale validation → confirm/cancel → executor/domain service → tests/Postman.
10. No frontend/Flutter changes unless explicitly requested.
11. No secrets, destructive commands, commit, or push.

## 13) Useful audit commands

```bash
php artisan route:list --path=ai-agent
php artisan test --filter=AIAgent
grep -R "SUPPORTED_ACTION_NAMES\|ALLOWED_PROPOSED_ACTIONS" app/Modules/AIAgent
grep -R "api/ai-agent" routes app tests DLMS_API_Postman_Collection.json
```

## 14) Immediate discussion target

We want to review the AI Agent API surface and implementation, especially:

```text
- exact route count and whether any route is redundant
- whether all text workflows remain behind /message
- Flutter contract consistency
- pending multi-application workflow
- remaining actions/gaps
- security/document-upload backlog
```

Do **not** optimize only for fewer endpoints. Optimize for low Flutter complexity, clear contracts, security, and reuse of domain services.
