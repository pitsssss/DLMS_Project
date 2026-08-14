# FINAL Software Architecture + NFR Evidence Audit

**System:** SYRTAK / DLMS  
**Scope:** Backend `D:\Projects\DLMS_Project` + Dashboard `D:\Projects\DLMS_Dashboard`  
**Audit type:** Read-only evidence audit (source code + automated tests as source of truth)  
**Audit date:** 2026-08-14  
**Purpose:** Basis for the final university committee report  

### Evidence rules applied

| Rule | Application |
|------|-------------|
| No code was modified for this audit | Confirmed |
| No missing features were implemented | Confirmed |
| No invented NFR numeric claims | Confirmed |
| Claims must be IMPLEMENTED / MEASURABLE / UNVERIFIED / NOT IMPLEMENTED | Applied throughout |
| Full PHPUnit suite pass counts | **PENDING FINAL RUN** (not executed in this audit) |
| Dashboard automated tests | **0** test files under `src/` |

### Claim status vocabulary

| Status | Meaning |
|--------|---------|
| **VERIFIED** | Mechanism exists **and** automated tests (or other reproducible measurement) support the claim for the scoped behavior |
| **IMPLEMENTED BUT UNMEASURED** | Mechanism exists in code; no quantitative SLA/benchmark result yet |
| **PARTIALLY SUPPORTED** | Some of the concern is implemented/tested; gaps remain |
| **DO NOT CLAIM** | Insufficient evidence, absent, or marketing wording that overstates reality |

---

## 1. SYSTEM ARCHITECTURE

### 1.1 Overall shape (evidence-based)

The system is a **modular monolith API** (Laravel 12 / PHP 8.4) consumed by:

1. **Employee Dashboard** — Next.js 15 App Router SPA (`syrtak-dashboard-next`) in the sibling repo  
2. **Citizen clients** — HTTP JSON consumers of the same API (Flutter is referenced in docs/Postman contracts; **Flutter source is not in either audited tree**)

Integrations evidenced in code/config: **MySQL/SQLite**, **Laravel Sanctum**, **Stripe** (optional) + mock payment provider, **Firebase FCM**, **Google Gemini** (AI Agent), **email OTP**, **database queue** + Docker Supervisor worker, **private filesystem** for documents.

There is **no** microservice decomposition, **no** domain event bus (`app/Events` / `app/Listeners` absent), and **no** true AOP framework.

```
Citizen client(s) ──┐
                    ├── HTTPS JSON ──► Laravel Modular Monolith ──► MySQL
Employee Dashboard ─┘         │              │
                              │              ├── Stripe / Gemini / FCM / Mail
                              │              └── Queue worker (push, default)
                              └── Public license verify (throttled)
```

---

### 1.2 Backend architecture layers

Do **not** force textbook Clean Architecture onto this codebase. The **actual** layering is:

**HTTP (Controllers + Form Requests + Middleware) → Application Services → (optional) Repositories → Eloquent Models**, with shared `app/Services`, `app/Support`, `app/Enums`, and one job under `app/Jobs`.

| Architecture Layer | Responsibility | Concrete implementation / files | Allowed dependency direction | Violations / exceptions |
|--------------------|----------------|----------------------------------|------------------------------|-------------------------|
| **HTTP / presentation** | Route binding, request/response, middleware stack | `routes/api.php`, `routes/web.php`, `bootstrap/app.php`; module routes: `app/Modules/Dashboard/Routes/dashboard.php`, `Admin/Routes/admin.php`, `AIAgent/Routes/ai-agent.php`, `Content/Routes/content.php`, `Settings/Routes/settings.php`; controllers under `app/Modules/*/Controllers/` | May call Services / Form Requests; must not own business rules | Some thin closures (e.g. `GET /api/ping`); Stripe webhook controller is integration-facing |
| **Request validation** | Input shape & field rules | Module `Requests/` (e.g. Dashboard has many Form Requests; Auth/Applications/Appointments/Payments/AIAgent/Fines/Content/Settings) | Requests → validated data into Controllers/Services | Validation also occurs inside services for domain rules (state machines, ownership) — intentional dual layer |
| **Authentication** | Identity via Sanctum tokens; OTP/password flows | `laravel/sanctum`; `app/Models/User` (`HasApiTokens`); `app/Modules/Auth/Services/AuthService.php`, `OtpService.php`; Dashboard auth under Dashboard module; middleware `auth:sanctum` | Middleware → Controllers | Citizen vs dashboard token surfaces share Sanctum; separation enforced by role/type middleware |
| **Authorization** | RBAC permissions + citizen ownership | `EnsurePermission`, `EnsureCitizen`, `EnsureDashboardUser`, `EnsureProfileApproved`, `EnsureSuperAdmin`, `EnsureRootSuperAdmin` in `app/Http/Middleware/`; `User::hasPermission()`; `config/dashboard_permissions.php`; ownership in repositories/services (`findOwnedByCitizen`, `citizen_id` / `user_id` filters) | Middleware gates routes; services enforce IDOR | Super Admin bypass returns `['*']` — document explicitly; dashboard authz is permission-based, not citizen ownership |
| **Controllers** | Orchestrate HTTP → service calls; return Resources | e.g. `ApplicationController`, `ApplicationPaymentController`, `LicenseVerificationController`, Dashboard `*Controller` set (18), Admin controllers, `AIAgent` controller | Controllers → Services/Resources | Dashboard module is large (many controllers) — still service-backed |
| **Application / domain services** | Business workflows, transactions, notifications side-effects | Widespread `app/Modules/*/Services/` (AIAgent ~41, Dashboard ~30, Payments 7, Licenses 6, …); shared `app/Services/AuditLogService.php` | Services → Repositories/Models/Jobs/Integrations; should not depend on HTTP Request objects except where audit captures IP/UA | Payments/Dashboard/AIAgent/Content/Reports/Settings/Tests often use Eloquent **directly** (no repository) |
| **Repositories / data-access abstractions** | Query encapsulation where present | `ApplicationRepository`, `AppointmentRepository`, `AppointmentSlotRepository`, `AuthRepository`, `PasswordResetTokenRepository`, `AuditLogRepository`, `FineRepository`, `LicenseRepository`, `NotificationRepository`, `PushDeviceRepository`, `PushDeliveryRepository` | Services → Repositories → Models | **Partial** repository pattern — Admin `Repositories/` is empty (`.gitkeep`); many modules skip repos |
| **Models / entities** | Persistence mapping, relations, helpers | `app/Models/*` (`User`, `LicenseApplication`, `Payment`, `License`, `Permission`, `Role`, `AuditLog`, `Otp`, …); enums in `app/Enums/` | Models used by repos/services | Domain rules live mainly in services, not rich domain entities |
| **Events / listeners** | Domain pub/sub | **NOT IMPLEMENTED** as first-class domain Events/Listeners | — | Cross-module reactions use direct service calls / `DB::afterCommit` / one Job |
| **Jobs / queues** | Async work | `app/Jobs/SendPushNotificationJob.php` (`ShouldQueue`, queue `push`); `config/queue.php` default `database`; Docker Supervisor `queue:work --queue=push,default`; scheduled tasks in `routes/console.php` | Services → Jobs | Only one application Job class; most work is synchronous request-path |
| **Notifications** | In-app + push planning | `app/Modules/Notifications/*`, `Push/*`, `Devices/*`, `Firebase/*` | Services → NotificationService → (optional) PushDeliveryService → Job | Push depends on worker process being up (ops concern) |
| **Integrations** | External systems | Stripe: `Payments` + `StripeWebhookController`; Gemini: `AIAgent` + `config/ai.php`; FCM: `Firebase` + `Push`; Mail OTP: Auth | Integration services isolate providers | External latency (Gemini/Stripe/FCM) must not be confused with core API latency |
| **Configuration / infrastructure** | Env-driven config, Docker, health | `config/*`, `.env.example`, `Dockerfile`, `docker-compose.yml`, `docker/supervisor/supervisord.conf`, health route `/up` in `bootstrap/app.php` | Infra → app via config | Local SQLite vs Docker MySQL — environment must be stated for any measurement |
| **Database / transaction boundaries** | Atomic multi-row updates + row locks | Widespread `DB::transaction` + `lockForUpdate` in Appointments, Payments, Licenses, sessions, AIAgent docs, etc.; unique keys on payment obligations, gateway events, license numbers, slot identity | Services own transaction boundaries | Booking capacity concurrency is **lock-based**, not a unique `(application,slot)` constraint |

#### Backend module map (18 modules)

| Module | Controllers | Services | Repositories | Notes |
|--------|:-----------:|:--------:|:------------:|-------|
| Admin | yes | yes | empty | Ops + audit read APIs |
| AIAgent | yes | many | no | Largest service surface; Gemini |
| Applications | yes | yes | yes | Citizen applications/docs |
| Appointments | yes | yes | yes | Booking + progression |
| AuditLogs | no | no | yes | Read path; writes via shared service |
| Auth | yes | yes | yes | Register/login/OTP/profile |
| Content | yes | yes | no | FAQs/pages |
| Dashboard | many | many | no | Employee API surface |
| Devices | yes | yes | yes | FCM device tokens |
| Fines | yes | yes | yes | Citizen fine list (pay not claimed here) |
| Firebase | no | yes | no | FCM client |
| Licenses | yes | yes | yes | Issue/lifecycle/verify |
| Notifications | yes | yes | yes | In-app center |
| Payments | yes | yes | no | Checkout + webhook + reconciliation |
| Push | no | yes | yes | Delivery planning/retry |
| Reports | yes | yes | no | Reporting |
| Settings | yes | yes | no | Citizen prefs |
| Tests | yes | yes | no | Test results API |

---

### 1.3 Dashboard (Next.js) architecture layers

Actual style: **feature-based UI** on App Router, with shared API client, auth context, and permission guards. **No** Next.js `middleware.ts`. **No** Redux/React Query. **No** automated frontend test suite.

| Architecture Layer | Responsibility | Concrete implementation / files | Allowed dependency direction | Violations / exceptions |
|--------------------|----------------|----------------------------------|------------------------------|-------------------------|
| **Route / app layer** | URL → page shells | `src/app/(auth)/*`, `src/app/(dashboard)/dashboard/*`, `src/app/licenses/verify/[verificationToken]/*`, `src/app/payment/{success,cancel}`, `src/app/forbidden` | Pages → feature pages + guards | Thin pages wrap features; some older features inline logic in page components |
| **Feature modules** | Domain UI + hooks | `src/features/*` (21 folders: access-control, applications, appointment-slots, auth, citizens, dashboard, document-review, employee-sessions, fees, fines, license-issuance, licenses, payments, profile, profile-review, public-license-verify, reports, settings, tests, users, documents, audit) | Features → `api/`, `types/`, `components/`, `lib/` | Cross-feature imports exist (formatters, heartbeat, profile-review embed); `documents` appears unused by `app/` |
| **API / client layer** | HTTP to Laravel | `src/api/axiosClient.ts`, `src/api/*Api.ts`, `src/lib/api/endpoints.ts`, `errors.ts` | Features/hooks → API modules → axios | Mixed ENDPOINTS vs hardcoded paths |
| **Hooks / state orchestration** | Fetch/mutate + URL query state | Feature `hooks/*`; shared `src/hooks/useAuth.ts`, `usePermissions.ts`, `useDebouncedValue.ts`, overview hooks | Hooks → API + auth | Local `useState`/`useEffect`/`AbortController`; no global server-cache library |
| **Components / presentation** | Layout + UI | `src/components/layout/*` (`ProtectedRoute`, `PermissionGuard`, `AuthGuestGuard`, `Sidebar`, `AdminLayout`), `src/components/ui/*`, feature `components/` | Presentation → hooks/props | — |
| **Permission guards** | Client-side route UX gating | `PermissionGuard.tsx`, `ProtectedRoute.tsx`, `lib/permissions/index.ts`, `constants/permissions.ts`, feature `*Permissions.ts` | Guards → AuthContext | **UX-only**; real enforcement is backend middleware — do not claim “frontend security” as sufficient |
| **Types** | TS contracts | `src/types/*` | Shared | — |
| **Utilities** | Formatters, status labels | `src/utils/*`, feature `utils/*` | Shared | Some cross-feature formatter reuse |
| **Public vs protected routes** | Access model | **Public:** login/forgot/verify/reset, license verify, payment success/cancel, forbidden. **Protected:** entire `(dashboard)` group via `ProtectedRoute` + per-page `PermissionGuard` (profile excepted from permission gate) | — | Token in `localStorage` (not httpOnly cookie) |

---

## 2. ARCHITECTURAL STYLE

Only styles with code evidence:

| Style | Evidence | Benefits in THIS project | Limitations / tradeoffs |
|-------|----------|--------------------------|-------------------------|
| **Layered architecture (pragmatic)** | Controllers → Services → Models/(Repos); middleware on routes | Clear request path for committee walkthroughs; testable services | Not strict Clean Architecture; HTTP/validation sometimes bleed into services |
| **Modular monolith** | `app/Modules/*` by domain; single deployable Laravel app | Cohesive license workflow (apply→docs→pay→book→test→issue) without distributed transactions | Module size imbalance (Dashboard/AIAgent large); cross-module coupling via models/services |
| **Service layer** | Business rules concentrated in `*Service` classes | Enables Feature tests against HTTP while keeping domain logic findable; transaction ownership is clear | Some services are very large (AIAgent orchestration) |
| **Repository abstraction (partial)** | Selected modules only | Useful for ownership queries (`findOwnedByCitizen`) and audit reads | Inconsistent — cannot claim “repository pattern throughout” |
| **Event-driven elements (minimal)** | `DB::afterCommit` notification dispatch; scheduled console commands | Avoids notifying on rolled-back work | **Not** an event-driven architecture; no domain Events/Listeners |
| **Queue-based asynchronous processing (narrow)** | `SendPushNotificationJob` + Supervisor | Isolates FCM latency/retries from request latency | Only push path; most API work remains synchronous |
| **Frontend feature-based architecture** | `src/features/*` + thin App Router pages | Matches backend domains; supports permission-scoped screens | Uneven maturity; zero FE tests; client-only route guards |

**Not evidenced as architectural styles:** microservices, CQRS, hexagonal ports/adapters as a formal structure, true AOP, serverless, BFF separate from Laravel.

---

## 3. AOP / CROSS-CUTTING CONCERNS

### 3.1 True Aspect-Oriented Programming?

**Verdict: NOT IMPLEMENTED.**

| AOP indicator | Result |
|---------------|--------|
| Aspect classes / pointcuts / advice | **Absent** |
| AOP packages (composer) | **Absent** (`composer.json` has Laravel, Sanctum, Stripe, mPDF, QR, Google Auth — no AOP library) |
| Attribute/annotation-driven method interception | **Absent** as an AOP layer |
| Decorators/proxies for business methods | **Absent** |
| Runtime method interception framework | **Absent** |

Cross-cutting behavior is implemented via **explicit Laravel middleware**, **Form Requests**, **central exception rendering**, **service-level helpers**, and **direct service calls** — classic layered/filter composition, **not** AOP.

**Committee wording:** Do **not** describe Laravel middleware as “AOP” unless the course definition explicitly equates HTTP filters with AOP (most SE curricula do not). Prefer: *“cross-cutting concerns via middleware and shared services.”*

---

### 3.2 Cross-cutting concerns inventory

| Concern | Mechanism | Where applied | Why cross-cutting | How it avoids duplication | Automated evidence | Legitimately AOP? |
|---------|-----------|---------------|-------------------|---------------------------|--------------------|-------------------|
| **Authentication** | Sanctum `auth:sanctum`; login/OTP/token issuance | Citizen + dashboard route groups in `routes/api.php` / module routes | Nearly all protected APIs need identity | Middleware alias + shared Auth services | `DashboardAuthTest`, `PasswordResetFlowTest`, session/auth Feature tests | **No** — middleware/filter |
| **Authorization / RBAC** | `permission:*` middleware; `EnsurePermission`; `User::hasPermission`; Super Admin bypass | Dashboard/Admin routes; frontend `PermissionGuard` (UX) | Many endpoints share permission vocabulary | Single middleware + permission registry (`config/dashboard_permissions.php`) | `DashboardPermissionTest`, `DashboardAccessControlTest`, `DashboardRoleManagementTest`, `DocumentReviewerAuthorizationTest`, `SuperAdminProtectionTest` | **No** |
| **Localization** | `ResolveRequestLocale` middleware; `resources/lang/{ar,en}`; Accept-Language + prefs | Citizen/public API groups; AI Agent locale services | Messages must be consistent across modules | Central `__()` keys + middleware locale resolution | Many `*Localization*`, `RequestLocaleTest`, `CitizenBilingualMessagesTest`, Agent locale Unit tests | **No** |
| **Rate limiting** | Route `throttle:N,1` middleware | Forgot-password `5`; payments/bookings `15`; docs/AI/verify often `30`; Stripe webhook `100`; etc. | Abuse protection spans endpoints | Declarative on routes | **Weak:** ~81 tests **disable** `ThrottleRequests`; **0** `assertStatus(429)` found | **No** |
| **Exception handling** | `bootstrap/app.php` JSON renders for 422/401/403/404/500; `ApiException` | All `api/*` | Uniform API error shape | Central exception callbacks | Implicit across Feature tests asserting status/JSON | **No** |
| **Audit logging** | `AuditLogService::log` called from domain services | Sensitive dashboard/citizen lifecycle actions | Compliance trail across modules | Shared service + `audit_logs` table | Many dashboard Feature tests assert `audit_logs`; `NotificationAuditReportTest` | **No** — explicit calls (can miss callers) |
| **Transactions** | `DB::transaction` in services | Payments, appointments, licenses, notifications, sessions, AIAgent docs, RBAC, … | Multi-step integrity | Service-owned boundaries | `NotificationTransactionSafetyTest`, concurrency/payment integrity tests | **No** |
| **Notification dispatch** | `NotificationService` + `DB::afterCommit`; push planning → Job | Domain services after successful mutations | Same side-effect pattern across domains | Central notification module + event keys | Large `Notification*` Feature suite | **No** |
| **Idempotency** | Unique obligation keys; Stripe idempotency key; gateway `(provider,event_id)`; notification `event_key`; push `delivery_key` | Payments, webhooks, notifications, push, some session/RBAC bootstrap | Safe retries / duplicate submits | DB uniqueness + service reuse of active attempts | `PaymentFlowTest`, `PaymentConcurrencyAndIntegrityTest`, `PaymentStripeTest`, `NotificationIdempotencyTest`, push planning tests | **No** |
| **Request validation** | Form Requests + service domain asserts | Module `Requests/` + services | Input hygiene everywhere | Framework FormRequest reuse | Widespread Feature coverage of 422 paths | **No** |
| **Employee session tracking** | `TrackEmployeeSessionActivity` middleware; session services; heartbeat; revoke | Dashboard routes + `employee.session.track` | Applies across dashboard API | Middleware + dedicated services | `EmployeeSession*` Feature suite | **No** |
| **Ownership / IDOR enforcement** | `citizen_id` / `user_id` scoped queries; `findOwnedByCitizen`; lock queries with ownership | Applications, appointments, payments, licenses, notifications, AI sessions, fines | Every citizen resource access | Repository/service helpers | Ownership exercised across Application/Appointment/Payment/Notification/License/AIAgent tests (literal “IDOR” string rare; `DashboardEmployeeSessionsTest` mentions IDOR) | **No** |

---

## 4. NFR INVENTORY

For each quality attribute: mechanisms, files, tests, measurable evidence, gaps, candidate metric, measurement method, committee claim status.

### 4.1 Security

1. **Mechanisms:** Sanctum tokens; citizen/dashboard separation middleware; RBAC permissions; profile-approval gate; ownership scoping; private document disk + MIME/size checks; hashed OTP; Stripe webhook signature path; throttles on sensitive routes; employee session revoke  
2. **Files:** `app/Http/Middleware/*`, `app/Modules/Auth/*`, `User` permission methods, document upload services, `Payments` webhook services, `OtpService`  
3. **Tests:** Broad Feature coverage (auth, RBAC, sessions, notification security, push device security, document reviewer authz)  
4. **Measurable evidence today:** Qualitative pass/fail of security Feature tests (**suite result PENDING FINAL RUN**); no pentest report in repo  
5. **Missing:** Penetration test; positive 429 tests; httpOnly cookie auth on dashboard; antivirus on uploads  
6. **Candidate metric:** % of critical mutating endpoints with automated 401/403/IDOR negative cases  
7. **Method:** Inventory critical routes → map to Feature assertions → compute coverage %  
8. **Claim status:** **PARTIALLY SUPPORTED** (strong authz engineering; do not claim “secure system” / “hacker-proof”)

### 4.2 Performance

1. **Mechanisms:** None that encode latency SLAs; throttles are abuse controls, not performance guarantees; queue isolates push only  
2. **Files:** Route throttles; queue worker config  
3. **Tests:** No load/benchmark suite (k6/JMeter/Artillery absent)  
4. **Measurable evidence:** **None**  
5. **Missing:** p50/p95/p99, throughput, saturation curves  
6. **Candidate metrics:** p50/p95/p99 latency (ms); RPS; error rate under concurrency  
7. **Method:** Controlled load tool against representative endpoints (§6), warm DB, fixed dataset  
8. **Claim status:** **DO NOT CLAIM** “high performance” — status **IMPLEMENTED BUT UNMEASURED** only for “API exists and responds in tests”

### 4.3 Reliability

1. **Mechanisms:** Transactions; idempotent payments/notifications/push; payment reconciliation schedule; push retries/backoff; stale AI action rejection patterns; `under_verification` payment states  
2. **Files:** Payment/Appointment/Notification/Push services; `routes/console.php` schedules; `SendPushNotificationJob`  
3. **Tests:** Concurrency, idempotency, push retry, AI pending workflow reliability tests  
4. **Measurable evidence:** Behavioral tests exist; **no** uptime measurement  
5. **Missing:** Production MTTR/MTBF; chaos tests  
6. **Candidate metric:** Duplicate-request success-without-duplicate-side-effect rate  
7. **Method:** Replay identical payment/notification/webhook requests in tests + controlled staging scripts  
8. **Claim status:** **PARTIALLY SUPPORTED** for workflow reliability patterns; **DO NOT CLAIM** “highly reliable / fault-tolerant platform”

### 4.4 Availability

1. **Mechanisms:** Health endpoint `/up`; Docker Compose multi-process (app + queue); queue worker for push  
2. **Files:** `bootstrap/app.php` (`health: '/up'`); `docker-compose.yml`; Supervisor conf  
3. **Tests:** No HA/failover tests  
4. **Measurable evidence:** **None** for uptime %  
5. **Missing:** Multi-node deploy, LB health history, 99.x SLA  
6. **Candidate metric:** Successful `/up` probe ratio over time window  
7. **Method:** Synthetic monitoring in staging (not claimed as production SLA)  
8. **Claim status:** **DO NOT CLAIM** “99.9% availability” / “zero downtime”

### 4.5 Data Integrity

1. **Mechanisms:** DB transactions; `lockForUpdate`; unique keys (payment obligations, gateway events, license numbers, application numbers, slot identity keys); domain status guards  
2. **Files:** Migrations under `database/migrations/*`; `AppointmentService`, `ApplicationPaymentService`, license issuance services  
3. **Tests:** `AppointmentSlotConcurrencyTest`, `PaymentConcurrencyAndIntegrityTest`, payment unique-key tests  
4. **Measurable evidence:** Automated concurrency/idempotency assertions (pass/fail PENDING FINAL RUN)  
5. **Missing:** Broader race matrix beyond slots/payments; formal invariant proofs  
6. **Candidate metric:** Overbook count under concurrent booking = 0 for capacity-1 slots  
7. **Method:** Parallel HTTP clients / PHPUnit concurrent scenarios already started — extend and report  
8. **Claim status:** **VERIFIED** for *tested* concurrency/idempotency scenarios; **PARTIALLY SUPPORTED** as system-wide absolute

### 4.6 Maintainability

1. **Mechanisms:** Module folders; enums; Form Requests; Feature tests; shared presenters/translators  
2. **Files:** `app/Modules/*`, `app/Enums/*`, `tests/Feature/*`  
3. **Tests:** Large Feature suite acts as regression net  
4. **Measurable evidence:** Module count, test-file count (inventory); no maintainability index tooling run  
5. **Missing:** Static analysis gate in CI; consistent repository layer  
6. **Candidate metrics:** Critical-path Feature test file coverage by module; mean time to locate service for a use case (process metric)  
7. **Method:** Module→test mapping table; avoid vanity LOC  
8. **Claim status:** **IMPLEMENTED BUT UNMEASURED** (structure is real; “highly maintainable” is **DO NOT CLAIM**)

### 4.7 Scalability

1. **Mechanisms:** Stateless token API; horizontal scale *possible* in principle; queue for push  
2. **Files:** Sanctum API; database queue  
3. **Tests:** No scalability benchmark  
4. **Measurable evidence:** **None**  
5. **Missing:** Multi-instance sticky/session analysis; DB bottleneck profiling; queue lag under load  
6. **Candidate metric:** RPS at p95≤X ms before error rate >1%  
7. **Method:** Load test + resource charts (CPU/DB connections)  
8. **Claim status:** **DO NOT CLAIM** “scalable” as a measured property — architecture is **compatible with** horizontal API scale (**IMPLEMENTED BUT UNMEASURED**)

### 4.8 Testability

1. **Mechanisms:** PHPUnit Feature/Unit; `phpunit.xml` isolated MySQL `dlms_testing`; mock payment provider; fixed OTP in testing; factories/seeders  
2. **Files:** `tests/**`, `phpunit.xml`  
3. **Tests:** **101 Feature + 5 Unit files**; rough inventory ~1027 test-method hits / ~4491 assert hits (heuristic, not suite run)  
4. **Measurable evidence:** File inventory only until final run  
5. **Missing:** Dashboard tests; CI; coverage thresholds; positive throttle tests  
6. **Candidate metrics:** Suite pass rate; assertion count; authz negative-case count  
7. **Method:** `php artisan test` with JUnit/log artifact committed or attached to report  
8. **Claim status:** **PARTIALLY SUPPORTED** (strong backend testability; FE **NOT IMPLEMENTED**)

### 4.9 Auditability

1. **Mechanisms:** `AuditLogService` + `audit_logs`; admin audit read APIs; some status histories  
2. **Files:** `app/Services/AuditLogService.php`, `app/Modules/AuditLogs/*`, callers across license/payment/dashboard services  
3. **Tests:** Multiple Feature tests `assertDatabaseHas('audit_logs', …)`  
4. **Measurable evidence:** Existence of records for tested actions — **not** full critical-operation coverage %  
5. **Missing:** Formal matrix of all critical ops → audit always written  
6. **Candidate metric:** Audit coverage % = audited critical ops / identified critical ops  
7. **Method:** Enumerate critical ops from use-case list; grep callers of `AuditLogService`; reconcile  
8. **Claim status:** **PARTIALLY SUPPORTED**

### 4.10 Localization

1. **Mechanisms:** Backend AR/EN messages; locale middleware; AI bilingual hardening; dashboard is **Arabic-first RTL hardcoded** (no i18n library)  
2. **Files:** `resources/lang/ar|en/messages.php`, `validation.php`; `ResolveRequestLocale`; Dashboard `layout.tsx` `lang="ar" dir="rtl"`  
3. **Tests:** Strong backend localization Feature/Unit suite  
4. **Measurable evidence:** Approx. key asymmetry: `messages.php` en ~669 `'=>` hits / ar ~946; unique-key heuristic en 509 vs ar 733 (ar denser; **0 only-in-en** in one scan)  
5. **Missing:** Dashboard EN UI; strict key-parity CI check; Flutter l10n (out of trees)  
6. **Candidate metric:** AR/EN key parity % for `messages.php`; localized API assertion pass rate  
7. **Method:** Scripted key diff + existing locale Feature tests  
8. **Claim status:** **VERIFIED** for bilingual **citizen API messages** (behavioral tests); **PARTIALLY SUPPORTED** for whole product UX (dashboard AR-only)

### 4.11 Usability

1. **Mechanisms:** Dashboard UX patterns; API message localization; public verify UI  
2. **Files:** Dashboard features; verify pages  
3. **Tests:** No UX/usability automated tests; no SUS scores  
4. **Measurable evidence:** **None** quantitative  
5. **Missing:** Usability study  
6. **Candidate metric:** Task completion rate in moderated demo checklist (manual)  
7. **Method:** Scripted committee demo tasks — label as **manual evidence**, not NFR SLA  
8. **Claim status:** **DO NOT CLAIM** quantitative usability; qualitative demo only

### 4.12 Portability

1. **Mechanisms:** Docker Compose; env-based config; Laravel portable PHP app  
2. **Files:** `Dockerfile`, `docker-compose.yml`, `.env.example`  
3. **Tests:** Tests assume MySQL testing DB  
4. **Measurable evidence:** Compose boots app+MySQL pattern exists; no multi-OS matrix in CI  
5. **Missing:** CI matrix; documented production portability proof  
6. **Candidate metric:** Fresh `docker compose up` success on clean machine  
7. **Method:** Clean-room bring-up checklist with log artifact  
8. **Claim status:** **IMPLEMENTED BUT UNMEASURED**

### 4.13 Observability

1. **Mechanisms:** Laravel logs (`Log::` in OTP/FCM/Gemini/push/dashboard paths); JSON API errors; health `/up`; queue worker stdout via Supervisor  
2. **Files:** `storage/logs` (runtime); selected service logging; `bootstrap/app.php` exception reporting  
3. **Tests:** `OtpDebugLoggingTest` (safety of debug logging)  
4. **Measurable evidence:** Log statements exist; **no** metrics backend (Prometheus/OpenTelemetry) evidenced  
5. **Missing:** Distributed tracing; structured correlation IDs standard; dashboards  
6. **Candidate metric:** % of 5xx responses with `report($e)` path in non-debug; presence of request correlation (if added later)  
7. **Method:** Staging fault injection + log inspection  
8. **Claim status:** **PARTIALLY SUPPORTED** (basic logging); **DO NOT CLAIM** “full observability platform”

### 4.14 Recoverability

1. **Mechanisms:** Payment reconciliation command (scheduled); push job retries; license expiry sync; employee session reconcile/prune; DB backups **not evidenced in code**  
2. **Files:** `routes/console.php` schedules; payment/push services  
3. **Tests:** Reconciliation/retry-oriented Feature tests for payments/push/licenses where present  
4. **Measurable evidence:** Behavioral recovery paths in tests; no backup/restore drill artifact  
5. **Missing:** RPO/RTO; DB backup automation evidence  
6. **Candidate metric:** Time to recover stuck payment via reconciliation in staging script  
7. **Method:** Controlled failed webhook → run reconcile → assert terminal state  
8. **Claim status:** **PARTIALLY SUPPORTED** for application-level recovery helpers; **DO NOT CLAIM** DR/BCP

### 4.15 Concurrency Safety

1. **Mechanisms:** `lockForUpdate` on slots/applications/payments/licenses/sessions/devices; unique obligation keys  
2. **Files:** `AppointmentService`, payment services, license issuance, etc. (~18 `lockForUpdate` call sites inventoried)  
3. **Tests:** `AppointmentSlotConcurrencyTest`, `PaymentConcurrencyAndIntegrityTest`  
4. **Measurable evidence:** Automated overbook/duplicate prevention tests exist  
5. **Missing:** Wider concurrent matrix (issue-license races, document submit races) as dedicated suites  
6. **Candidate metric:** Max concurrent bookers vs capacity → overbook incidents = 0  
7. **Method:** Parallel requests / PHPUnit patterns already used  
8. **Claim status:** **VERIFIED** for covered scenarios; expand before claiming global concurrency safety

### 4.16 Privacy

1. **Mechanisms:** Private document storage; public license verify returns constrained payload (dashboard client also whitelists fields); auth required for personal data APIs  
2. **Files:** `config/filesystems.php` private disk; document services; `publicLicensesApi` whitelist; license verification controller  
3. **Tests:** License verification tests; document flow tests  
4. **Measurable evidence:** Behavioral — public verify should not leak full PII (assert in tests where present)  
5. **Missing:** Formal data-retention policy implementation; DPIA  
6. **Candidate metric:** Forbidden fields absent from public verify JSON (field allowlist test count)  
7. **Method:** Contract tests on verify response keys  
8. **Claim status:** **PARTIALLY SUPPORTED**

---

## 5. QUANTITATIVE METRIC CANDIDATES

**Do not invent results.** These are candidates for a later measurement phase.

### 5.1 Performance

| Metric name | Unit | Workload / sample | Tool / method | Environment requirements | How to interpret | What NOT to infer |
|-------------|------|-------------------|---------------|--------------------------|------------------|-------------------|
| API latency p50 | ms | N warm requests, fixed dataset | k6/Artillery/wrk + Laravel log or load-tool stats | Staging-like Docker; no Xdebug; warm caches as declared | Typical response time | Not user-perceived FE time |
| API latency p95 | ms | Same | Same | Same | Tail latency under load | Not worst-case infinite |
| API latency p99 | ms | Same | Same | Same | Rare slow requests | Not SLA without ops history |
| Throughput | req/s | Sustained load step | Load tool | Fixed concurrency steps | Capacity before degradation | Not production capacity |
| Error rate | % | During load | HTTP non-2xx / business 5xx | Same | Stability under load | Not security |
| Concurrency level | VUs | Declared in test plan | Load tool | Same | Experimental factor | Not “users supported” marketing |
| Endpoint-specific latency | ms | Per endpoint in §6 | Same | Same | Compare simple vs complex vs AI | **Never mix AI/Gemini latency into core API SLA** |

### 5.2 Security

| Metric name | Unit | Workload / sample | Tool / method | Environment | Interpret | Do not infer |
|-------------|------|-------------------|---------------|-------------|-----------|--------------|
| Authorization Feature files / cases | count | Full suite | PHPUnit filter + inventory | `dlms_testing` | Breadth of authz regression net | Not pentest score |
| Unauthenticated → 401 cases | count | Critical protected routes | Feature tests / Postman negative pack | Same | AuthN enforcement | Not all routes covered unless inventoried |
| Unauthorized → 403 cases | count | Wrong role/permission | Feature tests | Same | RBAC enforcement | Not IDOR completeness |
| Ownership/IDOR negative cases | count | Other-citizen resource IDs | Feature tests | Same | Horizontal authz | Absence of keyword ≠ absence of tests |
| Rate-limit verification | pass/fail | Burst over throttle | Dedicated tests asserting 429 | Must **not** disable throttle middleware | Throttle works | Not DDoS immunity |
| Token properties | qualitative checklist | Sanctum personal access tokens | Code review + tests | — | Token auth model | Not OAuth2 completeness |

### 5.3 Reliability

| Metric name | Unit | Sample | Method | Env | Interpret | Do not infer |
|-------------|------|--------|--------|-----|-----------|--------------|
| Idempotent duplicate handling | pass/fail + duplicate row count | Double pay / double notify / double webhook | Existing + extended Feature tests | testing DB | Safe retries | Not exactly-once across all domains |
| Retry behavior | attempts until success/dead | Push job failures | `PushDeliveryRetryTest` + job config | queue worker on | Bounded retry | Not infinite resilience |
| Stale-state rejection | pass/fail | AI actions / appointments wrong status | Feature tests | Same | State machine guards | Not formal model checking |
| Transaction rollback | pass/fail | Forced mid-flight failure | Feature / unit with mock throw | Same | Atomicity | Not distributed TX |
| Queue failure handling | pass/fail | FCM down | Job tests | worker | Isolation of push failures | Not core API HA |

### 5.4 Data integrity / concurrency

| Metric name | Unit | Sample | Method | Interpret | Do not infer |
|-------------|------|--------|--------|-----------|--------------|
| Concurrent booking overbook count | count (target 0) | capacity=1, parallel book | `AppointmentSlotConcurrencyTest` + load variant | Locking works | All resources safe |
| Duplicate payment prevention | duplicate settled rows | parallel initiate/confirm | `PaymentConcurrencyAndIntegrityTest` | Obligation uniqueness | Stripe outage behavior |
| Duplicate issuance prevention | duplicate licenses | parallel issue | Extend issuance tests | Issuance atomicity | — |
| Unique constraint enforcement | exception/HTTP outcome | Insert conflict | DB + Feature | Schema guards | App always friendly-errors |

### 5.5 Testing

| Metric name | Unit | Notes |
|-------------|------|-------|
| Total test files | count | Feature 101 + Unit 5 = **106** (inventory) |
| Total test methods / assertions | count | Heuristic ~1027 / ~4491 — **recompute from final run** |
| Pass rate | % | **PENDING FINAL RUN** |
| Feature vs Unit split | count | 101 / 5 |
| Negative authz cases | count | Derive from final inventory |
| Concurrency/idempotency cases | count | Dedicated files listed in §7 |
| Module coverage by test files | mapping | Which modules lack Feature files |

### 5.6 Maintainability (defensible only)

| Metric name | Unit | Method | Do not use |
|-------------|------|--------|------------|
| Modules with ≥1 Feature test file | % | Map modules→tests | Raw LOC as quality |
| Critical workflow end-to-end Feature files | count | Apply→pay→book→issue paths | “Clean code score” without tool |
| Public API FormRequest adoption on writes | % | Route→Request mapping | Style opinions |

### 5.7 Localization

| Metric name | Unit | Method |
|-------------|------|--------|
| AR/EN `messages.php` key parity | % | Scripted key diff |
| Missing keys in either locale | count | Same |
| Localized API Feature pass rate | % | Locale test group |

### 5.8 Auditability

| Metric name | Unit | Method |
|-------------|------|--------|
| Identified critical operations | count | From use-case list |
| Critical ops writing audit rows | count | Grep `AuditLogService` + tests |
| Potential audit coverage | % | ratio |

### 5.9 Observability

| Metric name | Unit | Method |
|-------------|------|--------|
| Operational signals available | checklist | `/up`, logs, queue worker, JSON errors |
| Structured error contract compliance | % | Sample error responses match envelope |

---

## 6. PERFORMANCE TEST CANDIDATE ENDPOINTS

**Do not run load tests in this audit.** Candidates for phase-2 benchmarking:

| Category | Candidate endpoint | Why representative | DB / query complexity | Auth | Safe to load test? | Prerequisites |
|----------|--------------------|--------------------|-----------------------|------|--------------------|---------------|
| **Public / simple read** | `GET /api/ping` | Minimal JSON; baseline overhead | None/trivial | No | **Yes** | App up |
| **Public catalog read** | `GET /api/license-types` (also service-types/test-types) | Public read with locale | Small catalog tables | Locale only | **Yes** | Seeded catalog |
| **Public verify** | `GET /api/licenses/verify/{token}` | Security-sensitive public read; throttled 30/min | License lookup by token | Public + throttle | **Yes with care** — respect throttle or raise only in staging | Known verification tokens; do not hammer production |
| **Authenticated dashboard read** | `GET /api/dashboard/overview` | Aggregated employee home | Multiple aggregates | Sanctum + dashboard + permission | **Yes** in staging | Employee token + permissions + demo data |
| **Filtered / paginated list** | `GET /api/dashboard/applications` (with filters) | Typical list+filter+paginate | Indexed filters + joins | Sanctum + permission | **Yes** | Seeded applications volume |
| **Complex workflow read** | `GET /api/applications/{id}` or dashboard application details | Workflow projection | Multiple relations | Citizen or dashboard auth | **Yes** | Owned/known IDs |
| **Safe write (controlled)** | `POST /api/auth/login` **or** dashboard login with test users | Write-ish auth path; watch lockout/throttle | User lookup | Public | **Limited** — use dedicated users; avoid account lock storms | Test accounts; throttle awareness |
| **Safer domain write** | Create appointment slot (dashboard) on disposable slots **or** mock payment confirm in testing | Mutating but controllable | Transactions | Auth + permission | **Staging only**, disposable data | Isolated DB; cleanup plan |
| **AI endpoint (SEPARATE bucket)** | `POST /api/ai-agent/message` | External Gemini latency dominates | Session + orchestration + LLM HTTP | Sanctum + citizen | **Separate profile only** — never average with `/ping` | Gemini key; cost controls; mock provider if measuring pure app overhead |

**Reporting rule:** Publish three latency profiles minimum: (A) public baseline, (B) authenticated dashboard lists, (C) AI isolated.

---

## 7. TEST EVIDENCE INVENTORY

### 7.1 Suite status

| Item | Value |
|------|-------|
| Backend Feature test files | **101** |
| Backend Unit test files | **5** |
| Backend total test files | **106** |
| Heuristic test-method hits | ~1027 (**not** final) |
| Heuristic assertion hits | ~4491 (**not** final) |
| Full-suite pass/fail/skip/time | **PENDING FINAL RUN** |
| Dashboard automated tests | **0** under `src/` |
| CI workflows (`.github/workflows`) | **NOT FOUND** |
| Performance/load suites | **NOT FOUND** |
| Positive HTTP 429 assertions | **NOT FOUND** (throttles often disabled in tests) |

### 7.2 Classification by engineering concern

Counts below are **file-level associations** (a file may map to multiple concerns). They are inventory evidence, not suite results.

| Concern | Evidence strength | Representative / matching test files (non-exhaustive where broad) |
|---------|-------------------|---------------------------------------------------------------------|
| **Authentication** | Strong | `DashboardAuthTest`, `PasswordResetFlowTest`, `EmployeeSessionLifecycleTest`, `RequestLocaleTest` (401 paths), `FcmClientTest` |
| **Authorization / RBAC** | Strong | `DashboardPermissionTest`, `DashboardAccessControlTest`, `DashboardRoleManagementTest`, `DashboardEmployeeAccessTest`, `DocumentReviewerAuthorizationTest`, `SuperAdminProtectionTest`, `EmployeeManagementTest` |
| **IDOR / ownership** | Moderate–strong (behavior more than keyword) | Ownership exercised in Application/Appointment/Payment/Notification/License/AIAgent flows; explicit IDOR mention in `DashboardEmployeeSessionsTest` |
| **Business rules** | Strong | `ApplicationFlowTest`, `DocumentFlowTest`, `ProfileApprovalFlowTest`, `OtherLicenseServicesFlowTest`, `AvailableTestsApiTest`, progression-related appointment tests |
| **Payments** | Strong | `PaymentFlowTest`, `PaymentStripeTest`, `PaymentConcurrencyAndIntegrityTest`, `DashboardPaymentManagementTest`, `ApplicationFeeUsdCatalogTest` |
| **Concurrency** | Targeted strong | `AppointmentSlotConcurrencyTest`, `PaymentConcurrencyAndIntegrityTest` |
| **Idempotency** | Strong | `NotificationIdempotencyTest`, payment/stripe/flow tests, push planning/device registration, some license expiry sync |
| **Application state transitions** | Strong | Application/document/profile/license service flow tests |
| **Tests / exam progression** | Strong | `AppointmentFlowTest`, `AvailableTestsApiTest`, `DashboardTestAppointmentListTest`, AI appointment multi-slot tests |
| **License issuance** | Strong | `LicenseFlowTest`, `DashboardLicenseIssuanceQueueTest`, `DashboardIssuedLicensesTest`, `LicensePrintingTest`, `LicenseExpirySyncTest` |
| **Notifications** | Very strong | Full `Notification*` suite + appointment notification tests |
| **AI Agent** | Strong | 12 Feature `AIAgent*` + Unit `AgentLanguageDetectorTest`, `AgentLocaleContextTest` |
| **Localization** | Strong | `ArabicLocalizationTest`, `Citizen*Localization*`, `LicenseVerificationLocalizationTest`, `NotificationLocalizationTest`, `RequestLocaleTest`, Agent bilingual tests |
| **Public verification** | Strong | `LicenseVerificationTest`, `LicenseVerificationLocalizationTest` |
| **Push / FCM** | Strong | `Push*`, `Firebase*`, `SendPushNotificationJobTest`, `FcmClientTest` |
| **Employee sessions** | Strong | `EmployeeSession*` suite |
| **Audit** | Moderate–strong | Many dashboard tests assert audit rows; `NotificationAuditReportTest` |
| **Rate limiting** | **Weak / unverified positively** | Middleware present in routes; tests mostly disable throttle |
| **RAG / antivirus / SMS OTP / CI** | **NOT IMPLEMENTED** in evidence | No app code / no workflows |

---

## 8. ENGINEERING HIGHLIGHTS

Strongest committee-worthy engineering decisions (architecture/quality over UI):

| # | Engineering problem | Design decision | Implementation | Evidence | Why it matters | Suggested report chapter |
|---|---------------------|-----------------|----------------|----------|----------------|--------------------------|
| 1 | Prevent double-spend / duplicate checkout chaos | Payment obligation uniqueness + transactional locks | Unique `active_obligation_key` / `settled_obligation_key`; `lockForUpdate`; Stripe idempotency key | Migrations; `ApplicationPaymentService`; `PaymentConcurrencyAndIntegrityTest` | Financial integrity without microservices | Data Integrity / Payments |
| 2 | Prevent appointment overbooking | Pessimistic locking + capacity check | `AppointmentService::book` locks application+slot | Code + `AppointmentSlotConcurrencyTest` | Classic concurrency control teaching point | Concurrency Safety |
| 3 | Stripe retries / webhook duplicates | Gateway event uniqueness + reservation | `(provider, event_id)` unique; webhook controller throttled | Migrations; `PaymentStripeTest` | External integration reliability | Reliability / Integrations |
| 4 | Notification spam on retries | Idempotent `event_key` + `afterCommit` | Notification module design | `NotificationIdempotencyTest`, architecture tests | Side-effect safety after TX | Reliability / Notifications |
| 5 | Citizen IDOR on shared IDs | Ownership-scoped queries | `findOwnedByCitizen`, citizen_id filters | Repositories/services + Feature flows | Core API security property | Security |
| 6 | Employee least privilege | RBAC permissions middleware + bootstrap registry | `EnsurePermission`, `config/dashboard_permissions.php`, role tools | Dashboard permission/role Feature suite | Enterprise access control | Security / Dashboard |
| 7 | Separate citizen vs staff surfaces | Middleware persona gates | `citizen`, `dashboard`, `profile.approved` | `routes/api.php`, middleware classes | Clear trust boundaries | Architecture / Security |
| 8 | Uniform API errors + locale | Central exception JSON + locale middleware | `bootstrap/app.php`, `ResolveRequestLocale` | Locale Feature tests | API usability + i18n | Architecture / Localization |
| 9 | Async push without blocking requests | Queue job + retries + Supervisor | `SendPushNotificationJob`, Docker supervisor | Push Feature suite + compose | Partial async architecture with evidence | Architecture / Reliability |
| 10 | License public trust without login | Tokenized public verify + throttle | `GET /licenses/verify/{token}` | `LicenseVerificationTest` + dashboard public feature | Public integrity use case | Security / Public API |
| 11 | Audit trail for sensitive ops | Shared `AuditLogService` | Explicit domain calls + admin read | Audit asserts in Feature tests | Accountability | Auditability |
| 12 | AI actions must not silently mutate unsafely | Confirmation / pending workflow / ownership checks | AIAgent services + Phase1 critical action tests | `AIAgentPhase1CriticalActionsTest`, reliability tests | Safe agentic automation | AI Agent / Reliability |
| 13 | Modular monolith for one government workflow | `app/Modules` by domain | 18 modules, single deployable | Tree structure | Fits DLMS lifecycle cohesion | Architecture |
| 14 | Dashboard defense in depth (UX) | PermissionGuard + backend permissions | Next.js guards + Laravel middleware | `PermissionGuard.tsx` + backend tests | Honest layered UX; backend remains authority | Frontend Architecture |
| 15 | Reconcile eventually consistent payments | Scheduled reconciliation | Console schedules + payment services | Code + payment tests | Recoverability pattern | Recoverability |

---

## 9. WEAK / UNSUPPORTED CLAIMS

Strict list — **do not put these in the committee report** unless later measured/fixed and re-audited:

| Claim wording | Evidence check | Required stance |
|---------------|----------------|-----------------|
| “High performance” | No latency benchmarks | **DO NOT CLAIM** |
| “99.9% availability” / any uptime SLA | No monitoring history | **DO NOT CLAIM** |
| “Scalable” (as proven) | Architecture allows scale; unmeasured | Say “stateless API amenable to scale” only if careful; avoid “proven scalable” |
| “AOP” / “Aspect-Oriented Programming” | No AOP framework/aspects | **DO NOT CLAIM** — say middleware/cross-cutting services |
| “Zero downtime” | No rolling-deploy evidence | **DO NOT CLAIM** |
| “Secure” (absolute) | Strong controls + tests, no pentest | Say “security controls implemented and tested for …” |
| “Full test coverage” | No coverage report/threshold; FE 0 tests | **DO NOT CLAIM** |
| “CI/CD” | No `.github/workflows` (or other CI) found | **DO NOT CLAIM** |
| “RAG” / vector DB / embeddings | **0** in app code; docs mark out of scope | **DO NOT CLAIM** |
| “Antivirus scanning” of uploads | **Not Found** | **DO NOT CLAIM** |
| “SMS OTP” | Email OTP implemented; SMS not evidenced | **DO NOT CLAIM** |
| “Mobile application architecture” *from these repos* | Flutter source **Not Found** in audited trees | Claim only “citizen API ready”; attach Flutter repo separately if available |
| “Event-driven architecture” | No domain Events/Listeners | **DO NOT CLAIM** — mention limited afterCommit/queue only |
| “Repository pattern everywhere” | Partial repos only | **DO NOT CLAIM** |
| “Dashboard bilingual EN/AR” | AR-first hardcoded UI | **DO NOT CLAIM** EN dashboard |
| “Rate limiting verified” | Throttles configured; positive 429 tests missing | Claim “configured”; not “verified by tests” until added |
| “OpenAI-based agent” | Gemini provider in `config/ai.php` | **DO NOT CLAIM** OpenAI |
| “Microservices” | Modular monolith | **DO NOT CLAIM** |

---

## 10. FINAL EVIDENCE MATRIX

| NFR / Engineering Concern | Requirement / Goal | Design Decision | Implementation Evidence | Existing Test Evidence | Quantitative Metric | Measurement Still Needed | Report Claim Status | Recommended Report Location | Priority Class |
|---------------------------|--------------------|-----------------|-------------------------|------------------------|---------------------|--------------------------|---------------------|----------------------------|----------------|
| Layered modular API | Clear maintainable structure | Modules + service layer | `app/Modules/*`, routes, services | Indirect via Feature suite | Module/test mapping % | Optional static diagrams only | **VERIFIED** (structure) | Architecture | **MUST HIGHLIGHT** |
| RBAC + persona middleware | Staff least privilege | Permission middleware + registry | `EnsurePermission`, dashboard routes | Permission/role/access Feature tests | 401/403 case counts | Final suite run counts | **VERIFIED** (controls+tests) | Security | **MUST HIGHLIGHT** |
| Ownership / IDOR controls | Citizens cannot access others’ resources | Scoped queries | Repositories/services | Flow tests + session IDOR notes | IDOR negative case count | Explicit inventory matrix | **PARTIALLY SUPPORTED** → aim VERIFIED after matrix | Security | **MUST HIGHLIGHT** |
| Payment integrity | No duplicate settle / safe retries | TX + unique keys + Stripe idempotency | Payments module + migrations | Payment concurrency/flow/stripe tests | Duplicate row count=0 | Staging replay script | **VERIFIED** for tested paths | Data Integrity | **MUST HIGHLIGHT** |
| Booking concurrency | No overbook | `lockForUpdate` + capacity | `AppointmentService` | `AppointmentSlotConcurrencyTest` | Overbook incidents=0 | Parallel load variant | **VERIFIED** for tested paths | Concurrency | **MUST HIGHLIGHT** |
| Notification idempotency | No duplicate spam | `event_key` + afterCommit | Notifications module | `NotificationIdempotencyTest` et al. | Duplicate notifications count | Coverage vs event matrix % | **VERIFIED** for tested events | Reliability | **MUST HIGHLIGHT** |
| Localization (API) | AR/EN messages | Locale middleware + lang files | `resources/lang/*`, middleware | Many locale Feature/Unit tests | Key parity % | Parity script + final run | **VERIFIED** (behavior) / parity **UNMEASURED** | Localization | **MUST HIGHLIGHT** |
| Public license verify | Trustable public check | Token + throttle + limited payload | Verify controller + FE feature | Verification Feature tests | Verify latency + field allowlist | Load+contract metrics | **VERIFIED** functionally | Security / Public API | **IMPORTANT** |
| Audit logging | Sensitive actions traceable | Shared AuditLogService | `AuditLogService`, callers | Multiple audit asserts | Audit coverage % | Critical-ops matrix | **PARTIALLY SUPPORTED** | Auditability | **IMPORTANT** |
| Queue push isolation | Don’t block HTTP on FCM | ShouldQueue job + worker | `SendPushNotificationJob`, Supervisor | Push/Firebase Feature tests | Job success/retry rates | Staging FCM failure drill | **IMPLEMENTED BUT UNMEASURED** (ops) | Reliability | **IMPORTANT** |
| AI Agent safety | Controlled tool-like actions | Confirmation/pending workflow | AIAgent module + Gemini client | AIAgent Feature suite | Action reject rates | Separate LLM latency profile | **PARTIALLY SUPPORTED** | AI chapter | **IMPORTANT** |
| Exception uniformity | Consistent API errors | Central renders | `bootstrap/app.php` | Implicit Feature asserts | Envelope compliance % | Sample audit | **VERIFIED** pattern | Architecture | **SUPPORTING** |
| Rate limiting | Abuse control | `throttle:` routes | `routes/api.php` + module routes | Mostly disabled in tests | 429 pass/fail | **Add positive tests** | **IMPLEMENTED BUT UNMEASURED** | Security | **SUPPORTING** |
| Employee sessions | Staff session accountability | Track middleware + revoke | Session services/middleware | `EmployeeSession*` tests | Revoke propagation time | Optional | **VERIFIED** functionally | Security | **IMPORTANT** |
| Observability | Operate the system | Logs + `/up` | health route, `Log::` | OTP log safety test | Probe success ratio | Metrics stack if required | **PARTIALLY SUPPORTED** | Ops | **SUPPORTING** |
| Performance SLOs | Fast API | — | — | — | p95 latency | **Must measure** | **DO NOT CLAIM** | Limitations / future work | **DO NOT CLAIM** |
| Availability SLA | Always up | Docker/health only | `/up`, compose | — | Uptime % | Long-window monitoring | **DO NOT CLAIM** | Limitations | **DO NOT CLAIM** |
| Scalability proof | Handle growth | Stateless API | Sanctum API | — | RPS@p95 | Load test | **DO NOT CLAIM** (as proven) | Limitations | **DO NOT CLAIM** |
| True AOP | Aspect weaving | — | — | — | — | — | **DO NOT CLAIM** | Clarify cross-cutting instead | **DO NOT CLAIM** |
| CI/CD | Automated pipelines | — | No workflows found | — | Pipeline pass | Introduce CI | **DO NOT CLAIM** | Limitations | **DO NOT CLAIM** |
| RAG | Knowledge retrieval AI | — | Not in app | — | — | — | **DO NOT CLAIM** | Limitations | **DO NOT CLAIM** |
| Antivirus | Malware scan uploads | — | Not found | — | — | — | **DO NOT CLAIM** | Limitations | **DO NOT CLAIM** |
| SMS OTP | Phone codes | Email OTP instead | `OtpService` email | OTP tests (email) | — | — | **DO NOT CLAIM** SMS | Auth chapter accuracy | **DO NOT CLAIM** |
| Mobile app (from these trees) | Flutter UX | External client | API+Postman only | Backend API tests | — | Attach Flutter repo | **DO NOT CLAIM** mobile architecture here | External interfaces | **APPENDIX** / separate |
| Dashboard FE tests | UI regression | — | 0 tests | — | FE pass rate | Add Playwright/Vitest | **NOT IMPLEMENTED** | Limitations | **APPENDIX** |
| Dashboard i18n EN | Bilingual staff UI | AR-only UI | `lang=ar` | — | — | — | **DO NOT CLAIM** | Localization limitations | **APPENDIX** |
| Portability via Docker | Reproducible env | Compose/Dockerfile | docker/* | — | Clean bring-up | Clean-room run log | **IMPLEMENTED BUT UNMEASURED** | Deployment | **SUPPORTING** |
| Privacy (public verify) | Minimize PII exposure | Allowlisted public payload | Verify API + FE whitelist | Verification tests | Forbidden field count | Expand contract tests | **PARTIALLY SUPPORTED** | Privacy / Security | **IMPORTANT** |
| Recoverability helpers | Fix stuck states | Schedulers/reconcile | `routes/console.php` | Payment/push/license sync tests | Reconcile success rate | Staging drill | **PARTIALLY SUPPORTED** | Reliability | **SUPPORTING** |
| Testability (backend) | Regression safety | Large PHPUnit Feature suite | `tests/**` | 106 files | Pass rate **PENDING** | Final run artifact | **PARTIALLY SUPPORTED** until final run attached | V&V | **MUST HIGHLIGHT** |

---

## 11. NEXT MEASUREMENT PLAN

Execute later (not in this audit), ordered by **committee value → scientific defensibility → reproducibility → risk/cost**.

| Priority | Measurement | Why first | Deliverable artifact | Cost / risk |
|----------|-------------|-----------|----------------------|-------------|
| **1** | Full backend `php artisan test` (or PHPUnit) on clean `dlms_testing` | Converts PENDING → real pass rate, time, failures | JUnit/log + summary table (files, assertions if available) | Low risk; medium time |
| **2** | Security negative-case inventory spreadsheet | Makes “authorization evidence” countable | CSV: route × unauth 401 × forbid 403 × IDOR case × test method | Low |
| **3** | Audit coverage matrix | Turns auditability from anecdote → % | Critical ops list × `AuditLogService` caller × test assert | Low |
| **4** | Localization key-parity script (`messages.php` AR↔EN) | Quantifies bilingual completeness | Parity % + missing-key list | Low |
| **5** | Positive rate-limit tests (assert 429) for 2–3 routes | Closes largest “configured but unverified” gap | Feature tests enabling throttle | Low |
| **6** | Performance profile A — public baseline (`/ping`, catalog) | Establishes scientific latency floor | k6 script + p50/p95/p99 JSON | Low |
| **7** | Performance profile B — dashboard paginated list + overview | Representative staff workload | Same tool, auth setup notes | Medium (data seeding) |
| **8** | Concurrency extension — parallel booking + parallel payment under load tool | Stress beyond PHPUnit process model | Overbook/duplicate counters | Medium |
| **9** | Payment webhook replay + reconcile drill | Reliability/recoverability demo | Script + DB before/after snapshots | Medium |
| **10** | Performance profile C — AI Agent **isolated** | Prevents LLM latency contaminating API claims | Separate report section; optional mocked LLM overhead test | Medium/cost (API usage) |
| **11** | Docker clean-room bring-up | Portability evidence | Timed compose log | Low |
| **12** | Optional: minimal Playwright smoke on dashboard login+permission deny | Reduces FE test gap slightly | 2–3 e2e tests | Medium |
| **13** | Do **not** prioritize: availability 99.9% study, pentest (unless faculty requires), FE full coverage | High cost / easy to overclaim | — | High |

### Phase-2 reproducibility checklist (for whoever measures next)

1. Record: commit SHA (backend + dashboard), PHP/Node versions, `phpunit.xml` DB name, seed command, whether opcache/Xdebug on.  
2. Freeze dataset size (e.g., N applications, M slots).  
3. Store raw load-tool output beside a one-page interpretation that follows §5 “What NOT to infer”.  
4. Re-run this audit’s claim statuses after attaching numbers — promote only cells with artifacts.

---

## Appendix A — Source trees audited

| Tree | Role | Key tech evidenced |
|------|------|--------------------|
| `D:\Projects\DLMS_Project` | Backend API + PHPUnit | Laravel 12, Sanctum, modular `app/Modules`, Stripe, Gemini, FCM, Docker |
| `D:\Projects\DLMS_Dashboard` | Employee web UI | Next.js 15, React 19, Axios, feature modules, client permission guards |

## Appendix B — Related prior documents (pointers only; this file supersedes for NFR claim discipline)

- `SYRTAK_FINAL_SRS_SOURCE_OF_TRUTH.md` §25 (aligns: no invented numeric SLAs)  
- `docs/FINAL_REPORT_PROJECT_AUDIT.md` (broader product audit; Flutter/CI/RAG gaps)  
- `docs/PROJECT_MASTER_CONTEXT.md`  

## Appendix C — Explicit non-findings

| Topic | Result |
|-------|--------|
| True AOP | **NOT IMPLEMENTED** |
| Domain Events/Listeners | **NOT IMPLEMENTED** |
| CI/CD workflows | **NOT FOUND** |
| Dashboard unit/e2e tests | **NOT FOUND** |
| Load test suite | **NOT FOUND** |
| RAG / vector / embeddings | **NOT FOUND** in app |
| Antivirus on upload | **NOT FOUND** |
| SMS OTP | **NOT FOUND** (email OTP **IMPLEMENTED**) |
| Flutter source in audited paths | **NOT FOUND** |

---

**End of audit.**  
All quantitative suite outcomes remain **PENDING FINAL RUN** until a fresh test execution artifact is attached.
