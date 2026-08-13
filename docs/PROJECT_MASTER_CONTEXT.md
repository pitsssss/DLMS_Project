# SYRTAK / DLMS — Project Master Context

**Audience:** a new Cursor coding agent with no prior chat history.  
**System:** Digital License Management System (DLMS), product name **SYRTAK**.  
**This repository:** Laravel 11 REST API backend only. Flutter (citizen) and Next.js dashboard (employees) are **external clients**, not source in this repo.

### Source precedence (always)

1. **Current implementation / code** (`app/`, `routes/`, `config/`, migrations)
2. **Automated tests** (`tests/`)
3. **This file** (`docs/PROJECT_MASTER_CONTEXT.md`)
4. **Current maintained documentation** (e.g. `FLUTTER_AI_AGENT.md` after verifying against code; live FormRequests/enums)
5. **Old/stale documentation and historical chat** (`README.md`, `DLMS_AI_AGENT_CONTEXT_COMPACT.md`, older SRS Agent sections, prior Cursor chats)

When any lower source conflicts with a higher one, the higher source wins. Do not “fix” production behavior to match stale docs or chat. Update this file after intentional architecture changes.

**Last known full-suite snapshot (2026-08-08, Phase 2.6.1):** ~209 AI Agent tests, ~780 full suite, 7 AI Agent HTTP routes. Re-run before claiming current counts.

**Also stale vs code (do not copy blindly):** `FLUTTER_AI_AGENT.md` still mentions Phase 2.2 aliases `confirm_agent_upload` / `choose_manual_upload`. Implemented interaction actions are `choose_agent_document_upload` / `choose_manual_document_upload` (`HandleAgentInteractionRequest`). `GET /api/ping` still reports `'phase' => 9` — leftover, not a product version.

---

## 1. Project vision and scope

SYRTAK/DLMS digitizes government-style driving-license services:

- Citizen onboarding (register, email OTP, profile review)
- License applications (new, renew, lost replacement, damaged replacement, unblock as a service code)
- Document upload + employee review
- Fees and payments (mock or Stripe)
- Test appointments (vision → theory → practical) for **new licenses only**
- License issuance, block/unblock, public verify, print
- Fines (list + admin manage; **no citizen fine checkout**)
- In-app notifications + optional Firebase push
- Employee dashboard RBAC, reports, sessions
- Citizen **AI Agent** (Gemini + deterministic workflows) that calls the **same domain services** as the REST APIs

**Out of this repo:** Flutter UI, Next.js dashboard UI, RAG/vector DB, generic `/chatbot` product, citizen fine payment, live application `rejected`/`cancelled` transitions.

---

## 2. System architecture

```text
Flutter (citizen)  ──HTTPS JSON──►  Laravel API  (/api/*)
Next.js Dashboard  ──HTTPS JSON──►  Laravel API  (/api/dashboard/*, /api/admin/*)

Laravel modules (app/Modules/*)
  ├─ Auth / Profile
  ├─ Applications / Documents
  ├─ Payments (mock | Stripe + webhook)
  ├─ Appointments / Tests
  ├─ Licenses / Fines
  ├─ Notifications + Push (FCM job)
  ├─ Dashboard / Admin / RBAC / Reports
  └─ AIAgent (orchestration only → domain services)

External:
  Gemini (Agent NLU) | Stripe | Firebase FCM | SMTP (OTP) | private disk (docs)
```

**Responsibility split**

| Party | Owns |
|-------|------|
| Flutter | UX, FCM token, Agent chat UI, never parse `reply` for workflow |
| Dashboard | Review queues, issuance, RBAC, reports |
| Backend | AuthZ, state machine, persistence, notifications, Agent execution |
| AI Agent | Intent, slots, confirmation, **then** existing domain services |

There is **one** business backend. Manual REST and Agent must converge on the same services and status machine.

---

## 3. Technology stack

| Layer | Choice |
|-------|--------|
| PHP | ^8.2 |
| Framework | Laravel 11 |
| Auth | Laravel Sanctum personal access tokens |
| DB | MySQL in tests (`dlms_testing`); sqlite default in `.env.example` |
| Queue | `database` in prod config; `sync` in phpunit |
| PDF / QR | mpdf, endroid/qr-code |
| Payments | stripe/stripe-php (optional) |
| LLM | Gemini REST (`google/auth` for FCM, not Gemini SDK) |
| Tests | PHPUnit 11 |
| Frontend in repo | Vite only for Laravel assets / dev dashboard, **not** the product apps |

---

## 4. Repository / folder structure

```text
app/
  Console/Commands/      # payment reconcile, license expiry, employee sessions, FCM, RBAC
  Enums/                 # ApplicationStatus, ServiceCode, DocumentStatus, …
  Exceptions/ApiException.php
  Http/Middleware/       # citizen, dashboard, profile.approved, permission, locale, …
  Mail/OtpMail.php
  Models/                # Eloquent entities (shared; Agent models are in Modules)
  Modules/               # Feature modules (controllers, services, routes, requests)
  Jobs/SendPushNotificationJob.php
  Support/               # CitizenMessageTranslator, CitizenCatalogLabel, RequestLocaleResolver, Msg
  Traits/ApiResponse.php
bootstrap/app.php        # middleware aliases + API exception rendering
config/                  # ai.php, firebase.php, dashboard_permissions.php, localization.php, license.php, dlms.php, …
database/migrations|seeders|factories
resources/lang/{ar,en}/messages.php   # citizen + catalog strings (authoritative for API locale)
resources/views/emails/              # SYRTAK-branded OTP HTML
routes/api.php           # citizen + includes module route files
routes/web.php           # welcome + /dev-dashboard (local/staging/testing only)
routes/console.php       # Laravel scheduler
tests/Feature|Unit
docs/                    # this file
FLUTTER_AI_AGENT*.md     # Agent Flutter contract (frozen Phase 2.6.1)
TESTING_LOCALIZATION.md
SYRTAK_FINAL_SRS_SOURCE_OF_TRUTH.md
SYRTAK_COMPLETE_*.drawio # ERD / activity / sequence (lower authority than code)
DLMS_API_Postman_Collection.json
```

**Modules under `app/Modules/`:** `AIAgent`, `Admin`, `Applications`, `Appointments`, `AuditLogs`, `Auth`, `Content`, `Dashboard`, `Devices`, `Fines`, `Firebase`, `Licenses`, `Notifications`, `Payments`, `Push`, `Reports`, `Settings`, `Tests`.

---

## 5. Backend architecture

- **Modular Laravel**, not a monolith of fat controllers.
- Controllers: thin; FormRequests validate HTTP; **Services** own business rules; Repositories used where transactions/history matter (`ApplicationRepository`).
- Shared Eloquent models live in `app/Models`, not inside modules.
- API envelope via `App\Traits\ApiResponse`:

```json
{ "success": true, "message": "...", "data": {} }
```

Errors: `{ success: false, message, errors }` plus optional `code`/`data` from `ApiException`.

- Citizen messages: `CitizenMessageTranslator`. Dashboard: `EmployeeMessageTranslator`.
- No Laravel Gates. Authorization is middleware + `User::hasPermission` + ownership checks in services.
- `EnsurePermission` is **OR**: any listed permission grants access (`permission:view_payments,manage_payments`).
- Effective permissions = **role permissions UNION direct grants** (`users` ↔ `permissions` via `directPermissions()`). `isSuperAdmin()` returns `['*']` and bypasses checks.
- API exception envelope is centralized in `bootstrap/app.php` (422/401/403/404/500). `APP_DEBUG=true` lets Laravel’s default handler leak traces for uncaught throwables; production hides them.
- `ServiceWorkflow` (`app/Modules/Applications/Support/ServiceWorkflow.php`) is **fail-closed** for unknown service codes.
- Scheduler lives in `routes/console.php` (not Kernel).

---

## 6. Flutter / mobile architecture

**Not in this repository.** Flutter consumes `/api/*`.

For the AI Agent, the frozen contract is `FLUTTER_AI_AGENT.md`:

- Operational: `POST /message`, `POST /sessions/{id}/interactions`, `POST /sessions/{id}/documents`
- Optional: session GET list/show
- Deprecated wrappers: `/actions/{id}/confirm|cancel`
- Drive UI from `message_type`, `ui_payload`, `requires_confirmation`, `language` — **never parse `reply`**
- Never send trusted raw entity IDs when a `selection_token` exists

Related: `FLUTTER_AI_AGENT_DOCUMENT_FLOW.md`, `FLUTTER_AI_AGENT_APPLICATION_SELECTION.md`, `FLUTTER_AI_AGENT_APPOINTMENT_FLOW.md`. Prefer `FLUTTER_AI_AGENT.md` if they disagree.

---

## 7. Dashboard architecture

**Not in this repository** as a Next.js app. Backend surface:

- `/api/dashboard/auth/*` — employee login, forgot/reset, me, logout, change-password (`DashboardAuthService`)
- `/api/dashboard/*` — overview, reports, citizens, employees, apps, payments, slots, fees, catalogs, issued licenses, document reviews, roles, access-control, employee-sessions, contact-messages
- `/api/admin/*` — older/narrower employee APIs that **still exist alongside** dashboard routes (do not delete one thinking it is unused)

**Dual surfaces (same domain, different URLs — both live):**

| Capability | Admin | Dashboard |
|------------|-------|-----------|
| Document review | `/api/admin/documents/*` | `/api/dashboard/document-reviews/*` (includes file **preview**) |
| License block/unblock | `/api/admin/licenses/{id}/block\|unblock` | `/api/dashboard/licenses/{id}/block\|unblock` |
| Reports overview | `/api/admin/reports/overview` | `/api/dashboard/reports/*` (richer, permission-scoped) |
| Fines manage | `/api/admin/fines` | (dashboard has reports, not full fine CRUD) |

Dashboard application **show** looks up by `application_number` (e.g. `APP-2026-…`), **not** internal numeric id (`DashboardApplicationController`).

Middleware stack: `auth:sanctum` + `dashboard` + `employee.session.track`, then `permission:…` or `super_admin` / `root_super_admin`.

Employee sessions (`EmployeeSession`, UUID id): created on dashboard login; `POST /dashboard/session/heartbeat`; last-seen throttled by `EMPLOYEE_SESSION_*`. Root super admin (`role super_admin` only) lists/revokes. Scheduler: `ReconcileEmployeeSessionsCommand` hourly; `PruneEmployeeSessionsCommand` weekly dry-run.

Access-control CRUD (`/dashboard/access-control/*`) is `super_admin` middleware (role `super_admin` **or** `admin`), not `manage_roles` alone. Role archive/restore exists. Direct per-employee permissions: `PATCH /dashboard/employees/{id}/direct-permissions`.

Dashboard copy is largely **Arabic-fixed** via `EmployeeMessageTranslator` / `Msg` → `ArabicMessageTranslator`. Do not assume dashboard JSON `message` follows `Accept-Language`.

---

## 8. Domain model and important entities

| Model | Table / role |
|-------|----------------|
| `User` | Citizens and employees; `user_type`, profile fields, `language`, `theme`, `phone` (column exists, OTP unused), `profile_status`, `is_active` |
| `Role` / `Permission` | Custom RBAC; users also have `directPermissions()` |
| `LicenseApplication` | Service request; status machine; `related_license_id` for non-new services |
| `ApplicationStatusHistory` | Status audit trail |
| `RequiredDocument` / `ApplicationDocument` | Catalog + uploaded files (soft-deletes on documents) |
| `Fee` | Priced by `identity_key` (code + license/service/test scope); `version` |
| `Payment` / `PaymentGatewayEvent` | Checkout + webhook idempotency |
| `Fine` | Citizen fines (`unpaid`/`paid`/`cancelled`); **admin can mark paid** |
| `AppointmentCenter` / `AppointmentSlot` / `TestAppointment` | Scheduling + capacity (`SlotIdentity`) |
| `TestType` / `TestResult` | Ordered exams |
| `License` / `LicenseStatusHistory` | Issued licenses; stored status vs `LicenseEffectiveStatus` |
| `Notification` | In-app source of truth |
| `PushDevice` / `PushDelivery` | FCM |
| `Otp` / `PasswordResetToken` | Auth (`OtpPurpose`: register, forgot_password, dashboard_forgot_password) |
| `EmployeeSession` | Dashboard sessions (UUID) |
| `AuditLog` | Sensitive actions |
| `AIAgentSession` / `AIAgentMessage` / `AIAgentAction` / `AIAgentEvaluation` | Under `app/Modules/AIAgent/Models/`, not `app/Models` |
| `Faq` / `ContactMessage` | Content |
| `LicenseType` / `ServiceType` | Catalogs (Arabic `name` + stable `code`) |

ERD reference: `SYRTAK_COMPLETE_ERD.drawio`.

---

## 9. Roles, permissions and authorization

**Source of permission names:** `config/dashboard_permissions.php` (do not invent names at runtime). Seeded by `RolesSeeder` / `PermissionsSeeder`.

**System roles (names):** `super_admin`, `admin`, `profile_document_reviewer`, `fines_employee`, `audit_employee`, `reports_employee`, `settings_employee`, `application_manager`, `test_employee`, `license_employee`, `payment_employee`, `employee` (legacy bundle), `citizen` (protected, not a dashboard employee).

**Distinctions**

- `User::isSuperAdmin()` = role `super_admin` **OR** `admin` (permission bypass `*`)
- `User::isRootSuperAdmin()` = role `super_admin` **only** (employee-session admin APIs)
- Middleware `dashboard` (`EnsureDashboardUser`): `UserType::Admin` or `Employee`, active, `access_dashboard` unless super admin. Citizens cannot use dashboard tokens.
- Middleware `citizen` (`EnsureCitizen`) for citizen APIs: must be `UserType::Citizen`, `is_active`, else 403
- Middleware `profile.approved` gates **mutating** citizen services (create app, pay, book, upload, submit, renew/replace, reschedule/cancel)
- `EnsurePermission` = **OR** of the listed names
- Permissions are **role ∪ direct**. Super-admin last-role protection: `SuperAdminProtectionTest`. Do not strip the last `super_admin`.
- `UserType::Admin` (user_type column) ≠ role name `admin`. Dashboard users are `employee` or `admin` user_type; citizens are `citizen`. Role `citizen` is protected and not a dashboard employee role.

**No Spatie. No Gates.** Check `User::hasPermission` and route middleware.

Citizen role has empty dashboard permissions. Do not assign `citizen` as a dashboard employee role.

---

## 10. Application statuses and complete state transitions

Enum: `App\Enums\ApplicationStatus`.

| Status | Terminal? | Who transitions (production) |
|--------|-----------|------------------------------|
| `draft` | No | `ApplicationRepository::createDraftForCitizen` |
| `documents_under_review` | No | submit-for-review |
| `documents_rejected` | No | any required doc reject |
| `payment_pending` | No | all required docs approved |
| `payment_completed` | No | payment success (brief; immediately followed) |
| `appointment_pending` | No | payment success **if** `ServiceWorkflow::requiresTests` (new_license) |
| `in_testing` | No | book from `appointment_pending`; stay while tests remain |
| `waiting_retest` | No | fail/no-show under max attempts |
| `administrative_review` | No (still active) | fail/no-show at max attempts |
| `approved` | No (active) | all tests passed **or** paid non-test services |
| `license_issued` | Yes (not active) | `LicenseService::issueForApplication` |
| `rejected` | Intended terminal | **No production `transitionStatus` caller** |
| `cancelled` | Intended terminal | **No production `transitionStatus` caller** |

`ApplicationStatus::activeCases()` includes everything except `license_issued`, `rejected`, `cancelled`. Duplicate-application guards use this set.

**There is no central transition matrix in code.** `ApplicationRepository::transitionStatus()` will persist **any** `ApplicationStatus` the caller passes. The lifecycle above is enforced only by **domain services** (document review, payment lifecycle, appointments, tests, license issuance). Never call `transitionStatus` from a controller, Agent handler, or new helper with an invented next status. Never add `rejected`/`cancelled` callers without an explicit product request.

**Production lifecycle**

```text
draft
 → documents_under_review
    ⇄ documents_rejected (reupload + resubmit)
    → payment_pending
       → payment_completed
          → appointment_pending     [new_license only]
             → in_testing
                ⇄ waiting_retest
                → administrative_review   (no auto-issue)
                → approved
          → approved                [renew / lost / damaged; also license_unblock after pay]
             → license_issued       [issuable services only — NOT license_unblock]
```

`license_unblock` applications can reach `approved` after payment (`requiresTests` is false), but **issue-license is rejected** (`messages.licenses.use_unblock_endpoint`). Employees unblock via the dedicated license unblock API, not issuance. **Creating** a new `license_unblock` application currently fails eligibility (BR-29); seeded/demo rows may still exist.

Do **not** invent automatic exit from `administrative_review` to issuance.

---

## 11. Core business rules

| ID | Rule | Code |
|----|------|------|
| BR-01 | Mutating citizen service APIs require approved profile | `profile.approved` |
| BR-02 | Tests required **only** for `new_license` | `ServiceWorkflow::requiresTests` |
| BR-03 | Renew/lost/damaged/unblock require `related_license_id` | `requiresRelatedLicense` |
| BR-04 | Upload ≠ submit-for-review | `ApplicationDocumentService` |
| BR-05 | Any required doc reject → `documents_rejected` | `DocumentReviewService` |
| BR-06 | All required approved → `payment_pending` | same |
| BR-07 | Successful pay → `payment_completed` then `appointment_pending` or `approved` | `PaymentLifecycleService` |
| BR-08 | Book from `appointment_pending` → `in_testing` | `AppointmentService` |
| BR-09 | Cancel appointment does **not** change application status | `AppointmentService` |
| BR-10 | Test order vision → theory → practical; max attempts seeded 3 | `TestProgressionService`, seeder |
| BR-11 | Fail under max → `waiting_retest`; at max → `administrative_review` | `TestResultService` |
| BR-12 | Issue only if eligibility passes | `LicenseIssuanceEligibilityService` |
| BR-13 | Direct renew: old → `renewed`; direct replace: old → `inactive` | `LicenseService` |
| BR-14 | Agent mutations require confirmation when config true | `config/ai.php` |
| BR-15 | Agent cancel = no domain mutation | `AIAgentActionService::cancel` |
| BR-16 | Agent executor calls domain services, never raw SQL bypass | `AgentActionExecutor` |
| BR-17 | DB notification is source of truth; FCM is delivery | `NotificationService` |
| BR-18 | Push planned `afterCommit`; FCM failure does not roll back business | |
| BR-19 | Unknown service codes fail-closed | `ServiceWorkflow` |
| BR-20 | Unpaid fines can block issue/unblock | `LicenseService` |
| BR-21 | Agent must not invent IDs, fees, legal rules | Gemini prompt + deterministic layer |
| BR-22 | Fine **payment via Agent/citizen checkout** does not exist | do not invent |
| BR-23 | No second **active** application for same citizen + license type + service (new), or same related license + service (others) | `ApplicationService` |
| BR-24 | Apply requires verified email + completed profile + `profile_status=approved` | `assertCitizenCanApply` |
| BR-25 | Renew window: Active/Expired only; not before `expiry - LICENSE_RENEWAL_GRACE_DAYS` if still Active | `LicenseServiceEligibilityService` |
| BR-26 | Issued license expiry uses `config('license.validity_years')` (env default 10), **not** `license_types.validity_years` (seeded 5) | `LicenseService` |
| BR-27 | Citizen payment collects only `ApplicationFeeCatalog::APPLICATION_PAYABLE_CODES`. Seeded `vision_test_fee` / `theory_test_fee` / `practical_test_fee` are **catalog-only**, not charged at booking | `ApplicationFeeResolver` |
| BR-28 | Approved application documents cannot be replaced | `ApplicationDocumentService` |
| BR-29 | `license_unblock` **application create currently fails eligibility**: blocked licenses are rejected before `checkUnblock()` (`blocked_service`). Do not treat this path as a working citizen workflow | `LicenseServiceEligibilityService` |
| BR-30 | Admin `PUT /admin/fines/{id}` can set status `paid`/`cancelled` (office recording, not Stripe) | `FineService::update` |
| BR-31 | Accept-Language is request-scoped and **never persisted** into `users.language` | `RequestLocaleResolver` |

---

## 12. Complete major workflows

### A. Citizen register → profile → new license

1. `POST /auth/register` → inactive user + email OTP (`OtpPurpose::Register`) via `OtpMail` / `resources/views/emails/otp.blade.php`. **Phone columns remain but are unused.** `OTP_CHANNEL=email`.
2. `POST /auth/verify-otp` → activate + Sanctum token
3. `PUT /profile/complete` → `ProfileStatus::PendingReview`
4. Employee `POST /admin/profile-reviews/{user}/approve`
5. `POST /applications` `{ license_type_id, service_type_code: new_license }` → `draft` (duplicate active same type+service → 422)
6. Upload docs; `POST .../submit-documents` → `documents_under_review`
7. Employee approve all → `payment_pending`
8. Create/confirm payment → `appointment_pending`
9. Book slot → `in_testing`; examiner records results; all pass → `approved`
10. Employee `POST /admin/applications/{id}/issue-license` → `license_issued` + `License` active (expiry = now + `LICENSE_VALIDITY_YEARS`)

Forgot password (throttled 5/min): `POST /auth/forgot-password` → verify OTP (`OtpPurpose::ForgotPassword`) → `reset-password`. Dashboard has a parallel set under `/dashboard/auth/*` (`OtpPurpose::DashboardForgotPassword`).

### B. Renew / lost / damaged (application path)

Create app with `related_license_id` → docs → pay → **no tests** → `approved` → employee issue. Old license: renewed vs inactive depending on service.

### C. Direct license APIs (also exist)

- `POST /licenses/{id}/renew`
- `POST /licenses/{id}/replacement` (`lost`|`damaged`)
- `POST /licenses/{id}/unblock-request` — **acknowledgment only** (`LicenseService::requestUnblock`): requires blocked license + no unpaid fines; returns a message; **does not** create an application, change license status, or emit `license.unblocked`.

`license_unblock` as **application service code** is catalogued (fee `unblock_fee`) but **not a working create path today**: `LicenseServiceEligibilityService::check()` returns `blocked_service` for any blocked license *before* `checkUnblock()`. There are **no** `RequiredDocumentsSeeder` rows for this service. After pay (if an app existed) it would sit in `approved` and would not be issuable. Treat as incomplete. Citizen `unblock-request` remains ack-only. Employee unblock is the only mutation that works.

### D. Dual path

Every **working** citizen capability has a REST path. The Agent is an overlay that must hit the same services. Incomplete paths (`license_unblock` create, citizen fine checkout, `rejected`/`cancelled` apps) must not be invented in Agent.

### E. Content + settings

- Public: `GET /content/faqs`, `/content/privacy-policy`, `/content/contact-info`; `POST /contact-messages` (throttled)
- Citizen: `GET /settings`, `PUT /settings/preferences` (`language`, `theme` light/dark/system), `PUT /settings/change-password` (also `PUT /profile/change-password`)

---

## 13. Database architecture and relationships

High-level:

```text
User 1──* LicenseApplication (citizen_id)
User 1──* License (citizen_id)
LicenseApplication *──1 LicenseType, ServiceType
LicenseApplication ──? License (related_license_id)
LicenseApplication 1──* ApplicationDocument, Payment, TestAppointment, TestResult, ApplicationStatusHistory
ApplicationDocument *──1 RequiredDocument
TestAppointment *──1 AppointmentSlot *──1 AppointmentCenter
License ──? License (previous_license) + LicenseStatusHistory
User 1──* Notification, PushDevice, Fine, EmployeeSession, AIAgentSession
```

Catalog `name` columns are typically **Arabic-seeded**. Stable `code` is the localization key (see §24 / AgentCatalogLocalizer).

Do not add migrations “for convenience” if a code/key map already works (Agent catalog labels).

---

## 14. API structure and important endpoints

Base: `/api`. Locale middleware on public/citizen groups (`Accept-Language` → `users.language` → `ar`). Stripe webhook and dashboard/admin groups are **not** on citizen `locale` middleware.

**Health:** `GET /api/ping` (payload still says phase 9). Laravel `GET /up`.

**Auth:** `POST /auth/register|verify-otp|login|logout`, forgot/reset (throttle 5/min). `GET /auth/me`.

**Profile:** `PUT /profile/complete|update|change-password`, `GET /profile/status`.

**Catalogs (public):** `GET /license-types`, `/service-types`, `/test-types` — `name` localized via `CitizenCatalogLabel` (code keys in `messages.catalog.*`).

**Applications (citizen):** list/show; nested required-documents, documents, fee, payments (+ status), appointments, available-tests, test-results; submit-documents. Mutating routes behind `profile.approved` + throttles (pay/book 15/min, upload 30/min, submit 10/min).

**Appointments:** `GET /appointment-slots?test_type_id=` required; `PUT /appointments/{id}/reschedule`; `DELETE /appointments/{id}/cancel`.

**Licenses:** `GET /licenses`, `GET /licenses/{id}`, renew/replacement/unblock-request (throttle 10/min); public `GET /licenses/verify/{token}` (alphanumeric, throttle 30/min).

**Fines:** `GET /fines` only for citizens. Admin CRUD/status: `/api/admin/fines`.

**Notifications / push:** `/notifications*`, `POST|DELETE /devices/push-token`.

**Settings / content:** see §12 E.

**Payments webhook:** `POST /webhooks/stripe` (public, throttle 100/min, **no** Sanctum).

**AI Agent:** see §25 (`/ai-agent/*`, 7 routes). Requires citizen + active. Message/interactions 30/min; upload/confirm 20/min.

**Empty:** `Route::prefix('chatbot')` is empty — not a product.

Dashboard/admin: see §7. Full inventory also in `SYRTAK_FINAL_SRS_SOURCE_OF_TRUTH.md` §4 (verify against `routes/` if they disagree).

---

## 15. Important services / controllers / models / enums

### Domain services (do not bypass)

| Service | Module |
|---------|--------|
| `AuthService`, `OtpService`, `ProfileService` | Auth |
| `ApplicationService`, `ApplicationRepository` | Applications |
| `ApplicationDocumentService`, `DocumentReviewService` | Applications / Admin |
| `ApplicationPaymentService`, `PaymentLifecycleService`, `PaymentReconciliationService`, `PaymentGatewayEventService`, `ApplicationFeeResolver` | Payments |
| `AppointmentService`, `TestProgressionService`, `AvailableTestReasonResolver` | Appointments |
| `TestResultService` | Tests |
| `LicenseService`, `LicenseIssuanceEligibilityService`, `LicenseServiceEligibilityService`, `LicenseVerificationService`, `LicensePrintService`, `LicenseEffectiveStatus` | Licenses |
| `FineService` | Fines |
| `NotificationService`, `PushDeliveryService`, `FcmClient` | Notifications / Push |
| `AuditLogService` | `app/Services` |
| `DashboardAuthService`, `DashboardAccessControlService`, `EmployeeSessionService` | Dashboard |
| `SettingsService`, `ContentService` | Settings / Content |
| `CitizenCatalogLabel`, `CitizenContentLocalizer`, `CitizenMessageTranslator`, `EmployeeMessageTranslator`, `ArabicMessageTranslator` | `app/Support` |

### Enums (`app/Enums`)

`ApplicationStatus`, `ServiceCode`, `ProfileStatus`, `DocumentStatus`, `DocumentRejectionReason`, `PaymentStatus`, `PaymentFailureCode`, `PaymentGatewayEventStatus`, `AppointmentStatus`, `TestTypeCode`, `TestResultStatus`, `LicenseStatus`, `FineStatus`, `UserType`, `OtpPurpose`, `NotificationType`, `EmployeeSessionStatus`, `EmployeeSessionEndedReason`, `PushDeliveryStatus`.

Agent enums under `app/Modules/AIAgent/Enums/`: `AgentIntent`, `AgentActionStatus`, `AgentSessionStatus`, `AgentMessageRole`, `DocumentFlowState`.

### Agent (orchestration)

`AIAgentService`, `AIAgentActionService`, `AgentActionExecutor`, `AgentPendingWorkflowService`, `AgentDocumentFlowService`, `AgentDocumentUploadService`, `AgentUploadTokenService`, `AgentSelectionTokenService`, `AgentAppointmentHandler`, `AgentAppointmentOptionService`, `AgentOtherLicenseServicesHandler`, `AgentLicenseOptionService`, `AgentWorkflowOrchestrator`, `AgentApplicationActionPolicy`, `AgentApplicationStatusMap`, `GeminiAgentClient`, `AgentTranslator`, `AgentCatalogLocalizer`, `AgentLanguageDetector`, `AgentResponseLocale`, `AgentSessionLocaleManager`, `AgentLocaleContext`, `AgentPreProcessor`, `AgentPostProcessor`, `AgentSlotFiller`, `AgentIntentDetector`, `AgentWorkflowPhraseMatcher`, `AgentDuplicateApplicationGuard`, `AgentProfileApprovalGuard`, `AgentEvaluationService`.

---

## 16. Validation strategy

- HTTP: FormRequests (`app/Modules/*/Requests`).
- Domain: service-level checks (state, ownership, eligibility) throwing `ApiException` with optional machine `errorCode`.
- Documents: FormRequest max **5120 KB**; service also enforces required-document `max_size_kb` (default **4096** if null) + extension/MIME whitelist (`jpg/jpeg/png/pdf`).
- Agent: `SendAgentMessageRequest` message 1–4000 chars, text only; `HandleAgentInteractionRequest` enumerates actions; upload tokens for files.
- Unknown service codes fail-closed.
- Dual size limits are real — document both; do not “fix” unless asked.

---

## 17. Authentication / security / ownership / IDOR

- Sanctum tokens; citizen vs employee separated by `user_type` + middleware. A citizen token cannot call dashboard; an employee token cannot call `EnsureCitizen` routes (including Agent).
- Inactive citizens (`is_active=false`) get 403 on citizen middleware even with a valid token. Dashboard can activate/deactivate citizens.
- Ownership: applications, appointments, licenses, notifications, Agent sessions/actions must be scoped to `user_id` / `citizen_id`. Tests exist (`NotificationSecurityTest`, `PushDeviceSecurityTest`, document IDOR, Agent pending-workflow token tests).
- Agent **selection tokens** (`AgentSelectionTokenService`): HMAC-SHA256 of base64url JSON using `config('app.key')`. Bind `uid`, `sid`, `purpose`, entity ids, `wid`, `intent`, `exp`. Cross-user/session/purpose/workflow tokens fail with machine codes (`APPLICATION_SELECTION_TOKEN_*` / `INVALID_SELECTION_TOKEN`). Never trust Flutter-supplied raw IDs when a token exists.
- Confirm revalidates mutable domain state (stale slot, expired workflow, already executed action).
- Mock payment confirm refuses non-mock rows (`confirmMockPayment`).
- Stripe webhook: signature verification; `PaymentGatewayEvent` reserves duplicates.
- CORS (`config/cors.php`): `api/*` currently `allowed_origins => ['*']`, `supports_credentials => false`.
- Rate limits: auth reset 5/min; mutating citizen services 10–30/min; Agent 20–30/min; Stripe 100/min.
- Do not log OTP codes, document bytes, Stripe secrets, Gemini keys, or selection-token payloads in citizen errors. `OtpDebugLoggingTest` exists.
- Super-admin last-role protection: `SuperAdminProtectionTest`.

---

## 18. Document management / review

Seeded required-document codes by service (`RequiredDocumentsSeeder`; **none for `license_unblock`**):

| Service | Codes |
|---------|--------|
| `new_license` | `national_id_copy`, `personal_photo`, `blood_donation_certificate`, `medical_report` |
| `renew_license` | `national_id_copy`, `recent_personal_photo`, `medical_report_renewal` |
| `lost_replacement` | `national_id_copy`, `loss_declaration`, `recent_personal_photo` |
| `damaged_replacement` | `national_id_copy`, `damaged_license_proof`, `recent_personal_photo` |

1. List required docs (`CitizenCatalogLabel` for `name`).
2. **Upload** → `Storage::disk('local')` which roots at `storage/app/private` (not public). Document `pending_review`. Does **not** move application status. Approved docs cannot be replaced.
3. **Submit for review** from `draft` or `documents_rejected` → application `documents_under_review` (all required must be present and not rejected). Empty required set would submit vacuously — currently only relevant if a service has no seeded docs.
4. Employee approve/reject per document (`DocumentRejectionReason`: `unclear_document`, `wrong_document`, `expired_document`, `incomplete_document`, `other` + details). MIME whitelist `AllowedDocumentMime`.
5. Any reject → application `documents_rejected`.
6. All required approved → `payment_pending`.
7. Citizen reuploads rejected docs and resubmits.

**Profile review ≠ document review.** Dual employee APIs: `/api/admin/documents/*` and `/api/dashboard/document-reviews/*` (dashboard has preview).

Agent document flow (Phase 2.2): conversational offer → agent or manual path → `upload_token` → `POST .../documents`. Prefer token mode. Legacy body `application_id` + `required_document_id` still accepted by `UploadAgentDocumentRequest`. Exactly one file. Files **never** sent to Gemini. State machine: `DocumentFlowState`.

---

## 19. Profile review

Required complete fields: `name`, `national_id`, `birth_date`, `governorate`, `address`.

`ProfileStatus`: `incomplete`, `pending_review`, `approved`, `rejected`.

Sensitive updates (`name`, `national_id`, `birth_date`, `governorate`, `address`) on an **approved** profile return the citizen to pending review. Updates while already pending stay pending. Reject stores `profile_rejection_reason`. Mutating license-service APIs require `approved`. Apply also requires `email_verified_at`.

`users.language` / `users.theme` are settings preferences, not profile-review fields.

Admin: `/api/admin/profile-reviews/*` (`ProfileReviewService`). Dashboard citizen activate/deactivate is separate (`is_active`).

---

## 20. Payment workflow

- `PAYMENT_PROVIDER`: `mock` (phpunit + typical local) or `stripe`. `.env.example` currently defaults stripe — tests **force mock**.
- Citizen creates payment when application is `payment_pending`. Fee from `ApplicationFeeResolver` using `ServiceWorkflow::feeCode`. Currency **USD** (`ApplicationFeeCatalog::CURRENCY`). Seeded amounts are demo defaults only; runtime price is the `fees` row.
- Payable codes: `application_fee`, `renewal_fee`, `lost_replacement_fee`, `damaged_replacement_fee`, `unblock_fee`. Test-type fees exist in the catalog **but are not collected** by `ApplicationPaymentService`.
- Mock: `POST .../payments/{payment}/confirm` — **only if** `provider=mock`.
- Stripe: Checkout session; completion via `POST /api/webhooks/stripe`. May return `checkout_url`.
- `PaymentStatus`: `pending`, `completed`, `failed`, `under_verification`.
- On success: `payment_completed` then immediately `appointment_pending` (new license) or `approved` (other services).
- Stale pending: `PAYMENT_STALE_PENDING_MINUTES` (default 60); scheduler `payments:reconcile` every 30 minutes (`ReconcilePendingPaymentsCommand`).
- Dashboard verify: `POST /dashboard/payments/{id}/verify`.
- **Citizen fine payment: NOT IMPLEMENTED.** Admin may set fine `paid` (office).
- Agent `start_payment` is mutating (confirm); may return `checkout_url`. `get_payment_status` is read-only.

---

## 21. Appointment / testing / retest workflow

- Slots: capacity-managed `appointment_slots` (`SlotIdentity`); concurrency tests exist. List requires `test_type_id`.
- Book allowed in `appointment_pending` | `in_testing` | `waiting_retest` (progression still applies).
- Book from `appointment_pending` → `in_testing`. Sets `current_test_type_id`.
- Reschedule: booked appointments only; new slot must match same `test_type_id`. Cancel → appointment `cancelled`, frees capacity, **application status unchanged**.
- `AppointmentStatus`: `booked`, `cancelled`, `completed`, `no_show`.
- Tests (new_license only): `vision` → `theory` → `practical`. `specialized` exists in enum, not default seeder.
- One bookable type at a time (`TestProgressionService`). `AvailableTestReasonResolver` explains why a type is not bookable.
- Pass remaining → stay in testing. All required passed → `approved`.
- Fail/no-show under max (3) → `waiting_retest`. At max → `administrative_review`.
- **No separate mandatory retest fee** and **no booking-time test fee collection** — do not invent either.
- Examiner: `POST /admin/test-appointments/{id}/record-result`.
- Agent: multi-slot selection via pending workflow + tokens (`AgentAppointmentOptionService`, `AgentAppointmentHandler`). Text ordinals (`الأول` / `first` / `1`) can select.
- Business calendar: `BusinessClock` + `BUSINESS_TIMEZONE` (Asia/Damascus); storage `APP_TIMEZONE` UTC. `AppointmentTimezoneTest` exists.

---

## 22. License issuance and other license services

**Issuance:** employee `issue_license` → `LicenseService::issueForApplication` after `LicenseIssuanceEligibilityService`. App → `license_issued`. Direct `LicenseIssued` notification (application-status map returns null for `license_issued` to avoid duplicate). Expiry = `now + config('license.validity_years')` (**10** default). Catalog `license_types.validity_years` is **display/metadata** (seeded **5**) — do not assume issuance uses it.

`LicenseStatus` stored: `active`, `expired`, `suspended`, `blocked`, `renewed`, `inactive`.

**Effective status:** `LicenseEffectiveStatus` treats stored `active` past `expiry_date` as `expired` (business timezone). Public verify only succeeds for **effective** active (`LicenseVerificationService`). Daily `SyncExpiredLicensesCommand` persists expiry. `LICENSE_EXPIRING_SOON_DAYS` (default 90) for dashboard.

**Renew:** application path **or** `POST /licenses/{id}/renew` (direct path does not emit `LicenseIssued` notification). Eligibility: Active or Expired; not before `expiry - LICENSE_RENEWAL_GRACE_DAYS` while still Active; no newer active/expired license of same type.

**Replace:** lost vs damaged are **distinct** service/fee codes. Direct `POST /licenses/{id}/replacement`. Active or Expired only; blocked licenses cannot use these services.

**Block/unblock (three different things — do not merge):**

| Path | What it actually does |
|------|------------------------|
| Employee block/unblock (`/admin` **and** `/dashboard/licenses`) | Mutates `License` status; emits notifications; unpaid fines can block unblock |
| Citizen `POST /licenses/{id}/unblock-request` | Ack-only; no status change |
| Application `service_type_code=license_unblock` | **Create currently fails eligibility** (see BR-29). Fee/catalog exist. Not issuable. Agent has **no** conversational intent |

**Public verify:** `GET /licenses/verify/{verificationToken}`. Backfill: `BackfillLicenseVerificationTokensCommand`. Print: `POST /dashboard/licenses/{id}/print` (`LicensePrintService`).

Seeded license type codes: `private`, `public`, `truck`, `bus` (Arabic names; localized via `CitizenCatalogLabel` / `AgentCatalogLocalizer`).

Agent: `get_licenses` list only. **No `get_license_details` intent.** Renew/lost/damaged via Agent use `create_application` + `related_license_id` / `select_license` (`AgentOtherLicenseServicesHandler`). Do **not** execute `AgentWorkflowActionMap` names `renew_license` / `request_license_replacement` / `request_unblock` — those are catalog labels, not `AgentActionExecutor` cases.

---

## 23. Notifications

- DB `notifications` via `NotificationService` is the **source of truth**.
- Types: `App\Enums\NotificationType` (stable English machine strings).
- APIs: list, unread-count, mark one, mark all. Ownership by `user_id`.
- Localization: **recipient** `users.language` via `RecipientNotificationTranslator` — does **not** flip global app locale, independent of Agent session locale.
- Safe metadata only (`allowedDataKeys`). Dedup via event keys where implemented.
- Created after business success.

**Push:** `notify` → `DB::afterCommit` → persist → `planPushSafely` → `PushDelivery` rows → `SendPushNotificationJob` → `FcmClient` HTTP v1. Failure isolated. Default `FIREBASE_PUSH_ENABLED=false` until a worker runs. Backoff in `config/firebase.php`.

---

## 24. Arabic / English localization architecture

**Supported:** `ar` (default), `en`. Config: `config/localization.php`. Regional tags (`en-US`, `ar-SY`) normalize to base codes.

There are **four** locale systems. Do not merge them.

### 1. HTTP / REST (citizen)

- Middleware `locale` → `RequestLocaleResolver`: first supported `Accept-Language` → else `users.language` → else `ar`.
- Accept-Language is **never written** to the user row. Persist language only via `PUT /settings/preferences`.
- Restores default locale after the request (Octane-safe) in `ResolveRequestLocale`.
- Sets `Content-Language` and `Vary: Accept-Language`.
- Strings: `resources/lang/{ar,en}/messages.php` via `CitizenMessageTranslator` (explicit `Lang::has` per locale; missing EN does not silently return AR for Agent, but citizen translator may fall back to default locale).
- Catalog `name` in API resources: `CitizenCatalogLabel` keyed by code (`messages.catalog.*`).
- Static content: `CitizenContentLocalizer` (FAQ, privacy, contact, theme labels).
- Machine codes stay English (`documents_under_review`, intent names, `message_type`).

### 2. Agent conversation locale (separate)

Pipeline: message → `AgentPreProcessor` normalize → `AgentLanguageDetector` → `AgentSessionLocaleManager` → `AgentLocaleContext` + `app()->setLocale` → process → `AgentTranslator` / `AgentCatalogLocalizer` / `AgentResponseLocale`.

`/interactions` and `/documents` **restore session locale** via `AgentResponseLocale::applySessionLocale` (they do not re-detect from a message).

Rules (Phase 2.6 / 2.6.1):

- First message sets locale; re-detect on meaningful messages.
- Short yes/no/ordinals inherit session locale when ambiguous.
- Explicit switch (`speak english` / `تكلم عربي`) updates locale only — **does not** clear `pending_workflow`, tokens, or slots.
- Technical English inside Arabic (`payment`, `PDF`, `ID`) must not flip locale.
- Responses include `language`, `locale`, `text_direction` (`rtl`/`ltr`).
- Catalog labels via `AgentCatalogLocalizer` keyed by **code**, not Gemini translation.
- User-entered free text stays as stored (e.g. appointment center names).
- `Lang::has(..., false)` so missing English keys do not silently return Arabic.

### 3. Notifications

Recipient `users.language` via `RecipientNotificationTranslator`. Does **not** flip global app locale. Independent of Agent session locale and of the current request Accept-Language.

### 4. Dashboard / employee

Mostly Arabic via `EmployeeMessageTranslator` and `Msg` → `ArabicMessageTranslator` (always `ar`). Do not wire dashboard `message` to citizen Accept-Language unless product asks.

Do not merge Agent locale into `users.language` unless a product decision says so.

---

## 25. AI Agent architecture and complete implemented behavior

### What it is

Citizen transactional assistant. Hybrid: Gemini structured JSON **plus** deterministic phrase matchers, slot filling, pending workflows, safety, and domain execution.

**Not RAG.** No embeddings, vector DB, or document retrieval.

**Not a second backend.** `AgentActionExecutor` → existing domain services.

Phases implemented in this codebase (do not re-litigate):

| Phase | Outcome |
|-------|---------|
| 9A/9B | Sessions, Gemini, confirm mutations, read-only execute |
| 2.2 | Conversational document flow + upload tokens |
| 2.3 | Multi-application selection + pending_workflow + selection tokens |
| 2.4 | Appointment multi-slot book/reschedule/cancel |
| 2.5 | Renew/lost/damaged via `create_application`, payment status, `select_license`, interactions confirm/cancel |
| 2.6 | Bilingual from first message; `language` field; mid-session switch |
| 2.6.1 | Catalog localization + Flutter contract freeze |

**Verdict at 2.6.1:** functionally complete for implemented citizen Agent flows; ready for Flutter integration.

### HTTP surface (7 routes — do not add more)

| Method | Path | Flutter? |
|--------|------|----------|
| POST | `/api/ai-agent/message` | **Required** |
| POST | `/api/ai-agent/sessions/{session}/interactions` | **Required** |
| POST | `/api/ai-agent/sessions/{session}/documents` | **Required** |
| GET | `/api/ai-agent/sessions` | Optional |
| GET | `/api/ai-agent/sessions/{session}` | Optional |
| POST | `/api/ai-agent/actions/{id}/confirm` | Deprecated wrapper |
| POST | `/api/ai-agent/actions/{id}/cancel` | Deprecated wrapper |

Auth: `sanctum` + `citizen` (active citizen only). If `AI_AGENT_ENABLED=false` → 503. Throttles on message/interactions/upload/confirm.

First `POST /message` without `session_id` creates `AIAgentSession`. Flutter must persist `data.session_id` and send it on later turns.

### Message pipeline (`AIAgentService::handleMessage`)

1. Load/create owned session; detect language; set `AgentLocaleContext`; persist session locale if confident.
2. Store user message.
3. If awaiting confirmation: `AgentUserConfirmationDetector` yes/no or workflow query.
4. Document-flow text decisions (`AgentDocumentFlowPhraseMatcher` / `AgentDocumentFlowService`).
5. Pending workflow (application/license/slot/appointment selection) **before** Gemini/`general_help` (`AgentPendingWorkflowService`, `AgentWorkflowPhraseMatcher`).
6. Required-documents phrases (without stealing submit-for-review).
7. Gemini structured JSON (`GeminiAgentClient`); on failure/null → `AgentIntentDetector::detectFallback`.
8. `AgentPostProcessor`: safety, low confidence (`AI_AGENT_LOW_CONFIDENCE_THRESHOLD` default 0.55) may set `requires_human_support` and strip proposed action — **this is a flag, not a ticketing product**.
9. Slot fill, `AgentProfileApprovalGuard`, `AgentDuplicateApplicationGuard`, localize payload.
10. If missing `application_choice` / `related_license_id` / slot or appointment choice → pending workflow UI.
11. Read-only actions execute immediately; mutations persist `AIAgentAction` (`awaiting_confirmation`) then `AgentActionExecutor` on confirm.

Deterministic workflow replies (status, next step, docs, fees, appointments, licenses, fines, other-license create) go through `AgentWorkflowOrchestrator` + per-intent handlers. **Per-status Agent allow-list:** `AgentApplicationStatusMap` + `AgentApplicationActionPolicy`. This must stay consistent with domain services; it is not a second place to invent new business rules. If you add an Agent action, update the map **and** the domain service, then tests.

### Interaction router (`AIAgentController::handleInteraction`)

Order matters. After restoring session locale:

1. `cancel_pending_workflow` / `show_application_choices_again` → pending workflow
2. `select_application` — if document-flow state is **not** `application_selection`, pending-workflow token select; otherwise falls through to document flow
3. `select_appointment_slot` / `select_appointment` / `select_license` → pending workflow
4. `confirm_pending_action` / `cancel_pending_action` → `AIAgentActionService` (same as deprecated wrappers)
5. Else → `AgentDocumentFlowService::handleInteraction` (document buttons, including `select_application` during document-flow application pick)

### Executable actions (`AgentSafetyRules::PHASE_9B_EXECUTABLE_ACTIONS`)

**Read-only (no confirm):**  
`get_application_status`, `get_application_next_step`, `get_required_documents`, `get_application_fee`, `get_payment_status`, `get_profile_status`, `get_fines`, `get_licenses`, `get_available_tests`, `get_appointment_slots`, `get_current_appointments`, `get_test_results`

**Mutating (confirm):**  
`create_application` (new / renew / lost / damaged via arguments), `start_payment`, `submit_documents_for_review`, `book_appointment`, `reschedule_appointment`, `cancel_appointment`

Admin actions are denied (`admin_action_denied`).

**Do not confuse `AgentWorkflowActionMap` with executors.** That map still lists `get_license_details`, `get_notifications`, `renew_license`, `request_license_replacement`, `request_unblock`. Those names are **not** in `PHASE_9B_EXECUTABLE_ACTIONS` and have **no** `AgentActionExecutor` cases. Using them as proposed executable actions is a bug.

**Not implemented / not Agent-executable:** fine payment, `get_license_details`, `get_notifications`, direct license-mutation action names above, conversational `license_unblock` intent, RAG, human-handoff product workflow.

`create_application` executor will accept any **active** `service_types.code` (including `license_unblock` if passed explicitly). Conversational intents only cover new / renew / lost / damaged (`AgentIntent`). Domain eligibility still applies — so Agent `license_unblock` create would fail the same BR-29 check. Do not add a silent unblock product flow without an explicit feature request.

`AgentActionExecutor::requiresApprovedProfile` currently lists `create_application`, `start_payment`, `book_appointment`, `submit_documents_for_review` only. REST still requires `profile.approved` for reschedule/cancel. Do not weaken REST to match the narrower Agent list.

### Interaction actions (`HandleAgentInteractionRequest`)

`choose_agent_document_upload`, `choose_manual_document_upload`, `select_application`, `select_required_document`, `select_appointment_slot`, `select_appointment`, `select_license`, `cancel_document_upload`, `show_required_documents`, `cancel_pending_workflow`, `show_application_choices_again`, `confirm_pending_action`, `cancel_pending_action`.

**Not implemented:** `show_appointment_choices_again`, `show_slot_choices_again`, `confirm_agent_upload`, `choose_manual_upload` — do not invent them. Use the implemented names above.

### Pending workflow + tokens

Stored in `session.context.pending_workflow` with TTL (`AI_AGENT_PENDING_WORKFLOW_TTL`, default 900s). States: `PendingWorkflowState` (`awaiting_application_choice`, `awaiting_license_choice`, `awaiting_appointment_choice`, `awaiting_appointment_slot_choice`, plus completed/cancelled/expired/failed). Selection tokens TTL 1800s (`AgentSelectionTokenService`, HMAC with `APP_KEY`). Document **upload** tokens are separate (`AgentUploadTokenService`: random 64 + sha256 hash in session document_flow; only valid in `AwaitingFile`).

Purposes: `select_application`, `pending_application_selection`, `select_required_document`, `select_appointment_slot`, `select_appointment`, `select_license`.

Language switch must **not** clear pending workflow.

Preserve `context.locale` in `AgentSessionContextService::buildPersistedContext` (regression: it was dropped once).

Evaluations: `AIAgentEvaluation` / `AgentEvaluationService` persist quality traces; not a citizen feature.

### Flutter response contract

See `FLUTTER_AI_AGENT.md` (code wins on action names). Include `language` / `locale` / `text_direction`. Structured `message_type` inventory (implemented):

`application_selection_required`, `application_selection_expired`, `application_selection_cancelled`, `application_selected_confirmation_required`, `application_status`, `application_next_step`, `no_eligible_application`, `license_selection_required`, `license_service_confirmation_required`, `no_eligible_license`, `appointment_slot_selection_required`, `appointment_selection_required`, `appointment_confirmation_required`, `no_eligible_appointment`, `document_upload_offer`, `required_document_selection`, `file_upload_required`, `document_uploaded`, `documents_submitted_for_review`, `documents_uploaded_submission_failed`, `manual_document_upload_guidance`, `multiple_files_rejected`, `document_flow_error`.

Intent-executed replies may omit `message_type`.

### Gemini

`GeminiAgentClient`; model default `gemini-2.5-flash`; timeout 30s (`AI_AGENT_TIMEOUT_SECONDS`); `GEMINI_BASE_URL` default Google v1beta. System prompt (`AgentContextBuilder`) injects `response_locale`. Gemini must not be the only language enforcer. Never send document file contents to Gemini.

On provider failure: deterministic fallback in the **same** language. Never retry a mutation by blindly re-executing.

### Config (`config/ai.php`)

`AI_AGENT_ENABLED`, `AI_AGENT_REQUIRE_CONFIRMATION` (true), `GEMINI_API_KEY`, `GEMINI_MODEL`, `GEMINI_BASE_URL`, history 10 messages, temperature 0.2, low-confidence 0.55, token/workflow TTLs above.

### Tests (keep green)

`AIAgentFlowTest`, `AIAgentActionExecutionTest`, `AIAgentPhase1CriticalActionsTest`, `AIAgentConversationalDocumentFlowTest`, `AIAgentApplicationSelectionFlowTest`, `AIAgentPendingWorkflowReliabilityTest`, `AIAgentAppointmentMultiSlotFlowTest`, `AIAgentCitizenServicesPhase25Test`, `AIAgentBilingualHardeningTest`, `AIAgentCatalogLocalizationTest`, `AIAgentWorkflowIntelligenceTest`, `AIAgentDocumentUploadTest`, unit language/locale tests.

Stale file: `DLMS_AI_AGENT_CONTEXT_COMPACT.md` (pre-interactions, pre-2.5/2.6). Prefer this section + `FLUTTER_AI_AGENT.md`.

---

## 26. External integrations

| System | Role | Config (names only) |
|--------|------|---------------------|
| Gemini | Agent NLU | `AI_PROVIDER`, `GEMINI_API_KEY`, `GEMINI_MODEL`, `GEMINI_BASE_URL` |
| Stripe | Checkout + webhook | `PAYMENT_PROVIDER`, `STRIPE_*` |
| Firebase FCM | Push | `FIREBASE_PROJECT_ID`, `FIREBASE_CREDENTIALS_BASE64`, `FIREBASE_PUSH_ENABLED`, `FIREBASE_CA_BUNDLE` |
| SMTP | OTP / mail (`OtpMail`) | `MAIL_*` |
| Optional S3 | not required for core docs | `AWS_*` |

Mock payment and `OTP_FIXED_CODE` are local/test aids.

---

## 27. Storage / files

- Application documents: `Storage::disk('local')` → `storage/app/private/...`. Not publicly URL-guessable. Dashboard preview streams through auth.
- License PDF print via mpdf; QR via endroid.
- Agent uploads go through `ApplicationDocumentService` like manual uploads.
- Default `FILESYSTEM_DISK=local`. S3 env names exist but are unused for documents.

---

## 28. Queues / jobs

- `QUEUE_CONNECTION=database` typical; phpunit uses `sync`.
- Job: `app/Jobs/SendPushNotificationJob.php`. Dispatch leftover: `DispatchPendingPushDeliveriesCommand`.
- Push requires a worker when `FIREBASE_PUSH_ENABLED=true`.
- `composer dev` script runs `queue:listen` alongside `serve`.
- **Scheduler** (`routes/console.php`; run `php artisan schedule:work` locally):
  - `ReconcilePendingPaymentsCommand` every 30 minutes
  - `SyncExpiredLicensesCommand` daily 00:15 business TZ
  - `ReconcileEmployeeSessionsCommand` hourly
  - `PruneEmployeeSessionsCommand --dry-run` weekly
- Other commands: `RbacBootstrapCommand`, `RbacAuditCommand`, `RbacRepairDocumentReviewerCommand`, `FirebaseVerifyCommand`, `BackfillLicenseVerificationTokensCommand`.

---

## 29. Testing strategy and important test suites

- PHPUnit Feature + Unit. Isolated MySQL `dlms_testing` in `phpunit.xml` (`DB_CONNECTION=mysql`, `OTP_FIXED_CODE=123456`, `PAYMENT_PROVIDER=mock`, Agent enabled + confirmation required, `GEMINI_API_KEY` dummy). Do not copy DB credentials from phpunit into docs.
- Tests mock Gemini (`generateStructuredResponse` → null) to exercise deterministic paths. Helper: `tests/Support/AIAgentTestHelper.php`.
- ~103 `*Test.php` files. Counts drift — re-run.

**Domain:** `ApplicationFlowTest`, `DocumentFlowTest`, `PaymentFlowTest`, `PaymentStripeTest`, `PaymentConcurrencyAndIntegrityTest`, `AppointmentFlowTest`, `AppointmentSlotConcurrencyTest`, `AppointmentTimezoneTest`, `LicenseFlowTest`, `LicenseExpirySyncTest`, `LicenseVerificationTest`, `OtherLicenseServicesFlowTest`, `ProfileApprovalFlowTest`, `PasswordResetFlowTest`.

**Security / RBAC:** `NotificationSecurityTest`, `DashboardAccessControlTest`, `SuperAdminProtectionTest`, `EmployeeSessionSecurityTest`, `DocumentReviewerAuthorizationTest`, `PushDeviceSecurityTest`.

**i18n:** `RequestLocaleTest`, `NotificationLocalizationTest`, `CitizenBilingualMessagesTest`, `CitizenCatalogLocalizationTest`, `CitizenContentLocalizationTest`, `CitizenLanguagePreferenceTest`, `LicenseVerificationLocalizationTest`, Agent bilingual/catalog tests. See `TESTING_LOCALIZATION.md`.

**Dashboard:** `DashboardAuthTest`, `DashboardAccessControlTest`, `DashboardReportsTest`, `DashboardPaymentManagementTest`, `DashboardDocumentReviewTest`, `DashboardIssuedLicensesTest`, `DashboardAppointmentSlotsTest`, `DashboardEmployeeSessionsTest`, `EmployeeManagementTest`.

**Push:** `PushDelivery*`, `FcmClientTest`, `SendPushNotificationJobTest`, `Firebase*`.

**Content:** `ContentPagesTest`, `ContactMessageTest`, `SettingsTest`.

Commands:

```bash
php artisan test
php artisan test --filter=AIAgent
```

Do not stop on failures unless proven pre-existing and unrelated. Do not invent coverage percentages.

---

## 30. Current implemented features

Citizen auth (email OTP + unused phone columns), profile review, applications for five **catalog** service codes (unblock create currently blocked by eligibility), documents, payments mock+Stripe + reconcile, appointments/tests/retest, license issue/renew/replace/block/unblock, public verify, print, expiry sync, fines list + **admin mark paid**, notifications+optional push, dashboard RBAC (direct permissions, sessions, reports, catalogs, fees, dual document-review APIs), content/FAQ/contact, settings (language/theme), SYRTAK OTP email templates, local `/dev-dashboard`, **AI Agent bilingual E2E for supported citizen services**.

---

## Current Known Limitations and Technical Debt

Handoff limitations a new agent must treat as **current reality**, not bugs to drive-by-fix.

### Stale satellite documentation and source-of-truth precedence

Follow the **Source precedence** at the top of this file: code → tests → this file → maintained docs → stale docs/chat.

Stale or partially stale files (do not copy blindly):

- `README.md` — phone OTP, chatbot product, mock-only payments, Agent Phase 9A-only
- `DLMS_AI_AGENT_CONTEXT_COMPACT.md` — pre-interactions, pre-Phase 2.2–2.6.1
- `FLUTTER_AI_AGENT.md` — stale document-flow action aliases `confirm_agent_upload` / `choose_manual_upload` (code: `choose_agent_document_upload` / `choose_manual_document_upload`)
- Older AI Agent sections of `SYRTAK_FINAL_SRS_SOURCE_OF_TRUTH.md` vs Phase 2.5–2.6.1
- Historical Cursor chats
- `GET /api/ping` still reports `'phase' => 9` (leftover, not a product version)
- Empty `Route::prefix('chatbot')` leftover — not a product
- `lang/en/*` leftover Laravel stubs vs `resources/lang/{ar,en}/messages.php`

### Test-count snapshot date

Last known full-suite snapshot is **2026-08-08 (Phase 2.6.1):** ~209 AI Agent tests, ~780 full suite, 7 AI Agent HTTP routes. Re-run `php artisan test` before claiming current counts. Counts drift; this file does not replace a live run.

### Incomplete `license_unblock` application creation

`LicenseServiceEligibilityService::check()` rejects **all** blocked licenses before `checkUnblock()`, so REST/Agent **create** of `service_type_code=license_unblock` currently fails (`blocked_service`). No `RequiredDocumentsSeeder` rows for this service. Citizen `POST /licenses/{id}/unblock-request` is ack-only. Employee unblock APIs are the working mutation. Do not silently complete this path.

### Flutter and Next.js are outside this repository

This repo is the Laravel API only. Citizen Flutter and employee Next.js dashboard are external clients. Do not expect `.dart` / Next source here.

### Both `/admin` and `/dashboard` APIs are still live

Overlapping employee capabilities exist on both `/api/admin/*` and `/api/dashboard/*` (document review, license block/unblock, reports). Do not delete one surface as “legacy” without an explicit request.

### `ApplicationRepository::transitionStatus()` has no internal transition guard

It will persist **any** `ApplicationStatus` the caller passes. The lifecycle is enforced only by domain services. Never call it from a controller, Agent handler, or new helper with an invented next status.

### Gemini key required for live Agent chat

PHPUnit mocks `GeminiAgentClient`. Manual/local Agent conversation needs a real `GEMINI_API_KEY`. Tests use a dummy key and must keep Gemini mocked.

### Current permissive CORS configuration

`config/cors.php`: `paths` include `api/*`; `allowed_origins` is `['*']`; `supports_credentials` is `false`. Do not treat this as production-hardened. Do not tighten it as a drive-by change.

### Absence of a committed CI pass report

There is no committed CI green-report artifact in this repository. Do not invent coverage percentages or claim CI status from this file.

### Other technical debt (do not drive-by-fix)

- Application `rejected`/`cancelled` exist in enum/notifications but have **no live production `transitionStatus` callers**
- Issuance years (`LICENSE_VALIDITY_YEARS=10`) vs seeded `license_types.validity_years=5`
- Test fees seeded but never charged at booking
- `AgentWorkflowActionMap` contains non-executable action names leftover from earlier design
- Document max size 5120 vs 4096 dual enforcement
- Direct renew/replace skip `LicenseIssued` notification
- `administrative_review` has no automated issuance path
- Appointment center names are free-text (not catalog-localized)
- Unknown future document/test codes fall back to Arabic DB `name` until mapped
- Fine payment not implemented (citizen or Agent); admin can mark paid

---

## 32. Pending work / future improvements

Only if product asks — do **not** start these as drive-by work:

- Completing `license_unblock` (fix eligibility order + seed documents + employee completion path) — only if product asks
- Completing unblock-request into a real status mutation (or clearly keeping ack-only)
- Charging test fees at booking (currently catalog-only)
- Aligning issuance years with `license_types.validity_years`
- Citizen/Agent fine payment
- `get_license_details` Agent intent
- Live reject/cancel application workflows
- RAG (explicitly out of Agent phases)
- New AI HTTP endpoints
- Schema for bilingual catalog columns (avoid unless code maps are insufficient)
- Tightening CORS for production
- Flutter/Next apps live in other repos

---

## 33. Architectural decisions that must NOT be accidentally changed

1. **One domain backend.** Agent never duplicates eligibility, fees, status transitions, or SQL writes.
2. **Mutations need confirmation** (Agent) when `AI_AGENT_REQUIRE_CONFIRMATION` is true.
3. **Cancel Agent action = no side effects.**
4. **Tests only for `new_license`.**
5. **Fail-closed unknown service codes.**
6. **Upload ≠ submit-for-review.**
7. **Cancel appointment does not change application status.**
8. **No RAG.** No new AI routes. Flutter Agent surface stays 3 operational endpoints.
9. **Selection tokens** over raw IDs for Agent UI choices.
10. **Flutter must not parse `reply`** for workflow.
11. **Four locale systems:** HTTP request, `users.language` (notifications/settings), Agent session locale, dashboard Arabic — keep them distinct.
12. **Catalog labels by code**, not Gemini translation of DB Arabic names.
13. **Language switch must not clear pending_workflow.**
14. **No Laravel Gates / Spatie.** Custom RBAC.
15. **DB notification is source of truth;** push is optional delivery.
16. **Do not invent fine payment, retest fees, or rejected/cancelled app flows.**
17. **Do not send document bytes to Gemini.**
18. **Never commit secrets.** Never put tokens in this file or logs.
19. **EnsurePermission is OR**, not AND.
20. **Do not “fix” `license_unblock` eligibility, test-fee charging, or issuance years** unless the user asks — current behavior is the contract until then.
21. **Accept-Language is never persisted.**
22. **Duplicate active applications** are rejected (type+service or related-license+service).
23. **Phone OTP is unused**; do not switch auth back to SMS.
25. **`transitionStatus` is unguarded.** Only existing domain services may call it, and only along the documented lifecycle.
26. **Agent status allow-list** (`AgentApplicationStatusMap`) must not diverge from domain services and must not become a second rules engine.

---

## 34. Coding / project conventions

- Prefer small, surgical diffs. Match existing module layout.
- New HTTP validation → FormRequest. New business rules → domain service, not controller, not Agent handler copy.
- Enums for statuses/codes. Translation keys for citizen strings (`messages.*`); Agent uses `AgentTranslator` fallbacks.
- Feature tests for workflows; keep Phase 2.2–2.6.1 Agent tests green.
- Do not create commits unless the user asks.
- Do not add README/docs unless asked — **except** this master context when maintaining it after architecture changes.
- User (Peter) prefers working directly in code.

---

## 35. Local setup and run commands

```bash
composer install
copy .env.example .env   # Windows
php artisan key:generate
php artisan migrate --seed
# create MySQL database dlms_testing for tests
php artisan test
php artisan serve
# optional:
php artisan queue:listen --tries=1
php artisan schedule:work
composer dev             # serve + queue + pail + vite
php artisan route:list --path=api/ai-agent
```

Dev dashboard: `/dev-dashboard/*` behind `dev.dashboard` middleware (local testing UI).

Postman: `DLMS_API_Postman_Collection.json` (Agent folders 14–14f). Guide: `POSTMAN_API_GUIDE.md`.

---

## 36. Environment requirements (names only — no secrets)

`APP_KEY`, `APP_URL`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_TIMEZONE`, `BUSINESS_TIMEZONE`, `DB_*`, `QUEUE_CONNECTION`, `FILESYSTEM_DISK`, `MAIL_*`, `OTP_EXPIRES_MINUTES`, `OTP_FIXED_CODE`, `OTP_CHANNEL`, `PASSWORD_RESET_TOKEN_EXPIRES_MINUTES`, `PAYMENT_PROVIDER`, `PAYMENT_STALE_PENDING_MINUTES`, `PAYMENT_RECONCILE_BATCH_SIZE`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_CURRENCY`, `STRIPE_SUCCESS_URL`, `STRIPE_CANCEL_URL`, `LICENSE_VALIDITY_YEARS`, `LICENSE_RENEWAL_GRACE_DAYS`, `LICENSE_EXPIRING_SOON_DAYS`, `EMPLOYEE_SESSION_*`, `AI_PROVIDER`, `GEMINI_API_KEY`, `GEMINI_MODEL`, `GEMINI_BASE_URL`, `AI_AGENT_ENABLED`, `AI_AGENT_REQUIRE_CONFIRMATION`, `AI_AGENT_MAX_HISTORY_MESSAGES`, `AI_AGENT_TEMPERATURE`, `AI_AGENT_TIMEOUT_SECONDS`, `AI_AGENT_LOW_CONFIDENCE_THRESHOLD`, `AI_AGENT_DOCUMENT_UPLOAD_TOKEN_TTL`, `AI_AGENT_SELECTION_TOKEN_TTL`, `AI_AGENT_PENDING_WORKFLOW_TTL`, `FIREBASE_PROJECT_ID`, `FIREBASE_CREDENTIALS_BASE64`, `FIREBASE_PUSH_ENABLED`, `FIREBASE_CA_BUNDLE`, `FIREBASE_PUSH_TRIES`, `FIREBASE_PUSH_JOB_MAX_TRIES`, `FIREBASE_PUSH_JOB_TIMEOUT`, `FIREBASE_PUSH_PROCESSING_LEASE`, `FIREBASE_FCM_TIMEOUT_SECONDS`, `FIREBASE_FCM_CONNECT_TIMEOUT_SECONDS`, `DB_QUEUE_RETRY_AFTER`.

Never paste values into docs, commits, or chat logs.

---

## 37. Useful debugging knowledge

- Agent “fell to `general_help`”: check pending-workflow handler order vs Gemini; phrase matcher priority (payment status vs application status; cancel appointment vs current appointments).
- Agent English reply still Arabic: `AgentLocaleContext` not set on interactions; `locale` dropped from session context; `Lang::has` fallback chain; catalog `name` instead of `AgentCatalogLocalizer`.
- 403 on citizen mutating APIs: profile not approved, email unverified, or `is_active=false`.
- `license_unblock` create 422 `blocked_service`: expected with current eligibility order.
- Payment not advancing: wrong application status; Stripe webhook signature; confirming a Stripe row via mock endpoint.
- Slot booking 409: capacity; run `AppointmentSlotConcurrencyTest`. Missing `test_type_id` on slot list.
- Dashboard application 404: looked up numeric id instead of `application_number`.
- Push not sending: `FIREBASE_PUSH_ENABLED`, queue worker, device token.
- OTP: email channel; `OTP_FIXED_CODE` in local/testing; check `OtpMail` not SMS.
- Locale leak on workers: `ResolveRequestLocale` restores default in `finally`.
- Duplicate application: `AgentDuplicateApplicationGuard` + active statuses.
- Stale Agent confirm: action service revalidates; expired pending_workflow TTL; token purpose mismatch.
- Verify shows expired while DB says active: `LicenseEffectiveStatus` vs unsynced row — run expiry command.

Gemini is mocked in tests — local manual Agent chat needs a real `GEMINI_API_KEY`.

Dev-only UI: `/dev-dashboard` 404s outside local/staging/testing (`EnsureDevDashboardAllowed`).

---

## 38. Glossary

| Term | Meaning |
|------|---------|
| SYRTAK / DLMS | This product / Digital License Management System |
| Citizen | Mobile user (`UserType::Citizen`) |
| Employee / dashboard user | Staff (`Employee` or `Admin` user_type) |
| Application | `LicenseApplication` — a service request, not the issued plastic/digital license |
| License | Issued `License` entity |
| Service code | `new_license`, `renew_license`, `lost_replacement`, `damaged_replacement`, `license_unblock` |
| Related license | Existing license required for non-new services |
| Profile review | Employee approval of citizen identity data |
| Document review | Employee approval of uploaded files |
| Active application | Status in `ApplicationStatus::activeCases()` |
| Selection token | HMAC token from `AgentSelectionTokenService`; purpose-bound |
| Pending workflow | Session context awaiting a structured choice |
| Document flow state | `DocumentFlowState` inside session context |
| Catalog localizer | Code → AR/EN display label (Agent and/or REST) |
| Effective license status | `LicenseEffectiveStatus` (active past expiry → expired) |
| Fee identity_key | Unique fee scope key (code + license/service/test) |
| Direct permissions | Extra grants on a user beyond their role |
| Root super admin | Role `super_admin` only |
| Super admin (middleware) | Role `super_admin` **or** `admin` |
| Phase 9B | Original executable Agent action set (now extended by 2.2–2.6.1) |
| NOT_APPLICABLE | Feature absent in domain (e.g. Agent fine payment) |
| Ack-only | API returns success text without mutating domain state |

---

## AI Agent Handoff Instructions

### Inspect first (in this order)

1. `routes/api.php` + `app/Modules/AIAgent/Routes/ai-agent.php` + matching module route files
2. `app/Enums/ApplicationStatus.php`, `ServiceCode.php`, `app/Modules/Applications/Support/ServiceWorkflow.php`
3. `LicenseServiceEligibilityService` if touching renew/replace/unblock
4. Domain service you are touching (`ApplicationService`, `AppointmentService`, `LicenseService`, …)
5. If Agent work: `AIAgentService.php`, `AIAgentController.php` (interaction order), `AgentActionExecutor.php`, `AgentSafetyRules.php`, `AgentApplicationStatusMap.php`, `HandleAgentInteractionRequest.php`, `FLUTTER_AI_AGENT.md`
6. Matching `tests/Feature/*` for that workflow
7. This file, then `SYRTAK_FINAL_SRS_SOURCE_OF_TRUTH.md` for broader SRS-style inventory

### Assumptions you must never make

- Flutter or Next.js source lives in this repo
- README / compact Agent context / ping `phase` / Flutter action aliases are accurate
- Agent is a separate rules engine
- All five services require tests
- `license_unblock` applications can be created today
- Citizens can pay fines through API or Agent (admin marking paid is different)
- Application `rejected`/`cancelled` are live user workflows
- Unblock-request creates an application or unblocks a license
- Test fees are charged when booking
- Issuance uses `license_types.validity_years`
- `permission:a,b` means AND
- You should add RAG, new AI endpoints, or service-specific Agent routes
- You should parse `reply` in Flutter or duplicate that pattern on the backend
- You should translate arbitrary DB free text with Gemini
- `users.language`, Agent session locale, notification locale, and dashboard Arabic are the same field
- Confirming an Agent action can skip stale/ownership checks
- Cancelling an Agent action still runs the domain mutation
- You may invent fees, legal rules, or IDs in Agent replies
- `/admin` routes are obsolete because `/dashboard` exists (both are live)

### Invariants to preserve

Listed in §33. Especially: domain services own rules; Agent orchestrates; confirmation for mutations; bilingual catalog-by-code; 7 AI routes / 3 Flutter operational endpoints; fail-closed workflows; no document bytes to Gemini; do not silently complete `license_unblock`.

### If documentation and implementation disagree

Use source precedence: **code → tests → this file → maintained docs → stale docs/chat**. Then update `docs/PROJECT_MASTER_CONTEXT.md` (and a maintained Flutter/SRS doc only if you were asked to keep them aligned). Do not “fix” production behavior to match a stale README or historical chat.

### How to change the Agent safely

1. Prefer phrase matcher / pending workflow / translator keys over prompt-only fixes.
2. Reuse domain services.
3. Add focused Feature tests (AR+EN if citizen-facing).
4. Run `php artisan test --filter=AIAgent` then full `php artisan test`.
5. Do not expand the HTTP surface.

### How to change domain workflows safely

1. Update enum + `ServiceWorkflow` / eligibility / lifecycle service + notifications.
2. Cover with Feature tests including concurrency if slots/payments.
3. Remember the Agent will inherit the change automatically if it already calls that service — do not fork behavior.

---

*Generated for permanent project memory. No secrets included. Code remains the source of truth.*
