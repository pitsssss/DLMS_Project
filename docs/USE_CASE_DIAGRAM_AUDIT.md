# SYRTAK / DLMS — Use Case Diagram Audit

**Document type:** Pre-diagram audit (no UML XML / draw.io in this step)  
**Product:** SYRTAK / Digital License Management System (DLMS)  
**Authority:** current implementation first, then automated tests, then maintained docs  
**Audit date:** 2026-08-14  
**Code modified:** none (this file only)

### How this audit was done

Inspected live routes, controllers, services, FormRequests, middleware, permission registry, enums, models, scheduler, and current documentation (`docs/PROJECT_MASTER_CONTEXT.md`, `SYRTAK_FINAL_SRS_SOURCE_OF_TRUTH.md`, activity/sequence reports, README, Agent Flutter docs, dashboard contract).

Rules applied:

- Business-goal Use Cases only. Endpoints, list/show/stats/options helpers, and dual URL surfaces are **evidence**, not Use Cases.
- No invented features. Incomplete or catalog-only paths are labelled **PARTIAL** or **NOT A USE CASE**.
- Old UC numbering is **not** reused. IDs below are a proposed fresh scheme for the final diagram.
- AI Agent is an **alternative channel** over the same domain services, not a second product.
- Dev-only `/dev-dashboard` and empty `/api/chatbot` are **out of the business diagram**.

Source precedence when docs disagree with code: **code → tests → `docs/PROJECT_MASTER_CONTEXT.md` → other docs**.

---

## 1. Actors found in the implemented system

Actors are derived from `UserType`, dashboard roles in `config/dashboard_permissions.php`, middleware (`citizen`, `dashboard`, `super_admin`, `root_super_admin`), and public (unauthenticated) routes.

### 1.1 Human actors

| Actor ID | Name | Kind | Evidence | Notes |
|----------|------|------|----------|-------|
| A-GUEST | Guest | Unauthenticated public | `routes/api.php` public group; `app/Modules/Content/Routes/content.php` | Catalogs, content, contact form, public license verify, citizen register/login/OTP. |
| A-CIT | Citizen | Authenticated mobile user | `UserType::Citizen`; `EnsureCitizen`; citizen role is protected and **not** a dashboard role | Primary actor for license services. Inactive citizens (`is_active=false`) are rejected by citizen middleware. Mutating services also require `profile.approved`. |
| A-EMP | Employee | Abstract dashboard user | `UserType::Employee` or `Admin`; `EnsureDashboardUser`; permission `access_dashboard` | Parent actor. Concrete work is performed by specialized roles. Legacy role `employee` is a bundled permission set, not a distinct business actor. |
| A-REV | Profile and Document Reviewer | Specialized employee | Role `profile_document_reviewer`; permissions `review_profiles`, `review_documents` | One seeded role holds both permissions (SoD vs application management). Two business capabilities, one person-type. |
| A-APP | Application Manager | Specialized employee | Role `application_manager`; `view_applications`, `manage_applications` | Application **inspection** is implemented. No production transition to `rejected` / `cancelled`. |
| A-PAY | Payment Employee | Specialized employee | Role `payment_employee`; `view_payments`, `manage_payments` | Inspect payments and verify under-verification rows. |
| A-TST | Test Employee | Specialized employee | Role `test_employee`; `view_appointments`, `manage_appointments`, `record_test_result` | Slot capacity + recording results. |
| A-LIC | License Employee | Specialized employee | Role `license_employee`; `view_licenses`, `issue_license`, `manage_licenses` | Issue, inspect/print, block, unblock. |
| A-FIN | Fines Employee | Specialized employee | Role `fines_employee`; `view_fines`, `manage_fines` | Fine CRUD lives on `/api/admin/fines`, not a dashboard fines CRUD resource. |
| A-RPT | Reports Employee | Specialized employee | Role `reports_employee`; `view_reports` | Domain reports are further gated by the related domain permission. |
| A-AUD | Audit Employee | Specialized employee | Role `audit_employee`; `view_audit_logs` | Audit list plus nested audit fragments on other dashboard resources. |
| A-SET | Settings Employee | Specialized employee | Role `settings_employee`; `manage_settings`, contact-message permissions | License types, service types, fees, contact-message handling. |
| A-ADM | Admin | Specialized employee | Role `admin`; `User::isSuperAdmin()` | Permission bypass (`*`). **Cannot** call root-only employee-session admin APIs. |
| A-SA | Super Admin | Specialized employee | Role `super_admin`; `isRootSuperAdmin()` | Bypass plus access-control CRUD and employee-session supervision. |

**Not modelled as separate human actors**

| Candidate | Why excluded |
|-----------|----------------|
| Flutter app / Next.js dashboard | Clients, not actors. They are the interface the actor uses. |
| Legacy role `employee` | Assignable bundled role; capabilities already covered by specializations. |
| RBAC role `citizen` | Protected account marker, not a dashboard employee. |
| Distinct “Profile Reviewer” vs “Document Reviewer” persons | Seeded as **one** role. Permissions remain separate (see §7 and §10). |

### 1.2 In-system (non-human) actors

These may appear on the diagram only if the author wants system swimlanes. They are **not** external organizations.

| Actor ID | Name | Evidence | Role on a Use Case diagram |
|----------|------|----------|----------------------------|
| A-AI | AI Agent (SYRTAK orchestrator) | `app/Modules/AIAgent/*`; citizen-only `/api/ai-agent/*` | Secondary participant on citizen service Use Cases. Calls the **same** domain services after confirmation. Not an employee. Not a second backend. |
| A-TIME | Scheduler | `routes/console.php` | Primary actor of scheduled maintenance Use Cases (payment reconcile, license expiry, session reconcile). |

---

## 2. External systems / services

| Actor ID | Name | Integration | Evidence | Business role |
|----------|------|-------------|----------|---------------|
| X-MAIL | Mail / SMTP | Outbound email | `OtpMail`, `MAIL_*`, `OTP_CHANNEL=email` | Delivers registration and password-reset OTPs (citizen and dashboard). Phone columns exist; **SMS OTP is unused**. |
| X-PAY | Payment Gateway | Stripe Checkout + webhook; mock provider in tests/local | `PAYMENT_PROVIDER`, `StripeWebhookController`, `MockPaymentGatewayService` | Captures application fees. Mock is an internal substitute, **not** a second external actor. |
| X-LLM | Gemini | Agent NLU | `GeminiAgentClient`, `config/ai.php` | Interprets citizen messages. Must not invent fees, IDs, or legal rules. Files are never sent to Gemini. |
| X-FCM | Firebase Cloud Messaging | Push delivery | `FcmClient`, `SendPushNotificationJob`, `FIREBASE_PUSH_ENABLED` | Optional delivery channel. Database `notifications` remain the source of truth. |

**Not external actors (internal infrastructure)**

- Private disk (`Storage::disk('local')`) for application documents  
- Queue worker (implementation of push delivery)  
- Sanctum token store  
- Optional unused S3 env names  

**Not present (do not draw)**

- SMS gateway  
- Government identity provider  
- RAG / vector database  
- Citizen fine-payment PSP  
- Generic chatbot vendor  

---

## 3. Business Use Cases grouped by capability

Proposed IDs are **new**. They are not claimed to match any historical numbering (none was found as a canonical UC pack).

| Group | Proposed UCs | Primary actors |
|-------|----------------|----------------|
| Public information | UC-PUB-01 … UC-PUB-04 | Guest |
| Citizen account | UC-CIT-01 … UC-CIT-06 | Citizen / Guest |
| License services | UC-CIT-07 … UC-CIT-17 | Citizen |
| Citizen communications | UC-CIT-18 … UC-CIT-20 | Citizen |
| Employee access | UC-EMP-01 … UC-EMP-02 | Employee |
| Review work | UC-REV-01 … UC-REV-02 | Reviewer |
| Application / payment ops | UC-APP-01, UC-PAY-01 | Application Manager, Payment Employee |
| Tests | UC-TST-01 … UC-TST-02 | Test Employee |
| License ops | UC-LIC-01 … UC-LIC-04 | License Employee |
| Fines / citizens | UC-FIN-01, UC-USR-01 | Fines Employee, citizen manager |
| Administration | UC-HR-01, UC-RBAC-01, UC-SES-01 | Super Admin / Admin / employee manager |
| Oversight | UC-RPT-01, UC-AUD-01 | Reports / Audit employees |
| Configuration | UC-SET-01, UC-MSG-01 | Settings Employee |
| Scheduled / supporting | UC-SYS-01 … UC-SYS-04 | Scheduler / system |

**Explicitly not Use Cases**

| Item | Reason |
|------|--------|
| Every GET list/show/stats/options/search | Supporting queries inside a business UC |
| Dual `/api/admin/*` vs `/api/dashboard/*` URLs | Same capability, two HTTP surfaces |
| `GET /api/ping` | Health leftover (`phase: 9`) |
| Empty `/api/chatbot` | Not a product |
| `/dev-dashboard/*` | Local testing UI only |
| Agent intents one-by-one | Channel actions, not business goals |
| `license_unblock` **application create** | Currently fails eligibility (BR-29) |
| Citizen / Agent fine checkout | Not implemented |
| Application reject / cancel by a user | Enum only; no production `transitionStatus` caller |
| Dashboard create-citizen | `StoreDashboardCitizenRequest` exists; **no route** |
| Dashboard manage test types / required-document catalog | No dashboard CRUD routes |
| Employee send-notification / AI-agent monitoring APIs | Permissions exist; **no routes** |
| License suspend | Status exists on `LicenseStatus`; **no suspend API** |

---

## 4. Use Case catalog

Legend for **Status**: Implemented | Partial | Supporting (included by others; still a named UC).

### 4.1 Public information

#### UC-PUB-01 Browse service catalogs

| Field | Value |
|-------|--------|
| Name | Browse service catalogs |
| Primary actor | Guest (also Citizen) |
| Secondary actors | — |
| Permission | Public |
| Status | Implemented |
| Evidence | `GET /api/license-types`, `/service-types`, `/test-types`; `LicenseTypeController`, `ServiceTypeController`, `TestTypeController`; `CitizenCatalogLabel` |
| Notes | Supporting input to apply/renew/replace. Catalog `name` is localized by **code**. |

#### UC-PUB-02 Verify a driving license

| Field | Value |
|-------|--------|
| Name | Verify a driving license |
| Primary actor | Guest |
| Secondary actors | — |
| Permission | Public; throttle 30/min |
| Status | Implemented |
| Evidence | `GET /api/licenses/verify/{verificationToken}`; `LicenseVerificationController`; `LicenseVerificationService`; succeeds only for **effective** active licenses (`LicenseEffectiveStatus`) |
| Notes | QR/token verification. Distinct from citizen “view own licenses”. |

#### UC-PUB-03 Read public information

| Field | Value |
|-------|--------|
| Name | Read public information |
| Primary actor | Guest |
| Secondary actors | — |
| Permission | Public |
| Status | Implemented |
| Evidence | `GET /api/content/faqs`, `/privacy-policy`, `/contact-info`; `ContentController`; `CitizenContentLocalizer` |
| Notes | FAQ / privacy / contact info. Not employee CMS. |

#### UC-PUB-04 Submit a contact inquiry

| Field | Value |
|-------|--------|
| Name | Submit a contact inquiry |
| Primary actor | Guest |
| Secondary actors | Settings Employee (later handling via UC-MSG-01) |
| Permission | Public; throttle 20/min |
| Status | Implemented |
| Evidence | `POST /api/contact-messages`; `ContactMessageController`; `StoreContactMessageRequest`; `ContactMessageService` |

---

### 4.2 Citizen account

#### UC-CIT-01 Register and activate an account

| Field | Value |
|-------|--------|
| Name | Register and activate an account |
| Primary actor | Guest → becomes Citizen |
| Secondary actors | Mail / SMTP |
| Permission | Public |
| Status | Implemented |
| Evidence | `POST /api/auth/register`, `POST /api/auth/verify-otp`; `RegisterController`; `AuthService`; `OtpService`; `OtpPurpose::Register`; `OtpMail` |
| Notes | Creates an inactive citizen, emails OTP, then activates and issues a Sanctum token. Phone OTP is **not** used. |

#### UC-CIT-02 Sign in

| Field | Value |
|-------|--------|
| Name | Sign in |
| Primary actor | Citizen |
| Secondary actors | — |
| Permission | Public login endpoint; resulting token is citizen-scoped |
| Status | Implemented |
| Evidence | `POST /api/auth/login`; `LoginController`; `AuthService` |
| Notes | Employee tokens cannot call `EnsureCitizen` routes. |

#### UC-CIT-03 Recover account access

| Field | Value |
|-------|--------|
| Name | Recover account access |
| Primary actor | Guest / Citizen |
| Secondary actors | Mail / SMTP |
| Permission | Public; throttle 5/min |
| Status | Implemented |
| Evidence | `POST /api/auth/forgot-password`, `verify-forgot-password-otp`, `reset-password`; `ForgotPasswordController`; `OtpPurpose::ForgotPassword` |

#### UC-CIT-04 Sign out

| Field | Value |
|-------|--------|
| Name | Sign out |
| Primary actor | Citizen (also any Sanctum user on this route) |
| Secondary actors | — |
| Permission | `auth:sanctum` |
| Status | Implemented |
| Evidence | `POST /api/auth/logout`; `LogoutController` |
| Notes | Revokes the current token. Push unregister is a separate UC. |

#### UC-CIT-05 Complete identity profile

| Field | Value |
|-------|--------|
| Name | Complete identity profile |
| Primary actor | Citizen |
| Secondary actors | Profile and Document Reviewer (UC-REV-01) |
| Permission | `auth:sanctum`; profile status APIs also require `citizen` |
| Status | Implemented |
| Evidence | `PUT /api/profile/complete`, `PUT /api/profile/update`, `GET /api/profile/status`; `ProfileController`; `ProfileService`; `ProfileStatus` |
| Notes | Required fields: name, national_id, birth_date, governorate, address. Completion/sensitive update → `pending_review`. Approved profile is a **precondition** for mutating license services, not an `<<include>>`. |

#### UC-CIT-06 Manage account preferences

| Field | Value |
|-------|--------|
| Name | Manage account preferences |
| Primary actor | Citizen |
| Secondary actors | — |
| Permission | `auth:sanctum` + `citizen` |
| Status | Implemented |
| Evidence | `GET /api/settings`, `PUT /api/settings/preferences`, `PUT /api/settings/change-password`; also `PUT /api/profile/change-password`; `SettingsController`; `SettingsService` |
| Notes | Persists `language` and `theme`. `Accept-Language` is **never** written to `users.language`. |

---

### 4.3 Citizen license services

#### UC-CIT-07 Apply for a new driving license

| Field | Value |
|-------|--------|
| Name | Apply for a new driving license |
| Primary actor | Citizen |
| Secondary actors | Reviewer, Payment Employee, Test Employee, License Employee, Payment Gateway, AI Agent (optional channel) |
| Permission | `citizen` + `profile.approved` to create |
| Status | Implemented |
| Evidence | `POST /api/applications` with `service_type_code=new_license`; `ApplicationController@store`; `ApplicationService`; `ServiceWorkflow::requiresTests` true; tests `ApplicationFlowTest` |
| Notes | Creates `draft`. Duplicate active application for same citizen + license type + service is rejected (BR-23). Tests required only for this service. |

#### UC-CIT-08 Renew a driving license

| Field | Value |
|-------|--------|
| Name | Renew a driving license |
| Primary actor | Citizen |
| Secondary actors | Reviewer, Payment Employee, License Employee, Payment Gateway (application path); AI Agent (optional) |
| Permission | `citizen` + `profile.approved` |
| Status | Implemented (dual path — see §10) |
| Evidence | **Application path:** `POST /api/applications` `renew_license` + `related_license_id` → docs → pay → `approved` → employee issue. **Direct path:** `POST /api/licenses/{id}/renew`; `LicenseService::renew` immediately issues a successor (`old → renewed`). `LicenseServiceEligibilityService`; `OtherLicenseServicesFlowTest` |
| Notes | Direct path skips documents, payment, and employee issuance, and does not emit `LicenseIssued`. Eligibility: Active or Expired; not before `expiry - LICENSE_RENEWAL_GRACE_DAYS` while still Active. |

#### UC-CIT-09 Replace a lost or damaged license

| Field | Value |
|-------|--------|
| Name | Replace a lost or damaged license |
| Primary actor | Citizen |
| Secondary actors | Reviewer, Payment Employee, License Employee, Payment Gateway (application path); AI Agent (optional) |
| Permission | `citizen` + `profile.approved` |
| Status | Implemented (dual path — see §10) |
| Evidence | Application: `lost_replacement` / `damaged_replacement` (distinct fees). Direct: `POST /api/licenses/{id}/replacement` `{type: lost\|damaged}`; `LicenseService::replace` (`old → inactive`). Blocked licenses cannot be replaced. |
| Notes | Lost vs damaged are one business goal with two fee/document variants, not two diagram Use Cases. |

#### UC-CIT-10 Provide application documents

| Field | Value |
|-------|--------|
| Name | Provide application documents |
| Primary actor | Citizen |
| Secondary actors | Document Reviewer; AI Agent (optional upload channel) |
| Permission | `citizen` + `profile.approved` for upload/submit |
| Status | Implemented |
| Evidence | `GET .../required-documents`, `GET/POST .../documents`, `POST .../submit-documents`; Agent `POST /api/ai-agent/sessions/{session}/documents`; `ApplicationDocumentService`; `AgentDocumentUploadService`; private disk storage |
| Notes | Upload ≠ submit (BR-04). Approved documents cannot be replaced (BR-28). No required-document rows are seeded for `license_unblock`. |

#### UC-CIT-11 Pay application fees

| Field | Value |
|-------|--------|
| Name | Pay application fees |
| Primary actor | Citizen |
| Secondary actors | Payment Gateway (Stripe or mock); Payment Employee (verify path); AI Agent (optional `start_payment`) |
| Permission | `citizen` + `profile.approved`; pay/confirm throttle 15/min |
| Status | Implemented |
| Evidence | `GET .../fee`, `GET/POST .../payments`, `POST .../payments/{id}/confirm` (mock only), `POST /api/webhooks/stripe`; `ApplicationPaymentService`; `PaymentLifecycleService`; `ApplicationFeeResolver` |
| Notes | Payable codes: `application_fee`, `renewal_fee`, `lost_replacement_fee`, `damaged_replacement_fee`, `unblock_fee`. Seeded **test fees are catalog-only** and are not charged at booking (BR-27). Citizen fine payment does **not** exist. |

#### UC-CIT-12 Book a driving test

| Field | Value |
|-------|--------|
| Name | Book a driving test |
| Primary actor | Citizen |
| Secondary actors | Test Employee (later records result); AI Agent (optional) |
| Permission | `citizen` + `profile.approved`; book throttle 15/min |
| Status | Implemented (`new_license` only) |
| Evidence | `GET /api/appointment-slots?test_type_id=`, `GET .../available-tests`, `POST .../appointments`; `AppointmentService`; `TestProgressionService`; order vision → theory → practical |
| Notes | Allowed from `appointment_pending` \| `in_testing` \| `waiting_retest`. First book from `appointment_pending` → `in_testing`. No booking-time test fee. |

#### UC-CIT-13 Change a test appointment

| Field | Value |
|-------|--------|
| Name | Change a test appointment |
| Primary actor | Citizen |
| Secondary actors | AI Agent (optional) |
| Permission | `citizen` + `profile.approved` |
| Status | Implemented |
| Evidence | `PUT /api/appointments/{id}/reschedule`; `DELETE /api/appointments/{id}/cancel`; `AppointmentController`; `AppointmentService` |
| Notes | Reschedule keeps the same test type. Cancel frees capacity and **does not** change application status (BR-09). |

#### UC-CIT-14 Track a license application

| Field | Value |
|-------|--------|
| Name | Track a license application |
| Primary actor | Citizen |
| Secondary actors | AI Agent (read-only intents) |
| Permission | `citizen` (reads; no `profile.approved`) |
| Status | Implemented |
| Evidence | `GET /api/applications`, `GET /api/applications/{id}`, nested documents/fee/payments/appointments/test-results; Agent `get_application_status`, `get_application_next_step`, `get_payment_status`, `get_test_results` |
| Notes | Aggregates supporting GETs into one tracking goal. |

#### UC-CIT-15 View own licenses

| Field | Value |
|-------|--------|
| Name | View own licenses |
| Primary actor | Citizen |
| Secondary actors | AI Agent (`get_licenses` list only) |
| Permission | `citizen` |
| Status | Implemented |
| Evidence | `GET /api/licenses`, `GET /api/licenses/{id}`; `LicenseController`; Agent has **no** `get_license_details` executor |
| Notes | Includes eligibility flags for renew/replace. |

#### UC-CIT-16 View own fines

| Field | Value |
|-------|--------|
| Name | View own fines |
| Primary actor | Citizen |
| Secondary actors | AI Agent (`get_fines`) |
| Permission | `citizen` |
| Status | Implemented (read-only) |
| Evidence | `GET /api/fines`; `FineController`; `FineService::listForCitizen` |
| Notes | Unpaid fines can block issue/unblock (BR-20). Citizens cannot pay fines in this system. |

#### UC-CIT-17 Request unblocking of a blocked license

| Field | Value |
|-------|--------|
| Name | Request unblocking of a blocked license |
| Primary actor | Citizen |
| Secondary actors | License Employee (the **actual** unblock is UC-LIC-04) |
| Permission | `citizen` + `profile.approved` |
| Status | **Partial** |
| Evidence | `POST /api/licenses/{id}/unblock-request`; `LicenseService::requestUnblock` returns an acknowledgment only — no status change, no application, no `license.unblocked` |
| Notes | Application `service_type_code=license_unblock` **create currently fails** (`blocked_service` before `checkUnblock()`, BR-29). Do **not** draw a working “apply to unblock” Use Case. Employee unblock is the working mutation. |

---

### 4.4 Citizen communications and assistant

#### UC-CIT-18 Manage notifications

| Field | Value |
|-------|--------|
| Name | Manage notifications |
| Primary actor | Citizen |
| Secondary actors | — |
| Permission | `citizen` |
| Status | Implemented |
| Evidence | `GET /api/notifications`, `/unread-count`, `PUT .../read`, `PUT .../read-all`; `NotificationController`; `NotificationService` |
| Notes | Recipient locale is `users.language`, independent of Agent session locale and of `Accept-Language`. |

#### UC-CIT-19 Register a mobile device for push

| Field | Value |
|-------|--------|
| Name | Register a mobile device for push |
| Primary actor | Citizen |
| Secondary actors | Firebase FCM (later delivery via UC-SYS-04) |
| Permission | `citizen` |
| Status | Implemented (delivery config-gated) |
| Evidence | `POST /api/devices/push-token`, `DELETE /api/devices/push-token`; `PushDeviceController`; `PushDeviceService` |

#### UC-CIT-20 Use the conversational license assistant

| Field | Value |
|-------|--------|
| Name | Use the conversational license assistant |
| Primary actor | Citizen |
| Secondary actors | AI Agent; Gemini; (domain services as callees, not actors) |
| Permission | `citizen`; `AI_AGENT_ENABLED`; throttles 20–30/min |
| Status | Implemented for the executable citizen subset |
| Evidence | 7 routes in `app/Modules/AIAgent/Routes/ai-agent.php`; `AIAgentController`; `AIAgentService`; `AgentActionExecutor`; `AgentSafetyRules::PHASE_9B_EXECUTABLE_ACTIONS` |
| Executable mutations (after confirm) | `create_application`, `submit_documents_for_review`, `start_payment`, `book_appointment`, `reschedule_appointment`, `cancel_appointment` |
| Executable reads | status, next step, documents, fee, payment status, profile, fines, licenses (list), tests, slots, appointments, results |
| Not executable | fine payment, `get_license_details`, `get_notifications`, direct `renew_license` / `request_license_replacement` / `request_unblock` action names, conversational unblock, admin actions, RAG |
| Notes | One Use Case for the channel. Do not explode Agent intents into the diagram. Mutations require confirmation when `AI_AGENT_REQUIRE_CONFIRMATION` is true. Cancel = no domain mutation. |

---

### 4.5 Employee access

#### UC-EMP-01 Authenticate to the employee dashboard

| Field | Value |
|-------|--------|
| Name | Authenticate to the employee dashboard |
| Primary actor | Employee (all specializations) |
| Secondary actors | Mail / SMTP (forgot-password OTP) |
| Permission | Dashboard auth is public then `dashboard` + session tracking |
| Status | Implemented |
| Evidence | `/api/dashboard/auth/login|logout|me|change-password|forgot-password|verify-otp|reset-password`; `POST /api/dashboard/session/heartbeat`; `DashboardAuthController`; `DashboardAuthService`; `EmployeeSessionService`; `OtpPurpose::DashboardForgotPassword` |
| Notes | Creates `EmployeeSession` on login. Citizens cannot use dashboard tokens. |

#### UC-EMP-02 View operational overview

| Field | Value |
|-------|--------|
| Name | View operational overview |
| Primary actor | Employee |
| Secondary actors | — |
| Permission | `access_dashboard` (dashboard middleware) |
| Status | Implemented |
| Evidence | `GET /api/dashboard/overview`; `DashboardOverviewController`; `DashboardOverviewService`; module tiles also filtered by permission in `DashboardModuleService` |

---

### 4.6 Review work

#### UC-REV-01 Review citizen identity profiles

| Field | Value |
|-------|--------|
| Name | Review citizen identity profiles |
| Primary actor | Profile and Document Reviewer |
| Secondary actors | Citizen (notified) |
| Permission | `review_profiles` |
| Status | Implemented |
| Evidence | `/api/admin/profile-reviews` list/show/approve/reject; `ProfileReviewController`; `ProfileReviewService` |
| Notes | Distinct from document review. Approve → citizen may use mutating services. Reject stores `profile_rejection_reason`. |

#### UC-REV-02 Review application documents

| Field | Value |
|-------|--------|
| Name | Review application documents |
| Primary actor | Profile and Document Reviewer |
| Secondary actors | Citizen (notified) |
| Permission | `review_documents` |
| Status | Implemented |
| Evidence | `/api/admin/documents/*` and `/api/dashboard/document-reviews/*` (dashboard includes file **preview**); `DocumentReviewController`; `DashboardDocumentReviewController`; `DocumentReviewService` |
| Notes | Any required reject → `documents_rejected`. All required approved → `payment_pending`. Dual HTTP surfaces, one Use Case. |

---

### 4.7 Application and payment operations

#### UC-APP-01 Inspect license applications

| Field | Value |
|-------|--------|
| Name | Inspect license applications |
| Primary actor | Application Manager (also License/Payment employees with `view_applications`) |
| Secondary actors | — |
| Permission | `view_applications` (OR `manage_applications` only as extra grant; **no mutating manage routes**) |
| Status | Implemented (read) |
| Evidence | `GET /api/dashboard/applications`, `GET /api/dashboard/applications/{application_number}`; `DashboardApplicationController` (lookup by **application_number**, not numeric id) |
| Notes | `manage_applications` is a registry permission used for module visibility / action flags, not a separate “edit application” Use Case. |

#### UC-PAY-01 Process application payments

| Field | Value |
|-------|--------|
| Name | Process application payments |
| Primary actor | Payment Employee |
| Secondary actors | Payment Gateway; Citizen (notified); Scheduler (stale pending) |
| Permission | `view_payments` to inspect; `manage_payments` to verify |
| Status | Implemented |
| Evidence | `/api/dashboard/payments*` including `POST /payments/{id}/verify`; `DashboardPaymentController`; `DashboardPaymentService`; `PaymentReconciliationService` |
| Notes | Office verification of `under_verification` / stale rows. Not citizen checkout (that is UC-CIT-11). Fines are a different UC. |

---

### 4.8 Tests

#### UC-TST-01 Manage test appointment capacity

| Field | Value |
|-------|--------|
| Name | Manage test appointment capacity |
| Primary actor | Test Employee |
| Secondary actors | — |
| Permission | `view_appointments` to inspect; `manage_appointments` to create/update/activate/deactivate |
| Status | Implemented |
| Evidence | `/api/dashboard/appointment-slots*`; `DashboardAppointmentSlotController`; `DashboardAppointmentSlotService`; capacity/`SlotIdentity`; `AppointmentSlotConcurrencyTest` |
| Notes | Citizen booking consumes this capacity (UC-CIT-12). |

#### UC-TST-02 Record a driving-test result

| Field | Value |
|-------|--------|
| Name | Record a driving-test result |
| Primary actor | Test Employee |
| Secondary actors | Citizen (notified); License Employee (later issuance) |
| Permission | `record_test_result` to record; list queue also allows `view_appointments` OR `manage_appointments` OR `record_test_result` |
| Status | Implemented |
| Evidence | `POST /api/admin/test-appointments/{appointment}/record-result`; `GET /api/dashboard/test-appointments`; `TestAppointmentResultController`; `DashboardTestAppointmentController`; `TestResultService`; `TestProgressionService` |
| Notes | Results: `passed` / `failed` / `no_show`. All required passed → `approved`. Fail/no-show under max attempts → `waiting_retest`; at max → `administrative_review` with **no automated exit**. |

---

### 4.9 License operations

#### UC-LIC-01 Issue a driving license

| Field | Value |
|-------|--------|
| Name | Issue a driving license |
| Primary actor | License Employee |
| Secondary actors | Citizen (notified) |
| Permission | `issue_license` |
| Status | Implemented |
| Evidence | `POST /api/admin/applications/{id}/issue-license`; `ApplicationLicenseController`; `LicenseService::issueForApplication`; `LicenseIssuanceEligibilityService` |
| Notes | Issuable services: new / renew / lost / damaged. **Not** `license_unblock`. Unpaid fines block issuance. Expiry uses `config('license.validity_years')` (default 10), not seeded `license_types.validity_years` (5). |

#### UC-LIC-02 Inspect and print issued licenses

| Field | Value |
|-------|--------|
| Name | Inspect and print issued licenses |
| Primary actor | License Employee |
| Secondary actors | — |
| Permission | `view_licenses` OR `manage_licenses` |
| Status | Implemented |
| Evidence | `/api/dashboard/licenses*` including history and `POST /licenses/{id}/print`; `DashboardIssuedLicenseController`; `LicensePrintService` (mpdf + QR) |
| Notes | Print is the same business object (issued license), so it stays in this UC rather than a separate “generate PDF” Use Case. |

#### UC-LIC-03 Block a driving license

| Field | Value |
|-------|--------|
| Name | Block a driving license |
| Primary actor | License Employee |
| Secondary actors | Citizen (notified) |
| Permission | `manage_licenses` |
| Status | Implemented |
| Evidence | `POST /api/admin/licenses/{id}/block` and `POST /api/dashboard/licenses/{id}/block`; `LicenseManagementController`; `DashboardIssuedLicenseController`; `LicenseService` |
| Notes | Dual HTTP surfaces, one Use Case. `LicenseStatus::Suspended` is **not** a live employee action. |

#### UC-LIC-04 Unblock a driving license

| Field | Value |
|-------|--------|
| Name | Unblock a driving license |
| Primary actor | License Employee |
| Secondary actors | Citizen (notified) |
| Permission | `manage_licenses` |
| Status | Implemented |
| Evidence | `POST /api/admin/licenses/{id}/unblock` and dashboard equivalent; unpaid fines can block this action |
| Notes | This is the working unblock mutation. Do not merge with UC-CIT-17. |

---

### 4.10 Fines and citizen administration

#### UC-FIN-01 Manage citizen fines

| Field | Value |
|-------|--------|
| Name | Manage citizen fines |
| Primary actor | Fines Employee |
| Secondary actors | Citizen (notified); License Employee (fines affect issue/unblock) |
| Permission | `manage_fines` (list/create/update); `view_fines` is enough for the fines **report** |
| Status | Implemented (office recording, not PSP checkout) |
| Evidence | `/api/admin/fines` GET/POST/PUT; `FineManagementController`; `FineService`; statuses `unpaid` / `paid` / `cancelled`; admin may mark `paid` (BR-30) |
| Notes | Dashboard has fines **reports** and citizen-detail fine lists, not a second fine CRUD. Citizen cannot pay. |

#### UC-USR-01 Manage citizen accounts

| Field | Value |
|-------|--------|
| Name | Manage citizen accounts |
| Primary actor | Employee with `manage_users` (typically Admin / Super Admin; not a dedicated seeded role) |
| Secondary actors | Citizen (activation notifications) |
| Permission | `manage_users` |
| Status | Implemented (no dashboard **create** citizen) |
| Evidence | `/api/dashboard/citizens*` view/update/activate/deactivate plus nested applications/licenses/fines/audit; `DashboardCitizenController`; `DashboardCitizenService` |
| Notes | `StoreDashboardCitizenRequest` is unused. Citizens self-register (UC-CIT-01). Activate/deactivate is separate from profile approve/reject. |

---

### 4.11 Workforce, RBAC, sessions

#### UC-HR-01 Manage employee accounts

| Field | Value |
|-------|--------|
| Name | Manage employee accounts |
| Primary actor | Employee with `manage_employees` (Admin / Super Admin in practice) |
| Secondary actors | — |
| Permission | `manage_employees` (granular `create_employees` / `update_employees` / … exist in the registry but are **not** used as route middleware) |
| Status | Implemented |
| Evidence | `/api/dashboard/employees*` CRUD-like activate/deactivate/reset-password/assign-role; `DashboardEmployeeController`; `DashboardEmployeeService` |
| Notes | Last `super_admin` role cannot be stripped (`SuperAdminProtectionTest`). |

#### UC-RBAC-01 Administer roles and permissions

| Field | Value |
|-------|--------|
| Name | Administer roles and permissions |
| Primary actor | Super Admin (middleware `super_admin` = role `super_admin` **or** `admin`) |
| Secondary actors | — |
| Permission | Route gate is `super_admin`, **not** `manage_roles` alone. `view_roles` can list roles/permissions. |
| Status | Implemented |
| Evidence | `/api/dashboard/access-control/*`; `PATCH /employees/{id}/roles` and `direct-permissions`; `DashboardAccessControlController`; `PermissionRegistry`; `RbacBootstrapService` |
| Notes | Effective permissions = role ∪ direct grants. Super Admin / Admin bypass via `*`. |

#### UC-SES-01 Supervise employee sessions

| Field | Value |
|-------|--------|
| Name | Supervise employee sessions |
| Primary actor | Super Admin (**root only**: role `super_admin`, not `admin`) |
| Secondary actors | Scheduler (hourly reconcile) |
| Permission | `root_super_admin` middleware |
| Status | Implemented |
| Evidence | `/api/dashboard/employee-sessions*`; revoke / revoke-all; `DashboardEmployeeSessionController`; `EmployeeSessionService`; `ReconcileEmployeeSessionsCommand` |
| Notes | This is the only capability that distinguishes A-SA from A-ADM on the diagram. |

---

### 4.12 Oversight and configuration

#### UC-RPT-01 View operational reports

| Field | Value |
|-------|--------|
| Name | View operational reports |
| Primary actor | Reports Employee |
| Secondary actors | — |
| Permission | `view_reports` plus a domain permission per slice (applications, tests, appointments, licenses, fines, employees) |
| Status | Implemented |
| Evidence | `/api/dashboard/reports/*`; also `GET /api/admin/reports/overview`; `DashboardReportController`; `ReportController` |
| Notes | Permission-scoped slices. AI-agent report permission has **no** matching report API. |

#### UC-AUD-01 View audit records

| Field | Value |
|-------|--------|
| Name | View audit records |
| Primary actor | Audit Employee |
| Secondary actors | — |
| Permission | `view_audit_logs` |
| Status | Implemented |
| Evidence | `GET /api/admin/audit-logs`; `GET /api/admin/application-status-histories/{application}`; nested `*/audit-logs` on payments, licenses, citizens, slots, fees, roles, sessions when the actor also has the parent permission |
| Notes | Writing audit logs is a side effect of other UCs (`AuditLogService`), not a Use Case. |

#### UC-SET-01 Configure catalogs and fees

| Field | Value |
|-------|--------|
| Name | Configure catalogs and fees |
| Primary actor | Settings Employee |
| Secondary actors | — |
| Permission | `manage_settings` |
| Status | Implemented (license types, service types, fees only) |
| Evidence | `/api/dashboard/license-types*`, `/service-types*`, `/fees*`; corresponding Dashboard* controllers/services |
| Notes | No dashboard CRUD for **test types** or **required documents** (seeded). Creating a custom service type is fail-closed in `ServiceWorkflow` and does not inherit new-license behavior. |

#### UC-MSG-01 Handle contact messages

| Field | Value |
|-------|--------|
| Name | Handle contact messages |
| Primary actor | Settings Employee |
| Secondary actors | Guest (submitter) |
| Permission | `view_contact_messages` to list; `manage_contact_messages` to update status |
| Status | Implemented |
| Evidence | `GET /api/dashboard/contact-messages`; `PATCH .../status`; `DashboardContactMessageController` |

---

### 4.13 Scheduled and supporting system Use Cases

#### UC-SYS-01 Reconcile stale payments

| Field | Value |
|-------|--------|
| Name | Reconcile stale payments |
| Primary actor | Scheduler |
| Secondary actors | Payment Gateway; Payment Employee (may still verify) |
| Permission | Console / scheduler |
| Status | Implemented |
| Evidence | `ReconcilePendingPaymentsCommand` every 30 minutes; `PaymentReconciliationService`; `PAYMENT_STALE_PENDING_MINUTES` |

#### UC-SYS-02 Expire licenses

| Field | Value |
|-------|--------|
| Name | Expire licenses |
| Primary actor | Scheduler |
| Secondary actors | Citizen (notified `license.expired`) |
| Permission | Console / scheduler |
| Status | Implemented |
| Evidence | `SyncExpiredLicensesCommand` daily 00:15 business TZ; `LicenseEffectiveStatus` also treats stored `active` past expiry as expired for public verify |

#### UC-SYS-03 Reconcile employee sessions

| Field | Value |
|-------|--------|
| Name | Reconcile employee sessions |
| Primary actor | Scheduler |
| Secondary actors | Super Admin (supervision UI) |
| Permission | Console / scheduler |
| Status | Implemented |
| Evidence | `ReconcileEmployeeSessionsCommand` hourly; weekly `PruneEmployeeSessionsCommand --dry-run` is maintenance, not a separate business UC |

#### UC-SYS-04 Notify a citizen of a business event

| Field | Value |
|-------|--------|
| Name | Notify a citizen of a business event |
| Primary actor | (included by other UCs; not started by a human) |
| Secondary actors | Citizen; Firebase FCM (optional) |
| Permission | Internal `NotificationService`; no employee “send notification” API |
| Status | Implemented |
| Evidence | `NotificationService`; `NotificationType`; `planPushSafely` → `PushDelivery` → `SendPushNotificationJob` → `FcmClient` |
| Notes | DB row is source of truth (BR-17). Push failure does not roll back the business transaction. Types `application.rejected` / `application.cancelled` are wired but have **no live emitter**. Permissions `view_notifications` / `send_notifications` have **no dashboard routes**. |

---

## 5. Candidate `<<include>>` relationships

`<<include>>` = behavior that **always** happens as part of the base Use Case (not a mere precondition).

| Base Use Case | Includes | Why |
|---------------|----------|-----|
| UC-CIT-01 Register and activate | (OTP delivery via X-MAIL) | Activation always sends/verifies email OTP. Model mail as secondary actor rather than a nested human UC. |
| UC-CIT-07 Apply for a new license | UC-CIT-10 Provide documents | New-license application path always requires documents before payment. |
| UC-CIT-07 Apply for a new license | UC-CIT-11 Pay application fees | Always after documents approved. |
| UC-CIT-07 Apply for a new license | UC-CIT-12 Book a driving test | Tests are required only for `new_license` (BR-02). |
| UC-CIT-08 Renew (application path) | UC-CIT-10, UC-CIT-11 | Application renew always has docs + pay. Direct renew does **not** include these (see extend / ambiguity). |
| UC-CIT-09 Replace (application path) | UC-CIT-10, UC-CIT-11 | Same as renew application path. |
| UC-CIT-10 Provide documents | UC-SYS-04 Notify | Submit-for-review and review outcomes emit notifications. |
| UC-CIT-11 Pay fees | UC-SYS-04 Notify | Payment completed / failed / under verification. |
| UC-CIT-12 Book a test | UC-SYS-04 Notify | `appointment.booked`. |
| UC-CIT-13 Change appointment | UC-SYS-04 Notify | Rescheduled / cancelled. |
| UC-CIT-20 Use assistant | UC-CIT-02 Sign in | Agent routes require an authenticated citizen. |
| UC-REV-01 Review profiles | UC-SYS-04 Notify | Profile approved / rejected. |
| UC-REV-02 Review documents | UC-SYS-04 Notify | Document and application status notifications. |
| UC-PAY-01 Process payments | UC-SYS-04 Notify | When verification completes a payment. |
| UC-TST-02 Record test result | UC-SYS-04 Notify | Pass / fail / no-show. |
| UC-LIC-01 Issue license | UC-SYS-04 Notify | `license.issued`. |
| UC-LIC-03 / UC-LIC-04 Block / Unblock | UC-SYS-04 Notify | `license.blocked` / `license.unblocked`. |
| UC-FIN-01 Manage fines | UC-SYS-04 Notify | Fine created / paid / cancelled. |
| UC-USR-01 Manage citizen accounts | UC-SYS-04 Notify | Account activated / deactivated. |
| UC-SYS-04 Notify | (optional push via X-FCM) | Push is delivery, not a second notify UC. Prefer `<<extend>>` for FCM (below). |
| UC-LIC-01 Issue license | (eligibility check) | Internal; do not draw a separate “check eligibility” Use Case. |
| UC-TST-02 Record result | (locate appointment via dashboard list) | Supporting query, not a separate UC. |

**Do not include as Use Cases**

- `profile.approved` middleware — **precondition**, not `<<include>>`  
- Locale middleware, throttles, Sanctum — technical  
- Selection tokens / pending workflow — Agent internals of UC-CIT-20  

---

## 6. Candidate `<<extend>>` relationships

`<<extend>>` = optional / alternative / exceptional behavior.

| Extending Use Case | Extends | Extension condition |
|--------------------|---------|---------------------|
| UC-CIT-20 Use conversational assistant | UC-CIT-07, UC-CIT-08, UC-CIT-09, UC-CIT-10, UC-CIT-11, UC-CIT-12, UC-CIT-13, UC-CIT-14, UC-CIT-15, UC-CIT-16 | Citizen chooses the Agent channel instead of (or in addition to) manual REST. Same domain services after confirm. |
| Confirm / cancel proposed Agent action | UC-CIT-20 | Mutation proposed and `AI_AGENT_REQUIRE_CONFIRMATION` is true. Not a standalone business UC; draw only if the diagram needs the confirmation point. |
| Stripe webhook completion | UC-CIT-11 | `PAYMENT_PROVIDER=stripe`. Mock confirm is the other provider path, not a second UC. |
| UC-PAY-01 Employee verify | UC-CIT-11 | Payment is `under_verification` or stale pending. |
| UC-SYS-01 Reconcile stale payments | UC-CIT-11 | Pending older than stale threshold. |
| Push delivery via FCM | UC-SYS-04 | `FIREBASE_PUSH_ENABLED` and a registered device. |
| Direct `POST /licenses/{id}/renew` | UC-CIT-08 | Citizen uses the immediate-issue API instead of the application workflow. **Product ambiguity** — see §10. |
| Direct `POST /licenses/{id}/replacement` | UC-CIT-09 | Same dual-path issue as renew. |
| Retest booking | UC-CIT-12 | Application is `waiting_retest` and attempts remain. Same UC, extension point “retest”. |
| Re-upload after rejection | UC-CIT-10 | Application is `documents_rejected`. |
| Sensitive profile update returns to pending review | UC-CIT-05 | Profile was already `approved`. |
| Language switch mid-session | UC-CIT-20 | Explicit switch; must **not** clear pending workflow. |
| Low-confidence / `requires_human_support` flag | UC-CIT-20 | Below confidence threshold. Flag only — **no ticketing product**. |
| `administrative_review` after max attempts | UC-TST-02 | Fail/no-show count ≥ `max_attempts`. There is **no** employee completion extension implemented. |

**Rejected extend candidates (do not draw)**

| Candidate | Why |
|-----------|-----|
| Pay fine extends View fines | Fine checkout is not implemented. |
| Apply to unblock extends Request unblock | Application create currently fails. |
| Reject / cancel application | No production transition. |
| Agent `renew_license` executor names | Listed in `AgentWorkflowActionMap` but **not** in `PHASE_9B_EXECUTABLE_ACTIONS`. Agent renew is `create_application` with `renew_license`. |

---

## 7. Actor generalizations

```text
                    ┌────────────┐
                    │  Employee  │  A-EMP
                    └─────▲──────┘
                          │
        ┌─────────────────┼──────────────────────────────────┐
        │                 │                                  │
   A-REV Profile/    A-APP Application                 A-ADM Admin
   Document Reviewer Manager                                  │
        │                 │                                  │
   A-PAY Payment     A-TST Test Employee              A-SA Super Admin
   Employee               │                           (root sessions)
        │                 │
   A-LIC License     A-FIN Fines Employee
   Employee               │
        │                 │
   A-RPT Reports     A-AUD Audit Employee
        │
   A-SET Settings Employee
```

| Generalization | Justification in code |
|----------------|------------------------|
| All specialized staff **are** Employees | Same `EnsureDashboardUser` stack, `access_dashboard`, employee sessions. |
| A-SA **is a** A-ADM-level bypass user, plus root | `isSuperAdmin()` = role `super_admin` OR `admin`. `isRootSuperAdmin()` = `super_admin` only. |
| A-REV is one actor with two Use Cases | Seeded role combines `review_profiles` + `review_documents` and **excludes** `view_applications` / `manage_applications` (SoD). |
| Guest is **not** a parent of Citizen | Different authentication state. Guest becomes Citizen after UC-CIT-01. |
| AI Agent is **not** a specialization of Employee or Citizen | Citizen-only orchestrator; admin actions are denied. |
| Citizen RBAC role is **not** on this tree | Protected, non-assignable as a dashboard employee. |

`manage_users` (citizen administration) has **no dedicated seeded role**; treat the primary actor as Admin / Super Admin (or any employee granted that permission), not a new generalization.

---

## 8. Features in old documentation but not supported by current implementation

Compared mainly to `README.md` (stale), older Agent compact docs, SRS Agent sections that predate Phase 2.5–2.6.1, and README “Future Enhancements”.

| Claim in old docs | Current implementation |
|-------------------|------------------------|
| Phone / mock SMS OTP | Email OTP only (`OtpMail`). Phone columns unused. |
| Citizen pays test fees and fine fees | Test fees catalog-only (BR-27). Fine checkout **not implemented**. Admin can mark a fine paid. |
| Generic chatbot product / `/chatbot` | Empty route group. Not a product. |
| AI Agent Phase 9A-only | Phases 9B + 2.2–2.6.1 are implemented (docs/master context). |
| AI execute renew/replace/unblock as named executor actions | Agent uses `create_application` for renew/lost/damaged. Direct action names are **not** executable. Unblock conversational intent **does not exist**. |
| Phase 9C AI admin logs / analytics | Permissions `view_ai_agent_logs` / `view_ai_agent_reports` exist; **no APIs**. Evaluations are internal traces, not a citizen/admin product. |
| Dashboard manage test types | Public GET only. No dashboard CRUD. |
| Dashboard manage required documents | Seeded catalog only. No dashboard CRUD. |
| Complete license unblock as a citizen application | Create fails eligibility (BR-29). No required documents seeded. |
| Citizen unblock-request as a real workflow | Ack-only. |
| Live application `rejected` / `cancelled` | Enum + notification types only. |
| RAG / document Q&A | Explicitly out of scope. |
| SMS gateway, government identity, multi-branch traffic dept, workflow engine | README futures; **not implemented**. |
| Employee “send notifications” module | Permission only; notifications are domain side-effects. |
| `StoreDashboardCitizenRequest` / staff-created citizens | Request class exists; **no POST /citizens route**. |
| License `suspended` as an employee action | Enum + reports count; **no suspend API** (block exists). |
| `specialized` test type in the default exam path | Enum/localizer only; seeder is vision → theory → practical. |

---

## 9. Implemented features missing from old documentation

Especially missing or understated in `README.md` / compact Agent context / README futures that are now live.

| Implemented capability | Proposed UC | Typical old-doc gap |
|------------------------|-------------|---------------------|
| Email OTP (citizen + dashboard) | UC-CIT-01, UC-CIT-03, UC-EMP-01 | README still says phone OTP. |
| Profile review gate | UC-CIT-05, UC-REV-01 | Under-specified vs document review. |
| Stripe Checkout + webhook + mock + reconcile | UC-CIT-11, UC-PAY-01, UC-SYS-01 | README often “mock only”. |
| Dual `/admin` and `/dashboard` employee APIs | several employee UCs | README “single admin dashboard API”. |
| Custom RBAC + direct permissions + SoD reviewer | UC-RBAC-01, A-REV | Spatie/Gates assumed in some older text; not used. |
| Employee sessions + root revoke | UC-EMP-01, UC-SES-01 | Absent from README actors. |
| Public license verification | UC-PUB-02 | Listed as future QR verify. |
| License PDF print | UC-LIC-02 | Listed as future PDF generation. |
| Firebase push pipeline | UC-CIT-19, UC-SYS-04 | Listed as future push. |
| Dashboard domain reports (permission-scoped) | UC-RPT-01 | Listed as future advanced reporting. |
| Bilingual citizen API + four locale systems | cross-cutting | README barely covers i18n. |
| FAQ / privacy / contact + dashboard handling | UC-PUB-03/04, UC-MSG-01 | Missing from README core features. |
| Citizen language/theme settings | UC-CIT-06 | Missing. |
| Dashboard catalog/fee management | UC-SET-01 | Partial in README settings. |
| AI Agent document flow, selection tokens, appointments, bilingual 2.6.1 | UC-CIT-20 | Compact docs pre-2.2–2.6.1. |
| Agent renew/lost/damaged via `create_application` | UC-CIT-08/09 + UC-CIT-20 | SRS “AI execute renew not implemented” is stale vs `create_application`. |
| `administrative_review` after max test attempts | extension of UC-TST-02 | Often omitted; still no employee exit path. |
| License effective status + daily expiry sync | UC-SYS-02, UC-PUB-02 | Missing. |
| Dashboard test-appointment queue (list) | supporting UC-TST-02 | Newer than older admin-only record-result docs. |
| Access-control UI (archive/restore roles, direct grants) | UC-RBAC-01 | Missing from README. |
| Payment `under_verification` + employee verify | UC-PAY-01 | Missing. |

---

## 10. Ambiguities requiring analysis

These must be decided **before** drawing the final diagram. This audit does not resolve them.

1. **Dual renew/replace paths.** Application path (docs + pay + employee issue) vs direct APIs that immediately issue a successor license without payment, documents, or `LicenseIssued` notification. One Use Case with an `<<extend>>`, or two Use Cases? Direct path may be a leftover/testing API rather than the official business process.

2. **License unblock is three different things.** (a) Employee unblock (working). (b) Citizen ack-only request. (c) Catalog service `license_unblock` whose create currently fails. Drawing (c) as a Use Case would invent a workflow.

3. **AI Agent as actor vs channel.** Activity/sequence docs give the Agent a swimlane. For Use Cases, either a secondary actor on citizen UCs **or** a single UC-CIT-20 with `<<extend>>` to domain UCs. Drawing both plus every intent will explode the diagram.

4. **Direct renew/replace vs Agent.** Agent cannot execute the direct license-mutation action names; it creates applications. The diagram must not imply Agent = direct API.

5. **Profile/Document Reviewer: one actor or two?** One seeded role, two permissions, two Use Cases. Splitting actors implies SoD between persons that the seeder does not enforce.

6. **`manage_applications` has no mutating API.** Should the diagram show “Manage applications” at all? Evidence supports **Inspect** only.

7. **`administrative_review` has no employee Use Case.** Recording a result can enter this status; nothing implemented issues, rejects, or returns the application. Drawing “Resolve administrative review” would invent a feature.

8. **Fine payment.** Citizens can view fines; unpaid fines block issue/unblock; only employees can mark paid. Is “Pay a fine at the office” an extension of UC-FIN-01, or is mark-paid merely a status update with no cash Use Case?

9. **Guest vs Public Verifier.** Keep one Guest actor (recommended) or split a Verifier who only uses UC-PUB-02.

10. **Scheduler / Time on the business diagram.** Include UC-SYS-01/02/03 for completeness, or treat them as supporting and omit from the presentation diagram.

11. **Permissions without Use Cases.** `send_notifications`, `view_notifications`, `view_ai_agent_logs`, `view_ai_agent_reports`, granular employee CRUD names (`create_employees`, …), `assign_roles`, `manage_roles` as route gates. Do not draw UCs for unused permissions.

12. **Dashboard “fines” and “notifications” and “AI monitoring” module tiles** (`DashboardModuleService`) advertise capabilities that lack matching APIs (except fines **reports**). Do not let the module list invent UCs.

13. **Issuance years 10 vs catalog `validity_years` 5.** Affects UC-LIC-01 description, not the Use Case set.

14. **Test fees in catalog never charged.** Do not add “Pay test fee” even though fee rows exist.

15. **Custom dashboard service types.** Employees can create service types; workflows are fail-closed for unknown codes. Do not draw “Define a new license workflow”.

16. **Who is the primary actor of UC-USR-01?** No `citizen_manager` role. Any `manage_users` grant, typically Admin.

17. **Print vs inspect.** Combined here as UC-LIC-02. Split only if the committee wants print as its own oval.

18. **Notification UC visibility.** UC-SYS-04 is supporting/`<<include>>`. Putting it on the main diagram next to humans may look like an employee can broadcast messages (they cannot).

19. **`requires_human_support`.** Agent flag only. Do not add “Escalate to human support”.

20. **Inactive citizen vs rejected profile vs deactivated by dashboard.** Three different blocks (middleware `is_active`, `profile_status`, employee deactivate). Still one Citizen actor.

---

## 11. Totals

| Measure | Count |
|---------|-------|
| Human actors | **14** (Guest, Citizen, Employee, Reviewer, Application Manager, Payment Employee, Test Employee, License Employee, Fines Employee, Reports Employee, Audit Employee, Settings Employee, Admin, Super Admin) |
| In-system non-human actors | **2** (AI Agent orchestrator, Scheduler) |
| External actors | **4** (Mail/SMTP, Payment Gateway, Gemini, Firebase FCM) |
| **Total actors (human + system + external)** | **20** |
| **Total external actors** | **4** |
| **Total proposed business Use Cases** | **49** (UC-PUB-01..04, UC-CIT-01..20, UC-EMP-01..02, UC-REV-01..02, UC-APP-01, UC-PAY-01, UC-TST-01..02, UC-LIC-01..04, UC-FIN-01, UC-USR-01, UC-HR-01, UC-RBAC-01, UC-SES-01, UC-RPT-01, UC-AUD-01, UC-SET-01, UC-MSG-01, UC-SYS-01..04) |
| Partial Use Cases | **1** (UC-CIT-17 ack-only unblock request) |
| Documented-but-not-drawn capabilities | empty chatbot, dev dashboard, unused permissions, failed `license_unblock` create, citizen fine pay, reject/cancel application |

---

## 12. Unresolved ambiguities (checklist)

Copy this list into the diagram-authoring step; do not silently pick a side.

1. Treat direct renew/replace APIs as official alternative flows of UC-CIT-08/09, as testing leftovers, or as separate Use Cases?  
2. Draw UC-CIT-17 (ack-only) at all, and how to label it so it is not confused with employee unblock?  
3. AI Agent: secondary actor on domain UCs, single channel UC-CIT-20, or both?  
4. One Reviewer actor or two person-types?  
5. Show “Manage applications” despite read-only APIs?  
6. Show `administrative_review` as a Use Case, an extension note, or omit (no employee action exists)?  
7. Is office mark-fine-paid a “collect fine payment” Use Case?  
8. Include Scheduler Use Cases on the presentation diagram?  
9. Include supporting UC-SYS-04 on the main diagram?  
10. Split Guest vs Public Verifier?  
11. Split print from inspect licenses?  
12. How to show dual `/admin` + `/dashboard` without duplicating ovals?  
13. Whether unused RBAC permissions appear as future/grey Use Cases (audit recommendation: **no**).  
14. Whether fail-closed custom service types appear as settings behavior inside UC-SET-01 only.  
15. Primary actor of citizen-account management (`manage_users` without a dedicated role).

---

*End of audit. No diagram XML was generated. Code remains the source of truth.*
