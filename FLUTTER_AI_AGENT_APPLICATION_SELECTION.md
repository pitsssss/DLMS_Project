# Flutter AI Agent — Application Selection Continuation (Phase 2.3)

Generic Backend-owned flow when a citizen asks an **application-dependent** question and has **more than one eligible application**.

Flutter must **not**:

- send raw `application_id` for this path
- re-send the original question after a button tap
- parse application lists from `reply`
- invent intents or execute mutating actions on selection alone

Use `selection_token` from `ui_payload.applications` and the interactions endpoint.

---

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/api/ai-agent/message` | Free text (status, next step, etc.) + text selection while pending |
| `POST` | `/api/ai-agent/sessions/{session}/interactions` | Button selection / cancel / show choices again |

Auth: Sanctum Bearer token (citizen).

---

## Happy path

1. Citizen: `شو حالة طلبي؟` → `POST /message`
2. Backend returns `message_type = application_selection_required` + `ui_payload.applications[]` (each with `selection_token`)
3. Citizen taps one application → `POST .../interactions`

```json
{
  "action": "select_application",
  "selection_token": "..."
}
```

4. Backend validates token, fills the application slot, **resumes the original intent**, returns the real answer (e.g. `application_status`)
5. Flutter shows that response directly — citizen does **not** ask again

Single eligible application: Backend auto-selects and returns the final result immediately (no buttons).

---

## When `message_type = application_selection_required`

Render buttons from:

```text
data.ui_payload.applications
```

Each item includes at least:

| Field | Use |
|-------|-----|
| `label` | Primary button / list title |
| `subtitle` | Secondary line |
| `status_label` | Human status |
| `selection_token` | Send on tap |
| `service_type_label` / `license_type_label` | Optional display |

Do **not** build buttons from `reply` text.

Example envelope:

```json
{
  "success": true,
  "data": {
    "session_id": 1,
    "message_type": "application_selection_required",
    "reply": "لديك أكثر من طلب قيد المتابعة. يرجى اختيار الطلب...",
    "intent": "get_application_status",
    "missing_slots": ["application_choice"],
    "ui_payload": {
      "selection_purpose": "get_application_status",
      "applications": [
        {
          "label": "طلب رخصة جديدة — رقم 25",
          "subtitle": "رخصة خاصة — مسودة",
          "selection_token": "signed-token"
        }
      ]
    }
  }
}
```

---

## Interactions

```http
POST /api/ai-agent/sessions/{session}/interactions
Content-Type: application/json
Authorization: Bearer {{citizen_token}}

{
  "action": "select_application",
  "selection_token": "{{application_selection_token}}"
}
```

Supported actions for this flow:

| `action` | Body | Result |
|----------|------|--------|
| `select_application` | `selection_token` | Resume original intent |
| `cancel_pending_workflow` | — | Clears pending; official cancel reply |
| `show_application_choices_again` | — | Re-issues buttons / tokens |

**Important:** Document-flow application selection (Phase 2.2) also uses `select_application` when `document_flow` owns the session. Tokens have different `purpose` values and are **not** interchangeable.

---

## Text selection (while awaiting)

If the citizen types instead of tapping (e.g. `الأول`, `رقم 25`, `طلب التجديد`), send via `POST /message` in the **same session**. Backend resolves deterministically **before** Gemini and never falls through to `general_help` for ambiguous replies — it re-shows the same buttons.

---

## After successful selection

| Intent type | Expected behavior |
|-------------|-------------------|
| Read-only (`get_application_status`, `get_application_next_step`, …) | Final answer + `executed_action` |
| Mutating (`start_payment`, `book_appointment`, `submit_documents_for_review`) | May return confirmation-required pending action — selection ≠ confirm |

Typical status result:

```json
{
  "message_type": "application_status",
  "reply": "حالة طلب الرخصة الجديدة رقم 25 هي «مسودة».",
  "intent": "get_application_status",
  "application": { "id": 25, "status": "draft", "status_label": "مسودة" }
}
```

Display `reply` and optional `application` / `ui_payload` as returned. Do not force another status question.

---

## Other message types

| `message_type` | Meaning |
|----------------|---------|
| `application_selection_required` | Show application buttons |
| `application_selection_expired` | TTL expired; ask citizen to request the service again |
| `application_selection_cancelled` | Citizen cancelled selection |
| `application_selected_confirmation_required` | App chosen; mutating action awaits confirm |
| `no_eligible_application` | No matching apps for the intent |
| `application_status` (and other intent results) | Final read-only answers |
| `appointment_slot_selection_required` | Application chosen for booking; slot still required — **no confirm yet** |

---

## Expiry

When `message_type = application_selection_expired`:

- Dismiss / disable old application buttons
- Do **not** reuse old `selection_token` values
- Show the expiry reply
- Ask the citizen to request the service again (new message)

Interaction with an expired workflow returns HTTP 422 with `code = PENDING_WORKFLOW_EXPIRED`.

---

## Show choices again

```json
{
  "action": "show_application_choices_again"
}
```

Re-issues buttons and fresh tokens while pending is still active. If expired → same as expiry handling above.

---

## Appointment slot continuation (`book_appointment`)

Typical path:

```text
application_selection_required
→ select_application
→ appointment_slot_selection_required
→ (slot selection — may be extended later)
→ confirmation only when appointment_slot_id is present
```

Flutter must **not** show a confirm button until `pending_action` exists with a complete confirmable action. Application selection alone never books.

---

## Topic change

If the citizen sends a clear new intent while selection is pending (e.g. `ما بدي أعرف المخالفات`), Backend cancels the old workflow and returns the **new** intent response directly. Discard old tokens.

Exact cancel phrases (e.g. `ما بدي`, `إلغاء`) return `application_selection_cancelled` only.

---

## Error codes (selection / pending)

| Code | Typical HTTP |
|------|----------------|
| `PENDING_WORKFLOW_NOT_FOUND` | 422 |
| `PENDING_WORKFLOW_EXPIRED` | 422 |
| `PENDING_WORKFLOW_STATE_INVALID` | 422 |
| `PENDING_WORKFLOW_RETRY_REQUIRED` | 422 |
| `PENDING_WORKFLOW_RESUME_FAILED` | (metadata) |
| `ACTION_ARGUMENTS_INCOMPLETE` | 422 |
| `APPLICATION_SELECTION_TOKEN_INVALID` | 422 |
| `APPLICATION_SELECTION_TOKEN_EXPIRED` | 422 |
| `APPLICATION_SELECTION_TOKEN_MISMATCH` | 422 |
| `APPLICATION_NO_LONGER_ELIGIBLE` | 422 |
| `APPLICATION_NOT_OWNED` | 422 / 404 |

Never log or display token payloads, signatures, or full session context to the user.

---

## Separation from Document Flow (Phase 2.2)

| Concern | Generic pending workflow | Document flow |
|---------|--------------------------|---------------|
| Trigger | Status / next step / fee / payment / … | `شو الوثائق المطلوبة؟` etc. |
| Context key | `pending_workflow` | `document_flow` |
| Token purpose | `pending_application_selection` | document application selection |
| After select | Resume original intent | Continue upload offer / docs |

Do not reuse a document-flow `selection_token` for generic selection (and vice versa).

---

## Flutter checklist

- [ ] On `application_selection_required`, render `ui_payload.applications`
- [ ] On tap, send only `action` + `selection_token`
- [ ] Keep `session_id` for follow-up messages / interactions
- [ ] Show resumed response without re-asking the original question
- [ ] Treat ambiguous text replies as re-selection UI, not as chat failure / general help
- [ ] On `application_selection_expired`, drop old tokens/buttons
- [ ] On `appointment_slot_selection_required`, do not show booking confirm yet
- [ ] Support `show_application_choices_again`
- [ ] For mutating intents after selection, wait for explicit confirmation UX already used elsewhere
