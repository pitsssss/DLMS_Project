# SYRTAK / DLMS — Final University Project Audit

**Product name:** SYRTAK  
**System name:** Digital License Management System (DLMS)  
**Audit date:** 2026-08-14  
**Auditor role:** code-first inspection for the final committee report  
**Code modified:** none  

---

## How this audit was done

| Rule | Application |
|------|-------------|
| Source of truth | Current code (`app/`, `routes/`, `config/`, `database/migrations`, `tests/`), then the sibling Next.js dashboard at `d:\Projects\DLMS_Dashboard` |
| Documentation | Used only as a pointer. Claims that exist only in README / Flutter markdown / SRS are labelled **Documentation Only** |
| Status labels | **Implemented** / **Partially Implemented** / **Documentation Only** / **Not Found** |
| Test pass counts | **needs final run** — no committed PHPUnit result artifact and no CI workflow |
| Flutter | **Not Found** in this repository or under `d:\Projects` (0 `.dart` files, 0 `pubspec.yaml`) |

**Repositories inspected**

| Repo | What it is |
|------|------------|
| `d:\Projects\DLMS_Project` | Laravel 12 REST API backend (this git repo) |
| `d:\Projects\DLMS_Dashboard` | Next.js 15 employee dashboard (`syrtak-dashboard-next`) — sibling, not in this git repo |

---

## Executive snapshot (code-backed)

SYRTAK is a **modular Laravel API** for a government-style driving-license lifecycle, with:

- Citizen REST API (Sanctum) + bilingual AR/EN messages
- Employee APIs under both `/api/dashboard/*` and `/api/admin/*`
- Custom RBAC (roles + direct permissions, not Spatie)
- Domain services that enforce workflow (documents → payment → tests for new licenses → issuance)
- Stripe + mock payments with obligation uniqueness, webhook idempotency, and reconciliation
- FCM push pipeline (queued job + supervisor worker)
- A **hybrid AI Agent**: Gemini proposes JSON; backend executes the **same** domain services after confirmation

What the committee should **not** assume from README wording: live phone-OTP SMS, citizen fine checkout, Flutter screens in this repo, CI/CD, a central FSM that rejects illegal status jumps, or a complete Next.js UI for test recording and license issuance.

---

# 1. System scope and major modules

**Status: Implemented** (backend). Flutter UI **Not Found**. Employee UI **Implemented** in sibling Next.js repo (with gaps in §15).

### Laravel modules (`app/Modules/`)

| Module | Role |
|--------|------|
| `Auth` | Register, email OTP, login/logout, profile complete/update |
| `Applications` | Draft applications, documents, catalogs, eligibility |
| `Admin` | Profile review, document review, record test result, issue license, fines, audit logs |
| `Dashboard` | Employee SPA API: auth, RBAC, citizens, applications, payments, slots, fees, issued licenses, reports, sessions |
| `Payments` | Mock + Stripe checkout, webhook, lifecycle, reconcile |
| `Appointments` | Slots, booking, reschedule/cancel, test progression |
| `Tests` | Record/list test results |
| `Licenses` | Issue, renew, replace, block/unblock, public verify, print |
| `Fines` | Admin CRUD + citizen list |
| `Notifications` | In-app notifications + event matrix |
| `Devices` / `Push` / `Firebase` | FCM device tokens and delivery jobs |
| `AIAgent` | Gemini + deterministic workflows |
| `Content` | FAQ, privacy, contact messages |
| `Settings` | Citizen language/theme |
| `Reports` | Admin overview reports |
| `AuditLogs` | Resources for audit log API |

Route entrypoints: `routes/api.php` (citizen + includes), `app/Modules/Dashboard/Routes/dashboard.php`, `app/Modules/Admin/Routes/admin.php`, `app/Modules/AIAgent/Routes/ai-agent.php`, `routes/web.php` (dev dashboard only), `routes/console.php` (scheduler).

Empty leftover: `Route::prefix('chatbot')` in `routes/api.php` is an empty group. `GET /api/ping` still returns `'phase' => 9`.

### Service catalog (`App\Enums\ServiceCode`)

`new_license`, `renew_license`, `lost_replacement`, `damaged_replacement`, `license_unblock`.

`ServiceWorkflow` (`app/Modules/Applications/Support/ServiceWorkflow.php`): tests required **only** for `new_license`; related license required for renew/replace/unblock; issuable codes exclude `license_unblock`.

---

# 2. Actors, roles, permissions, RBAC, separation of duties

**Status: Implemented** (custom RBAC). Laravel Policy classes **Not Found**.

### Actors (`App\Enums\UserType`)

`citizen` | `employee` | `admin`

Middleware aliases in `bootstrap/app.php`: `citizen` (`EnsureCitizen`), `dashboard` (`EnsureDashboardUser`), `permission` (`EnsurePermission` — **OR** of listed permissions), `profile.approved`, `super_admin`, `root_super_admin`, `employee.session.track`, `locale`.

Separation:

- Citizens cannot use dashboard (`EnsureDashboardUser` rejects `isCitizen()`).
- Employees cannot use citizen/AI routes (`EnsureCitizen`).
- Inactive accounts rejected in both citizen and dashboard middleware.
- Super-admin bypasses permission checks (`User::hasPermission`).
- Employee session listing/revoke is `root_super_admin` only (`dashboard.php`).

### Permission registry

Source of permission **names**: `config/dashboard_permissions.php` via `PermissionRegistry`.  
Runtime assignments: `roles`, `permissions`, `permission_role`, `permission_user` (direct permissions). Migration: `2026_07_30_120000_add_rbac_metadata_and_direct_permissions.php`.

Specialized seeded roles (same config `system_roles`): `super_admin`, `admin`, `profile_document_reviewer` (intentionally **no** `view_applications`), `fines_employee`, `audit_employee`, `reports_employee`, `settings_employee`, `application_manager`, `test_employee`, `license_employee`, `payment_employee`, legacy `employee`, protected `citizen`.

SoD evidence: `profile_document_reviewer` baseline is only `access_dashboard` + `review_profiles` + `review_documents`. Tested in `tests/Feature/DocumentReviewerAuthorizationTest.php`, `DashboardPermissionTest.php`, `SuperAdminProtectionTest.php`, `DashboardAccessControlTest.php`, `DashboardRoleManagementTest.php`.

Commands: `rbac:bootstrap`, `rbac:audit`, `rbac:repair-document-reviewer`.

**Gap:** `EnsureUserIsActive` exists but is **not** registered in `bootstrap/app.php` (active checks are inlined). No `app/Policies/*`.

---

# 3. Application lifecycle and state transitions

**Status: Partially Implemented**

### Statuses (`App\Enums\ApplicationStatus`)

`draft` → `documents_under_review` → `documents_rejected` | `payment_pending` → `payment_completed` → `appointment_pending` | `approved` → `in_testing` → `waiting_retest` | `approved` → `license_issued`  
Also: `administrative_review`, `rejected`, `cancelled`.

History: `ApplicationStatusHistory` + `ApplicationRepository::transitionStatus()` writes history, audit `application.status_changed`, and notification.

### Who drives which transition (production callers)

| Transition | Caller |
|------------|--------|
| draft / documents_rejected → documents_under_review | `ApplicationDocumentService::submitForReview` |
| documents_under_review → payment_pending | `DocumentReviewService::approve` when all required docs approved |
| documents_under_review → documents_rejected | `DocumentReviewService::reject` |
| payment_pending → payment_completed → appointment_pending **or** approved | `PaymentLifecycleService::completeVerifiedPayment` + `ServiceWorkflow::requiresTests` |
| booking / results | `AppointmentService`, `TestResultService` |
| all tests passed → approved | `TestResultService::handlePassed` |
| fail / no-show → waiting_retest or administrative_review | `TestResultService::handleFailed` / `handleNoShow` |
| approved → license_issued | `LicenseService::issueForApplication` |

**Gap (important):** `transitionStatus()` does **not** validate allowed edges. Any caller can set any status. There is no central FSM matrix.

**Gap:** `rejected` and `cancelled` are in the enum and `NotificationEventMatrix` (`wired_pending_caller`) but **no production `transitionStatus` caller** was found. Do not claim citizens can cancel applications or employees can reject whole applications.

---

# 4. Business rules and where they are enforced

**Status: Implemented** (service layer, not Laravel Gates)

| Rule | Enforcement |
|------|-------------|
| Profile must be approved before mutating citizen services | `EnsureProfileApproved` + `ProfileService::assertCanUseCitizenServices` |
| Duplicate active application (same license type + service) | `ApplicationService::assertNoDuplicateActiveApplication*` |
| Related-license ownership + eligibility | `ApplicationService::createFromPayload` + `LicenseServiceEligibilityService` |
| Tests only for new license | `ServiceWorkflow::requiresTests` |
| Document MIME/size/applicability | `ApplicationDocumentService` + `AllowedDocumentMime` |
| Payment only in payment_pending; one settled obligation | `PaymentLifecycleService` + unique keys |
| Slot capacity / concurrent booking | `AppointmentService` `lockForUpdate` |
| Test sequence + max attempts | `TestProgressionService`, `TestResultService` |
| Issuance readiness | `LicenseIssuanceEligibilityService::assertReady` |
| Block/unblock legal transitions | `LicenseTransitionPolicy` |
| Unpaid fines block issuance | `LicenseIssuanceEligibilityService::citizenHasUnpaidFines` |
| AI cannot do admin work | `AgentSafetyRules` + `AgentPostProcessor` |
| AI action vs application status | `AgentApplicationActionPolicy` + `AgentApplicationStatusMap` |

Form Requests validate input shape. Business rules live in services. That is the architecture to describe to the committee.

---

# 5. Profile review and document review

**Status: Implemented** (backend + Next.js UI)

### Profile

- Citizen: `PUT /api/profile/complete` → `pending_review` (`ProfileService`)
- Employee: `POST /api/admin/profile-reviews/{user}/approve|reject` (`ProfileReviewController` / `ProfileReviewService`)
- States: `App\Enums\ProfileStatus` — incomplete, pending_review, approved, rejected
- Next.js: `/dashboard/document-review?tab=profiles` (`ProfileReviewTab`, `ProfileReviewDetailsPage`)
- Tests: `tests/Feature/ProfileApprovalFlowTest.php`

### Documents

- Citizen upload/submit: `POST /api/applications/{id}/documents`, `POST .../submit-documents`
- Employee review (two APIs): `/api/admin/documents/*` and `/api/dashboard/document-reviews/*` (preview + approve/reject)
- Structured rejection: `DocumentRejectionReason` + migration `2026_07_25_120000_add_structured_rejection_to_application_documents.php`
- Next.js: `DocumentReviewQueuePage`, preview, approve/reject dialogs
- Tests: `DocumentFlowTest.php`, `DashboardDocumentReviewTest.php`, `DocumentReviewerAuthorizationTest.php`

When all required documents are approved, application moves to `payment_pending` (`DocumentReviewService::approve`).

---

# 6. Document upload / storage / security / validation

**Status: Partially Implemented**

| Concern | Status | Evidence |
|---------|--------|----------|
| Private disk (`storage/app/private`) | Implemented | `config/filesystems.php`; `ApplicationDocumentService::upload` uses `Storage::disk('local')->putFileAs('application_documents/{id}', …)` |
| UUID filenames | Implemented | `Str::uuid()` |
| Trusted MIME via Fileinfo | Implemented | `AllowedDocumentMime` + `detectTrustedMimeType()` using `UploadedFile::getMimeType()` — jpg/jpeg/png/pdf only |
| Size/extension from `RequiredDocument` | Implemented | `validateFileAgainstRules` |
| Ownership | Implemented | `findOwnedByCitizen` before upload |
| Replace-in-place (soft-delete previous row for same required doc) | Implemented | upload deletes previous rows; **no version history** |
| Approved documents cannot be replaced | Implemented | `assertApprovedDocumentCannotBeReplaced` |
| Preview allowlist | Implemented | `DashboardDocumentReviewService::PREVIEW_ALLOWED_MIMES` |
| Antivirus / ClamAV | **Not Found** | no scanner in `app/` |
| Public storage of citizen docs | Not used for application docs | private disk |

AI uploads reuse the **same** `ApplicationDocumentService::upload` (see §22).

---

# 7. Payments and failure / duplicate-payment handling

**Status: Implemented** (application fees). Citizen fine checkout **Not Found**.

### Core

- `PaymentLifecycleService::completeVerifiedPayment` — `lockForUpdate`, idempotent if already completed
- Duplicate settlement: if another payment already has `settled_obligation_key` → `UnderVerification` + `PaymentFailureCode::ObligationAlreadySettled`
- Unique keys: `settled_obligation_key`, `active_obligation_key`, `(provider, provider_reference)` — migration `2026_07_27_100000_add_payment_lifecycle_fields_to_payments_table.php`
- Stripe webhook: `StripeWebhookController` verifies signature; `PaymentGatewayEventService::reserve` unique `(provider, event_id)` — duplicate event returns HTTP 200
- Failure codes: `App\Enums\PaymentFailureCode` (11 codes)
- Reconcile: `payments:reconcile-pending` every 30 minutes (`routes/console.php`)
- Mock: `MockPaymentGatewayService`; Stripe: `StripePaymentGatewayService`
- After payment: new_license → `appointment_pending`; other issuable services → `approved` (`ServiceWorkflow::requiresTests`)
- Dashboard: list/verify/attempts/audit; **application payments only** (`whereNull('fine_id')`)

Tests: `PaymentFlowTest`, `PaymentStripeTest`, `PaymentConcurrencyAndIntegrityTest`, `ApplicationFeeUsdCatalogTest`, `DashboardPaymentManagementTest`.

**Do not claim:** citizen can pay fines via checkout; test fees are charged at booking (no booking-fee code in `Appointments`).

---

# 8. Appointments, tests, sequence, retests, no-show

**Status: Implemented** (backend). Next.js **test recording UI is a stub**.

- Bookable statuses: `appointment_pending`, `in_testing`, `waiting_retest`
- Sequence: `TestProgressionService` — required tests ordered by `sequence_order`; prior must be passed
- `AppointmentService::book` locks application + slot (`lockForUpdate`); concurrency tests exist
- Fail / no-show: `TestResultService` — appointment → `no_show` or `completed`; application → `waiting_retest` or `administrative_review` if `max_attempts` reached
- No-show **is implemented** (`handleNoShow` delegates to `handleFailed`)
- Reschedule / cancel: citizen API + Agent actions
- Slot CRUD: dashboard appointment-slots (Next.js **Implemented**)
- Record result API: `POST /api/admin/test-appointments/{id}/record-result` **Implemented**
- Next.js `/dashboard/appointments`: `TestAppointmentsPage` is title/copy only; header queue still links there

Tests: `AppointmentFlowTest`, `AppointmentSlotConcurrencyTest`, `AvailableTestsApiTest`, `AppointmentNotificationTest`, `AppointmentTimezoneTest`.

---

# 9. License issuance, renewal, replacement, other license services

**Status: Partially Implemented** — two parallel citizen paths; unblock application path is effectively dead.

### Application-based (documents + payment + employee issue)

| Service | Create application | Issue |
|---------|--------------------|-------|
| new_license | Implemented | `LicenseService::issueForApplication` |
| renew_license | Implemented | same, successor license |
| lost_replacement / damaged_replacement | Implemented | same |
| license_unblock | Catalog exists | **Not issuable** (`ServiceWorkflow::issuableCodes`) |

Issuance eligibility: `LicenseIssuanceEligibilityService::assertReady` — approved status, fee paid, required docs approved, tests if new_license, no unpaid fines, not already issued.

Employee issue API: `POST /api/admin/applications/{id}/issue-license` (`permission:issue_license`).  
**Next.js has no `issue-license` endpoint and no issue button.** Overview KPI “رخص جاهزة للإصدار” links to `/dashboard/licenses` (already issued).

### Direct citizen shortcuts (bypass application/fee workflow)

- `POST /api/licenses/{id}/renew` → `LicenseService::renew` (citizen, no employee, no fee)
- `POST /api/licenses/{id}/replacement` → `LicenseService::replace`
- `POST /api/licenses/{id}/unblock-request` → `LicenseService::requestUnblock` returns an **acknowledgement payload only**; does not unblock and does not create an application

### Employee block/unblock

`POST /api/admin/licenses/{id}/block|unblock` and dashboard equivalents. Next.js **Implemented** (block/unblock/print).

### `license_unblock` application create — dead eligibility

`LicenseServiceEligibilityService::check()` rejects **Blocked** licenses before the `LicenseUnblock` branch. `checkUnblock()` then requires Blocked. Result: creating a `license_unblock` application cannot succeed either way.

### Other implemented license features

- Public verify: `GET /api/licenses/verify/{token}` + Next.js public page
- Print PDF + QR: `LicensePrintService` (mpdf + endroid/qr-code)
- Expiry sync: `licenses:sync-expired` daily
- History: `LicenseStatusHistory`

**Config inconsistency:** issuance uses `config('license.validity_years', 10)` (`config/license.php`); seeded license types use `validity_years = 5` (`LicenseTypesSeeder`).

Tests: `LicenseFlowTest`, `OtherLicenseServicesFlowTest`, `LicenseExpirySyncTest`, `LicensePrintingTest`, `LicenseVerificationTest`.

---

# 10. Notifications

**Status: Implemented** (in-app). Push **Implemented** behind flags. Appointment reminders **deferred**.

- Types: `App\Enums\NotificationType`
- Coverage matrix: `NotificationEventMatrix` (implemented / deferred / silent / wired_pending_caller)
- Idempotency: unique `event_key` (`2026_08_09_120000_add_event_key_to_notifications_table.php`)
- `afterCommit` so rolled-back domain work does not notify (`NotificationService`)
- Push: `PushDeliveryService` → `SendPushNotificationJob` → `FcmClient`
- Devices: `POST/DELETE /api/devices/push-token`
- Recovery command: `push:dispatch-pending` — **not scheduled** in `routes/console.php`
- Docker worker: supervisor program `queue-push`

**Not implemented:** `appointment.reminder` (explicitly deferred in matrix). Application rejected/cancelled notify wiring without callers.

Tests: 15 notification feature files + 10 push/Firebase files (see §24).

---

# 11. Authentication, authorization, ownership, IDOR

**Status: Implemented** (service ownership + middleware). Laravel Policies **Not Found**.

- Auth: Sanctum personal access tokens
- Citizen OTP: **email** channel (`OtpService`, `OTP_CHANNEL=email` in phpunit). Phone OTP methods marked `@deprecated`
- Ownership: `ApplicationRepository::findOwnedByCitizen`, `LicenseRepository::findOwnedByCitizen`, `AppointmentRepository::findOwnedByCitizen`; Agent actions filtered by `user_id`
- Related license must match `citizen_id` (`ApplicationService` → 403)
- Notification mark-read scoped to user (`NotificationSecurityTest`)
- Push device ownership (`PushDeviceSecurityTest`)
- Employee sessions revoke (`EmployeeSessionSecurityTest`)
- Throttles on auth, payments, uploads, webhooks, Agent confirm

IDOR pattern: unknown/foreign IDs return 404 (not found) rather than leaking existence of others’ resources in most citizen show endpoints.

---

# 12. Backend architecture and separation of concerns

**Status: Implemented**

Pattern: **Module → Controller → FormRequest → Service → (Repository) → Model → Resource**.

Cross-cutting: `ApiException`, `AuditLogService`, `NotificationService`, enums, `bootstrap/app.php` JSON error rendering.

AI Agent is orchestration only: `AgentActionExecutor` calls `ApplicationService`, `ApplicationPaymentService`, `AppointmentService`, etc. — same domain as REST.

**Gaps:** uneven repository usage; dual `/admin` + `/dashboard` surfaces (maintenance cost); empty `chatbot` group.

---

# 13. Database / domain model

**Status: Implemented** — 52 migrations.

Core tables: users/roles/permissions, license_types, service_types, test_types, required_documents, fees, license_applications, application_status_histories, application_documents, licenses, license_status_histories, payments, payment_gateway_events, appointment_slots, appointment_centers, test_appointments, test_results, fines, notifications, audit_logs, otps, employee_sessions, push_devices, push_deliveries, AI agent sessions/messages/actions/evaluations, faqs, contact_messages, jobs, cache.

Drawio ERD exists (`SYRTAK_COMPLETE_ERD.drawio`) — **lower authority than migrations**. Re-export after confirming against current schema.

---

# 14. Flutter / mobile architecture and citizen flows

**Status: Not Found** (app source). Citizen **API** **Implemented**. Flutter contract docs **Documentation Only**.

- 0 `.dart` files, 0 `pubspec.yaml` in `DLMS_Project` and `d:\Projects`
- Contract: `FLUTTER_AI_AGENT.md` and related files; `postman/SYRTAK_Flutter_API.postman_collection.json`
- `docs/PROJECT_MASTER_CONTEXT.md` states Flutter is an external client

**Do not write a Flutter architecture chapter from this repo.** Obtain screenshots and architecture from the actual Flutter project (team confirmation required — §F).

Citizen API flows that **are** implemented: register/OTP/login, profile, catalogs, applications (new/renew/lost/damaged), documents, payments, appointments, test results (read), licenses list/verify, direct renew/replace, unblock-request ack, fines list, notifications, push token, AI Agent.

---

# 15. Dashboard architecture and employee flows

**Status: Partially Implemented**

### Surfaces

| Surface | Location | Role |
|---------|----------|------|
| Next.js employee UI | `d:\Projects\DLMS_Dashboard` | Production staff UI |
| Blade DevDashboard | `resources/views/dev-dashboard/*`, `/dev-dashboard` | Local API tester (`EnsureDevDashboardAllowed`) — **not** the product |

Stack: Next.js 15, React 19, Tailwind, Axios, feature folders, permission guards. **No NextAuth. No frontend test script** (`package.json` has `lint` only). UI is **Arabic RTL only** (`<html lang="ar" dir="rtl">`).

### Next.js vs backend

| Flow | Backend | Next.js |
|------|---------|---------|
| Login / forgot / me | Implemented | Implemented |
| Applications list/details | Implemented | Implemented |
| Document + profile review | Implemented | Implemented |
| Payments list/verify | Implemented | Implemented |
| Appointment **slots** CRUD | Implemented | Implemented |
| Record test result | Implemented (`/admin/test-appointments/{id}/record-result`) | **Stub** (`TestAppointmentsPage`); not in sidebar; header still links `/dashboard/appointments` |
| Issue license | Implemented (`/admin/applications/{id}/issue-license`) | **Not Found** (no endpoint, no button). KPI links to issued-licenses page |
| Block/unblock/print | Implemented | Implemented |
| RBAC / access-control | Implemented | Implemented (super-admin) |
| Reports | Implemented | Implemented |
| Global audit log page | Implemented (`GET /admin/audit-logs`) | **Not Found** (constant only). Per-entity audit UIs exist |
| Contact messages | Implemented | **Not Found** (endpoint only, no sidebar) |
| Fines | Implemented | Implemented |
| Employee sessions | Implemented | Implemented (root super admin) |
| Citizens activate/deactivate | Implemented | Implemented |

---

# 16. Arabic / English localization

**Status: Partially Implemented** (citizen API bilingual; dashboard Arabic-only; Flutter unknown)

Four separate locale systems — do not conflate:

1. HTTP request locale — `ResolveRequestLocale` + `config/localization.php` (`ar`/`en`), `Content-Language` + `Vary: Accept-Language`
2. `users.language` preference
3. AI Agent session locale (`AgentTranslator`, `AgentLanguageDetector`)
4. Dashboard employee strings — `EmployeeMessageTranslator` → **Arabic-fixed** (`ArabicMessageTranslator`)

Files: `resources/lang/ar/messages.php`, `resources/lang/en/messages.php`, validation AR/EN.

Tests: `ArabicLocalizationTest`, `RequestLocaleTest`, `CitizenBilingualMessagesTest`, `CitizenCatalogLocalizationTest`, `AIAgentBilingualHardeningTest`, `NotificationLocalizationTest`, etc.

---

# 17. AI Agent architecture

**Status: Implemented** (backend). Flutter Agent UI **Documentation Only**.

Hybrid pipeline (`AIAgentService`):

```
POST /api/ai-agent/message
  → preprocess + language detect
  → awaiting confirmation? (yes/no or auto-cancel on new workflow)
  → document-flow / pending-workflow (no Gemini)
  → Gemini structured JSON OR intent fallback
  → phrase-matcher overrides
  → profile/duplicate guards
  → persist AIAgentAction; auto-execute read-only
```

Gemini **proposes**; `AgentActionExecutor` **executes** domain services. Document/button paths bypass Gemini.

Routes (`app/Modules/AIAgent/Routes/ai-agent.php`): message, sessions, confirm, cancel, interactions, session document upload. All under `auth:sanctum` + `citizen`.

Models: `AIAgentSession`, `AIAgentMessage`, `AIAgentAction`, `AIAgentEvaluation`.

---

# 18. AI intents / actions currently implemented

**Status: Implemented** (with catalog leftovers that must not be claimed as executable)

### Intents (`AgentIntent`)

Implemented: create new/renew/lost/damaged application, get status/next-step/docs/fee/payment/profile/fines/licenses/tests/slots/appointments/results, submit docs, start payment, book/reschedule/cancel appointment, general_help, out_of_scope, admin_action_denied, unknown.

`clarify_application_selection` exists on the enum; selection is mainly `pending_workflow` + interactions, not a primary phrase intent.

### Executable actions (`AgentActionExecutor::SUPPORTED_ACTION_NAMES`)

Read-only (no confirm): `get_application_status`, `get_application_next_step`, `get_required_documents`, `get_application_fee`, `get_payment_status`, `get_profile_status`, `get_fines`, `get_licenses`, `get_available_tests`, `get_appointment_slots`, `get_current_appointments`, `get_test_results`.

Mutating (confirm): `create_application`, `start_payment`, `submit_documents_for_review`, `book_appointment`, `reschedule_appointment`, `cancel_appointment`.

**Not executable** (appear in maps/docs only): `get_license_details`, `get_notifications`, `renew_license` as an action name (renew is `create_application` + `service_type_code=renew_license`), `request_unblock`. Admin names in `AgentSafetyRules::ADMIN_ONLY_ACTIONS` are blocked.

Interactions (`HandleAgentInteractionRequest`): `choose_agent_document_upload`, `choose_manual_document_upload`, `select_application`, `select_required_document`, `select_appointment_slot`, `select_appointment`, `select_license`, `cancel_document_upload`, `show_required_documents`, `cancel_pending_workflow`, `show_application_choices_again`, `confirm_pending_action`, `cancel_pending_action`.

Fine payment via Agent is explicitly unsupported (`AgentTranslator` `ai_agent.fines.pay_unsupported`).

---

# 19. AI confirmation / cancel flow

**Status: Implemented**

Mutating proposed action → `AIAgentAction` `awaiting_confirmation`. Confirm via:

- `POST /api/ai-agent/actions/{id}/confirm`
- `POST .../interactions` `{ action: confirm_pending_action, action_id }`
- free-text yes (`AgentUserConfirmationDetector`)

Cancel via matching cancel endpoints / free-text no. A **new workflow query while awaiting confirm auto-cancels** the pending action.

Ownership: action `id` + `user_id`. Status must be `awaiting_confirmation`. Already executed/cancelled/failed cannot be confirmed (`assertAwaitingConfirmation`).

**No confirmation nonce column. No action TTL** on `AIAgentAction` (see §21).

Selection tokens: HMAC, TTL default 1800s (`AgentSelectionTokenService`). Upload tokens: random 64 + SHA-256 hash, TTL 600s, single-use. Pending workflow TTL 900s (`config/ai.php`).

---

# 20. AI policy / business-rule enforcement

**Status: Implemented**

| Guard | Class |
|-------|-------|
| Profile approval on propose | `AgentProfileApprovalGuard` |
| Profile on execute | `AgentActionExecutor` + `ProfileService::assertCanUseCitizenServices` |
| Duplicate application | `AgentDuplicateApplicationGuard` |
| Status × action matrix | `AgentApplicationActionPolicy` + `AgentApplicationStatusMap` |
| Admin denial | `AgentPostProcessor` + `AgentSafetyRules` |
| Allow-list | `isAllowedProposedAction` / `isPhase9bExecutable` |
| Argument completeness | `AgentActionArgumentValidator` |
| Citizen-only routes | `citizen` middleware |

---

# 21. Stale-confirmation protection

**Status: Partially Implemented**

On confirm, `AIAgentActionService::assertMutatingActionStillAllowed` reloads the owned application and calls `AgentApplicationActionPolicy::blockReason` for `start_payment`, `book_appointment`, `submit_documents_for_review` (submit also rechecks required-document checklist).

`reschedule_appointment` / `cancel_appointment` skip that recheck (domain `AppointmentService` rechecks ownership/status). **`create_application` is not revalidated** by this method.

Also implemented: expired selection/upload/workflow tokens; stale slot handling in pending workflow (`ai_agent.appointments.slots.stale`).

**Not Found:** confirmation nonce, action-level TTL/expiry, idempotency keys on actions.

Report wording: “confirm re-checks current application status for payment/booking/submit” — **not** “cryptographic confirmation nonce” or “all actions expire after N minutes”.

---

# 22. AI document upload

**Status: Implemented**

`POST /api/ai-agent/sessions/{session}/documents`:

| Mode | Mechanism |
|------|-----------|
| Official token path | `upload_token` → `AgentDocumentFlowService::uploadWithToken` + `AgentUploadTokenService::assertActiveToken` |
| Legacy | `application_id` + `required_document_id` + file → `AgentDocumentUploadService` |

Both call `ApplicationDocumentService::upload` (same MIME/size/ownership rules). Gemini **never** receives file bytes (`AgentContextBuilder` prompt states this). Conversational states: `DocumentFlowState`. Tests: `AIAgentDocumentUploadTest`, `AIAgentConversationalDocumentFlowTest`.

---

# 23. LLM isolation / security

**Status: Implemented** with limits

| Item | Code |
|------|------|
| Provider | Gemini (`config/ai.php`, default `gemini-2.5-flash`) |
| Client | `GeminiAgentClient::generateStructuredResponse` — JSON `responseMimeType`, temperature 0.2 |
| Tool/function calling | **Not Found** |
| Sent to LLM | system instruction, session state JSON (intent/slots/profile flags, **summarized** active applications: id, number, status, codes), last 10 messages text |
| Not sent | file bytes, upload/selection tokens, API keys, raw DB dumps, employee tools |
| Fail closed | Gemini failure → fallback detector; post-processor strips illegal actions |
| Prompt injection | allow-list + admin phrase list; **no** dedicated sanitizer |

Gemini HTTP failures log status/body (`Log::warning`) — possible log leakage of provider error bodies.

---

# 24. Automated tests and categories

**Status: Implemented** (PHPUnit 11). Pest **Not Found**. CI **Not Found**.

- `phpunit.xml`: Unit + Feature; MySQL `dlms_testing`; mock payments; Agent enabled; `OTP_FIXED_CODE=123456`
- **103** `*Test.php` files (98 Feature + 5 Unit)
- **991** `public function test_*` methods (count from repository source on 2026-08-14)

### Categories (by filename; method counts from grep)

| Category | Files (approx.) | `test_*` methods (approx.) |
|----------|----------------:|---------------------------:|
| Dashboard / employees / sessions / RBAC | 28 | 359 |
| AI Agent (Feature 12 + Unit 2) | 14 | 217 |
| Localization / locale | 12 | 99 |
| Notifications | 15 | 89 |
| Push / Firebase / FCM | 10 | 78 |
| Appointments / available tests | 5 | 40 |
| Auth / profile / settings / content | 6 | 40 |
| Payments / fees | 4 | 34 |
| Licenses | 6 | 34 |
| Applications / documents | 3 | 26 |
| Example placeholders | 2 | 2 |

Engineering-quality tests to highlight: `PaymentConcurrencyAndIntegrityTest`, `AppointmentSlotConcurrencyTest`, `NotificationIdempotencyTest`, `NotificationSecurityTest`, `DocumentReviewerAuthorizationTest`, `SuperAdminProtectionTest`, `AIAgentPendingWorkflowReliabilityTest`, `PushDeviceSecurityTest`.

---

# 25. Current test counts

| Figure | Value | Trust |
|--------|-------|--------|
| Test files in repo | **103** | Verified (filesystem) |
| `test_*` methods | **991** | Verified (grep 2026-08-14) |
| Passed / failed / skipped | **needs final run** | No `php artisan test` artifact; no `.github/workflows` |
| `docs/PROJECT_MASTER_CONTEXT.md` snapshot 2026-08-08 | ~209 Agent / ~780 suite | **Stale** vs 991 methods |
| Compact AI doc | 161 Agent / 673 suite | Older milestone |

**Report rule:** publish pass/fail only after a dated `php artisan test` (or CI) run. Until then write “991 automated test methods in 103 files (PHPUnit 11); last green run: _team to fill_”.

---

# 26. API testing / Postman

**Status: Implemented**

Collections in this repo:

1. `postman/SYRTAK_Flutter_API.postman_collection.json` (canonical citizen kit)
2. `postman/SYRTAK_Local.postman_environment.json`
3. `DLMS_API_Postman_Collection.json`
4. `DLMS API - phase 9.postman_collection.json`
5. `DLMS_Dashboard_Admin_Employee_Postman_Collection.json`
6. `DLMS_Dashboard_Appointment_Slots_Postman_Collection.json`
7. `DLMS_Dashboard_Citizens_Postman_Collection.json`
8. `DLMS_Dashboard_Citizen_Applications_Postman_Collection.json`
9. `DLMS_Dashboard_Citizen_Licenses_Fines_Postman_Collection.json`
10. `DLMS_Dashboard_Document_Reviews_Postman_Collection.json`
11. `DLMS_Dashboard_Issued_Licenses_Postman_Collection.json`
12. `DLMS_Dashboard_Service_Fees_Postman_Collection.json`

Guide: `POSTMAN_API_GUIDE.md`. Builders: `postman/_build_syrtak_flutter_collection.py`, `scripts/build_*postman*`.

Prefer the Flutter + Dashboard collections over the older “phase 9” file when screenshotting for the report.

---

# 27. Logging / audit trail

**Status: Implemented** (write-oriented)

- `AuditLog` + `AuditLogService::log` (actor, action, entity, old/new, IP, UA)
- `GET /api/admin/audit-logs` (`permission:view_audit_logs`)
- Domain history: `ApplicationStatusHistory`, `LicenseStatusHistory`
- Per-entity dashboard audit: payments, fees, slots, licenses, employees, citizens, roles
- Agent evaluations: `AIAgentEvaluation`
- Laravel `storage/logs`

Not every read is audited (by design). Next.js has **no global audit-logs page**.

---

# 28. Error / failure handling

**Status: Implemented**

- `ApiException` → JSON `{ success, message, errors }`
- `bootstrap/app.php`: 422 / 401 / 403 / 404 / generic 500 when `!debug`
- Payment under-verification vs failed vs completed
- Push job `failed()` marks delivery failed; retries/backoff
- Stripe invalid signature → 400; unconfigured secret → 503
- Agent confirm failures mark action `failed` and store assistant error reply

---

# 29. Performance / scalability / maintainability / reliability (actually in code)

**Status: Partially Implemented**

| Feature | Evidence |
|---------|----------|
| Queues | `jobs` table; `SendPushNotificationJob`; supervisor `queue:work --queue=push,default` |
| Scheduler | payment reconcile 30m; license expiry daily; employee session reconcile hourly (`routes/console.php`) |
| **Scheduler in Docker** | **Not Found** — `entrypoint.sh` starts supervisord only; no `schedule:run` cron |
| DB locks | payments, appointments, licenses, test results |
| Unique indexes | payment obligations, gateway events, notification `event_key`, push `delivery_key` |
| Throttling | auth, payments, uploads, webhooks, Agent |
| Opcache | Docker PHP image |
| Next.js list UX | debounce, pagination, AbortController, `useTransition`, dynamic charts |
| Redis cluster / horizontal scale playbook | **Not evidenced** as a product requirement |
| `push:dispatch-pending` | command exists, **not scheduled** |
| Weekly session prune | `--dry-run` only |

---

# 30. Docker / deployment / production configuration

**Status: Implemented** (packaging). Ghaymah itself is external.

- `Dockerfile`: PHP 8.4-FPM + nginx + supervisor + `composer install --no-dev`
- `docker-compose.yml`: app + MySQL 8 + phpMyAdmin (**local**)
- `docker/nginx`, `docker/php/php.ini`, `docker/php/entrypoint.sh` (`RUN_MIGRATIONS` / `RUN_SEEDERS` flags)
- `.env.docker.example` (Ghaymah-oriented)
- README documents Ghaymah Cloud Docker + managed MySQL — **no Ghaymah SDK in `app/`**

**Gaps for production claims:** CORS `allowed_origins => ['*']` (`config/cors.php`); Laravel scheduler not in the container; phpMyAdmin in compose is local-only and should not be described as production.

---

# 31. External integrations

| Integration | Status | Evidence |
|-------------|--------|----------|
| Stripe Checkout + webhooks | Implemented | `StripePaymentGatewayService`, `StripeWebhookController`, `stripe/stripe-php` |
| Mock payment gateway | Implemented | `MockPaymentGatewayService`, `PAYMENT_PROVIDER=mock` in tests |
| Firebase FCM | Implemented | `app/Modules/Firebase/*`, `google/auth`, `config/firebase.php` |
| Google Gemini | Implemented | `GeminiAgentClient`, `config/ai.php` |
| SMTP email OTP | Implemented | `OtpMail`, `OtpService::sendEmailOtp` |
| OpenAI | **Not Found** | |
| SMS OTP gateway | **Not Found** (phone methods deprecated) | |
| ClamAV / virus scan | **Not Found** | |
| Ghaymah | Documentation + Docker only | |
| RAG / vector DB | **Not Found** | |

---

# 32. Features that look advanced for a university project

These are **in code**, not just slides:

1. Hybrid AI Agent with allow-listed execution and confirmation (not a chatbot wrapping the LLM)
2. Payment obligation uniqueness + Stripe webhook idempotency + concurrency tests
3. Custom RBAC with direct permissions, SoD roles, and `rbac:audit`
4. Test sequence machine + retest + no-show + max-attempts → administrative review
5. License issuance eligibility as a single service used by issue + reports “ready” counts
6. Notification `event_key` idempotency + `afterCommit` + FCM retry pipeline
7. Employee session tracking / remote revoke (root super admin)
8. Public license verification token + printable PDF with QR
9. Bilingual citizen API with request-locale isolation (restore default locale after request)
10. Appointment slot locking under concurrent booking
11. AI upload tokens (hashed, TTL, single-use) so Gemini never sees files
12. Large PHPUnit suite covering security (IDOR, permissions) not only happy paths

---

# 33. Important weaknesses / gaps before the committee

**Should fix or openly declare as limitations:**

1. Next.js cannot **issue licenses** or **record test results** (backend can; UI cannot complete the employee happy path)
2. Dual citizen renew/replace paths (application+fee vs instant API) — confusing to explain; pick one as “official”
3. `license_unblock` application create is eligibility-dead; citizen unblock-request is ack-only
4. No central application status FSM (`transitionStatus` ungated)
5. `rejected` / `cancelled` statuses unused in production
6. Citizen fine payment not implemented
7. Flutter source not in the audited trees — cannot demonstrate mobile without the other repo
8. Dashboard Arabic-only; no FE tests; no CI
9. CORS `*`
10. Docker image does not run `schedule:run` (reconcile/expiry depend on an external cron)
11. License validity 10 years (config) vs seeded types 5 years
12. README overclaims (phone OTP, pay fine fees, mock-only payments)
13. No antivirus on uploads
14. Stale Flutter Agent docs (`confirm_agent_upload` vs `choose_agent_document_upload`)

---

# 34. Claims we must NOT make

1. Flutter screens / architecture / l10n exist **in this repository**
2. “N tests passed” without a **fresh** `php artisan test` / CI log
3. CI/CD is set up (no GitHub Actions)
4. Pest is the test framework
5. Employees can record tests or issue licenses **in the Next.js UI today**
6. Dashboard is bilingual
7. Citizens / Agent can pay fines
8. `license_unblock` applications can be created
9. Unblock-request unblocks a license
10. Phone/SMS OTP is the live auth path (email OTP is)
11. Chatbot product / RAG / vector DB / OpenAI
12. All five services require driving tests (only `new_license`)
13. Live bank gateway beyond configured Stripe
14. DevDashboard is the production employee portal
15. `/admin` is obsolete (both admin and dashboard APIs are live)
16. Confirmation nonce / action TTL for the Agent
17. Antivirus scanning of uploads
18. Horizontal scaling / Redis as a delivered NFR (config exists; not a specialized implementation)
19. Appointment reminder notifications
20. Ghaymah as an in-app integration (deploy target only)

README lines that conflict with code (do not copy): “Verify phone number using mock OTP”; “Pay … fine fees”; “Mock Services” as if Stripe were absent.

---

# 35. Recommended screenshots / diagrams / evidence

See **§E** checklist. Existing drawio files (`SYRTAK_COMPLETE_ERD.drawio`, `SYRTAK_COMPLETE_SEQUENCE_DIAGRAMS.drawio`, `SYRTAK_COMPLETE_ACTIVITY_DIAGRAMS.drawio`) are useful **after** a pass against current enums/routes. Treat them as draft diagrams, not proof.

---

# Feature / engineering matrix

| Feature / Engineering Point | Status | What is implemented | Evidence (exact files/classes/routes/tests) | Why it matters to committee | Recommended report section | Screenshot/Diagram/Test evidence needed | Priority |
|-----------------------------|--------|---------------------|---------------------------------------------|-----------------------------|----------------------------|------------------------------------------|----------|
| Modular Laravel backend | Implemented | 18 modules, controller/service/request/resource split | `app/Modules/*`, `routes/api.php` | Shows SE layering, not a single `Controller.php` | Architecture | Module diagram from `docs/PROJECT_MASTER_CONTEXT.md` updated to 18 modules | MUST HIGHLIGHT |
| Citizen vs employee API split | Implemented | Sanctum + `EnsureCitizen` / `EnsureDashboardUser` | `bootstrap/app.php`, `EnsureCitizen.php`, `EnsureDashboardUser.php` | Separation of duties at the HTTP boundary | Security / Actors | Sequence: citizen token cannot call `/dashboard` | MUST HIGHLIGHT |
| Custom RBAC + direct permissions | Implemented | Registry, role+user pivots, SoD roles, audit command | `config/dashboard_permissions.php`, `User::hasPermission`, `RbacAuditCommand`, `DashboardAccessControlTest` | Realistic public-sector access control | Security / RBAC | Screenshot: access-control UI + reviewer role denied applications | MUST HIGHLIGHT |
| Profile gate | Implemented | Incomplete → pending_review → approved/rejected; middleware blocks services | `ProfileStatus`, `EnsureProfileApproved`, `ProfileReviewService`, `ProfileApprovalFlowTest` | Identity proofing before services | Citizen lifecycle | Screenshot: pending profile vs approved | IMPORTANT |
| Application status machine | Partially Implemented | 13 statuses + history + notifications; **no edge guard** | `ApplicationStatus`, `ApplicationRepository::transitionStatus`, `ApplicationStatusHistory` | Core workflow; honesty about missing FSM | Domain model | Activity diagram of **implemented** transitions only | MUST HIGHLIGHT |
| Document review workflow | Implemented | Upload → submit → per-doc approve/reject → payment_pending | `ApplicationDocumentService`, `DocumentReviewService`, `DashboardDocumentReviewController`, `DocumentFlowTest` | Classic government back-office | Employee flows | Screenshot: preview + structured rejection | MUST HIGHLIGHT |
| Private document storage + MIME | Partially Implemented | Private disk, UUID, Fileinfo MIME, size rules; no AV | `AllowedDocumentMime`, `ApplicationDocumentService::upload` | Security of PII documents | Security | Architecture note: private disk path | IMPORTANT |
| Stripe + mock payments + duplicates | Implemented | Obligation keys, webhook reserve, under_verification, reconcile | `PaymentLifecycleService`, `StripeWebhookController`, `PaymentGatewayEventService`, `PaymentConcurrencyAndIntegrityTest` | Rare in graduation projects | Payments | Sequence: webhook duplicate → 200; concurrent pay test output | MUST HIGHLIGHT |
| Test sequence / retest / no-show | Implemented | Sequence order, max attempts → administrative_review, no-show | `TestProgressionService`, `TestResultService::handleFailed/handleNoShow`, `AvailableTestsApiTest` | Domain complexity | Tests & appointments | Diagram: vision→theory→practical | MUST HIGHLIGHT |
| Appointment slot locking | Implemented | `lockForUpdate` on application+slot | `AppointmentService`, `AppointmentSlotConcurrencyTest` | Concurrency / integrity | Reliability | Cite test name in report | IMPORTANT |
| License issuance eligibility | Implemented | Single service: docs, fee, tests, fines, status | `LicenseIssuanceEligibilityService`, `LicenseService::issueForApplication` | Centralized business rule | License services | Show `assertReady` checklist in report | MUST HIGHLIGHT |
| Dual renew/replace paths | Partially Implemented | Application workflow **and** `POST /licenses/{id}/renew\|replacement` | `LicenseService::renew/replace`, `OtherLicenseServicesFlowTest`, `LicenseFlowTest` | Must not present as one design | License services | Table of two paths; recommend “official” path | IMPORTANT |
| license_unblock via application | Not Found (dead) | Eligibility rejects blocked **and** requires blocked | `LicenseServiceEligibilityService::check` vs `checkUnblock` | Do not claim unblock-as-service | Limitations | — | DO NOT CLAIM |
| Citizen unblock-request | Partially Implemented | Ack JSON only | `LicenseService::requestUnblock`, `LicenseFlowTest` | Honest limitation | Limitations | — | DO NOT CLAIM |
| Employee block/unblock | Implemented | Policy + history + notify | `LicenseTransitionPolicy`, admin/dashboard routes, Next.js licenses pages | Administrative control | License services | Screenshot: block reason + history | IMPORTANT |
| Public verify + PDF/QR print | Implemented | Token verify + mpdf QR | `LicenseVerificationController`, `LicensePrintService`, Next.js `/licenses/verify/[token]` | Distinctive citizen-facing artifact | License services | Photo of printed PDF + public verify page | MUST HIGHLIGHT |
| Fines | Partially Implemented | Admin create/update (mark paid); citizen list; no checkout | `FineService`, `FineController`, `FineManagementController` | Blocking issuance is real; payment is office-only | Fines | Screenshot: admin mark paid | SUPPORTING |
| In-app notifications + idempotency | Implemented | Matrix, event_key, afterCommit | `NotificationService`, `NotificationEventMatrix`, `NotificationIdempotencyTest` | Production-minded eventing | Notifications | Matrix excerpt in appendix | MUST HIGHLIGHT |
| FCM push pipeline | Implemented | Devices, deliveries, job retries, supervisor | `SendPushNotificationJob`, `FcmClient`, `docker/supervisor/supervisord.conf`, `PushProductionCertificationTest` | Real mobile integration (if Flutter uses it) | Notifications | Need Flutter notification screenshot from team | IMPORTANT |
| Appointment reminders | Documentation / deferred | Matrix marks deferred | `NotificationEventMatrix` `appointment.reminder` | Do not claim | Limitations | — | DO NOT CLAIM |
| IDOR / ownership | Implemented | findOwnedByCitizen pattern | repositories + `NotificationSecurityTest`, `PushDeviceSecurityTest` | Security engineering | Security | Cite tests | MUST HIGHLIGHT |
| Laravel Policies | Not Found | 0 files in `app/Policies` | — | Do not claim “Laravel Policies” | Limitations | — | DO NOT CLAIM |
| Bilingual citizen API | Implemented | ar/en messages, locale middleware | `resources/lang/{ar,en}/messages.php`, `ResolveRequestLocale`, localization tests | NFR localization | Localization | Same API call AR vs EN screenshots | MUST HIGHLIGHT |
| Dashboard i18n | Partially Implemented | Arabic RTL only | `DLMS_Dashboard/src/app/layout.tsx` `lang="ar" dir="rtl"`, `EmployeeMessageTranslator` | Honest NFR | Localization | — | DO NOT CLAIM (as bilingual dashboard) |
| AI hybrid architecture | Implemented | Gemini JSON propose + domain execute | `AIAgentService`, `GeminiAgentClient`, `AgentActionExecutor`, `AgentSafetyRules` | Strongest differentiator | AI Agent | Architecture diagram: LLM vs backend | MUST HIGHLIGHT |
| AI confirm/cancel | Implemented | HTTP + chat yes/no + auto-cancel | `AIAgentActionService`, `ai-agent.php` routes, `AIAgentActionExecutionTest` | Safety | AI Agent | Screenshot of pending_action + confirm | MUST HIGHLIGHT |
| AI stale confirm | Partially Implemented | Policy recheck for pay/book/submit; no nonce/TTL | `assertMutatingActionStillAllowed` | Precise wording | AI Agent | — | IMPORTANT |
| AI document upload tokens | Implemented | Hash + TTL + single-use; same domain upload | `AgentUploadTokenService`, `AIAgentDocumentUploadTest` | Isolation | AI Agent | Sequence: token issue → upload → consume | MUST HIGHLIGHT |
| LLM isolation | Implemented | Summarized context; no files; allow-list | `AgentContextBuilder`, `GeminiAgentClient` | Security of AI | AI Agent | Prompt excerpt (sanitized) in appendix | MUST HIGHLIGHT |
| AI Flutter UI | Documentation Only | Markdown contracts only | `FLUTTER_AI_AGENT*.md`; 0 `.dart` files | Cannot claim mobile Agent UX from this repo | AI Agent | Need Flutter screenshots from team | DO NOT CLAIM (until screenshots) |
| Next.js employee UI | Partially Implemented | Strong review/RBAC/reports; stub tests; no issue-license | `DLMS_Dashboard/src/features/*`, `TestAppointmentsPage.tsx`, `endpoints.ts` | Show real UI; admit two gaps | Dashboard | Screenshots of implemented pages only | MUST HIGHLIGHT |
| Issue license in dashboard UI | Not Found | Backend only | `ApplicationLicenseController`; no Next endpoint | Critical demo gap | Gaps | Implement **or** demo via Postman/DevDashboard | IMPORTANT |
| Record test result in dashboard UI | Partially Implemented | API yes; UI stub; header links to stub | `TestAppointmentResultController`; `TestAppointmentsPage.tsx` | Same | Gaps | Implement **or** demo via Postman | IMPORTANT |
| Employee sessions | Implemented | Heartbeat, revoke, root_super_admin | `dashboard.php` session routes, `EmployeeSession*Test` | Ops security | Dashboard | Screenshot: session list + revoke | IMPORTANT |
| Reports | Implemented | Domain reports + permission-scoped | `DashboardReportController`, `DashboardReportsTest`, Next.js `ReportsPage` | Management layer | Dashboard | Screenshot: reports tabs | IMPORTANT |
| Audit logs | Partially Implemented | Backend global + per-entity; no global UI page | `AuditLogController`, entity audit routes | Accountability | Audit | Screenshot entity audit + Postman global list | SUPPORTING |
| DevDashboard | Implemented | Local/staging tester | `routes/web.php`, `EnsureDevDashboardAllowed`, `DevDashboardTest` | Dev tooling, not production | Appendix | Optional screenshot labelled “developer tool” | APPENDIX |
| Docker packaging | Implemented | PHP 8.4 + nginx + supervisor + MySQL compose | `Dockerfile`, `docker-compose.yml`, `.env.docker.example` | Deployability | Deployment | Screenshot: `docker compose ps` | SUPPORTING |
| Laravel scheduler in Docker | Not Found | Commands exist; container has no cron | `routes/console.php` vs `entrypoint.sh` | Do not claim unattended expiry/reconcile in the image | Deployment | Document external cron if Ghaymah provides it | DO NOT CLAIM (in-container) |
| CORS lockdown | Not Found | `allowed_origins: ['*']` | `config/cors.php` | Production hardening gap | Limitations | — | DO NOT CLAIM |
| CI/CD | Not Found | No `.github/workflows` | — | Do not claim pipelines | Limitations | Generate a test-run log instead | DO NOT CLAIM |
| PHPUnit suite size | Implemented (structure) | 103 files / 991 methods | `tests/`, `phpunit.xml` | SE quality | Testing | **needs final run** for pass count | MUST HIGHLIGHT |
| Postman collections | Implemented | 11+ collections | listed in §26 | API completeness | Testing | Import + run one citizen flow screenshot | SUPPORTING |
| Email OTP | Implemented | Hashed OTP, mail, fixed code in tests | `OtpService`, `OtpMail`, `PasswordResetFlowTest` | Auth | Security | Screenshot: OTP email template | SUPPORTING |
| Phone SMS OTP | Not Found | Deprecated phone helpers only | `OtpService::cleanupPendingPhoneOtps` `@deprecated` | README is wrong | Limitations | — | DO NOT CLAIM |
| Empty chatbot group | Not Found (product) | Empty route prefix | `routes/api.php` | Do not call it a chatbot product | Limitations | — | DO NOT CLAIM |

---

# A. TOP 15 strongest engineering achievements in SYRTAK

1. **Hybrid AI Agent** — Gemini proposes JSON; backend executes allow-listed domain services after confirmation (`AIAgentService`, `AgentActionExecutor`, `AgentSafetyRules`).
2. **Payment integrity** — obligation uniqueness, webhook event reserve, `ObligationAlreadySettled` → under_verification, concurrency tests.
3. **Custom RBAC with SoD** — permission registry, direct permissions, specialized roles (`profile_document_reviewer` cannot manage applications).
4. **License issuance eligibility** as one service used by issue API and “ready for issuance” counts.
5. **Test progression** — ordered tests, retest, no-show, max attempts → `administrative_review`.
6. **Notification idempotency** — `event_key` + `afterCommit` + explicit coverage matrix.
7. **FCM delivery pipeline** — device tokens, queued job, retries, supervisor worker, production-oriented tests.
8. **IDOR-oriented ownership** — `findOwnedByCitizen` on applications, licenses, appointments; Agent actions scoped by `user_id`.
9. **Bilingual citizen API** with per-request locale isolation (`ResolveRequestLocale` restores default locale).
10. **Appointment slot locking** under concurrent booking (`AppointmentSlotConcurrencyTest`).
11. **AI document upload tokens** — hashed, TTL, single-use; files never sent to Gemini.
12. **Public license verify + printable QR PDF** (`LicensePrintService`, `LicenseVerificationController`).
13. **Employee session revoke** restricted to root super admin.
14. **Status history + audit logs** on sensitive writes (`application.status_changed`, payment, document, license).
15. **Large PHPUnit suite** covering permissions, concurrency, Agent, push, and locale — not only CRUD happy paths (991 methods; pass count needs final run).

---

# B. TOP 10 differentiators versus a normal CRUD graduation project

1. LLM is **not** the business engine; it is an NLU front-end with a confirmation gate.
2. Payments model **duplicate settlement** and webhook **idempotency**, not “mark paid”.
3. RBAC is a **registry + SoD roles**, not a single `is_admin` flag.
4. New-license tests are a **sequence machine**, not a boolean “passed exam”.
5. Notifications are **deduplicated business events**, not `Notification::create` on every click.
6. Documents go to a **private disk** with Fileinfo MIME checks.
7. Citizen and employee APIs are **hard-separated** by middleware.
8. Issuance is gated by a **checklist service** (docs, fee, tests, fines).
9. Concurrency is tested (`lockForUpdate` + dedicated tests).
10. Localization is a **request pipeline** (AR/EN) with tests against hardcoded strings.

---

# C. TOP risks / gaps before final submission

| # | Risk | Why it hurts in committee | Mitigation |
|---|------|---------------------------|------------|
| 1 | Next.js cannot issue licenses or record tests | Live demo of full employee path fails | Add UI **or** script a Postman/DevDashboard demo and say so in the report |
| 2 | Flutter not in audited trees | Cannot show citizen UX | Attach Flutter repo path + screenshots (§F) |
| 3 | Dual renew/replace designs | Examiner asks “which is real?” | Declare application workflow as official; mark direct APIs as legacy shortcuts |
| 4 | Unblock story is incomplete | Service catalog shows a service that cannot be applied | Remove from “implemented services” slides |
| 5 | No FSM on `transitionStatus` | Examiner: “can you jump to license_issued?” | Honest limitation; optional pre-committee guard |
| 6 | README vs code (OTP, fines, mock-only) | Easy gotcha question | Align report with this audit, not README |
| 7 | No CI / no saved test run | “How do we know 991 tests pass?” | Run `php artisan test`, archive log with date |
| 8 | Docker has no scheduler | Expiry/reconcile may not run in deploy | Document cron on Ghaymah or add to image |
| 9 | CORS `*` | Production-security question | State as known limitation |
| 10 | Stale Agent Flutter docs | Integration bugs in demo | Freeze `FLUTTER_AI_AGENT.md` to `HandleAgentInteractionRequest` actions |

---

# D. Missing evidence we need to generate before writing the report

1. Dated `php artisan test` output (pass/fail/skipped).
2. Flutter: repo location, architecture diagram, AR/EN screenshots of register → profile → application → documents → pay → book → Agent chat.
3. Next.js screenshots of: login, RBAC, document review+preview, profile review, payments verify, reports, issued license print/QR, **and an explicit note** that issue-license and record-result are API-only today (unless fixed).
4. Stripe test-mode checkout screenshot (or mock confirm) + webhook event in `payment_gateway_events`.
5. Public license verify page with QR.
6. OTP email screenshot (SYRTAK template).
7. One concurrency test passing in the log (`PaymentConcurrencyAndIntegrityTest` or `AppointmentSlotConcurrencyTest`).
8. Agent: pending confirmation card + document upload token flow (Flutter or Postman).
9. Updated activity/sequence diagrams matching `ApplicationStatus` + `ServiceWorkflow` (tests only for `new_license`).
10. Confirmation from the team which renew path is “official”.

---

# E. Suggested final-report evidence checklist

- [ ] System context diagram: Flutter + Next.js + Laravel + Stripe + Gemini + FCM
- [ ] Module diagram (`app/Modules`)
- [ ] ERD exported from **current** migrations (not unverified drawio)
- [ ] Application lifecycle activity diagram (implemented edges only)
- [ ] New-license sequence: profile → docs → pay → tests → issue
- [ ] Renew sequence: **one** official path
- [ ] RBAC matrix (roles × permissions) from `config/dashboard_permissions.php`
- [ ] Payment sequence: checkout → webhook → obligation key
- [ ] AI Agent sequence: message → propose → confirm → `AgentActionExecutor` → domain service
- [ ] AI isolation diagram: what is/isn’t sent to Gemini
- [ ] Screenshot set: dashboard review, reports, license PDF, citizen API locale AR vs EN
- [ ] Test evidence: command line of PHPUnit summary + named concurrency/security tests
- [ ] Postman: one full citizen flow + one dashboard review flow
- [ ] Limitations page: fines pay, unblock application, FSM, Flutter-not-in-this-repo, dashboard gaps, no CI
- [ ] Do-not-claim list (copy §34)

---

# F. Facts that need manual confirmation from the team

1. **Where is the Flutter repo?** It is not under `d:\Projects` as of this audit.
2. Which Flutter state management (Riverpod/Bloc/etc.) and whether Agent chat uses `ui_payload.buttons` as documented?
3. Is FCM actually enabled in the deployed environment (`FIREBASE_PUSH_ENABLED`)?
4. Is production payment `stripe` or `mock`?
5. Does Ghaymah run `php artisan schedule:run` (reconcile, expiry)?
6. Which renew/replace path should the report call official?
7. Will issue-license and record-result be added to Next.js before the defense?
8. Last green PHPUnit run date and counts (this audit cannot invent pass numbers).
9. Are drawio diagrams already updated to Phase 2.6+ or still older?
10. Committee demo script: live Stripe vs mock; live Gemini vs recorded Agent session.

---

## Appendix — quick file index

| Topic | Path |
|-------|------|
| Citizen routes | `routes/api.php` |
| Dashboard routes | `app/Modules/Dashboard/Routes/dashboard.php` |
| Admin routes | `app/Modules/Admin/Routes/admin.php` |
| Agent routes | `app/Modules/AIAgent/Routes/ai-agent.php` |
| Scheduler | `routes/console.php` |
| Permission registry | `config/dashboard_permissions.php` |
| Service workflow | `app/Modules/Applications/Support/ServiceWorkflow.php` |
| Master context (internal) | `docs/PROJECT_MASTER_CONTEXT.md` |
| Next.js UI | `d:\Projects\DLMS_Dashboard` (sibling) |

---

*End of audit. No application code was modified. Status of every major claim is tied to files listed above.*
