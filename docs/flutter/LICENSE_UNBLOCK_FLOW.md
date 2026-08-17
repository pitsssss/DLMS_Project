# License Unblock — Flutter Integration Guide

## Purpose

License Unblock (`license_unblock`) lets a citizen request restoration of a **blocked** driving license through the standard **application workflow**. The backend does **not** issue a new license or license number. An employee executes the final action after documents, payment, and approval; the existing related license transitions `blocked` → `active` or `expired`.

## When Flutter shows the action

Use the backend eligibility flag from license APIs — **do not** infer eligibility from `status == blocked` alone.

```json
{
  "can_request_unblock": true
}
```

`can_request_unblock` is `false` when, for example:

- License is not blocked
- Citizen has unpaid fines (citizen-level `Fine.status = unpaid`)
- Another active unblock application already exists for that license
- License is not owned by the citizen

## Deprecated endpoint

**Do not use:**

```http
POST /api/licenses/{license}/unblock-request
```

This legacy endpoint only acknowledges intent. It does **not** create an application or unblock the license.

**Canonical entry:**

```http
POST /api/applications
```

## Complete ordered flow

| Step | Actor | Action |
|------|-------|--------|
| 1 | Citizen | List/show licenses; find blocked license with `can_request_unblock: true` |
| 2 | Citizen | Create `license_unblock` application with `related_license_id` |
| 3 | Citizen | GET required documents |
| 4 | Citizen | Upload each required document |
| 5 | Citizen | Submit documents for review → `documents_under_review` |
| 6 | Employee | Approve or reject documents (generic document review) |
| 7 | Citizen | GET application fee (`unblock_fee`) |
| 8 | Citizen | Create payment |
| 9 | Citizen | Confirm payment (mock provider) → application `approved` |
| 10 | Employee | POST unblock-license on the application |
| 11 | Both | Application `completed`; license `active` or `expired` |
| 12 | Citizen | Refresh application + license + notifications |

---

## Citizen APIs

### 1. Get licenses

```http
GET /api/licenses
Authorization: Bearer {citizen_token}
Accept-Language: ar|en
```

**When:** License list screen; before showing unblock CTA.

**Important fields:** `status`, `can_request_unblock`, `id`, `license_number`

**Errors:** 401 unauthenticated

**Next:** If `can_request_unblock`, open unblock flow.

---

### 2. Get license details

```http
GET /api/licenses/{license_id}
```

Same fields as list item.

---

### 3. Create unblock application

```http
POST /api/applications
Authorization: Bearer {citizen_token}
Content-Type: application/json

{
  "service_type_code": "license_unblock",
  "related_license_id": 123
}
```

**When:** User confirms unblock request.

**Response:** Application in `draft` with `service_type.code = license_unblock`, `related_license`.

**Errors:**

| HTTP | Condition |
|------|-----------|
| 403 | License owned by another citizen |
| 404 | License not found |
| 422 | License not blocked, unpaid fines, duplicate active application, missing `related_license_id` |

**Next:** Required documents.

---

### 4. Get required documents

```http
GET /api/applications/{application_id}/required-documents
```

**Required codes (seeded):** `national_id_copy`, `fine_clearance`

**Next:** Upload each document.

---

### 5. Upload document

```http
POST /api/applications/{application_id}/documents
Content-Type: multipart/form-data

required_document_id={id}
file={pdf|jpg|...}
```

**Next:** Repeat until checklist complete, then submit.

---

### 6. Submit documents

```http
POST /api/applications/{application_id}/submit-documents
```

**Result:** `documents_under_review`

**Next:** Wait for employee review.

---

### 7. Get application fee

```http
GET /api/applications/{application_id}/fee
```

**Fee code:** `unblock_fee`  
**Default amount:** `30.00 USD` (from project fee catalog/seeder)

**When:** After documents approved → status `payment_pending`

---

### 8. Create payment

```http
POST /api/applications/{application_id}/payments
```

**When:** Status is `payment_pending`.

---

### 9. Confirm payment

```http
POST /api/applications/{application_id}/payments/{payment_id}/confirm
```

**When:** Mock payment provider in dev/staging.

**Result:** Application → `approved` (no tests/appointments for unblock)

---

### 10. Get application (track status)

```http
GET /api/applications/{application_id}
```

**Terminal success status:** `completed`  
**Terminal failure statuses:** `rejected`, `cancelled`

---

### 11. Get notifications

```http
GET /api/notifications
```

**After successful unblock:** expect `license.unblocked` and `application.completed`  
**Not on application creation:** no `license.unblocked` at step 3

---

## Employee APIs

Document review uses existing admin/dashboard document review endpoints.

### List approved unblock applications (queue)

```http
GET /api/dashboard/applications?service_type_code=license_unblock&status=approved
Authorization: Bearer {employee_token}
Permission: view_applications (list) / manage_licenses (final action)
```

---

### Final unblock action

```http
POST /api/dashboard/applications/{application_id}/unblock-license
Authorization: Bearer {employee_token}
Permission: manage_licenses
```

**When:** Application `approved`, service `license_unblock`, related license still `blocked`, no unpaid citizen fines.

**Success:** `{ application.status: completed, license.status: active|expired }`

**Errors:** 403 unauthorized employee, 422 wrong status/service/stale license/unpaid fines/already completed

---

### Reject approved unblock application

```http
POST /api/dashboard/applications/{application_id}/reject
Content-Type: application/json

{ "reason": "..." }
```

**Permission:** `manage_licenses`  
**Result:** `rejected`; license stays `blocked`; citizen notified.

---

## Status handling

### License statuses

| Status | Meaning for unblock |
|--------|---------------------|
| `blocked` | Eligible target for new application |
| `active` / `expired` | After successful employee action |
| Other | Not eligible for unblock application |

### Application statuses (unblock flow)

| Status | Flutter action |
|--------|------------------|
| `draft` | Upload/submit documents |
| `documents_under_review` | Wait |
| `documents_rejected` | Re-upload and resubmit |
| `payment_pending` | Pay `unblock_fee` |
| `approved` | Wait for employee final action |
| `completed` | Success — refresh license |
| `rejected` | Show reason; license still blocked |
| `cancelled` | Terminal — may start new request if eligible |

---

## Important Flutter rules

1. Never call `POST /api/licenses/{id}/unblock-request` in new builds.
2. Never call `issue-license` for unblock — issuance is forbidden for `license_unblock`.
3. Use `can_request_unblock` from the API, not raw license status.
4. Refresh application after each citizen action.
5. Refresh licenses after `completed` or `license.unblocked` notification.
6. Final success = application `completed` **and** license `active` or `expired` (not a new license row).
7. One active unblock application per related license at a time.
8. Unpaid fines block both application creation and final employee unblock.

---

## Direct staff bypass (not Flutter)

Employees may still use direct license unblock (outside application flow):

- `POST /api/dashboard/licenses/{license_id}/unblock`
- `POST /api/admin/licenses/{license_id}/unblock`

The application-based flow above is the **canonical citizen experience**.
