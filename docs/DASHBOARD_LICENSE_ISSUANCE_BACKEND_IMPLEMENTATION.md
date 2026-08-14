# Dashboard License Issuance — Backend Implementation

Read-only dashboard queue for applications that are **ready to issue**. **Issue-license mutation is unchanged.**

GET readiness is informational. POST still re-runs `LicenseIssuanceEligibilityService::assertReady()` (and related-license / transition checks inside `LicenseService::issueForApplication`). The frontend must handle **422** even if a prior GET said ready.

## Endpoint contract

```
GET /api/dashboard/license-issuance/applications
GET /api/dashboard/license-issuance/applications/{application}
```

| Item | Value |
|---|---|
| Middleware | `auth:sanctum`, `dashboard`, `employee.session.track`, `permission:issue_license,view_applications,manage_applications` (**OR**) |
| `{application}` | Numeric **application id** (same id used by POST issue-license), `whereNumber` |
| Issue mutation | Still `POST /api/admin/applications/{application}/issue-license` + **`permission:issue_license` only** |
| Success | **200** |
| List message | `messages.licenses.dashboard_issuance_queue_retrieved` |
| Details message | `messages.licenses.dashboard_issuance_details_retrieved` |

The GET endpoints never mutate.

### Query filters (list only)

| Param | Default | Notes |
|---|---|---|
| `search` | — | `application_number` or citizen `name` |
| `service_type_id` | — | `exists:service_types,id` |
| `service_type_code` | — | `exists:service_types,code` |
| `license_type_id` | — | `exists:license_types,id` |
| `license_type_code` | — | `exists:license_types,code` |
| `date` | — | Single business day (`Asia/Damascus`) on `approved_at` |
| `date_from` / `date_to` | — | Inclusive calendar range on `approved_at`; ignored when `date` is set |
| `page` / `per_page` | 1 / 20 | `per_page` 1–100 |

There is no `status=approved` filter. The default (and only) queue is eligibility-filtered.

## Readiness definition

The list is **not** `status = approved`.

Queue membership uses `LicenseIssuanceEligibilityService::eligibleQuery()`, which matches `assertReady()` plus related-license presence for renew / lost replacement / damaged replacement.

`assertReady()` (POST authority, **unchanged**) checks in order:

1. Not `license_unblock` → else 422 `messages.licenses.use_unblock_endpoint`
2. Service produces a license (`new_license`, `renew_license`, `lost_replacement`, `damaged_replacement`) → else 422 `messages.licenses.service_not_issuable`
3. Application status = `approved` → else 422 `messages.licenses.must_be_approved`
4. No license already issued for this application → else 422 `messages.licenses.already_issued`
5. Matching service fee payment `completed` → else 422 `messages.licenses.payment_required`
6. All required documents’ latest upload `approved` → else 422 `messages.licenses.documents_required`
7. If `new_license`: all required tests passed → else 422 `messages.licenses.tests_required` (renew/replacement skip tests)
8. Citizen has no unpaid fines → else 422 `messages.licenses.unpaid_fines_issue`

Read-only `evaluate()` builds `is_ready`, `checklist`, and translated `blockers`. `is_ready` is true only when `assertReady()` would pass **and** a related license is present when the service requires one (`issueForApplication` still checks that after `assertReady()`).

`actions.can_issue_license` uses `evaluate().is_ready` plus the actor’s `issue_license` permission. It does not fake readiness.

## Response shape

### List

```json
{
  "success": true,
  "message": "تم جلب طابور إصدار الرخص بنجاح.",
  "data": {
    "items": [
      {
        "id": 10,
        "application_number": "APP-…",
        "status": { "value": "approved", "label": "معتمد" },
        "created_at": "2026-08-14T11:00:00+00:00",
        "submitted_at": "2026-08-14T11:00:00+00:00",
        "approved_at": "2026-08-14T11:00:00+00:00",
        "citizen": { "id": 3, "name": "…" },
        "service_type": { "id": 1, "code": "new_license", "name": "إصدار رخصة جديدة" },
        "license_type": { "id": 1, "code": "private", "name": "رخصة قيادة خاصة" },
        "related_license": null,
        "readiness": {
          "is_ready": true,
          "checklist": {
            "service_issuable": true,
            "application_approved": true,
            "payment_completed": true,
            "documents_approved": true,
            "required_tests_passed": true,
            "no_unpaid_fines": true,
            "not_already_issued": true,
            "related_license_present": true
          },
          "blockers": []
        },
        "actions": {
          "can_issue_license": true,
          "can_view_application": true
        }
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 1,
      "last_page": 1
    }
  }
}
```

Citizen payload is `id` + `name` only.

`related_license` (renew / replacement) includes `id`, `license_number`, `status.{value,label}`, `issue_date`, `expiry_date`, `license_type`.

### Details

Same item object (not wrapped in `items`). Unknown id → **404** `messages.applications.not_found`.

Details is **not** limited to the ready queue. An approved-but-unpaid (or otherwise blocked) application returns `is_ready: false`, checklist flags, and translated blockers such as:

```json
{
  "code": "payment_required",
  "message": "يجب إتمام دفع الرسوم قبل إصدار الرخصة."
}
```

Blocker codes match POST 422 message keys (`payment_required`, `documents_required`, `tests_required`, `unpaid_fines_issue`, `already_issued`, `must_be_approved`, `use_unblock_endpoint`, `service_not_issuable`, `related_license_required`). Internal exception details are not exposed.

### `actions`

| Flag | True when |
|---|---|
| `can_issue_license` | Actor has `issue_license` **and** `evaluate().is_ready` |
| `can_view_application` | Actor has `view_applications` **or** `manage_applications` |

`license_employee` typically has both issue and view. `application_manager` can list the queue but `can_issue_license` is false. `test_employee` is **403**.

## Permissions

| Endpoint | Permission |
|---|---|
| GET queue / details | `issue_license` **OR** `view_applications` **OR** `manage_applications` |
| POST issue-license | **`issue_license` only** (unchanged) |

## Stale-read safety

Between GET and POST, payment / documents / tests / fines / issuance can change.

1. GET may show `readiness.is_ready = true` and `actions.can_issue_license = true`.
2. POST `issue-license` **always** re-runs `assertReady()` inside `issueForApplication`.
3. Frontend must treat POST **422** as current truth, show `message`, and reload the queue.

Do not skip POST because GET said ready.

## Exact frontend integration

Auth: dashboard Sanctum token. Role `license_employee` or any user with `issue_license` / `view_applications` / `manage_applications`.

1. **Load ready queue:** `GET /api/dashboard/license-issuance/applications`  
   Optional: `search`, `service_type_code`, `license_type_code`, `date`, `page`, `per_page`.
2. **Show issue action** only when `items[].actions.can_issue_license` is true.  
   Optional confirmation: `GET /api/dashboard/license-issuance/applications/{items[].id}` and render `readiness.checklist` / `blockers`.
3. **Issue:** `POST /api/admin/applications/{items[].id}/issue-license` with **empty body**.  
   Use numeric **`id`**, not `application_number`.
4. **On 200:** navigate with **`data.id`** (new license PK):  
   `GET /api/dashboard/licenses/{data.id}` if the actor has `view_licenses` or `manage_licenses`. Display `data.license_number`. Reload the issuance queue (the row should disappear).
5. **On 422:** show `message`, reload the queue. Do not assume GET is still valid.

Do **not** send `license_unblock` applications through this button.

## What was not changed

- `ApplicationLicenseController` / `LicenseService::issueForApplication()` mutation path
- `LicenseIssuanceEligibilityService::assertReady()` behavior (same order, same 422 keys)
- POST `permission:issue_license`
- Domain transitions (new / renew / replacement issuance)

`LicenseIssuanceEligibilityService` gained **read** helpers only: `evaluate()`, `hasRequiredRelatedLicense()`. `eligibleQuery()` now also requires `related_license_id` for renew/replacement (overview ready-count stays aligned).

## Tests

`tests/Feature/DashboardLicenseIssuanceQueueTest.php`

1. Authorized license employee lists ready applications (fields + details)
2. Approved but unpaid is excluded (details still returns blockers)
3. Missing required approved documents excluded
4. New-license missing tests excluded
5. Unpaid fine excluded
6. Already issued excluded
7. `license_unblock` excluded
8. Ready renew / lost replacement included with related license
9. `actions.can_issue_license` respects permission (`application_manager` lists, cannot POST)
10. Unauthorized employee / citizen / anonymous rejected
11. Pagination / search / service / license type / date filters
12. Existing POST issue-license still 200 for a ready application, then disappears from queue
13. Stale unpaid fine after GET ready → POST **422**, then queue empty

Also re-ran existing `LicenseFlowTest` issue/fines cases, `OtherLicenseServicesFlowTest` renew/replacement issue, and `DashboardOverviewTest` ready-count cases.

**15 passed** (issuance queue) + **4** existing mutation + **3** overview ready-count.

## Changed files

| File | Change |
|---|---|
| `app/Modules/Licenses/Services/LicenseIssuanceEligibilityService.php` | `evaluate()`, related-license on `eligibleQuery()`, `hasRequiredRelatedLicense()` — `assertReady()` unchanged |
| `app/Modules/Dashboard/Routes/dashboard.php` | GET `/license-issuance/applications` and `/{application}` |
| `app/Modules/Dashboard/Controllers/DashboardLicenseIssuanceController.php` | **new** |
| `app/Modules/Dashboard/Requests/ListDashboardLicenseIssuanceRequest.php` | **new** |
| `app/Modules/Dashboard/Services/DashboardLicenseIssuanceService.php` | **new** |
| `app/Modules/Dashboard/Resources/DashboardLicenseIssuanceApplicationResource.php` | **new** |
| `app/Modules/Dashboard/Support/DashboardLicenseIssuanceActions.php` | **new** |
| `resources/lang/en/messages.php` | issuance queue/details messages |
| `resources/lang/ar/messages.php` | same |
| `tests/Feature/DashboardLicenseIssuanceQueueTest.php` | **new** |
| `docs/DASHBOARD_LICENSE_ISSUANCE_BACKEND_IMPLEMENTATION.md` | this file |
