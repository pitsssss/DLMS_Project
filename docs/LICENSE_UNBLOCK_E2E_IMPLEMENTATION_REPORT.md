# License Unblock E2E Implementation Report

**Project:** SYRTAK / DLMS Laravel backend  
**Date:** 17 August 2026  
**Scope:** Make `license_unblock` a production-consistent, application-based, state-aware, permission-aware, auditable, localized end-to-end service.

---

## 1. Final Verdict

**COMPLETE**

The application-based License Unblock workflow is implemented, localized (AR/EN), documented for Flutter/Postman, and verified by automated tests.

```text
Focused tests:  24 passed (LicenseUnblockFlowTest)
Full suite:     1111 passed (7112 assertions)
```

---

## 2. Problem That Was Fixed

The codebase previously had a **broken mixed implementation**:

| Path | Status before this work |
|------|-------------------------|
| Application-based `POST /api/applications` with `service_type_code=license_unblock` | Broken at creation |
| Direct citizen `POST /api/licenses/{license}/unblock-request` | Ack-only stub; did not create an application or unblock |
| Staff `LicenseService::unblock()` via admin/dashboard | Worked independently |

**Root contradiction:**

```text
generic eligibility:
  blocked license → rejected for ALL related-license services

checkUnblock():
  requires license.status == blocked
```

`checkUnblock()` was never reached, because generic eligibility rejected blocked licenses first. Citizens could not create a `license_unblock` application.

---

## 3. Final Implemented Flow

```text
Citizen has blocked license
↓
GET /api/licenses  →  can_request_unblock: true
↓
POST /api/applications
  { service_type_code: license_unblock, related_license_id }
  → eligibility: blocked + owned + no unpaid fines + no duplicate active app
  → application status: draft
↓
GET /api/applications/{id}/required-documents
  → national_id_copy, fine_clearance
↓
POST /api/applications/{id}/documents   (upload each)
↓
POST /api/applications/{id}/submit-documents
  → documents_under_review
↓
Employee reviews documents (generic document review)
  → payment_pending
↓
GET /api/applications/{id}/fee
  → unblock_fee ($30.00 USD)
↓
POST /api/applications/{id}/payments
POST /api/applications/{id}/payments/{id}/confirm
  → approved  (no tests / appointments for unblock)
↓
Employee POST /api/dashboard/applications/{id}/unblock-license
  permission: manage_licenses
  revalidates: approved, related license still blocked, no unpaid fines,
               fee paid, documents approved, not already completed
↓
LicenseService::unblock()
  → license: blocked → active  OR  blocked → expired
↓
application: approved → completed
↓
Application history + License history + Audit log + Notifications
```

`license_unblock` does **not** use the license issuance endpoint and does **not** create a new license. It updates the existing related license.

---

## 4. Files Changed

### New files

| Path | What | Why |
|------|------|-----|
| `app/Modules/Applications/Services/ApplicationUnblockService.php` | Employee unblock + reject orchestration | Atomic application → `completed` / `rejected` |
| `app/Modules/Dashboard/Requests/RejectDashboardApplicationRequest.php` | Validates `reason` (required, 3–1000 chars) | Rejection flow |
| `tests/Feature/LicenseUnblockFlowTest.php` | 24 feature tests | E2E + regression coverage |
| `docs/flutter/LICENSE_UNBLOCK_FLOW.md` | Flutter integration guide | Canonical citizen/employee contract |
| `docs/LICENSE_UNBLOCK_E2E_IMPLEMENTATION_REPORT.md` | This report | Full implementation record |

### Modified files

| Path | What changed | Why |
|------|--------------|-----|
| `app/Enums/ApplicationStatus.php` | Added `Completed = 'completed'`, `terminalCases()`, `isTerminal()` | Terminal status for unblock (not `license_issued`) |
| `app/Enums/NotificationType.php` | Added `ApplicationCompleted` | Completion notification |
| `app/Modules/Applications/Services/LicenseServiceEligibilityService.php` | Unblock checked **before** generic blocked-license rejection; citizen-level unpaid fines at create; `can_request_unblock` flag | Fixes eligibility bug |
| `app/Modules/Applications/Services/ApplicationService.php` | Audit action `application.unblock_created` | Auditable creation |
| `app/Modules/Applications/Repositories/ApplicationRepository.php` | Persist `rejection_reason` on `Rejected` (not only `DocumentsRejected`) | Employee rejection stores reason |
| `app/Modules/Dashboard/Controllers/DashboardApplicationController.php` | `unblockLicense()`, `reject()` | Employee HTTP actions |
| `app/Modules/Dashboard/Routes/dashboard.php` | `POST .../unblock-license` and `POST .../reject` under `permission:manage_licenses` | RBAC-protected endpoints |
| `app/Modules/Licenses/Controllers/LicenseController.php` | Expose `can_request_unblock`; `@deprecated` on `unblockRequest()` | Citizen API + legacy mark |
| `app/Modules/Licenses/Resources/LicenseResource.php` | `can_request_unblock` field | Flutter eligibility source of truth |
| `app/Modules/Notifications/Support/NotificationEventMatrix.php` | `application.completed` implemented | Notification routing |
| `app/Modules/Dashboard/Resources/DashboardApplicationDetailsResource.php` | `completed` workflow step + `extra_details.related_license` | Employee dashboard context |
| `app/Modules/Dashboard/Services/DashboardApplicationService.php` | Eager-load `relatedLicense` | Queue/details support |
| `app/Modules/Dashboard/Services/DashboardDocumentReviewService.php` | Include `Completed` in completed statuses | Document review filters |
| `app/Modules/AIAgent/Support/AgentApplicationStatusMap.php` | `Completed` case | AI agent status map |
| `app/Modules/AIAgent/Support/ApplicationStatusLabelMapper.php` | AR/EN labels for `completed` | Localized status labels |
| `app/Modules/AIAgent/Services/AgentApplicationActionPolicy.php` | `Completed` next-step | Exhaustive match |
| `app/Modules/AIAgent/Services/AgentRequiredDocumentsHandler.php` | Treat `Completed` as past document-upload stage | Exhaustive status list |
| `app/Support/EmployeeMessageTranslator.php` | `completed` fallback label | Dashboard localization |
| `resources/lang/en/messages.php` | Unblock / rejection / completion messages | EN localization |
| `resources/lang/ar/messages.php` | Same keys in Arabic | AR localization |
| `routes/api.php` | DEPRECATED comment on `/licenses/{license}/unblock-request` | Legacy documentation |
| `database/seeders/FullLifecycleSeeder.php` | Unblock flows use `completed` instead of `license_issued` | Seed coverage for new status |
| `database/seeders/Support/FullLifecycleKit.php` | `Completed` lifecycle path + unblock history/notification | Seed real completed unblock apps |
| `tests/Feature/CommitteeDemoSeederTest.php` | Idempotency asserts actual demo count instead of hardcoded `4` | Match current committee demo seeder |
| `postman/SYRTAK_Flutter_API.postman_collection.json` | Canonical unblock flow; deprecate legacy endpoint; employee actions | Flutter/Postman contract |

---

## 5. Eligibility Fix (the original bug)

`LicenseServiceEligibilityService::check()` now evaluates `ServiceCode::LicenseUnblock` **before** the generic rule that rejects blocked licenses.

```text
license_unblock + blocked owned license + no unpaid fines
  → potentially eligible

license_unblock + active / non-blocked license
  → 422 not eligible

renew / lost replacement / damaged replacement + blocked license
  → still 422 (generic protection unchanged)
```

`flagsForCitizen()` now returns:

```json
{
  "can_renew": false,
  "can_request_lost_replacement": false,
  "can_request_damaged_replacement": false,
  "can_request_unblock": true
}
```

Flutter must use `can_request_unblock`. It must **not** infer eligibility from `status == blocked` alone.

---

## 6. Final API Contract

### Citizen APIs

| Method | Path | Auth | Payload | Purpose | When Flutter uses it |
|--------|------|------|---------|---------|----------------------|
| GET | `/api/licenses` | Citizen Bearer | — | List licenses with `status` + `can_request_unblock` | License screen |
| GET | `/api/licenses/{id}` | Citizen Bearer | — | License detail + eligibility | Before unblock CTA |
| POST | `/api/applications` | Citizen Bearer + profile approved | `{ "service_type_code": "license_unblock", "related_license_id": 123 }` | Create unblock application (`draft`) | User confirms unblock |
| GET | `/api/applications/{id}/required-documents` | Citizen Bearer | — | Checklist | After create |
| POST | `/api/applications/{id}/documents` | Citizen Bearer | multipart `required_document_id` + `file` | Upload one required document | Per checklist item |
| POST | `/api/applications/{id}/submit-documents` | Citizen Bearer | — | Submit for review → `documents_under_review` | After all uploads |
| GET | `/api/applications/{id}/fee` | Citizen Bearer | — | Returns `unblock_fee` | After docs approved (`payment_pending`) |
| POST | `/api/applications/{id}/payments` | Citizen Bearer | — | Create payment | `payment_pending` |
| POST | `/api/applications/{id}/payments/{id}/confirm` | Citizen Bearer | — | Confirm mock payment → `approved` | Dev/staging payment |
| GET | `/api/applications/{id}` | Citizen Bearer | — | Track status through `completed` | Throughout the flow |
| GET | `/api/notifications` | Citizen Bearer | — | `license.unblocked`, `application.completed` | After final action |

### Employee APIs

| Method | Path | Permission | Payload | Purpose |
|--------|------|------------|---------|---------|
| GET | `/api/dashboard/applications?service_type_code=license_unblock&status=approved` | `view_applications` | — | Discover ready queue |
| POST | `/api/admin/documents/{id}/approve` (existing generic review) | document-review perms | — | Approve citizen documents |
| POST | `/api/dashboard/applications/{id}/unblock-license` | **`manage_licenses`** | — | Final unblock action |
| POST | `/api/dashboard/applications/{id}/reject` | **`manage_licenses`** | `{ "reason": "..." }` | Reject approved unblock request |

Direct staff bypass (still functional, not the Flutter path):

- `POST /api/dashboard/licenses/{license_id}/unblock`
- `POST /api/admin/licenses/{license_id}/unblock`

---

## 7. Application Status Flow

```text
draft
→ documents_under_review
→ documents_rejected  (re-upload / resubmit)
→ payment_pending
→ approved            (unblock skips tests and appointments)
→ completed           (after successful employee unblock)

Also terminal: rejected | cancelled
```

`ApplicationStatus::Completed` value: `completed`

- Included in `terminalCases()`
- **Not** included in `activeCases()`
- Duplicate prevention uses `activeValues()`, so a completed/rejected/cancelled application does **not** permanently block a future request

Valid final transition: `approved → completed` only after `LicenseService::unblock()` succeeds. If unblock fails, the application stays `approved`.

Issuance status `license_issued` is **never** used for unblock.

---

## 8. License Status Flow

Reuse existing `LicenseTransitionPolicy::resolveUnblockStatus()` via `LicenseService::unblock()`:

```text
blocked + still valid expiry  →  active
blocked + already expired     →  expired
```

Preserved behavior:

- Block reason cleared
- `blocked_at` / `blocked_by` cleared
- Previous block reason in license history metadata (existing support)
- Same license row
- Same license number
- No new license created

---

## 9. Documents

Required documents for `license_unblock` (seeded, unchanged):

| Code | Role |
|------|------|
| `national_id_copy` | Identity |
| `fine_clearance` | Fine clearance evidence |

Uses the existing standard workflow:

```text
GET required documents
POST document upload
POST submit documents
employee review (approve / reject)
```

No custom document subsystem.

---

## 10. Fees

| Field | Value |
|-------|--------|
| Code | `unblock_fee` |
| Amount | `30.00` USD |
| Source | `ApplicationFeeCatalog` / `FeesSeeder` |

Payment uses the generic application payment workflow. After documents are approved:

```text
payment_pending → payment → confirm → approved
```

An employee cannot execute final unblock while the application is not `approved` (fee paid + documents approved are revalidated).

---

## 11. Fines

**Citizen-level unpaid fines** (not license-specific):

```text
Fine
  where citizen_id = target citizen
  and status = unpaid
```

Enforced at:

1. **Application creation** — `LicenseServiceEligibilityService::checkUnblock()`
2. **Final employee action** — `ApplicationUnblockService` and existing `LicenseService::unblock()`

Unpaid fines → 422. Paid / no unpaid fines → allowed.

---

## 12. Permissions

Final employee actions are protected by **`manage_licenses`**.

`EnsurePermission` middleware is OR-based: any listed permission is enough. The new routes list only `manage_licenses`.

| Actor | Result |
|-------|--------|
| Employee with `manage_licenses` (e.g. `license_employee`) | Allowed |
| Employee without `manage_licenses` (e.g. `fines_employee`) | 403 |
| Citizen | Route not available under citizen prefix |

Backend enforcement is mandatory even if the frontend hides the button.

---

## 13. Notifications

| Step | Notification type | When |
|------|-------------------|------|
| Application created | `application.created` | Citizen creates unblock application |
| Documents / payment / status changes | Generic application status notifications | Existing matrix |
| Payment confirmed | `application.approved` | Application becomes `approved` |
| Employee unblock success | `license.unblocked` **and** `application.completed` | After actual license unblock |
| Employee rejection | `application.rejected` | Approved application rejected |

**Not sent** on application creation: `license.unblocked`.

Machine codes stay untranslated: `license_unblock`, `unblock_fee`, `completed`, `national_id_copy`, `fine_clearance`.

---

## 14. Audit / History

| Event | Record |
|-------|--------|
| Create application | Audit `application.unblock_created` |
| Status changes | Application status history + audit `application.status_changed` |
| Final unblock | Audit `application.unblock_completed`; history `approved → completed` |
| License change | License history `action = unblocked`; audit via `LicenseService::unblock()` |
| Rejection | History `approved → rejected` with reason on application + history |

---

## 15. Deprecated Endpoint

```http
POST /api/licenses/{license}/unblock-request
```

**Status:** retained for compatibility, **not** canonical.

Marked as DEPRECATED / LEGACY in:

- `routes/api.php` comment
- `LicenseController::unblockRequest()` `@deprecated` PHPDoc
- Postman collection request name + description
- Flutter document `docs/flutter/LICENSE_UNBLOCK_FLOW.md`

Behavior unchanged: acknowledges intent only. Does **not** create an application and does **not** unblock the license.

Canonical citizen entry:

```http
POST /api/applications
{
  "service_type_code": "license_unblock",
  "related_license_id": 123
}
```

---

## 16. Flutter Integration Instructions

See also: [`docs/flutter/LICENSE_UNBLOCK_FLOW.md`](flutter/LICENSE_UNBLOCK_FLOW.md)

Chronological sequence:

1. GET licenses → show unblock CTA only if `can_request_unblock === true`
2. POST applications (`license_unblock` + `related_license_id`)
3. GET required documents → upload each → submit
4. Wait for employee document approval (`payment_pending`)
5. GET fee → create payment → confirm payment
6. Poll application until `approved`
7. Employee executes unblock (not a Flutter call)
8. Poll until `completed`; refresh licenses; handle `license.unblocked`

Important Flutter rules:

- Never call `/unblock-request` in new builds
- Never call `issue-license` for unblock
- Do not infer eligibility from license status alone
- Refresh application after each citizen action
- Refresh licenses after `completed` or `license.unblocked`
- Final success = application `completed` **and** license `active` or `expired`
- One active unblock application per related license at a time

---

## 17. Error Cases

| HTTP | Condition |
|------|-----------|
| 403 | License owned by another citizen; unauthorized employee |
| 404 | Related license not found |
| 422 | Missing `related_license_id`; license not blocked; unpaid fines; duplicate active application; wrong application status/service; stale license at final action; already completed; rejection without reason |
| 422 | `issue-license` on a `license_unblock` application |

No silent no-op success for invalid state transitions.

---

## 18. Tests Added

File: `tests/Feature/LicenseUnblockFlowTest.php`

| Test | Covers |
|------|--------|
| `test_blocked_owned_license_can_create_unblock_application` | Eligibility 1 |
| `test_active_license_cannot_create_unblock_application` | Eligibility 2 |
| `test_another_citizens_license_is_forbidden` | Eligibility 3 (403) |
| `test_generic_services_still_reject_blocked_license` | Eligibility 6 (renew still blocked) |
| `test_unpaid_fines_block_unblock_application_creation` | Fines at create |
| `test_duplicate_active_unblock_application_is_rejected` | Duplicate prevention |
| `test_new_unblock_application_allowed_after_completed_terminal_application` | New request after terminal |
| `test_required_documents_for_unblock_are_returned` | `national_id_copy`, `fine_clearance` |
| `test_unblock_fee_is_returned_for_application` | `unblock_fee` |
| `test_full_e2e_unblock_application_flow` | Employee action, active license, no new row, history, audit, notifications |
| `test_unblock_expired_blocked_license_resolves_to_expired` | Expired blocked → expired |
| `test_unauthorized_employee_cannot_unblock_from_application` | 403 |
| `test_cannot_unblock_before_approved_status` | State guard |
| `test_cannot_unblock_wrong_service_application` | Wrong service |
| `test_cannot_execute_unblock_twice_on_same_application` | Idempotency |
| `test_stale_license_state_is_rejected_at_final_action` | Stale license; app stays approved |
| `test_unpaid_fines_block_final_unblock_action` | Fines at final action |
| `test_employee_can_reject_approved_unblock_application` | Rejection + license stays blocked |
| `test_rejection_requires_reason` | Validation |
| `test_can_request_unblock_flag_on_licenses` | License resource flag |
| `test_issue_license_still_rejects_license_unblock_application` | Issuance forbidden |
| `test_dashboard_application_queue_filters_unblock_applications` | Queue discoverability |
| `test_unblock_request_endpoint_does_not_create_application` | Legacy endpoint |
| `test_application_creation_does_not_emit_license_unblocked_notification` | No false notification |

---

## 19. Test Results

### Focused

```text
php artisan test tests/Feature/LicenseUnblockFlowTest.php

Tests:    24 passed (171 assertions)
Duration: ~17s
```

### Full suite

```text
php artisan test

Tests:    1111 passed (7112 assertions)
Duration: ~322s
```

Related seeder updates required by the new `completed` status:

- `FullLifecycleSeeder` unblock matrix now seeds `completed` instead of `license_issued`
- `FullLifecycleKit` completes unblock applications through `approved → completed` and unblocks the related license
- `CommitteeDemoSeederTest` idempotency assertion no longer hardcodes 4 demo applications (the current demo seeder creates the full committee set)

---

## 20. Postman Changes

**File:** `postman/SYRTAK_Flutter_API.postman_collection.json`

Updates:

- **Create Unblock Application** — canonical payload; stores `unblock_application_id`
- **DEPRECATED — Request Unblock (Legacy Ack Only)** — old `/licenses/{id}/unblock-request`
- **Unblock License From Application (Dashboard)** — `POST /api/dashboard/applications/{unblock_application_id}/unblock-license`
- **Reject Approved Unblock Application (Dashboard)** — `POST /api/dashboard/applications/{unblock_application_id}/reject`

Environment variables used: `license_id`, `unblock_application_id`, `application_id`, `payment_id`, `citizen_token`, `employee_token`.

---

## 21. Localization

All new user-facing strings use translation keys in both `resources/lang/en/messages.php` and `resources/lang/ar/messages.php`.

Examples:

| Key | EN | AR |
|-----|----|----|
| `messages.applications.must_be_approved_unblock` | The unblock application must be approved before the license can be unblocked. | يجب أن يكون طلب فك الحظر في حالة مقبول قبل تنفيذ فك الحظر. |
| `messages.applications.already_completed` | This unblock application has already been completed. | تم إكمال طلب فك الحظر هذا مسبقاً. |
| `messages.applications.not_unblock_service` | This action is only available for license unblock applications. | هذا الإجراء متاح فقط لطلبات فك حظر الرخصة. |
| `messages.applications.license_not_blocked` | The related license is no longer blocked… | — |
| `messages.applications.unblock_completed` | License unblocked successfully. | تم فك حظر الرخصة بنجاح. |
| `messages.notifications.application_completed_title` | Unblock request completed | اكتمل طلب فك الحظر |
| `messages.licenses.fines_before_unblock` | All fines must be settled before requesting an unblock. | — |

Dashboard / AI labels for status `completed`: EN `Completed` / AR `مكتمل`.

---

## 22. Database Changes

No migration was added.

`ApplicationStatus::Completed` is a PHP string-backed enum (`completed`). The application status column already stores string values. Adding a new status did not require a schema change.

---

## 23. Architecture Constraints Honored

- Existing service / repository / module structure
- Thin controllers; business rules in services
- No duplicated license unblock transition logic (`LicenseService::unblock()` is the domain operation)
- Permission middleware on dashboard routes
- Outer DB transaction in `ApplicationUnblockService` around existing inner transaction in `LicenseService::unblock()` (Laravel savepoints)
- IDOR / ownership checks (citizen vs related license; application citizen vs license citizen)
- Existing notification, audit, and history architecture
- AR/EN localization via translation keys
- No global API envelope change
- Production `/unblock-request` route not deleted
- `license_unblock` remains excluded from `ServiceWorkflow::issuableCodes()`

---

## 24. Remaining Gaps

**NONE** for the required E2E workflow.

Optional (not blocking):

- A dedicated Postman folder that sequences all 13 Flutter steps in one place (canonical requests exist; they are spread across existing folders plus the new employee actions)

---

## 25. Final Confirmation Checklist

- [x] Blocked citizen license can start application
- [x] Duplicate request prevented
- [x] Documents work (generic workflow)
- [x] Review works (generic admin document review)
- [x] `unblock_fee` works
- [x] Payment works → `approved`
- [x] `approved` reached correctly
- [x] Employee action is permission protected (`manage_licenses`)
- [x] Final action calls existing `LicenseService::unblock`
- [x] License becomes `active` or `expired`
- [x] Application becomes `completed`
- [x] No new license created
- [x] License history created
- [x] Application history created
- [x] Audit created
- [x] Notification created on actual unblock (not on create)
- [x] AR/EN localization complete
- [x] Old endpoint documented as deprecated
- [x] Postman updated
- [x] Flutter document created
- [x] Focused tests pass (24 / 24)
- [x] Full test suite passes (1111 / 1111)

---

## 26. Related Documents

| File | Purpose |
|------|---------|
| [`docs/flutter/LICENSE_UNBLOCK_FLOW.md`](flutter/LICENSE_UNBLOCK_FLOW.md) | Flutter developer contract (APIs, statuses, rules) |
| [`postman/SYRTAK_Flutter_API.postman_collection.json`](../postman/SYRTAK_Flutter_API.postman_collection.json) | Canonical Postman collection |
| [`tests/Feature/LicenseUnblockFlowTest.php`](../tests/Feature/LicenseUnblockFlowTest.php) | Automated E2E coverage |
