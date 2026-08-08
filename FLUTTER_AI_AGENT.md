# Flutter AI Agent — Frozen Integration Contract (Phase 2.6.1)

Single authoritative guide for integrating the SYRTAK/DLMS citizen AI Agent.

## Operational endpoints (Flutter must use these)

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/api/ai-agent/message` | Free-text turns, language detection, workflow |
| `POST` | `/api/ai-agent/sessions/{session}/interactions` | All button/control actions |
| `POST` | `/api/ai-agent/sessions/{session}/documents` | Document file upload (never via Gemini) |

**Flutter-required route count: 3**

### Optional session lifecycle

| Method | Path |
|--------|------|
| `GET` | `/api/ai-agent/sessions` |
| `GET` | `/api/ai-agent/sessions/{session}` |

### Deprecated compatibility wrappers (do not use in new Flutter code)

| Method | Path | Prefer |
|--------|------|--------|
| `POST` | `/api/ai-agent/actions/{action}/confirm` | `confirm_pending_action` via `/interactions` |
| `POST` | `/api/ai-agent/actions/{action}/cancel` | `cancel_pending_action` via `/interactions` |

**Total AI Agent routes: 7.** No service-specific AI endpoints. Do not add any.

---

## Session lifecycle

1. First `POST /message` without `session_id` creates a session.
2. Persist `data.session_id`.
3. Pass `session_id` on every subsequent message.
4. Language is resolved on every free-text message and stored on the session.
5. `/interactions` and `/documents` restore the session locale automatically.

---

## Unified response envelope

```json
{
  "session_id": 1,
  "message_type": "application_selection_required",
  "reply": "...",
  "intent": "get_application_status",
  "language": "ar",
  "locale": "ar",
  "text_direction": "rtl",
  "missing_slots": [],
  "requires_confirmation": false,
  "pending_action": null,
  "ui_payload": {}
}
```

| Field | Rule |
|-------|------|
| `language` | `ar` \| `en` — drive UI copy/direction |
| `locale` | Same as `language` (compat) |
| `text_direction` | `rtl` for AR, `ltr` for EN |
| `message_type` | Language-neutral workflow state |
| `intent` | Language-neutral |
| `reply` | Localized display text only |
| `ui_payload` | Structured UI; labels already localized |
| `requires_confirmation` / `pending_action` | Mutation gate |

**Never parse `reply` to decide workflow state.** Use `message_type`, `ui_payload`, `requires_confirmation`, `language`.

---

## Interaction actions (implemented)

```json
{ "action": "select_application", "selection_token": "..." }
{ "action": "select_required_document", "selection_token": "..." }
{ "action": "select_license", "selection_token": "..." }
{ "action": "select_appointment", "selection_token": "..." }
{ "action": "select_appointment_slot", "selection_token": "..." }
{ "action": "confirm_pending_action", "action_id": 123 }
{ "action": "cancel_pending_action", "action_id": 123 }
{ "action": "cancel_pending_workflow" }
{ "action": "show_application_choices_again" }
{ "action": "show_required_documents" }
```

Document-flow also uses agent/manual upload confirmation actions defined in Phase 2.2 (`confirm_agent_upload`, `choose_manual_upload`, etc. — follow `ui_payload.buttons[].action`).

**Not implemented (do not invent):** `show_appointment_choices_again`, `show_slot_choices_again`. Re-request the service or use `show_application_choices_again` / cancel + restart when needed.

**Selection-token rule:** Never send trusted raw entity IDs when a `selection_token` is present.

---

## `message_type` inventory

| message_type | Typical ui_payload |
|--------------|--------------------|
| `application_selection_required` | `applications[]` with tokens |
| `application_selection_expired` | retry guidance |
| `application_selection_cancelled` | cancelled |
| `application_selected_confirmation_required` | confirm mutating app action |
| `application_status` / `application_next_step` | status data |
| `no_eligible_application` | empty |
| `license_selection_required` | `licenses[]` |
| `license_service_confirmation_required` | license + confirm |
| `no_eligible_license` | empty |
| `appointment_slot_selection_required` | `slots[]` |
| `appointment_selection_required` | `appointments[]` |
| `appointment_confirmation_required` | book/reschedule/cancel confirm |
| `no_eligible_appointment` | empty |
| `document_upload_offer` | docs + agent/manual buttons |
| `required_document_selection` | document buttons |
| `file_upload_required` | upload_token + constraints |
| `document_uploaded` | progress |
| `documents_submitted_for_review` | success |
| `documents_uploaded_submission_failed` | retry |
| `manual_document_upload_guidance` | deep-link hint |
| `multiple_files_rejected` | retry same token |
| `document_flow_error` | safe error |

Intent-executed replies may omit `message_type` when not in a structured selection flow; still rely on `intent` + `language`.

---

## Bilingual behavior

```text
free text → normalize → detect language → resolve session locale → process → localized reply/labels
```

1. Arabic message → `language: "ar"`, Arabic reply + Arabic catalog labels.
2. English message → `language: "en"`, English reply + English catalog labels.
3. Mid-session AR↔EN switch updates locale only — does **not** clear `pending_workflow`, tokens, or selections.
4. Short replies (`yes`/`نعم`/`first`/`الأول`) inherit session/workflow locale when ambiguous.
5. Technical English terms inside Arabic (`payment`, `PDF`, `ID`) do not flip locale.
6. Catalog codes (`national_id_copy`, `vision`, `private`, `new_license`, …) are localized via backend maps — Flutter must display `label` / `*_label` fields as returned.

---

## Confirmation / cancel / stale

- Mutations require `requires_confirmation` + `pending_action`.
- Prefer `/interactions` confirm/cancel actions.
- Expired/stale workflows return structured same-language retry messages — request the service again; do not invent tokens.

---

## Document upload

1. Citizen reaches `file_upload_required` with `upload_token` in `ui_payload`.
2. `POST /sessions/{id}/documents` with `upload_token` + exactly one file.
3. Never send document bytes to Gemini.

---

## Payment

When payment is prepared, open `result.checkout_url` in a webview/browser. Do not invent payment state from `reply`. Fine payment via agent is **not supported**.

---

## Concise examples

**Start EN session**

```http
POST /api/ai-agent/message
{ "message": "I want a new driving license" }
```

**Confirm via interactions**

```http
POST /api/ai-agent/sessions/12/interactions
{ "action": "confirm_pending_action", "action_id": 55 }
```

**Select application**

```http
POST /api/ai-agent/sessions/12/interactions
{ "action": "select_application", "selection_token": "..." }
```

---

## Related deep-dives

- `FLUTTER_AI_AGENT_DOCUMENT_FLOW.md`
- `FLUTTER_AI_AGENT_APPLICATION_SELECTION.md`
- `FLUTTER_AI_AGENT_APPOINTMENT_FLOW.md`

This file is the frozen contract for Flutter handoff. Prefer it when guides disagree.
