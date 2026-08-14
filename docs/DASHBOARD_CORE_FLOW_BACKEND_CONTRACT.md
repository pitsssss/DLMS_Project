# Dashboard Core Flow — Live Backend Contract

Audit of the **current** Laravel implementation only. No APIs were added or changed.

Source of truth: `app/Modules/Admin/Routes/admin.php`, `app/Modules/Tests/Services/TestResultService.php`, `app/Modules/Licenses/Services/LicenseService.php`, `app/Modules/Licenses/Services/LicenseIssuanceEligibilityService.php`.

All paths below are under the Laravel API prefix: `/api`.

---

### Record Result Contract

| Item | Live value |
|---|---|
| HTTP method | `POST` |
| Exact route | `/api/admin/test-appointments/{appointment}/record-result` |
| Route param | `{appointment}` = **numeric test appointment id** (`whereNumber`) |
| Route middleware | `auth:sanctum`, `dashboard` (`EnsureDashboardUser`), `permission:record_test_result`, `throttle:60,1` |
| Required permission | **`record_test_result` only** (OR-list of one). Baseline roles: `test_employee`, generic `employee`. Super admin bypasses via dashboard middleware, still needs this permission unless `hasPermission` is granted through roles. |
| Controller | `App\Modules\Admin\Controllers\TestAppointmentResultController::store` |
| FormRequest | `App\Modules\Tests\Requests\RecordTestResultRequest` |
| Service | `App\Modules\Tests\Services\TestResultService::recordForAppointment` |
| Success HTTP status | **200** (`successResponse` default) |
| Success message key | `messages.tests.recorded` → EN: `Test result recorded successfully.` |

#### Validation rules (`RecordTestResultRequest`)

```
result  required|string|in:passed,failed,no_show
notes   nullable|string|max:2000
```

`TestResultStatus::Pending` (`pending`) is **not** accepted.

#### Request payload

```json
{
  "result": "passed",
  "notes": "optional examiner notes"
}
```

- `result` required.
- `notes` optional. Stored on `test_results.notes`. **Notes are supported.**

#### Allowed result values

| Value | Enum |
|---|---|
| `passed` | `TestResultStatus::Passed` |
| `failed` | `TestResultStatus::Failed` |
| `no_show` | `TestResultStatus::NoShow` |

#### Required appointment / application states

Before insert, `TestResultService::recordForAppointment` requires:

1. Appointment exists → else **404** `messages.tests.appointment_not_found`
2. Appointment `status === booked` → else **422** `messages.tests.only_booked_result`
3. No existing `testResult` on that appointment → else **422** `messages.tests.already_recorded`
4. Application `status` is **`in_testing` or `waiting_retest`** → else **422** `messages.tests.not_testable_status`

Appointment is locked (`lockForUpdate`) with the application.

#### What happens by result

Shared for every successful record:

- Creates `test_results` with `attempt_number` = count of existing results for that `(application, test_type)` **+ 1** (includes prior passed/failed/no_show).
- Sets `recorded_by` = employee id, `recorded_at` = now.
- Audit: `test_result.recorded`
- Citizen notification: `test_result.passed` / `test_result.failed` / `test_result.no_show`

Then:

**`passed`**

- Appointment status → `completed`
- If `TestProgressionService::allRequiredTestsPassed` (every active required test type has a `passed` result): application → **`approved`**, note `messages.tests.note_all_passed`
- Else: `current_test_type_id` = next required type by `sequence_order`; if application was not already `in_testing`, transition → **`in_testing`** (`messages.tests.note_passed_continue`). If already `in_testing`, only `current_test_type_id` is updated (no extra status transition).

Seeded required sequence (`TestTypesSeeder`): `vision` (1) → `theory` (2) → `practical` (3). Passing vision unlocks theory; passing the last required test approves.

**`failed`**

- Appointment status → `completed`
- Failed/no-show attempt count for that test type is counted **after** this insert (`Failed` + `NoShow` only).
- If count **≥ `test_types.max_attempts`** (seeded **3**): keep `current_test_type_id` on this test; application → **`administrative_review`** (`messages.tests.note_max_attempts`)
- Else: `current_test_type_id` = this test; application → **`waiting_retest`** (`messages.tests.note_failed_retest`)

Citizen may book the same test again only while `waiting_retest` (and attempts remain). Booking later tests is blocked until this type is passed (`TestProgressionService`).

**`no_show`**

- Appointment status → **`no_show`** (not `completed`)
- Then **`handleNoShow` = `handleFailed`**: same application transitions (`waiting_retest` or `administrative_review`). No-show counts toward `max_attempts`.

#### Success JSON

Envelope from `ApiResponse::successResponse` (citizen translator). Resource: `TestResultResource`.

```json
{
  "success": true,
  "message": "Test result recorded successfully.",
  "data": {
    "id": 1,
    "application_id": 10,
    "test_appointment_id": 20,
    "test_type_id": 1,
    "test_type": { "id": 1, "name": "…", "code": "vision" },
    "result": "passed",
    "attempt_number": 1,
    "notes": null,
    "recorded_at": "2026-08-14T13:00:00+00:00",
    "recorded_by": { "id": 5, "name": "Examiner" }
  }
}
```

**Does this response refresh dashboard application/test state?** **No.** It does not include:

- application `status` / `application_number` / `current_test_type`
- appointment `status` after the write
- remaining tests / `can_book` / max-attempts remaining
- whether the application is now `approved`

Frontend must re-fetch a list or `GET /api/dashboard/applications/{application_number}` (needs `view_applications`). `test_employee` baseline does **not** include `view_applications`; refresh via appointment lists instead.

#### Errors

| Status | When | Message key / shape |
|---|---|---|
| 401 | No Sanctum token | `messages.http.unauthenticated` |
| 403 | Not dashboard user / inactive / missing `record_test_result` | `messages.dashboard.*` or `messages.middleware.permission_denied` |
| 404 | Unknown appointment id | `messages.tests.appointment_not_found` |
| 404 | Non-numeric `{appointment}` | Laravel `NotFoundHttpException` → `messages.http.not_found` |
| 422 | Validation (`result` missing/invalid, `notes` too long) | `{ success: false, message: validation.failed, errors: {…} }` |
| 422 | Appointment not `booked` | `messages.tests.only_booked_result` |
| 422 | Result already recorded | `messages.tests.already_recorded` |
| 422 | Application not `in_testing` / `waiting_retest` | `messages.tests.not_testable_status` |
| 429 | Over 60 requests / minute | Laravel throttle |

Error envelope (`ApiException`): `{ "success": false, "message": "…", "errors": {} }`.

#### Tests proving this behavior

| File | What it proves |
|---|---|
| `tests/Feature/AppointmentFlowTest.php` | HTTP `passed` → 200, `data.result=passed`, app stays `in_testing`, next test `can_book`; HTTP `failed` → app `waiting_retest` |
| `tests/Feature/AvailableTestsApiTest.php` | Pass vision then fail theory via this endpoint; waiting-result / max-attempts (max-attempts seeded, not via 3rd HTTP call) |
| **No HTTP test** for `no_show` on this route | Behavior is code-only: `handleNoShow` → `handleFailed` |
| **No HTTP test** that records the last required test through this route until `approved` | Approval-after-all-pass is implemented in `handlePassed`; issuance tests seed `test_results` instead |

---

### Issue License Contract

| Item | Live value |
|---|---|
| HTTP method | `POST` |
| Exact route | `/api/admin/applications/{application}/issue-license` |
| Identifier | **`{application}` = numeric application id**, not application number (`whereNumber`) |
| Route middleware | `auth:sanctum`, `dashboard`, `permission:issue_license`, `throttle:30,1` |
| Required permission | **`issue_license` only**. Baseline: `license_employee`, generic `employee`. |
| Controller | `App\Modules\Admin\Controllers\ApplicationLicenseController::issue` |
| FormRequest | **None.** Empty body. |
| Service | `App\Modules\Licenses\Services\LicenseService::issueForApplication` |
| Eligibility | `LicenseIssuanceEligibilityService::assertReady` (called first) |
| Success HTTP status | **200** |
| Success message key | `messages.licenses.issued` → EN: `Driving license issued successfully.` |

#### Preconditions (`assertReady`)

Checked in this order:

1. `license_unblock` service → **422** `messages.licenses.use_unblock_endpoint` (use unblock APIs, not issuance)
2. Service must produce a license: `new_license`, `renew_license`, `lost_replacement`, `damaged_replacement` → else **422** `messages.licenses.service_not_issuable`
3. Application status **must be `approved`** → else **422** `messages.licenses.must_be_approved`
4. No existing `licenses.application_id` row → else **422** `messages.licenses.already_issued`
5. Matching service fee payment `completed` (`application_fee` / `renewal_fee` / `lost_replacement_fee` / `damaged_replacement_fee`) → else **422** `messages.licenses.payment_required`
6. Every required document’s latest non-deleted upload is `approved` → else **422** `messages.licenses.documents_required`
7. If `new_license`: all required tests have a `passed` result → else **422** `messages.licenses.tests_required` (renew/replacement skip tests)
8. Citizen has no `unpaid` fines → else **422** `messages.licenses.unpaid_fines_issue`

Renew/replacement also require `relatedLicense`; missing → **422** `messages.applications.related_license_required` (inside `issueForApplication`, after `assertReady`).

#### Side effects on success

- Creates `License` (`status=active`, unique `license_number`, `issued_by` = employee, expiry = now + `config('license.validity_years')` default **10** years for new/renew; replacement keeps old expiry)
- Marks previous license `renewed` or `inactive` when applicable
- Application `approved_at` set if null, `issued_at` = now, status → **`license_issued`**
- Audit + `license.issued` notification
- Duplicate POST after success → **422** `already_issued`

#### Success JSON

Resource: `LicenseResource` (loaded: `licenseType`, `application`, `issuedBy`, `previousLicense`). `issuedBy` is loaded but **not** in the resource. `can_renew` / replacement flags are omitted unless those attributes are set (they are not on this path).

```json
{
  "success": true,
  "message": "Driving license issued successfully.",
  "data": {
    "id": 42,
    "license_number": "…",
    "status": "active",
    "status_label": "…",
    "stored_status": "active",
    "effective_status": "active",
    "issue_date": "2026-08-14",
    "expiry_date": "2036-08-14",
    "days_remaining": 3652,
    "is_expiring_soon": false,
    "license_type": { "id": 1, "name": "…", "code": "private" },
    "application": {
      "id": 10,
      "application_number": "APP-…",
      "status": "license_issued"
    },
    "created_at": "2026-08-14T13:00:00+00:00"
  }
}
```

#### How the new license id / number is returned

- **`data.id`** = new license primary key (integer)
- **`data.license_number`** = generated public number
- **`data.application.application_number`** and `data.application.id` also returned

#### Can the dashboard navigate to the issued license?

**Yes**, if the user also has `view_licenses` **or** `manage_licenses`:

`GET /api/dashboard/licenses/{license}` (`whereNumber`, same integer as `data.id`)

`license_employee` has both `issue_license` and `view_licenses`. Generic `employee` has `issue_license` + `manage_licenses` (so show works) but not `view_licenses`.

`issue_license` alone is **not** enough for that GET.

#### Errors

| Status | When |
|---|---|
| 401 | Unauthenticated |
| 403 | Not dashboard / missing `issue_license` |
| 404 | Unknown application id (`messages.applications.not_found`) |
| 422 | Any `assertReady` failure (keys above); `related_license_required`; `already_has_successor` from transition policy |
| 429 | Over 30 / minute |

#### Tests proving this behavior

| File | What it proves |
|---|---|
| `tests/Feature/LicenseFlowTest.php` | Issue → 200, `data.status=active`, app `license_issued`; unpaid fines → 422; `data.id` used for block/renew |
| `tests/Feature/DashboardOverviewTest.php` | Ready-queue vs issue; `license_unblock` excluded; duplicate issue → 422 after 200 |
| `tests/Feature/OtherLicenseServicesFlowTest.php` | Renew + lost replacement issuance |
| `tests/Feature/DashboardIssuedLicensesTest.php` | Issue then `GET /api/dashboard/licenses/{id}` |
| `tests/Feature/LicensePrintingTest.php` / `LicenseVerificationTest.php` | Issue then print/verify |

---

### Existing Supporting APIs

#### A. Listing tests/appointments waiting for result

**No dedicated operational queue endpoint.** Closest live options:

| Endpoint | Permission | Useful fields | Limitation |
|---|---|---|---|
| `GET /api/dashboard/overview` | dashboard + tests visibility (`record_test_result` **or** `view_appointments` **or** `manage_appointments`) | `data.operational_queues.tests_awaiting_result` (count of `booked` appointments with no `test_result`); `data.upcoming_appointments[]` with `id`, `application.{id,application_number}`, `citizen`, `test_type`, `scheduled_at`, `status` | Count only for the full queue. `upcoming_appointments` is **future booked only**, capped by `recent_limit`. Past booked-awaiting-result rows are omitted. |
| `GET /api/dashboard/appointment-slots/{slot}/bookings?status=booked` | `view_appointments` **or** `manage_appointments` | Booking `id` (**this is the record-result id**), `application.{id,application_number}`, `citizen`, `test_type`, `status`, `scheduled_at`, `test_result` if any, `actions.can_view_application` | **Per slot**, not global. Must list slots first: `GET /api/dashboard/appointment-slots`. |
| `GET /api/dashboard/reports/appointments?appointment_status=booked` | `view_reports` **and** (`view_appointments` **or** `manage_appointments`) | `data.rows[].id`, `application_number`, `citizen`, `test_type`, `scheduled_at`, `status`, `test_result` | Report, default period **`30d`** on `scheduled_at`. Not a full queue. `test_employee` has **no** `view_reports`. |
| `GET /api/dashboard/applications?status=in_testing` (also `waiting_retest`) | `view_applications` | Application `id`, `application_number`, translated `status`, citizen, license/service type | Applications, **not appointments**. List `status` is a **label**, not the enum code. No appointment id. |

Definition of “awaiting result” in overview KPI: `TestAppointment` `status=booked` and `whereDoesntHave('testResult')`.

#### B. Getting one appointment/test details

**MISSING** as a dedicated `GET /api/dashboard/test-appointments/{id}`.

Workaround fields exist only inside list rows (slot bookings / reports / overview upcoming). Enough to call record-result (`id` + citizen/test labels). Not a full detail document.

Dashboard application details (`GET /api/dashboard/applications/{application_number}`) has **no** appointments, **no** test results, **no** attempt counts. Identifier is **application number**, not id.

#### C. Listing applications ready for license issuance

**MISSING** as an eligibility-filtered list.

| Endpoint | What it actually is |
|---|---|
| `GET /api/dashboard/overview` → `operational_queues.licenses_ready_for_issuance` | **Count only** (`LicenseIssuanceEligibilityService::readyCount`) |
| `GET /api/dashboard/reports/licenses` → `summary.ready_for_issuance` | **Count only**; `rows` are **already issued** licenses |
| `GET /api/dashboard/applications?status=approved` | Super-set. `approved` ≠ ready. Proven in `DashboardOverviewTest` (missing payment/docs/tests/fines still `approved` but not issuable). List item **does** include numeric `id` for `POST .../issue-license`. Status in the JSON is a translated label. |

#### D. Issuance-readiness / `can_issue` for one application

**MISSING.** `LicenseIssuanceEligibilityService::isReady()` exists in PHP only.

`GET /api/dashboard/applications/{application_number}` has header status **label**, `issued_at`, workflow steps — **no** `can_issue`, **no** missing-payment/docs/tests/fines breakdown.

The only live check is **POST issue-license** (mutates on success, 422 with a reason on failure).

---

### Missing Backend Support

Only items the frontend **cannot** implement without guessing or mutating:

1. **Eligibility-filtered issuance queue** — cannot list “ready to issue” without either over-listing `status=approved` or calling the mutating issue endpoint.
2. **Per-application `can_issue` (and why not)** — no read API; 422 on issue is the only explanation.
3. **Dedicated GET one test appointment** — not required to *record* a result if the list row already has `id`, but there is no detail screen API.

Not listed as blocking:

- Awaiting-result **list** can be assembled from slot bookings (and/or reports if `view_reports`).
- Overview counts exist for badges.
- Issue response already returns `data.id` for license navigation.

---

### Recommended Minimal Frontend Integration

Smallest UI that works against **current** APIs. Two screens, no new backend.

#### 1. Record test result (examiner)

Permissions: `record_test_result` plus `view_appointments` (true for `test_employee`).

1. Badge: `GET /api/dashboard/overview` → `operational_queues.tests_awaiting_result`.
2. Queue: `GET /api/dashboard/appointment-slots` then for each relevant slot `GET /api/dashboard/appointment-slots/{slot}/bookings?status=booked`. Use `items[].id` as `{appointment}`. Optional: `upcoming_appointments` on overview for a short “today/upcoming” list (incomplete queue).
3. Form: `result` = `passed` \| `failed` \| `no_show`; optional `notes` (max 2000).
4. Submit: `POST /api/admin/test-appointments/{id}/record-result`.
5. Refresh the bookings list (do not trust the POST body for application status). Handle 422 (`already_recorded`, `only_booked_result`, `not_testable_status`).

Do not wait for a dedicated “waiting for result” API.

#### 2. Issue license (issuer)

Permissions: `issue_license` plus `view_applications` and `view_licenses` (true for `license_employee`).

1. Badge: `GET /api/dashboard/overview` → `operational_queues.licenses_ready_for_issuance` (count only).
2. Work list: `GET /api/dashboard/applications?status=approved`. Keep **`id`** from each row (not the translated status string). Treat this as a **candidate** list; some rows will 422.
3. Confirm: `POST /api/admin/applications/{id}/issue-license` with empty body. Show `message` on 422 (payment/docs/tests/fines/not approved/already issued/unblock).
4. On 200: navigate to `GET /api/dashboard/licenses/{data.id}` (or list `GET /api/dashboard/licenses`). Display `data.license_number`.

Do not send application number on issue. Do not use `license_unblock` applications on this button.

#### 3. After last test passed

When record-result returns `passed` and a later `GET` of the application (if the user has `view_applications`) shows approved — or when issue 422 says tests required — the issuance screen is the next operator step. The record-result response itself will not say `approved`.
