# Flutter AI Agent Document Flow (Phase 2.2)

Backend-owned conversational document upload for SYRTAK / DLMS.

Flutter must **not** invent `application_id` or `required_document_id` for this path.
Use tokens and buttons returned by the Backend.

---

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/api/ai-agent/message` | Ask for required documents / text yes-no during offer |
| `POST` | `/api/ai-agent/sessions/{session}/interactions` | Deterministic button actions |
| `POST` | `/api/ai-agent/sessions/{session}/documents` | Upload exactly one file with `upload_token` |

Auth: Sanctum Bearer token (citizen).

---

## Happy path

1. Citizen: `شو الوثائق المطلوبة؟` → `POST /message`
2. Backend returns `document_upload_offer` (or `application_selection_required`)
3. Citizen taps **نعم، رفعها وإرسالها عبر المساعد** → `POST .../interactions` `{ "action": "choose_agent_document_upload" }`
4. Backend returns `required_document_selection` with document buttons + `selection_token`
5. Citizen selects one document → `{ "action": "select_required_document", "selection_token": "..." }`
6. Backend returns `file_upload_required` + `upload_token` + `maximum_files: 1`
7. Citizen picks **one** file → multipart `upload_token` + `file`
8. Repeat 4–7 until `documents_submitted_for_review`

Consent for agent upload **includes** automatic send to قسم مراجعة الوثائق when all required documents are complete.

---

## Message types

| `message_type` | When |
|----------------|------|
| `application_selection_required` | Multiple eligible applications |
| `document_upload_offer` | Documents listed + agent/manual choice |
| `manual_document_upload_guidance` | Manual path + navigation target |
| `required_document_selection` | Choose next document buttons |
| `file_upload_required` | Upload token issued for one document |
| `multiple_files_rejected` | More than one file in upload request |
| `document_uploaded` | One document uploaded; remaining may remain |
| `documents_submitted_for_review` | Auto-submit succeeded |
| `documents_uploaded_submission_failed` | All uploaded but submit failed |
| `document_flow_error` | No eligible application / cancelled |

Every flow response includes:

```json
{
  "session_id": 12,
  "message_type": "...",
  "reply": "...",
  "ui_payload": {}
}
```

Render `reply` as text. Render buttons from `ui_payload` only — never parse document names from `reply`.

---

## Interactions

```http
POST /api/ai-agent/sessions/{session}/interactions
Content-Type: application/json

{
  "action": "choose_agent_document_upload"
}
```

Supported actions:

- `choose_agent_document_upload`
- `choose_manual_document_upload`
- `select_application` (+ `selection_token`)
- `select_required_document` (+ `selection_token`)
- `cancel_document_upload`
- `show_required_documents`

Button interactions are **fully deterministic**. Do not send button presses to Gemini.

---

## Upload (token mode — official)

```http
POST /api/ai-agent/sessions/{session}/documents
Content-Type: multipart/form-data

upload_token: {{token}}
file: <exactly one file>
```

Do **not** send `application_id` or `required_document_id`.

### Exactly one file

Backend flattens `request->allFiles()` recursively. If count ≠ 1:

- `0` → `DOCUMENT_FILE_REQUIRED`
- `>1` → `EXACTLY_ONE_DOCUMENT_FILE_REQUIRED` (token remains valid; no DB write)

Flutter should also enforce a single-file picker, but Backend remains authoritative.

### Legacy mode

Still accepted for older clients:

- `application_id`
- `required_document_id`
- `file`

If `upload_token` is sent with IDs, IDs must match the token binding or the request is rejected.

---

## Manual upload

```json
{
  "message_type": "manual_document_upload_guidance",
  "ui_payload": {
    "navigation_target": {
      "screen": "application_documents",
      "params": { "application_id": 25 }
    },
    "button_label": "الانتقال إلى صفحة الوثائق"
  }
}
```

No upload token. No auto-submit. Application status unchanged.

---

## Flutter responsibilities

**Do:**

- Keep `session_id`
- Show `reply`
- Draw buttons from `ui_payload`
- Send interactions
- Open single-file picker
- Send `upload_token` + one file
- Show upload progress / errors
- Navigate using `navigation_target` when present

**Do not:**

- Choose application/required-document IDs for this flow
- Decide completeness or submit
- Send binaries to Gemini
- Claim content verification (OCR)
- Assign reviewers

---

## Important security notes

- Selection tokens are signed and bound to user + session + purpose + expiry.
- Upload tokens are hashed in session context (plain token never stored).
- Upload tokens are short-lived and one-time.
- Database remains source of truth for eligibility and document status.
- Agent never claims it inspected the file contents — wording must stay advisory.
