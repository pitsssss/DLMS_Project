# Flutter AI Agent — Phase 2.5 Contract

Minimal Flutter-facing surface for citizen services (applications, payments, tests, licenses, fines, documents, appointments).

## Endpoints Flutter should use

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/api/ai-agent/message` | Text + language detection |
| `POST` | `/api/ai-agent/sessions/{session}/interactions` | All structured actions |
| `POST` | `/api/ai-agent/sessions/{session}/documents` | Document upload |

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

Never send trusted raw IDs when a `selection_token` exists.

---

## Standard response

```json
{
  "session_id": 1,
  "message_type": "...",
  "reply": "...",
  "intent": "...",
  "missing_slots": [],
  "requires_confirmation": false,
  "pending_action": null,
  "ui_payload": {}
}
```

Render workflow UI from `message_type` + `ui_payload` only — never parse `reply`.

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

---

## Bilingual behavior

- Arabic input → Arabic `reply`
- English input → English `reply`
- `message_type` / error `code` stay language-neutral
- Session locale persists across turns when detection is confident

---

## Service notes

- **Renew / lost / damaged:** may require `select_license` then confirm `create_application`
- **Payment:** fee / status are read; start payment requires confirmation; returns `checkout_url` when available
- **Fine payment:** not supported in domain via agent — list only
- **Retest:** phrases map to available tests / appointment booking rules
- **Documents / appointments:** reuse Phase 2.2 / 2.4 contracts

See also: `FLUTTER_AI_AGENT_DOCUMENT_FLOW.md`, `FLUTTER_AI_AGENT_APPLICATION_SELECTION.md`, `FLUTTER_AI_AGENT_APPOINTMENT_FLOW.md`.
