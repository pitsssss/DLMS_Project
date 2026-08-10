# SYRTAK / DLMS — FINAL SRS SOURCE OF TRUTH

**Document type:** Implementation-based requirements source pack (NOT the SRS itself)  
**Audience:** Technical writer reconstructing the FINAL Software Requirements Specification  
**System:** Digital License Management System (DLMS) / SYRTAK  
**Authority order:** (1) executable code (2) automated tests (3) enums/state transitions (4) migrations/models (5) API routes/contracts (6) existing documentation  

**Rules applied in this pack:**
- Describes CURRENT implemented behavior only.
- When docs conflict with code, **code wins** and the discrepancy is stated.
- No secrets, tokens, credentials, private keys, real PII, or environment secret values.
- UML artifacts are references only; they are not higher authority than code.

**Related design artifacts (project root):**
- `SYRTAK_COMPLETE_ERD.drawio`
- `SYRTAK_COMPLETE_ACTIVITY_DIAGRAMS.drawio`
- `SYRTAK_ACTIVITY_DIAGRAMS_REPORT_AR.md`
- `SYRTAK_COMPLETE_SEQUENCE_DIAGRAMS.drawio`
- `SYRTAK_SEQUENCE_DIAGRAMS_REPORT_AR.md`
- Postman: `postman/SYRTAK_Flutter_API.postman_collection.json`
- Narrative README: `README.md` (partially stale — see §31)

---

## 1. PROJECT EXECUTIVE SUMMARY

### Purpose
SYRTAK/DLMS is a Laravel REST API backend for government-style digital driving-license services: citizen onboarding, application workflow, document review, payments, appointments/tests, license issuance/lifecycle, notifications, employee dashboard operations, RBAC, and a controlled citizen AI Agent.

### Implemented system scope

| Area | Status |
|------|--------|
| Citizen auth (register, email OTP verify, login, logout, password reset OTP) | IMPLEMENTED |
| Citizen profile + employee profile review | IMPLEMENTED |
| License applications (`new_license`, `renew_license`, `lost_replacement`, `damaged_replacement`, `license_unblock` as service codes) | IMPLEMENTED (with path nuances) |
| Documents upload / submit / employee review | IMPLEMENTED |
| Payments (mock + Stripe + webhook + dashboard verify) | IMPLEMENTED |
| Appointments book / reschedule / cancel + slots | IMPLEMENTED |
| Tests vision→theory→practical + retest + administrative_review | IMPLEMENTED (new_license only) |
| License issue / renew / replace / block / unblock (employee) / public verify | IMPLEMENTED |
| Direct citizen renew/replace APIs | IMPLEMENTED |
| Citizen unblock-request acknowledgment | PARTIALLY IMPLEMENTED (no status/application mutation) |
| Fines list + admin create/update status | PARTIALLY IMPLEMENTED (no citizen pay-fine checkout) |
| In-app Notification Center | IMPLEMENTED |
| Firebase Push (device register + delivery pipeline) | IMPLEMENTED (push may be disabled by config) |
| AI Agent (Gemini) with confirmation for mutating actions | IMPLEMENTED (Phase 9B executable subset) |
| Dashboard RBAC, employees, sessions, reports, catalogs/fees | IMPLEMENTED |
| Application statuses `rejected` / `cancelled` as live transitions | NOT IMPLEMENTED (enum/notify wired only) |
| AI execute renew/replace/unblock | NOT IMPLEMENTED (mutating listed; not Phase 9B executable) |
| Generic `/chatbot` route group | NOT IMPLEMENTED (empty prefix) |
| RAG for AI | NOT IMPLEMENTED |
| Mock OTP / Mock payment provider | SIMULATED / TEST MODE (configurable; Stripe is real integration path) |

### Major components
- **Backend:** Laravel modular API (`app/Modules/*`)
- **Citizen client:** Flutter mobile (API consumer; not in this repo as source of truth for backend rules)
- **Employee client:** Web Dashboard (Next.js/web consuming `/api/dashboard` and `/api/admin`)
- **AI Agent:** Backend-orchestrated Gemini citizen assistant
- **External:** Stripe (optional), Firebase FCM (optional), email OTP channel, local/private file storage, queue workers

### Responsibility split
| Party | Responsibility |
|-------|----------------|
| Citizen mobile | UX, collect inputs, call citizen APIs, FCM token, Agent chat UI |
| Employee dashboard | Review queues, payments verify, tests, issuance, RBAC, reports, sessions |
| Backend | AuthZ/AuthN, business rules, state machine, persistence, notifications, integrations |
| AI Agent | Interpret intent, gather slots, propose mutating actions, confirm, call same domain services |
| External systems | Payment capture (Stripe), push delivery (FCM), LLM completion (Gemini) |

---

## 2. ACTORS AND USER CLASSES

| Actor | Type | Responsibility |
|-------|------|----------------|
| Citizen | Human | Register, profile, applications, docs, pay, book, track, licenses, fines (read), notifications, AI Agent |
| Profile/Document Reviewer | Employee role `profile_document_reviewer` | `review_profiles`, `review_documents` |
| Financial Employee | Employee role `payment_employee` | `view/manage_payments`, view applications |
| Examiner/Tester | Employee role `test_employee` | appointments + `record_test_result` |
| Issuance Employee | Employee role `license_employee` | `issue_license`, `manage_licenses`, view applications/licenses |
| Fines Employee | `fines_employee` | view/manage fines, view licenses |
| Application Manager | `application_manager` | view/manage applications |
| Reports/Audit/Settings employees | dedicated roles | reports, audit logs, settings/contact messages |
| Admin | role `admin` | permission bypass (`*`); Super-admin middleware includes this |
| Super Admin | role `super_admin` | bypass + root-only session management (`isRootSuperAdmin`) |
| Legacy `employee` | bundled multi-permission role | assignable legacy bundle |
| AI Agent | System participant | Citizen-facing assistant; not an employee |
| Payment Gateway | External | Mock or Stripe |
| Firebase FCM | External | Push delivery |
| Gemini LLM | External | Agent NLU/response generation |

**Citizen role** `citizen`: protected, not assignable as dashboard employee; empty dashboard permissions.

**Permission model:** custom middleware `permission` + `User::hasPermission` / role+direct grants. **No Laravel Gates.**

**Important distinctions:**
- `isSuperAdmin()` = role `super_admin` OR `admin`
- `isRootSuperAdmin()` = role `super_admin` only (employee-sessions APIs)
- Middleware `dashboard` requires employee-type dashboard access + `access_dashboard` (unless super admin)

---

## 3. COMPLETE MODULE INVENTORY

| Module | Purpose | Status | Major entities | Main routes (prefix `/api`) | Main services | Major ops | Main tests |
|--------|---------|--------|----------------|-----------------------------|---------------|-----------|------------|
| Authentication | Register/OTP/login/logout/password | COMPLETE | User, Otp, tokens | `/auth/*` | AuthService, OtpService | register, verifyOtp, login, logout, forgot/reset | PasswordResetFlowTest, OtpDebugLoggingTest |
| Citizen Profile | Complete/update/status | COMPLETE | User profile fields | `/profile/*`, `/auth/me` | ProfileService | complete, update, status | ProfileApprovalFlowTest |
| Profile Review | Approve/reject profiles | COMPLETE | User.profile_* | `/admin/profile-reviews/*` | ProfileReviewService | approve/reject | ProfileApprovalFlowTest |
| Applications | Create/list/show draft apps | COMPLETE | LicenseApplication, histories | `/applications` | ApplicationService, ApplicationRepository | createDraft, transitionStatus | ApplicationFlowTest, OtherLicenseServicesFlowTest |
| Documents | Required list, upload | COMPLETE | ApplicationDocument, RequiredDocument | `/applications/{id}/documents`, required-documents | ApplicationDocumentService | upload | DocumentFlowTest |
| Document Review | Submit + employee review | COMPLETE | docs + app status | submit-documents; `/admin/documents/*`; `/dashboard/document-reviews/*` | DocumentReviewService | submitForReview, approve/reject | DocumentFlowTest, DashboardDocumentReviewTest |
| Payments | Fees + pay flow | COMPLETE | Payment, Fee, PaymentGatewayEvent | `/applications/{id}/payments*`, `/webhooks/stripe` | ApplicationPaymentService, PaymentLifecycleService | create, confirm, webhook complete | PaymentFlowTest, PaymentStripeTest |
| Financial Ops | Dashboard verify/reconcile | COMPLETE | payments | `/dashboard/payments/*` | DashboardPaymentService, PaymentReconciliationService | verify | DashboardPaymentManagementTest |
| Service Fees | Fee catalog CRUD dashboard | COMPLETE | Fee | `/dashboard/fees*` | Dashboard fee services | manage fees | DashboardFeesManagementTest |
| Fines | List/create/update status | PARTIAL | Fine | citizen `GET /fines`; admin `/admin/fines*` | Fine services | list; admin create/update | (dashboard/admin coverage) |
| Appointments | Book/reschedule/cancel | COMPLETE | TestAppointment, AppointmentSlot, Center | appointments + slots | AppointmentService | book/reschedule/cancel | AppointmentFlowTest |
| Appointment Slots | Availability/capacity | COMPLETE | AppointmentSlot | citizen GET slots; dashboard slot CRUD | AppointmentService / DashboardAppointmentSlot* | list/manage | AppointmentSlotConcurrencyTest, DashboardAppointmentSlotsTest |
| Tests | Ordered exam types | COMPLETE | TestType | catalogs `/test-types` | TestProgressionService | sequence | AvailableTestsApiTest |
| Test Results | Record outcomes | COMPLETE | TestResult | `/admin/test-appointments/{id}/record-result` | TestResultService | recordForAppointment | LicenseFlowTest, AppointmentFlowTest |
| Retest | waiting_retest path | COMPLETE | app status + appointments | same appointment/result APIs | TestProgressionService | rebook/record | Appointment/License flows |
| License Issuance | Issue from application | COMPLETE | License | `/admin/applications/{id}/issue-license` | LicenseService | issueForApplication | LicenseFlowTest |
| Issued Licenses | Citizen/employee views, print, verify | COMPLETE | License | `/licenses*`, dashboard issued, public verify | LicenseService, LicensePrintService, LicenseVerificationService | list/show/print/verify | LicenseVerificationTest, LicensePrintingTest |
| Renewal | App path + direct API | COMPLETE | LicenseApplication / License | apps + `POST /licenses/{id}/renew` | ApplicationService, LicenseService | renew | OtherLicenseServicesFlowTest |
| Lost/Damaged Replacement | App + direct | COMPLETE | same | apps + `POST /licenses/{id}/replacement` | LicenseService | replace | OtherLicenseServicesFlowTest |
| License Block/Unblock | Employee block/unblock; citizen request ack | PARTIAL | License | admin/dashboard block/unblock; citizen unblock-request | LicenseService | block/unblock/requestUnblock | LicenseFlowTest |
| Citizen Management | Dashboard citizens | COMPLETE | User | `/dashboard/citizens*` | DashboardCitizen* | view/manage | DashboardCitizenManagementTest |
| Employee Management | CRUD employees | COMPLETE | User, Role | `/dashboard/employees*` | DashboardEmployeeService | create/update/assignRole | EmployeeManagementTest |
| RBAC | Roles/permissions/access-control | COMPLETE | Role, Permission | `/dashboard/roles*`, `/access-control*` | DashboardAccessControlService, PermissionRegistry | assign perms | DashboardAccessControlTest, DashboardPermissionTest |
| Employee Sessions | Track/revoke | COMPLETE | EmployeeSession | `/dashboard/employee-sessions*` | EmployeeSessionService | start/heartbeat/revoke | EmployeeSession* tests |
| Dashboard Overview | KPIs | COMPLETE | aggregates | `/dashboard/overview` | Dashboard overview services | summary | DashboardOverviewTest |
| Reports | Domain reports | COMPLETE | aggregates | `/dashboard/reports/*`, `/admin/reports/overview` | Dashboard report services | filter reports | DashboardReportsTest |
| Localization | ar/en | COMPLETE | users.language, lang files | middleware `locale` | RequestLocaleResolver, translators | resolve locale | RequestLocaleTest, NotificationLocalizationTest, … |
| AI Agent | Citizen assistant | PARTIAL (9B subset) | AIAgentSession/Message/Action/Evaluation | `/ai-agent/*` | AIAgentService, AIAgentActionService, AgentActionExecutor | message/confirm/cancel/upload | AIAgent* Feature tests |
| In-App Notifications | Notification Center | COMPLETE | Notification | `/notifications*` | NotificationService | list/unread/mark | NotificationCenterApiTest, … |
| Firebase Push | Device + delivery | COMPLETE (config-gated) | PushDevice, PushDelivery | `/devices/push-token` | PushDeviceService, PushDeliveryService, FcmClient | register/plan/send | Push* / Fcm* tests |
| Content | FAQ/privacy/contact | COMPLETE | Faq, ContactMessage | content routes | Content services | public content + contact | ContentPagesTest, ContactMessageTest |
| Settings | Preferences/password | COMPLETE | User prefs | `/settings*` | Settings services | preferences | SettingsTest |
| Catalogs | license/service/test types | COMPLETE | LicenseType, ServiceType, TestType | public GETs + dashboard CRUD | catalog services | list/manage | ServiceTypesApiTest, Dashboard* |
| Audit Logs | Sensitive action audit | COMPLETE | AuditLog | `/admin` audit views | AuditLogService | log/view | notification/audit related |
| Dev Dashboard | Local testing UI | COMPLETE (dev) | — | `/dev-dashboard/*` | Dev controllers | manual testing | DevDashboardTest |

---

## 4. API INVENTORY

Base prefix: `/api`. Locale middleware on public/citizen groups.

### Citizen / public (highlight)

| METHOD | URI | Auth | Actor | Purpose |
|--------|-----|------|-------|---------|
| POST | `/auth/register` | public | Citizen | Register inactive account + send OTP |
| POST | `/auth/verify-otp` | public | Citizen | Activate + token |
| POST | `/auth/login` | public | Citizen | Login |
| POST | `/auth/forgot-password` | public+throttle | Citizen | Start reset OTP |
| POST | `/auth/verify-forgot-password-otp` | public+throttle | Citizen | Verify reset OTP |
| POST | `/auth/reset-password` | public+throttle | Citizen | Reset password |
| POST | `/auth/logout` | sanctum | Citizen/Employee | Revoke current token |
| GET | `/auth/me` | sanctum | Authenticated | Current user |
| PUT | `/profile/complete` | sanctum | Citizen | Complete profile → pending_review |
| PUT | `/profile/update` | sanctum | Citizen | Update profile |
| PUT | `/profile/change-password` | sanctum | Citizen | Change password |
| GET | `/profile/status` | sanctum+citizen | Citizen | Profile status payload |
| GET | `/license-types`, `/service-types`, `/test-types` | public | Any | Catalogs |
| GET | `/licenses/verify/{verificationToken}` | public+throttle | Any | Public license verify |
| GET/POST | `/applications…` | sanctum+citizen (+ profile.approved for mutating) | Citizen | App CRUD/docs/payments/appointments/results |
| PUT/DELETE | `/appointments/{id}/reschedule\|cancel` | sanctum+citizen+profile.approved | Citizen | Reschedule/cancel |
| GET | `/appointment-slots` | sanctum+citizen | Citizen | Available slots |
| GET/POST | `/licenses…` renew/replacement/unblock-request | sanctum+citizen (+ profile.approved mutating) | Citizen | License ops |
| GET | `/fines` | sanctum+citizen | Citizen | Own fines |
| GET/PUT | `/notifications*` | sanctum+citizen | Citizen | Notification Center |
| POST/DELETE | `/devices/push-token` | sanctum+citizen | Citizen | Push device lifecycle |
| POST/GET | `/ai-agent/*` | sanctum+citizen | Citizen | AI Agent |
| POST | `/webhooks/stripe` | public+throttle | Stripe | Payment webhook |
| GET/PUT | settings/content routes | per module | Citizen/public | Preferences/content |

### Dashboard / Admin (highlight)

| Area | Prefix | Auth | Purpose |
|------|--------|------|---------|
| Dashboard auth | `/dashboard/auth/*` | public then sanctum | Employee login/forgot/reset |
| Overview/reports/slots/payments/citizens/docs/employees/apps/licenses/fees/types | `/dashboard/*` | sanctum+dashboard+session track | Operational dashboard |
| Access control | `/dashboard/access-control/*` | super_admin | RBAC UI APIs |
| Employee sessions | `/dashboard/employee-sessions/*` | root_super_admin | Session visibility/revoke |
| Profile reviews | `/admin/profile-reviews/*` | permission review_profiles | Profile approve/reject |
| Document reviews | `/admin/documents/*` | review_documents | Doc approve/reject |
| Record test | `/admin/test-appointments/{id}/record-result` | record_test_result | Exam results |
| Issue license | `/admin/applications/{id}/issue-license` | issue_license | Issuance |
| Manage licenses | `/admin/licenses/{id}/block\|unblock` | manage_licenses | Block/unblock |
| Fines/audit/reports | `/admin/fines|audit|reports…` | matching permissions | Admin ops |

**Classification note for SRS author:** catalog GETs and many report GETs are supporting APIs; empty `/chatbot` group is not a product API.

---

## 5. AUTHENTICATION & ACCOUNT REQUIREMENTS

### Citizen
1. **Register** → user created inactive; email OTP (`OtpPurpose::register`).
2. **Verify OTP** → activate, set `email_verified_at`, issue Sanctum token.
3. **Login** requires active citizen with verified email.
4. **Logout** deletes current access token.
5. **Forgot/reset password** via OTP purposes for citizen (and separate dashboard forgot flow).
6. **Token mechanism:** Laravel Sanctum personal access tokens.
7. **Profile prerequisite:** mutating service APIs require middleware `profile.approved`.

### OTP — ACTUAL
- **IMPLEMENTED** for registration and password recovery.
- Channel configuration defaults to **email** (not phone verification as primary production path).
- **Discrepancy:** `README.md` still says “verify phone using mock OTP” in places — **outdated vs code**.

### Employee
- Separate `/dashboard/auth/*` via `DashboardAuthService`.
- Dashboard middleware enforces employee dashboard user + permissions.
- Employee sessions started on dashboard login; tracked by `employee.session.track`.

### Separation
- `user_type` / role distinguish citizen vs employee/admin.
- Citizen middleware `EnsureCitizen`; dashboard `EnsureDashboardUser`.

---

## 6. APPLICATION TYPES AND APPLICATION LIFECYCLE

### Service codes (`ServiceCode`)
`new_license`, `renew_license`, `lost_replacement`, `damaged_replacement`, `license_unblock`

| Rule | Implementation |
|------|----------------|
| Tests required | **only** `new_license` |
| Related license required | renew, lost, damaged, unblock |
| Produces license on issue | new, renew, lost, damaged |
| Fee codes | `application_fee`, `renewal_fee`, `lost_replacement_fee`, `damaged_replacement_fee`, `unblock_fee` |
| Unknown service codes | fail-closed |

### ApplicationStatus values

| STATUS | Meaning | Terminal? | Production transition callers |
|--------|---------|-----------|-------------------------------|
| `draft` | Created, docs not submitted | No | createDraftForCitizen |
| `documents_under_review` | Submitted for doc review | No | submitForReview |
| `documents_rejected` | At least one required doc rejected | No | DocumentReviewService.reject |
| `payment_pending` | All required docs approved | No | DocumentReviewService.approve (all done) |
| `payment_completed` | Brief intermediate after pay | No | PaymentLifecycleService (then immediate next) |
| `appointment_pending` | Paid; tests required | No | after payment if requiresTests |
| `in_testing` | Has/entered testing | No | book from appointment_pending; pass mid-sequence |
| `waiting_retest` | Failed/no-show under max attempts | No | TestResultService |
| `administrative_review` | Attempts exhausted | No (activeCases) | TestResultService at max attempts |
| `approved` | Ready for issuance (or post-pay non-test services) | No (activeCases) | payment path without tests; all tests passed |
| `license_issued` | License issued | Yes (not active) | LicenseService.issueForApplication |
| `rejected` | Catalogued rejection | Intended terminal | **NO production transitionStatus caller** |
| `cancelled` | Catalogued cancel | Intended terminal | **NO production transitionStatus caller** |

### Valid lifecycle (production)
```
draft
 → documents_under_review
    → documents_rejected → (reupload/resubmit) → documents_under_review
    → payment_pending
       → payment_completed
          → appointment_pending   [new_license only]
             → in_testing
                → waiting_retest ↔ in_testing
                → administrative_review
                → approved
          → approved              [renew/lost/damaged/unblock fee path]
             → license_issued
```

### Other rules
- **Ownership:** citizen owns application; employees act by permission.
- **Duplicate/active prevention:** enforced in application creation logic / active status set (`ApplicationStatus::activeCases`).
- **Cancellation/rejection of applications:** enum+notifications exist; **not** live workflow outcomes in production services.
- **Type differences:** tests only for new license; related_license_id for non-new services.

---

## 7. CITIZEN PROFILE

### Required complete fields
`name`, `national_id`, `birth_date`, `governorate`, `address`

### Flow
1. After auth, citizen completes profile → `ProfileStatus::pending_review`.
2. Sensitive updates may re-enter pending review.
3. Employee approve → `approved` (unlocks `profile.approved` APIs).
4. Reject → `rejected` + `profile_rejection_reason`; citizen can resubmit/complete again.

### ProfileStatus
`incomplete`, `pending_review`, `approved`, `rejected`

### Separation
**Profile Review ≠ Document Review** (different entities, routes, services, statuses), even if same employee role may hold both permissions.

---

## 8. DOCUMENT MANAGEMENT

### Exact semantics
1. **List required documents** for application.
2. **UPLOAD DOCUMENT** → stores private file + metadata; document status `pending_review`. Does **not** alone move application to under review.
3. **SUBMIT DOCUMENTS FOR REVIEW** → application `documents_under_review` (from `draft` or `documents_rejected`).
4. Employee **approve/reject** each document (`approved`/`rejected`) with structured `DocumentRejectionReason` codes.
5. Any reject → application `documents_rejected`.
6. All required approved → application `payment_pending`.
7. Citizen may reupload rejected docs and resubmit.

### DocumentStatus
`pending_review`, `approved`, `rejected`

### Validation (implemented)
- Form request max upload **5120 KB**.
- Service uses required-doc `max_size_kb` (default **4096** if null) + allowed extensions (jpg/jpeg/png/pdf) + MIME map.
- **Ambiguity for SRS:** dual size limits (request vs service default) — document both.

### Storage
Private/local disk for application documents (not public CDN exposure).

---

## 9. PAYMENTS AND FINANCIAL OPERATIONS

| Topic | Current truth |
|-------|---------------|
| Providers | `PAYMENT_PROVIDER`: `mock` (default) or `stripe` |
| Create payment | Citizen when app `payment_pending` |
| Mock confirm | `POST .../payments/{payment}/confirm` |
| Stripe | Checkout session + **`POST /api/webhooks/stripe` IMPLEMENTED** |
| PaymentStatus | `pending`, `completed`, `failed`, `under_verification` |
| Completion effect | `payment_completed` then `appointment_pending` OR `approved` |
| Dashboard verify | `POST /dashboard/payments/{id}/verify` → reconcile |
| Currency | Application fee catalog USD-oriented config |
| Citizen fine payment | **NOT IMPLEMENTED** |
| Production-only claims | Do not claim “live bank gateway beyond Stripe config” unless deployed with secrets (out of SRS code scope) |

Notifications on payment completed/failed/under_verification + resulting application status notifications.

---

## 10. APPOINTMENTS

| Topic | Truth |
|-------|-------|
| Slots | Capacity-managed `appointment_slots` |
| Book | Requires tests workflow; statuses `appointment_pending|in_testing|waiting_retest` |
| On book from `appointment_pending` | App → `in_testing` |
| Reschedule | Only `booked` appointments |
| Cancel | → `cancelled`; frees capacity; **does not auto-change application status** |
| AppointmentStatus | `booked`, `cancelled`, `completed`, `no_show` |
| Notifications | booked / rescheduled / cancelled |

---

## 11. TEST / EXAMINATION FLOW

### Order (seeded)
1. `vision`
2. `theory`
3. `practical`  
(`specialized` exists in enum; not in default seeder sequence)

### Rules
- Only `new_license` requires tests.
- Progression enforced (`TestProgressionService`): prior must pass; one bookable type at a time.
- Examiner records via admin API.
- **Pass + more remain** → stay in testing path.
- **Pass + all required done** → `approved`.
- **Fail/no_show** under `max_attempts` (seeded **3**) → `waiting_retest`.
- **Fail/no_show at max** → `administrative_review`.
- Appointment becomes `completed` or `no_show`.
- **Retest fee as separate mandatory step:** **NOT evidenced** as a distinct required payment step in current retest path — do not invent.

### TestResultStatus
`passed`, `failed`, `no_show`, `pending`

---

## 12. LICENSE ISSUANCE

### Prerequisites (eligibility service)
Application ready (typically `approved`), documents/payments/tests as required by service, unpaid fines checks, etc. (`LicenseIssuanceEligibilityService`).

### Flow
Employee `issue_license` → `LicenseService::issueForApplication` → branches new/renew/replace → app `license_issued`, license usually `active`.

### Notifications
Direct `LicenseIssued` notification. Application status map returns null for `license_issued` (legacy suppressed).

### LicenseStatus
`active`, `expired`, `suspended`, `blocked`, `renewed`, `inactive`

### Citizen access
List/show own licenses; public verify by token; printing supported for employees.

---

## 13. RENEWAL

### Path A — Application workflow
Create app `renew_license` + `related_license_id` → documents → payment → **no tests** → `approved` → employee issue (`issueRenewalLicense`) → old license handled as renewed in issuance branch.

### Path B — Direct API
`POST /licenses/{id}/renew` → new `active`, old `renewed`.  
**No LicenseIssued notification** on this direct path.

Do not document renewal as identical to new-license (no tests; dual entry points).

---

## 14. LOST / DAMAGED REPLACEMENT

| Aspect | Truth |
|--------|-------|
| Distinction | **Yes** — `lost_replacement` vs `damaged_replacement` (+ different fee codes) |
| Application path | Docs + payment → approved → issue replacement; old becomes inactive in issuance branch |
| Direct path | `POST /licenses/{id}/replacement` with `lost|damaged` → new active, old `inactive`; **no direct LicenseIssued notify** |
| Unblock related | Employee block/unblock; citizen `unblock-request` returns acknowledgment only if blocked and no unpaid fines — **does not create application or change status** |
| Application `license_unblock` service | Supported as service code/fee workflow for applications (distinct from direct requestUnblock) |

---

## 15. AI AGENT — CRITICAL

### Architecture facts
- Provider family: **Gemini** (`config/ai.php` provider default `gemini`; model family configurable, default flash-class).
- **RAG: NOT USED.**
- Confirmation default: **true** (`require_confirmation`).
- Sessions/messages/actions/evaluations persisted.
- Conversation locale managed in session context (`AgentLocaleContext`) — **separate** from `users.language` notification locale.
- Agent is **not** a second backend: mutating execution goes through `AgentActionExecutor` → existing domain services.

### Capability table

| Agent capability | Type | Confirmation | Domain service / notes |
|------------------|------|--------------|------------------------|
| get_application_status | Read-only | No | Application query |
| get_application_next_step | Read-only | No | AgentApplicationNextStepService / domain |
| get_required_documents | Read-only | No | ApplicationDocumentService |
| get_application_fee | Read-only | No | fee resolver |
| get_payment_status | Read-only | No | payments |
| get_available_tests | Read-only | No | appointments/tests |
| get_appointment_slots | Read-only | No | AppointmentService |
| get_current_appointments | Read-only | No | AppointmentService |
| get_test_results | Read-only | No | tests |
| get_licenses | Read-only | No | LicenseService |
| get_fines | Read-only | No | fines |
| get_profile_status | Read-only | No | ProfileService |
| get_license_details | Read-only (mapped) | — | **NOT in Phase 9B executor** |
| get_notifications | Read-only (mapped) | — | **NOT in Phase 9B executor** |
| create_application | Mutating | Yes | ApplicationService |
| start_payment | Mutating | Yes | ApplicationPaymentService |
| submit_documents_for_review | Mutating | Yes | ApplicationDocumentService |
| book_appointment | Mutating | Yes | AppointmentService |
| reschedule_appointment | Mutating | Yes | AppointmentService |
| cancel_appointment | Mutating | Yes | AppointmentService |
| renew_license | Mutating listed | Yes conceptually | **NOT executable Phase 9B** |
| request_license_replacement | Mutating listed | Yes conceptually | **NOT executable Phase 9B** |
| request_unblock | Mutating listed | Yes conceptually | **NOT executable Phase 9B** |
| Admin actions (approve doc, issue, etc.) | Denied | — | Blocked for citizens |

### Confirmation / cancel / stale
- Mutating → pending/`awaiting_confirmation` → citizen confirm/cancel endpoints (or affirmative chat detector).
- **Cancel = no domain mutation.**
- Ownership + stale/invalid action protection enforced in action services.
- Document upload: `POST /ai-agent/sessions/{session}/documents` → `AgentDocumentUploadService` → `ApplicationDocumentService` (file contents **not** required to be sent to Gemini).

### Manual vs Agent convergence
Manual Flutter APIs and Agent executor call the **same domain services** and state machine.

---

## 16. IN-APP NOTIFICATIONS

- **Source of truth:** DB `notifications` via `NotificationService`.
- Ownership by `user_id`.
- APIs: list, unread-count, mark one read, mark all read.
- Types: `NotificationType` enum (profile/application/payment/appointment/test/license/…).
- Dedup via event keys where implemented.
- Localization: recipient `users.language` via `RecipientNotificationTranslator` (does not flip global app locale).
- Safe metadata only; no secrets.
- Created after business success; push planning is separate afterCommit side effect.

---

## 17. FIREBASE PUSH NOTIFICATIONS

### Client-facing APIs only
- `POST /api/devices/push-token`
- `DELETE /api/devices/push-token`

### Server pipeline
`notify` → `DB::afterCommit` → persist notification → `planPushSafely` → `PushDeliveryService::planForNotification` → per-device `push_deliveries` → async `SendPushNotificationJob` → `FcmClient` (HTTP v1) → FCM.

### Behaviors
- Multi-device supported (one delivery per device).
- Token rotation via re-register.
- Token stored hashed/encrypted internally (do not specify secret material in SRS).
- Backoff `[60,120,300,900]` seconds; tries/job limits in `config/firebase.php`.
- UNREGISTERED → invalid_token (+ cleanup semantics).
- Worker recovery commands exist for pending/stale processing.
- At-least-once delivery semantics via jobs/retries.
- **Failure isolation:** FCM failure does **not** roll back business transaction or DB notification.
- Push may be disabled via config flag.

---

## 18. LOCALIZATION

| Topic | Truth |
|-------|-------|
| Languages | `ar`, `en` (default `ar`) |
| Accept-Language | Request-scoped resolution; **not persisted** |
| Precedence | Accept-Language (supported) → `users.language` → default |
| Preferences API | Can set `users.language` |
| Notifications | Recipient language from `users.language` |
| Machine codes | Remain English (`documents_under_review`, platforms, etc.) |
| Historical notifications | Stored localized copy at creation time (do not imply Flutter re-translates push body) |
| AI conversation language | Session context locale; separate from notification recipient language |

---

## 19. RBAC / EMPLOYEE MANAGEMENT

- Custom RBAC (roles + permissions tables), seeded from `config/dashboard_permissions.php` via registry/bootstrap.
- 13 system roles (see §2); ~35 permission strings.
- Employee CRUD + role assignment on dashboard.
- Access-control management requires `super_admin` middleware (admin OR super_admin bypass roles).
- Enforcement: route middleware `permission:…`, dashboard gate, policies where present for ownership.
- Not Spatie (unless later added — **currently custom**).

---

## 20. EMPLOYEE SESSIONS

- Started on dashboard login.
- Activity tracking middleware updates session.
- Heartbeat/last-seen supported in session services/tests.
- Root super admin can list/revoke sessions (`root_super_admin`).
- Statuses: `active`, `idle`, `expired`, `logged_out`, `revoked` (+ ended-reason enum).

---

## 21. REPORTS / DASHBOARD / ADMIN OPERATIONS

Implemented surfaces include:
- Overview KPIs
- Reports: applications, tests, appointments, licenses, fines, employees, summary/options
- Citizen management
- Document review queues (dashboard + admin)
- Profile review (admin)
- Payments management/verify
- Appointment slot management
- Issued licenses / license types / service types / fees
- Employee & RBAC management
- Audit log viewing
- Settings/contact messages (permissioned)
- License block/unblock
- Test result recording
- License issuance

---

## 22. DATA MODEL SUMMARY

| Entity | Purpose | Key relationships |
|--------|---------|-------------------|
| User | Citizens/employees | role, profile fields, language |
| Role / Permission | RBAC | many-to-many style grants |
| LicenseApplication | Service request | citizen, license_type, service_type, related_license, status |
| ApplicationStatusHistory | Status audit | application |
| RequiredDocument / ApplicationDocument | Doc requirements & uploads | application, reviewer |
| Fee / Payment / PaymentGatewayEvent | Pricing & payments | application/fee/user |
| Fine | Citizen fines | citizen, optional license |
| AppointmentCenter / AppointmentSlot / TestAppointment | Scheduling | test type, capacity |
| TestType / TestResult | Exams | application, appointment |
| License / LicenseStatusHistory | Issued licenses | citizen, application, previous_license |
| Notification | In-app truth | user |
| PushDevice / PushDelivery | Push channel | user, notification |
| Otp / PasswordResetToken | Auth recovery | user |
| EmployeeSession | Dashboard sessions | employee |
| AuditLog | Sensitive actions | actor |
| AIAgentSession / Message / Action / Evaluation | Agent state | citizen |
| Faq / ContactMessage | Content | — |
| LicenseType / ServiceType | Catalogs | applications/fees/docs |

Cross-check terminology with `SYRTAK_COMPLETE_ERD.drawio`.

---

## 23. BUSINESS RULES

| ID | Rule | Evidence | Module |
|----|------|----------|--------|
| BR-01 | Citizen mutating service APIs require approved profile | middleware `profile.approved` | Profile/Apps |
| BR-02 | Only `new_license` requires tests | `ServiceWorkflow::requiresTests` | Applications/Tests |
| BR-03 | Renew/lost/damaged/unblock require related license | `requiresRelatedLicense` | Applications |
| BR-04 | Upload ≠ submit-for-review | Document service methods | Documents |
| BR-05 | Any doc reject → `documents_rejected` | DocumentReviewService | Documents |
| BR-06 | All required docs approved → `payment_pending` | DocumentReviewService | Documents |
| BR-07 | Successful payment from `payment_pending` → `payment_completed` then appointment_pending or approved | PaymentLifecycleService | Payments |
| BR-08 | Booking from `appointment_pending` → `in_testing` | AppointmentService | Appointments |
| BR-09 | Cancel appointment does not auto-change application status | AppointmentService | Appointments |
| BR-10 | Test order vision→theory→practical; max attempts seeded 3 | TestTypesSeeder + progression | Tests |
| BR-11 | Fail under max → `waiting_retest`; at max → `administrative_review` | TestResultService | Tests |
| BR-12 | Issue requires eligibility; sets `license_issued` | LicenseIssuanceEligibilityService + LicenseService | Licenses |
| BR-13 | Direct renew: old→`renewed`; direct replace: old→`inactive` | LicenseService | Licenses |
| BR-14 | Agent mutating actions require confirmation when enabled | AgentWorkflowActionMap + config | AI Agent |
| BR-15 | Agent cancel performs no domain mutation | AIAgentActionService.cancel | AI Agent |
| BR-16 | Agent executes via domain services, not direct DB writes | AgentActionExecutor | AI Agent |
| BR-17 | DB notification is source of truth; FCM is delivery | NotificationService + PushDeliveryService | Notifications |
| BR-18 | Push planned afterCommit; FCM failure isolated | NotificationService::runAfterCommit | Notifications |
| BR-19 | Permissions enforced by middleware; super_admin/admin bypass | EnsurePermission / User::hasPermission | RBAC |
| BR-20 | Root super admin only for employee-session admin APIs | EnsureRootSuperAdmin | Sessions |
| BR-21 | Active application statuses exclude issued/rejected/cancelled | ApplicationStatus::activeCases | Applications |
| BR-22 | Unpaid fines can block unblock/issue paths | LicenseService checks | Licenses/Fines |
| BR-23 | Document MIME/extension whitelist | AllowedDocumentMime + required_documents | Documents |
| BR-24 | OTP required to activate citizen registration | AuthService + OtpService | Auth |

---

## 24. FUNCTIONAL REQUIREMENTS SOURCE

### AUTH
- Register citizen with email/password (+ optional phone/name).
- Send/verify registration OTP over configured channel (email default).
- Login/logout with Sanctum token.
- Forgot/reset password via OTP.
- Separate employee dashboard authentication.

### PROFILE
- Complete required profile fields.
- Update profile; re-review when required.
- Employee approve/reject profile with reason.
- Gate service mutations on approved profile.

### APP
- Create applications for supported service codes.
- Enforce related-license and duplicate/active constraints.
- Track status history.
- List/show owned applications.

### DOC
- List required documents.
- Upload private documents with validation.
- Submit package for review.
- Employee approve/reject with coded reasons.
- Resubmit after rejection.

### PAY
- Resolve fee by service.
- Create pending payment.
- Confirm mock payment OR complete via Stripe webhook.
- Mark failed/under_verification.
- Employee verify/reconcile.
- Transition application after successful payment.

### APT
- List available slots with capacity.
- Book/reschedule/cancel with ownership and state checks.
- Notify on booking changes.

### TEST
- Enforce ordered tests for new licenses.
- Record pass/fail/no_show.
- Advance, retest, or administrative_review.

### LIC
- Issue licenses from eligible applications.
- Citizen list/show; public verify.
- Direct renew/replace.
- Employee block/unblock.
- Citizen unblock-request acknowledgment.
- Print/manage issued licenses on dashboard.

### AI
- Chat sessions with locale context.
- Read-only auto queries.
- Propose mutating actions with confirmation.
- Confirm/cancel actions.
- Upload documents through agent session endpoint.
- Deny admin actions to citizens.

### NOT
- Persist localized notifications.
- Notification Center read APIs.
- Event-driven creation from domain services.

### PUSH
- Register/rotate/unregister device tokens.
- Plan deliveries per device.
- Async FCM send with retry/invalid-token handling.

### ADMIN
- Dashboard overview/reports.
- Citizen/employee/RBAC management.
- Review queues, payments, slots, fees, catalogs.
- Employee session admin for root super admin.
- Audit log viewing.
- Fines admin management.

---

## 25. NON-FUNCTIONAL REQUIREMENTS — IMPLEMENTATION EVIDENCE

| NFR Area | What implementation guarantees | Evidence | Unknown/unmeasured |
|----------|--------------------------------|----------|--------------------|
| Security | AuthN/AuthZ middleware, ownership checks, private files, hashed tokens, credential providers for FCM | middleware, services, tests (security suites) | No claimed penetration-test score |
| Performance | No formal latency SLA in code | — | Response-time targets unknown |
| Reliability | afterCommit notifications; push retries/backoff; payment reconciliation helpers | Notification/Push/Payment code | Uptime SLA unknown |
| Availability | Queue workers assumed for push | config/commands | Deployment HA unknown |
| Scalability | Stateless API + queue jobs pattern | architecture | Load-test numbers unknown |
| Maintainability | Modular `app/Modules`, enums, feature tests | structure | — |
| Usability | AR/EN API messages; dashboard labels | localization tests | Client UX outside backend |
| Localization | ar/en with Accept-Language + user preference | localization module | — |
| Privacy | Private document disk; notification metadata discipline | storage/services | Legal retention policy unknown |
| Auditability | AuditLogService on sensitive actions; status histories | services | Completeness vs all events: review NotificationEventMatrix |
| File security | Extension/MIME/size checks; private storage | document service | Malware scanning not evidenced |
| Queue reliability | job tries, lease recovery, backoff | firebase config + jobs | Broker SLA unknown |
| External failure isolation | Payment under_verification; FCM fail leaves DB notify | payment/push services | — |
| Concurrency | Slot locking/tests for appointments; payment integrity tests | concurrency tests | — |
| Data integrity | DB transactions around status/payment transitions | repositories/services | — |

**Do not invent numeric SLAs** (uptime %, ms latency) in final SRS unless separately mandated.

---

## 26. SECURITY AUDIT SUMMARY FOR SRS

Implemented protections relevant to requirements:
- Sanctum authentication; citizen/employee separation.
- Permission middleware; super-admin bypass explicitly coded.
- Citizen ownership checks on applications/appointments/notifications/licenses.
- Profile gate for mutating citizen services.
- Form requests / service validation.
- Document type/size validation + private storage.
- OTP throttling on password reset routes.
- Stripe webhook endpoint public but signature/event handling in payment gateway services (document as implemented verification path without secrets).
- AI action ownership + confirmation + admin-action denial.
- Firebase credentials via provider/config (never embed in SRS).
- Logging: avoid claiming “no sensitive logs” absolutely; OTP debug logging is test-controlled.

No unaudited penetration-test claims.

---

## 27. EXTERNAL INTERFACES

| Interface | Purpose | Direction | Protocol | Status |
|-----------|---------|-----------|----------|--------|
| Flutter citizen app | Citizen UX | Client→API | HTTPS JSON REST | IMPLEMENTED (consumer) |
| Employee Dashboard web | Staff UX | Client→API | HTTPS JSON REST | IMPLEMENTED (consumer) |
| MySQL (typical) | Persistence | Backend↔DB | SQL | IMPLEMENTED |
| Stripe | Card checkout | Backend↔Stripe | HTTPS + webhook | IMPLEMENTED (optional provider) |
| Mock payment | Dev/test payments | Internal | API confirm | SIMULATED |
| Firebase FCM | Push | Backend→FCM | HTTP v1 | IMPLEMENTED (optional enable) |
| Gemini | Agent LLM | Backend→Gemini | HTTPS | IMPLEMENTED |
| Email (OTP) | Verification codes | Backend→mail | Mail transport | IMPLEMENTED |
| Local/private storage | Documents | Backend↔disk | filesystem | IMPLEMENTED |
| Queue | Async push | Backend↔worker | Laravel queue | IMPLEMENTED |
| Public license verify | Third-party check | Public→API | HTTPS | IMPLEMENTED |

---

## 28. OPERATING / DEPLOYMENT ENVIRONMENT

Verified from project docs/config (no secrets):
- Laravel 11 PHP API
- Relational DB (tests use MySQL `dlms_testing` in phpunit.xml)
- Queue workers required for push jobs
- Docker / cloud deployment referenced in README
- HTTPS expected for real clients/webhooks
- Storage local/private for documents
- Optional Supervisor/worker for queues in deployment

Do not publish credential/env values in SRS.

---

## 29. ERROR / EDGE CASE INVENTORY

Verified handled cases include:
- Invalid OTP / login credentials
- Unapproved profile blocked from mutating services
- Duplicate/invalid application creation conditions
- Invalid application state for upload/submit/pay/book/issue
- Unauthorized ownership (IDOR-oriented tests exist for notifications/docs)
- Missing/incomplete documents on submit
- Document reject + resubmit
- Payment failed / under_verification
- Appointment slot capacity conflicts
- Reschedule/cancel invalid appointment state
- Test fail → retest; max attempts → administrative_review
- Agent cancel; stale/invalid action; non-executable proposed actions
- Push disabled / no device / invalid token / retryable FCM failures
- Unblock-request when not blocked or unpaid fines present
- Super-admin protection rules

---

## 30. TEST EVIDENCE

- **~103** `*Test.php` files under `tests/` (Feature + Unit).
- `phpunit.xml` configures isolated testing DB and mock payment; AI confirmation true.
- **No committed latest full-suite pass/fail report** found in repository — do not fabricate counts.
- Coverage is strong for: applications/docs/payments/appointments/licenses/notifications/push/AI/RBAC/sessions/localization.
- See module inventory (§3) and Feature class lists from audit for SRS Traceability.

---

## 31. OLD SRS GAP ANALYSIS

**No dedicated SRS file exists** in repository. Closest narrative: `README.md`.

| Old README / narrative item | Current Status | Required Change |
|-----------------------------|----------------|-----------------|
| Verify phone via mock OTP | OUTDATED | SRS must say email OTP registration/recovery as implemented |
| OTP Service = phone verification | OUTDATED | Align to email-channel OTP |
| Mock payments only | EXPANDED | Mock **or** Stripe + webhook |
| Chatbot group / generic chatbot | REMOVED / NOT IMPLEMENTED | Document AI Agent instead; empty `/chatbot` not a feature |
| AI Agent controlled Phase 9 | STILL VALID / EXPANDED | Specify Phase 9B executable subset + non-executable mutating license ops |
| Application statuses including rejected/cancelled | PARTIAL | Keep in status catalog; mark transitions not live |
| Actors employee roles | STILL VALID / EXPANDED | Use seeded system roles + permissions registry |
| Notifications | EXPANDED | DB center + Firebase push pipeline |
| Localization AR/EN | STILL VALID / EXPANDED | Accept-Language vs users.language vs Agent locale |
| Renewal/replacement | EXPANDED | Dual paths (application + direct) |
| Fines | PARTIAL | Admin manage + citizen list; no citizen pay |
| Security/NFR numeric claims | UNKNOWN if asserted historically | Only evidence-based NFRs |

---

## 32. UML / DESIGN ARTIFACT REFERENCES

| Artifact | Path | Role |
|----------|------|------|
| ERD | `SYRTAK_COMPLETE_ERD.drawio` | Data structure reference |
| Activity Diagrams | `SYRTAK_COMPLETE_ACTIVITY_DIAGRAMS.drawio` | Process reference |
| Activity Report AR | `SYRTAK_ACTIVITY_DIAGRAMS_REPORT_AR.md` | Arabic process explanation |
| Sequence Diagrams | `SYRTAK_COMPLETE_SEQUENCE_DIAGRAMS.drawio` | Interaction reference |
| Sequence Report AR | `SYRTAK_SEQUENCE_DIAGRAMS_REPORT_AR.md` | Arabic interaction explanation |
| Use Case / Class / Communication / State final packs | **Not found as canonical final files** | If needed, derive from code; do not invent |

Code remains higher authority than UML if conflict appears.

---

## 33. REQUIREMENTS TRACEABILITY SOURCE

| Module | Business Rules | Main APIs | Services | Models | Tests | UML |
|--------|----------------|-----------|----------|--------|-------|-----|
| Auth | BR-24 | `/auth/*` | AuthService, OtpService | User, Otp | PasswordResetFlowTest, Otp* | Act 02 / Seq 02 |
| Profile | BR-01 | `/profile/*` | ProfileService | User | ProfileApprovalFlowTest | Act 03 / Seq 03 |
| Profile Review | BR-01 | `/admin/profile-reviews/*` | ProfileReviewService | User | ProfileApprovalFlowTest | Act 03,15 / Seq 04,25 |
| Applications | BR-02,03,21 | `/applications` | ApplicationService, ApplicationRepository | LicenseApplication | ApplicationFlowTest | Act 04,17 / Seq 05 |
| Documents | BR-04,05,06,23 | documents + submit | ApplicationDocumentService | ApplicationDocument | DocumentFlowTest | Act 05 / Seq 06–07 |
| Payments | BR-07 | payments + webhook | PaymentLifecycleService | Payment | PaymentFlowTest, PaymentStripeTest | Act 08 / Seq 12–13 |
| Appointments | BR-08,09 | appointments/slots | AppointmentService | TestAppointment | AppointmentFlowTest | Act 09 / Seq 14–15 |
| Tests | BR-10,11 | record-result | TestResultService | TestResult | Appointment/License tests | Act 10 / Seq 16–17 |
| Licenses | BR-12,13,22 | issue/renew/replace/block | LicenseService | License | LicenseFlowTest | Act 11–13 / Seq 18–20 |
| AI Agent | BR-14,15,16 | `/ai-agent/*` | AIAgent* / Executor | AIAgent* | AIAgent* tests | Act 07 / Seq 09–11 |
| Notifications | BR-17 | `/notifications*` | NotificationService | Notification | NotificationCenter* | Act 14 / Seq 21 |
| Push | BR-18 | `/devices/push-token` | PushDeliveryService, FcmClient | PushDevice/Delivery | Push*/Fcm* | Act 14 / Seq 22–24 |
| RBAC | BR-19 | `/dashboard/employees|access-control` | Dashboard* RBAC services | Role/Permission | AccessControl/Employee tests | Act 16 / Seq 26 |
| Sessions | BR-20 | `/dashboard/employee-sessions` | EmployeeSessionService | EmployeeSession | EmployeeSession* | Seq 27 |

---

## 34. KNOWN GAPS / AMBIGUITIES

| Ambiguity | Conflicting sources | Recommended SRS wording |
|-----------|---------------------|-------------------------|
| Application `rejected`/`cancelled` | Enum + notify maps vs no production transition callers | “Statuses exist for future/compatibility; no current end-user workflow transitions into them.” |
| Citizen `unblock-request` vs `license_unblock` application | Direct ack-only API vs service-code application path | Document both explicitly as distinct behaviors. |
| AI renew/replace/unblock | Mutating map vs Phase 9B non-executable | “May be proposed/confirmed architecture-wise but not currently executed by AgentActionExecutor.” |
| Document max size 5120 vs 4096 | FormRequest vs service default | “Request allows up to 5120KB; service also enforces required-document max (default 4096KB).” |
| OTP phone vs email | README vs code | Prefer code: email OTP. |
| Retest payment | Generic licensing assumptions vs code | “No separate mandatory retest fee step evidenced in current retest path.” |
| Administrative_review exit | Status exists; limited automated exit to issuance | “Requires administrative handling; do not invent automatic issuance.” |
| Push enabled in production | config default false possible | “Push delivery operates when enabled and devices registered.” |
| Latest full test run result | Not in repo | “Test suite exists; latest CI/local run result not packaged in this source pack.” |
| Flutter/Next exact versions | Outside backend truth | Keep as external clients consuming documented APIs. |

**Do not resolve these by guessing.**

---

## 35. FINAL SRS READINESS VERDICT

| Metric | Count / note |
|--------|----------------|
| Implemented modules (COMPLETE) | ~30 major modules/areas in inventory |
| Partial modules | AI Agent executable subset; Fines (no citizen pay); Unblock-request ack-only; rejected/cancelled transitions |
| Uncovered/ambiguous items | Listed in §34 |
| Major old-doc mismatches | OTP phone narrative; chatbot; payment-only-mock; status completeness assumptions |

### Conclusion

This pack is sufficient for a technical writer to author the FINAL SRS for the **currently implemented** SYRTAK/DLMS system, provided ambiguities in §34 are carried forward as explicit SRS caveats rather than silently filled.

**SRS SOURCE PACK READY**

---

*End of SYRTAK_FINAL_SRS_SOURCE_OF_TRUTH.md*  
*Generated from current repository implementation and tests. No application code was modified.*
