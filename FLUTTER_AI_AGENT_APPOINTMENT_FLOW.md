# Flutter AI Agent — Appointment Multi-Slot Workflow (Phase 2.4)

Citizen appointment flows (`get` / `book` / `reschedule` / `cancel`) run through the **same** AI endpoints as Phase 2.2–2.3. No appointment-specific AI routes.

Flutter must **not**:

- send trusted raw `application_id` / `appointment_id` / `appointment_slot_id` when a `selection_token` is present
- parse slots or appointments from `reply`
- invent confirmation or execute mutations on selection alone

Render from `message_type` + `ui_payload`.

---

## Endpoints (unchanged)

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/api/ai-agent/message` | Free text + ordinal/date text selection while pending |
| `POST` | `/api/ai-agent/sessions/{session}/interactions` | Button selection / cancel / show choices again |
| `POST` | `/api/ai-agent/actions/{action}/confirm` | Confirm book / reschedule / cancel |
| `POST` | `/api/ai-agent/actions/{action}/cancel` | Decline pending confirmation |

Auth: Sanctum Bearer (citizen).

---

## Interaction actions

| `action` | When |
|----------|------|
| `select_application` | Multi-application step (same as Phase 2.3) |
| `select_appointment_slot` | Slot buttons (`ui_payload.slots`) |
| `select_appointment` | Appointment buttons (`ui_payload.appointments`) |
| `cancel_pending_workflow` | Abort current pending workflow |
| `show_application_choices_again` | Re-show current choice set (apps / slots / appointments) |

Always send:

```json
{
  "action": "select_appointment_slot",
  "selection_token": "..."
}
```

---

## Message types

| `message_type` | UI |
|----------------|----|
| `application_selection_required` | Application buttons |
| `appointment_slot_selection_required` | Slot buttons from `ui_payload.slots` |
| `appointment_selection_required` | Appointment buttons from `ui_payload.appointments` |
| `appointment_confirmation_required` | Confirm/Cancel using `pending_action.id` |
| `no_eligible_appointment` | Empty state — no dead end; offer get slots / help |
| `application_selection_expired` | Restart the original request |

`message_type` and error `code` values are language-neutral. Localize only `reply`.

---

## Book flow

1. Text: `احجزلي موعد` / `book appointment` → `/message`
2. If multiple apps → `application_selection_required`
3. Then `appointment_slot_selection_required` with real slots + tokens
4. Tap slot → `select_appointment_slot` **or** text (`الأول`, `١`, unique date/time)
5. `appointment_confirmation_required` → confirm once
6. Result contains structured appointment payload

Shortcut: `احجز أول موعد متاح` may propose the first slot with confirmation directly (single eligible app).

---

## Reschedule flow

1. `تغيير الموعد` / `reschedule appointment`
2. Select appointment if multiple → `select_appointment`
3. Replacement slots → `select_appointment_slot`
4. Confirm → domain reschedule (atomic; do not cancel separately)

---

## Cancel flow

1. `الغي الموعد` / `cancel appointment`
2. Select appointment if multiple
3. Confirm → cancel once  
Selection alone never cancels.

---

## Current appointments

`حجزتلي موعد؟` / current-appointment phrases return structured `result.appointments`.  
Multiple appointments do **not** force selection unless the citizen starts cancel/reschedule.

---

## Text selection rules (Backend-owned)

While awaiting slot/appointment choice, Backend resolves before Gemini:

- Ordinals: الأول / الثاني / الثالث / first / second
- Arabic/Persian digits
- Unique date/time match against offered options
- Ambiguous → re-show choices (never `general_help`)
- Clear new intent → clear workflow and process new intent
- Exact cancel phrase (`ما بدي`) → cancel workflow

Only offered options are accepted.

---

## Expiry / stale / security

| Case | Behavior |
|------|----------|
| Expired pending workflow | Chat: `application_selection_expired`; interaction: `PENDING_WORKFLOW_EXPIRED` |
| Stale/full slot | `APPOINTMENT_SLOT_NO_LONGER_AVAILABLE` — keep/retry with fresh slots |
| Wrong user/session/purpose/workflow token | `422` invalid/mismatch codes |
| Tampered token | `422` |
| Duplicate confirm | `422` — no double-book |
| Zero slots / appointments | Structured empty `ui_payload`, no incomplete `ai_agent_actions` |

---

## Arabic / English

- Arabic input → Arabic `reply`
- English input → English `reply`
- Do not hardcode language from Flutter; Backend detects locale

---

## Example slot payload

```json
{
  "message_type": "appointment_slot_selection_required",
  "intent": "book_appointment",
  "missing_slots": ["appointment_slot_choice"],
  "requires_confirmation": false,
  "ui_payload": {
    "selection_type": "appointment_slot",
    "slots": [
      {
        "label": "2026-08-10 09:00 — Center",
        "date": "2026-08-10",
        "time": "09:00:00",
        "selection_token": "..."
      }
    ]
  }
}
```
