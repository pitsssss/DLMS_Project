# Flutter AI Agent — Phase 2.6 Contract

Minimal Flutter-facing surface for bilingual citizen services (applications, payments, tests, licenses, fines, documents, appointments).

## Endpoints Flutter should use

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/api/ai-agent/message` | Free-text + language detection + workflow |
| `POST` | `/api/ai-agent/sessions/{session}/interactions` | All structured button/control actions |
| `POST` | `/api/ai-agent/sessions/{session}/documents` | Document upload (never via Gemini) |

Optional (session history / resume):

| Method | Path |
|--------|------|
| `GET` | `/api/ai-agent/sessions` |
| `GET` | `/api/ai-agent/sessions/{session}` |

Deprecated thin wrappers (still work; prefer interactions):

| Method | Path |
|--------|------|
| `POST` | `/api/ai-agent/actions/{action}/confirm` |
| `POST` | `/api/ai-agent/actions/{action}/cancel` |

**Route count:** 7 total. **Flutter-required:** 3 (+2 optional session GETs).

Do not add or depend on service-specific AI endpoints.

---

## Session lifecycle

1. First `POST /message` without `session_id` creates a session.
2. Persist `session_id` from the response.
3. Send subsequent turns with `session_id`.
4. Language is resolved on **every** free-text message (see bilingual behavior).
5. Structured interactions use the same `session_id` and inherit the stored session locale.

---

## Interaction actions

```json
{ "action": "select_application", "selection_token": "..." }
{ "action": "select_license", "selection_token": "..." }
{ "action": "select_appointment_slot", "selection_token": "..." }
{ "action": "select_appointment", "selection_token": "..." }
{ "action": "select_required_document", "selection_token": "..." }
{ "action": "confirm_pending_action", "action_id": 123 }
{ "action": "cancel_pending_action", "action_id": 123 }
{ "action": "cancel_pending_workflow" }
{ "action": "show_application_choices_again" }
```

Also used by document flow (Phase 2.2): agent/manual upload choices, document selection cancel, etc.

Never send trusted raw entity IDs when a `selection_token` exists.

---

## Unified response contract

```json
{
  "session_id": 1,
  "language": "ar",
  "locale": "ar",
  "text_direction": "rtl",
  "message_type": "...",
  "reply": "...",
  "intent": "...",
  "missing_slots": [],
  "requires_confirmation": false,
  "pending_action": null,
  "ui_payload": {}
}
```

Flutter must use:

- `language` (`ar` | `en`) for UI direction / copy
- `message_type`
- `ui_payload`
- `requires_confirmation` / `pending_action`

Flutter must **not** parse `reply` to decide workflow state.

`locale` / `text_direction` remain for compatibility; prefer `language`.

### Important `message_type` values

| message_type | ui_payload |
|--------------|------------|
| `application_selection_required` | `applications[]` |
| `license_selection_required` | `licenses[]` |
| `license_service_confirmation_required` | license + confirm |
| `appointment_slot_selection_required` | `slots[]` |
| `appointment_selection_required` | `appointments[]` |
| `appointment_confirmation_required` | confirm book/reschedule/cancel |
| `document_*` | Phase 2.2 document flow |
| `no_eligible_license` / `no_eligible_appointment` | empty structured state |
| stale/expired selection types | retry / re-request service |

---

## Bilingual behavior

Language lifecycle for every free-text message:

```text
message → normalize → detect language → resolve session locale → process → reply in locale
```

Rules:

1. Arabic message → Arabic response (`language: "ar"`).
2. English message → English response (`language: "en"`).
3. Detected on the **first** message, not after intent.
4. Re-evaluated on each meaningful user message (mid-session switch supported).
5. Explicit switches (`speak english` / `تكلم عربي`) update session locale immediately.
6. Short replies (`yes` / `نعم` / `first` / `الأول`) inherit the active session/workflow language when ambiguous.
7. Mixed messages with technical terms (`payment`, `PDF`, `ID`) do not incorrectly flip locale.
8. `message_type`, intent names, and error codes stay language-neutral.

Language switching must **not** clear `pending_workflow`, invalidate selection tokens, or change selected entities — only the response locale changes.

Interactions (`/interactions`, upload, confirm/cancel) restore the session locale so replies stay in the citizen's current language.

---

## Confirmation / cancel / stale

- Mutations require confirmation (`requires_confirmation` + `pending_action`).
- Prefer `confirm_pending_action` / `cancel_pending_action` via `/interactions`.
- Expired or stale workflows return structured same-language retry messages — ask the citizen to request the service again; do not invent tokens.

---

## Payment redirect

When payment is prepared, the result may include `checkout_url`. Open that URL in the app webview/browser; do not invent payment status from `reply`.

---

## Service notes

- **Renew / lost / damaged:** may require `select_license` then confirm `create_application`
- **Payment:** fee / status are read; start payment requires confirmation
- **Fine payment:** not supported via agent — list only (`NOT_APPLICABLE`)
- **Retest:** phrases map to available tests / appointment booking rules
- **Documents / appointments:** reuse Phase 2.2 / 2.4 contracts

See also: `FLUTTER_AI_AGENT_DOCUMENT_FLOW.md`, `FLUTTER_AI_AGENT_APPLICATION_SELECTION.md`, `FLUTTER_AI_AGENT_APPOINTMENT_FLOW.md`.
