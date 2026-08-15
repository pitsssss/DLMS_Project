# FINAL REPORT EVIDENCE — SYRTAK / DLMS

**Role:** Single source of truth for the graduation committee report  
**System:** SYRTAK — Digital License Management System  
**Audit type:** Read-only synthesis of repository evidence (no application-code changes)  
**Primary backend repo:** `D:\Projects\DLMS_Project`  
**Sibling dashboard (out of git tree):** `D:\Projects\DLMS_Dashboard` (Next.js)  
**Evidence cut-off:** 2026-08-15  

### Evidence rules

| Rule | Application |
|------|-------------|
| Metrics only from committed artifacts / code inventory | Applied |
| No invented latency, throughput, coverage, or SLA numbers | Applied |
| Marketing architecture labels without code evidence | Excluded (see §15) |
| Flutter mobile client | **Not Found** in audited trees — do not claim |
| CI/CD workflows | **Not Found** — do not claim |

### Claim vocabulary

| Status | Meaning |
|--------|---------|
| **VERIFIED** | Implemented **and** automated / measured evidence supports the scoped claim |
| **PARTIALLY VERIFIED** | Real mechanism + incomplete coverage or caveats |
| **IMPLEMENTED BUT UNMEASURED** | Code exists; no quantitative / behavioral proof in artifacts |
| **DO NOT CLAIM** | Absent, overstated, or unsafe for committee wording |

### Claim block template (used below)

For each important claim: **Claim** · **Design** · **Implementation** · **Verification** · **Measured result** · **Artifact** · **Safe wording** · **Limitations**

---

## 1. System baseline

| Item | Value | Artifact |
|------|-------|----------|
| Backend stack | PHP **8.4.24**, Laravel **12.66.0**, Composer **2.10.2** | `docs/evidence/final-measurements/php_version.txt`, `laravel_version.txt`, `composer_version.txt` |
| Recorded commit | `0c27c3df574ae53eb953763785e8b22f0f95f40b` | `docs/evidence/final-measurements/backend_commit.txt` |
| Architecture shape | Modular monolith REST API | `app/Modules/*`, `docs/FINAL_NFR_ARCHITECTURE_EVIDENCE_AUDIT.md` |
| Data store | MySQL / MariaDB (Docker / local); SQLite used in PHPUnit | `config/database.php`, `phpunit.xml`, `.env.benchmark` |
| Auth | Laravel Sanctum bearer tokens | `composer.json`, `app/Models/User.php` |
| Integrations | Stripe (optional) + mock payments, Gemini AI, Firebase FCM, mail OTP, database queue | `config/*`, module services |
| Benchmark DB | `dlms_benchmark`, `APP_ENV=benchmark` | `.env.benchmark`, `docs/evidence/final-measurements/BENCHMARK_DATASET.md` |

### Claim — reproducible backend baseline

- **Claim / Requirement:** Report must state exact stack and evidence commit.
- **Design decision:** Capture versions + commit at measurement time.
- **Implementation evidence:** Version text dumps + commit file.
- **Verification method:** File contents listed above.
- **Measured result:** PHP 8.4.24 / Laravel 12.66.0 / Composer 2.10.2 / commit `0c27c3df…`.
- **Reproducibility artifact:** `docs/evidence/final-measurements/{php,laravel,composer}_version.txt`, `backend_commit.txt`.
- **Safe report wording:** “Backend measurements refer to Laravel 12.66.0 on PHP 8.4.24 at commit `0c27c3df…`.”
- **Claim limitations:** Versions may drift after that commit; re-capture before citing newer runs.

### Claim — full automated suite (post-hardening)

- **Claim / Requirement:** Final functional/regression baseline is green.
- **Design decision:** PHPUnit Feature + Unit as authoritative automated verification.
- **Implementation evidence:** Hardening suite + full console dump.
- **Verification method:** `php artisan test` (recorded).
- **Measured result:** **1058 tests passed**, **6694 assertions**, **0 failures**, **258.15 s**.
- **Reproducibility artifact:** `docs/evidence/final-measurements/backend_full_suite_after_hardening.txt`, `EVIDENCE_HARDENING_RESULT.md`.
- **Safe report wording:** “After evidence hardening, the backend suite recorded 1058 passed tests (6694 assertions) with zero failures in 258.15 seconds.”
- **Claim limitations:** Pre-hardening baseline was 1043 / 6557 / 229.64s (`backend_full_suite_console.txt`). Matrices dated 2026-08-14 often still quote 1043/6557. Dashboard Next.js has **0** automated test files under `src/`.

---

## 2. Architecture

### Claim — modular monolith (not microservices / not event-driven / not AOP)

- **Claim / Requirement:** Describe the real architecture honestly.
- **Design decision:** Domain modules inside one Laravel deployable; cross-cutting via middleware + shared services.
- **Implementation evidence:** 18 modules under `app/Modules/` (Auth, Applications, Appointments, Payments, Licenses, Dashboard, Admin, AIAgent, Notifications, Push, Devices, Firebase, Fines, Reports, Settings, Content, Tests, AuditLogs). Controllers → Services → (optional) Repositories → Eloquent. No `app/Events` / `app/Listeners` domain bus. No AOP library.
- **Verification method:** Tree + architecture audit.
- **Measured result:** Qualitative — modular monolith confirmed; repository pattern **partial**; only one queued job class (`SendPushNotificationJob`).
- **Reproducibility artifact:** `docs/FINAL_NFR_ARCHITECTURE_EVIDENCE_AUDIT.md`, `docs/FINAL_REPORT_PROJECT_AUDIT.md`.
- **Safe report wording:** “SYRTAK backend is a Laravel modular monolith with a service layer and selective repositories.”
- **Claim limitations:** **DO NOT CLAIM** microservices, CQRS, hexagonal ports as formal architecture, true AOP, event-driven architecture, or “repository pattern everywhere.”

### Claim — client surfaces

- **Claim / Requirement:** Name consumers of the API.
- **Design decision:** One API for citizen clients + employee dashboard.
- **Implementation evidence:** Sanctum citizen routes; Dashboard/Admin modules; Next.js sibling dashboard.
- **Verification method:** Route files + dashboard audit.
- **Measured result:** Employee UI exists as Next.js 15 SPA (sibling repo). Flutter source **not found** (0 `.dart` / `pubspec.yaml` in audited trees).
- **Reproducibility artifact:** `docs/FINAL_REPORT_PROJECT_AUDIT.md` §1.
- **Safe report wording:** “Employee operations are served by a Next.js dashboard consuming the Laravel API; a Flutter repository was not part of the audited evidence set.”
- **Claim limitations:** Do not claim a completed mobile architecture from this backend repo alone.

---

## 3. Functional verification

### Claim — core license lifecycle is automated

- **Claim / Requirement:** End-to-end licensing workflow is implemented and tested.
- **Design decision:** Service-enforced workflow: draft → documents → payment → (tests for `new_license`) → approval → issuance; renew/replace/unblock via related license.
- **Implementation evidence:** `ServiceWorkflow`, Applications / Documents / Payments / Appointments / Tests / Licenses / Dashboard services.
- **Verification method:** Broad Feature suite (Application, Appointment, Payment, License, Dashboard issuance, Document review, etc.).
- **Measured result:** Covered by the **1058-pass** suite (artifact above). Exact per-feature counts live in PHPUnit files under `tests/Feature/`.
- **Reproducibility artifact:** `backend_full_suite_after_hardening.txt`; module tests named in `docs/FINAL_REPORT_PROJECT_AUDIT.md`.
- **Safe report wording:** “Core citizen and employee license workflows are covered by automated Feature tests in the recorded green suite.”
- **Claim limitations:** `transitionStatus()` does **not** enforce a central FSM matrix (illegal edges are not globally rejected). `rejected` / `cancelled` application statuses lack proven production transition callers. Citizen fine **checkout** is not claimed as complete payment UX.

---

## 4. Security

Post-hardening security metrics (authoritative updates in `EVIDENCE_HARDENING_RESULT.md`; base inventory in `SECURITY_TEST_EVIDENCE_MATRIX.md` + CSV).

| Metric | Measured result |
|--------|-----------------|
| SEC-AUTHN-401 | **26** scenarios |
| SEC-AUTHZ-403 | **95** scenarios |
| SEC-IDOR-NEGATIVE | **21** (read **5** / write **16**) |
| SEC-DATA-EXPOSURE-NEGATIVE | **34** methods |
| SEC-TRUST-BOUNDARY | **32** scenarios |
| SEC-CRITICAL-AUTHORIZED-COVERAGE | **13/13 (100%)** |
| SEC-CRITICAL-401-COVERAGE | **7/13 (53.8%)** after hardening (was 2/13) |
| SEC-CRITICAL-403-COVERAGE | **13/13 (100%)** after hardening |
| SEC-CRITICAL-IDOR-COVERAGE | **1/2 (50%)** |
| SEC-RATE-LIMIT-429 | **4** positive 429 tests after hardening (`RateLimitEvidenceTest`) |

### Claim — authentication / authorization automation

- **Claim / Requirement:** Protected APIs reject guests and unauthorized personas.
- **Design decision:** Sanctum + citizen/dashboard middleware + RBAC (`permission:*`) + ownership scoping.
- **Implementation evidence:** `EnsureCitizen`, `EnsureDashboardUser`, `EnsurePermission`, `User::hasPermission`, private document disk.
- **Verification method:** Curated Feature inventory + hardening additions.
- **Measured result:** 26×401, 95×403, 21 IDOR negatives; critical mutate-route 403 = 13/13; critical mutate-route 401 = 7/13.
- **Reproducibility artifact:** `SECURITY_TEST_EVIDENCE_MATRIX.md`, `security_test_evidence.csv`, `EVIDENCE_HARDENING_RESULT.md`, `tests/Feature/RateLimitEvidenceTest.php`, `CriticalMutationAuthorizationTest.php`.
- **Safe report wording:** “Unauthenticated and unauthorized access denials are supported by 26 automated 401 scenarios and 95 automated 403 scenarios; critical mutating operations have 100% mutate-route 403 coverage and 53.8% mutate-route 401 coverage.”
- **Claim limitations:** **DO NOT CLAIM** “completely secure,” pentest-grade assurance, or full route-complete authz. Remaining critical 401 gaps: profile approve/reject, payment verify/confirm, appointment-slot mutations, issue license, employee create/update, session revoke. Dashboard token storage is client `localStorage` (UX), not httpOnly cookie.

---

## 5. Reliability

Inventory: `RELIABILITY_CONCURRENCY_EVIDENCE_MATRIX.md` + hardening updates.

| Metric | Measured / inventoried |
|--------|------------------------|
| `DB::transaction` call sites | **68** (implementation count) |
| `lockForUpdate` call sites | **56** / **18** files |
| REL-IDEMPOTENCY-METHODS | **36** |
| REL-DUPLICATE-SIDE-EFFECT-METHODS | **31** |
| REL-STALE-STATE-REJECTION-METHODS | **22** |
| REL-ATOMICITY-METHODS | **5** |
| REL-ROLLBACK-METHODS | **1** |
| REL-AFTERCOMMIT-SAFETY-METHODS | **2** |
| REL-RECOVERY-METHODS | **6** (+ payment reconcile verified in hardening) |
| REL-DB-INVARIANTS-TESTED | **12/12** after hardening (was 7/12) |

### Claim — idempotent payments / notifications / push planning

- **Claim / Requirement:** Retries must not double-settle or spam side effects.
- **Design decision:** Unique obligation keys, gateway event uniqueness, notification `event_key`, push `delivery_key`, status guards.
- **Implementation evidence:** Payment + Notification + Push services/migrations.
- **Verification method:** Feature tests inventoried under REL-* metrics; reconciliation Feature proof added in hardening.
- **Measured result:** 36 idempotency methods; 31 duplicate-side-effect methods; 12/12 critical DB uniqueness invariants tested; payment reconcile completes pending → completed without duplicate completed rows (mocked Stripe).
- **Reproducibility artifact:** `RELIABILITY_CONCURRENCY_EVIDENCE_MATRIX.md`, `reliability_concurrency_evidence.csv`, `PaymentReconciliationAndDbInvariantEvidenceTest.php`, `EVIDENCE_HARDENING_RESULT.md`.
- **Safe report wording:** “Duplicate payment initiation, webhook handling, notification event keys, and push delivery keys are covered by automated idempotency/duplicate-prevention tests; all 12 inventoried critical uniqueness invariants have direct constraint evidence.”
- **Claim limitations:** **DO NOT CLAIM** platform-wide fault tolerance, disaster recovery, or exactly-once delivery across all integrations. Call-site counts ≠ proof for every site.

---

## 6. Concurrency

| Metric | Measured result |
|--------|-----------------|
| CONC-LOCK-CALLS | **56** |
| CONC-OPTIMISTIC-ENTITIES | **3** (AppointmentSlot, Role, Fee) |
| CONC-OPTIMISTIC-CONFLICT-METHODS | **3** (HTTP 409) |
| CONC-APPOINTMENT-METHODS | **9** |
| CONC-LOCKED-DOMAINS with behavioral tests | **11/13** |

### Claim — booking / optimistic concurrency controls

- **Claim / Requirement:** Concurrent booking must not overbook capacity; admin edits need conflict detection.
- **Design decision:** `lockForUpdate` on slots/payments/licenses; optimistic `version` on slots/fees/roles.
- **Implementation evidence:** Appointment + Dashboard slot/fee/role services.
- **Verification method:** Feature concurrency / stale_version tests (sequential HTTP loops in PHPUnit).
- **Measured result:** Capacity=1 concurrent booking scenario: success=1, failure=1, `booked_count=1` (matrix claim). Optimistic 409 proven for 3 entities.
- **Reproducibility artifact:** `RELIABILITY_CONCURRENCY_EVIDENCE_MATRIX.md` §§6–8, 18.
- **Safe report wording:** “Automated tests show no overbooking in the covered capacity=1 booking scenario, and stale version updates return HTTP 409 for appointment slots, fees, and roles.”
- **Claim limitations:** PHPUnit concurrency is **not** OS-thread parallelism. Fines and test-results lack concurrency behavioral tests (2/13 locked domains). **DO NOT CLAIM** “race-free system.”

---

## 7. Data integrity

### Claim — critical uniqueness and settlement integrity

- **Claim / Requirement:** Business identities and settlement keys cannot double-commit.
- **Design decision:** DB unique constraints + service-level obligation keys + transactions.
- **Implementation evidence:** `payments.settled_obligation_key` / `active_obligation_key`, `payment_gateway_events(provider,event_id)`, `licenses.license_number` / `verification_token`, notification/push keys, fee/slot `identity_key`.
- **Verification method:** Direct QueryException / invariant Feature tests (hardened to 12/12).
- **Measured result:** **REL-DB-INVARIANTS-TESTED = 12/12**.
- **Reproducibility artifact:** `EVIDENCE_HARDENING_RESULT.md` §8; `PaymentReconciliationAndDbInvariantEvidenceTest.php`.
- **Safe report wording:** “Twelve critical database uniqueness invariants have direct automated constraint evidence, including payment obligation keys and license identity fields.”
- **Claim limitations:** Integrity is scoped to inventoried invariants; not a proof of global referential perfection.

---

## 8. Auditability

| Metric | Measured result |
|--------|-----------------|
| AUD-CRITICAL-OPERATIONS | **36** |
| AUD-IMPLEMENTED-COVERAGE | **36/36 (100%)** |
| AUD-TESTED-COVERAGE | **36/36** after hardening |
| AUD-IMPLEMENTED-BUT-UNTESTED | **0** after hardening |

### Claim — critical mutations leave AuditLog evidence

- **Claim / Requirement:** Sensitive staff/citizen mutations are accountable.
- **Design decision:** Shared `AuditLogService` + domain history tables (`application_status_histories`, `license_status_histories`).
- **Implementation evidence:** `app/Services/AuditLogService.php`, `audit_logs` migration, dashboard/admin read APIs.
- **Verification method:** Code path inventory + Feature write asserts for all 36 critical ops.
- **Measured result:** 36/36 implemented and 36/36 tested for general AuditLog writes.
- **Reproducibility artifact:** `AUDITABILITY_EVIDENCE_MATRIX.md`, `auditability_evidence.csv`, `EVIDENCE_HARDENING_RESULT.md`.
- **Safe report wording:** “All 36 inventoried critical auditable operations write to the general AuditLog and have automated write assertions.”
- **Claim limitations:** History tables are separate mechanisms (not double-counted). API resource omits `user_agent` though stored. Audit insertion is caller-dependent regarding transaction boundaries.

---

## 9. Performance

**Official concurrent-user measurements = paced k6 workloads.**  
Unpaced saturation is documented separately as a **local OS/TCP exhaustion experiment**, not application capacity.

### Environment notes

- Target: `http://127.0.0.1:8001`
- Benchmark dataset: `BenchmarkPerformanceSeeder` / `dlms_benchmark` (`BENCHMARK_DATASET.md`)
- Scripts: `tests/performance/*.js`
- Connection reuse explicitly enabled in paced scripts

### 9.1 Infrastructure baseline — `GET /api/ping`

| Item | Measured result |
|------|-----------------|
| VUs / duration | **1 VU / 60s** |
| Requests | **10,203** |
| Throughput | **170.044 req/s** |
| Median latency | **5.72 ms** |
| p95 | **6.63 ms** |
| p99 | **7.36 ms** |
| HTTP errors | **0%** |

**Artifacts:** `docs/evidence/performance/summaries/ping-1vu-summary.json`, `ping-1vu-console.txt`, `raw/ping-1vu-raw.json`, `tests/performance/ping-baseline.js`

### 9.2 Official paced applications — `GET /api/dashboard/applications`

Script: `tests/performance/dashboard-applications-paced.js` (`sleep(1)` per iteration).  
Custom metrics: `paced_applications_*`.

| VUs | Workload requests | req/s | p95 (ms) | p99 (ms) | Failures |
|----:|------------------:|------:|---------:|---------:|---------:|
| 10 | 590 | 9.64 | 42.16 | 54.67 | 0% |
| 25 | 1450 | 23.92 | 55.97 | 80.47 | 0% |
| 50 | 2944 | 48.08 | 62.91 | 109.07 | 0% |
| 100 | 5842 | 95.36 | 65.76 | 212.17 | 0% |

**Artifacts:**  
`summaries/applications-paced-{10,25,50,100}vu-summary.json`  
`applications-paced-{10,25,50,100}vu-console.txt`

### 9.3 Paced overview (supporting)

`tests/performance/dashboard-overview-paced.js` — 10 VUs / 60s:

| Metric | Measured result |
|--------|-----------------|
| `overview_requests` | **510** (8.46 req/s) |
| `overview_duration` p95 / p99 | **216.10 ms** / **225.67 ms** |
| `overview_failed` / transport failed | **0%** |

**Artifact:** `summaries/overview-paced-10vu-summary.json`, `overview-paced-10vu-console.txt`

### 9.4 Unpaced saturation experiment (NOT capacity)

Script: `tests/performance/dashboard-applications.js` (no think-time).

| Observation | Evidence |
|-------------|----------|
| Client socket exhaustion message | `connectex: Only one usage of each socket address (protocol/network address/port) is normally permitted.` in `applications-10vu-console.txt` |
| Failure rates (unpaced 10 VU / 60s) | `applications_failed` **35.44%** (6912/19498); `http_req_failed` **35.54%** |
| Retry run (hardened script still unpaced) | `applications_failed` **21.01%** (4341/20661); transport_failed **0.00%** (`applications-10vu-retry-console.txt`) |
| Project OS correlation | DB `TIME_WAIT` ≈ **16,318** vs Windows dynamic TCP pool **16,384**; Laravel logged **PDO SQLSTATE HY000/2002** during saturation |
| Monitor artifact present | `docs/evidence/performance/db-tcp-monitor.csv` |

### Claim — paced concurrency is the official performance claim

- **Claim / Requirement:** Report concurrent-user latency/throughput without confusing OS socket exhaustion with app capacity.
- **Design decision:** Separate ping baseline, paced authenticated reads, and unpaced saturation diagnostics.
- **Implementation evidence:** k6 scripts + benchmark dataset + summaries.
- **Verification method:** k6 runs with exported JSON summaries and console transcripts.
- **Measured result:** Tables in §§9.1–9.3; unpaced failure mode in §9.4.
- **Reproducibility artifact:** `docs/evidence/performance/**`, `tests/performance/**`, `BENCHMARK_DATASET.md`.
- **Safe report wording:** “Under paced concurrent load (1s think-time), authenticated applications list handled 10–100 VUs with 0% workload failures; p95 stayed between 42.16 ms and 65.76 ms. Unpaced saturation exposed a local Windows/PHP→MariaDB TCP TIME_WAIT / ephemeral-port limit and must not be reported as production capacity.”
- **Claim limitations:** Local loopback benchmarks ≠ production multi-node scalability. Do not mix Gemini/Stripe/FCM latency into these figures. Ping is infrastructure-only.

---

## 10. Localization

| Metric | Measured result |
|--------|-----------------|
| LOC-AR-KEYS / LOC-EN-KEYS | **918** / **756** |
| LOC-SHARED-KEYS | **667** |
| LOC-KEY-PARITY | **66.24%** |
| LOC-EN-COVERAGE-OF-AR | **72.66%** |
| LOC-EMPTY-AR / EN | **0** / **0** |
| LOC-BEHAVIOR-METHODS | **130** |
| LOC-NEGOTIATION-SCENARIOS | **17** |
| LOC-PREFERENCE-SCENARIOS | **12** |
| LOC-AI-LOCALE-METHODS | **43** |
| LOC-MODULE-BI | **13/13** citizen capability groups (backend) |

### Claim — citizen API bilingual support (not dashboard UI bilingual)

- **Claim / Requirement:** Citizen-facing API messages support AR/EN with negotiation.
- **Design decision:** `ResolveRequestLocale` + `resources/lang/{ar,en}` + translators; dashboard Next.js treated as Arabic RTL UI.
- **Implementation evidence:** Middleware, lang packs, Citizen/Agent translators.
- **Verification method:** Localization matrix + whitelist Feature/Unit suites.
- **Measured result:** EN covers **72.66%** of AR leaf keys; **130** locale behavior methods; negotiation **17** scenarios.
- **Reproducibility artifact:** `LOCALIZATION_EVIDENCE_MATRIX.md`, `localization_evidence.csv`, `_localization_parity_summary.json`.
- **Safe report wording:** “Citizen API localization is implemented for Arabic and English with automated negotiation and preference tests; English leaf-key coverage of Arabic is 72.66%.”
- **Claim limitations:** **DO NOT CLAIM** “dashboard bilingual.” Key parity is incomplete (251 AR-only, 89 EN-only). Linguistic quality not judged.

---

## 11. Maintainability

### Claim — modular service organization aids maintenance

- **Claim / Requirement:** Code structure should support committee walkthroughs and change isolation.
- **Design decision:** Domain modules + service layer + shared enums/support.
- **Implementation evidence:** `app/Modules/*`, Form Requests, Resources, config registries (`dashboard_permissions.php`).
- **Verification method:** Architecture audit (qualitative).
- **Measured result:** No quantitative maintainability index in artifacts.
- **Reproducibility artifact:** `docs/FINAL_NFR_ARCHITECTURE_EVIDENCE_AUDIT.md`.
- **Safe report wording:** “The backend is organized as domain modules with service-centric business logic.”
- **Claim limitations:** Module size imbalance (Dashboard/AIAgent large). Partial repository usage. No CI maintainability gates.

---

## 12. Testability

### Claim — backend is highly automated-test covered; frontend is not

- **Claim / Requirement:** Prefer automated regression evidence for graduation claims.
- **Design decision:** PHPUnit Feature tests hit HTTP + DB; Unit tests for translators/locale detectors.
- **Implementation evidence:** `tests/Feature/*`, `tests/Unit/*`; evidence matrices derived from those suites.
- **Verification method:** Full suite console + inventory exporters.
- **Measured result:** **1058** passed / **6694** assertions / **0** failures / **258.15 s** (after hardening). Hardening added **15** new `test_*` methods (+137 assertions vs prior 6557).
- **Reproducibility artifact:** `backend_full_suite_after_hardening.txt`, `EVIDENCE_HARDENING_RESULT.md`, matrix CSVs under `docs/evidence/final-measurements/`.
- **Safe report wording:** “Backend quality attributes are backed by a recorded green PHPUnit suite of 1058 tests and quantitative evidence matrices.”
- **Claim limitations:** Next.js dashboard automated tests = **0**. No CI pipeline artifact. Some inventories still quote pre-hardening 1043/6557.

---

## 13. Deployment / portability

### Claim — Dockerized bring-up exists; availability SLA does not

- **Claim / Requirement:** Document how the system can be deployed.
- **Design decision:** Dockerfile + Compose (app + queue worker via Supervisor); health `/up`.
- **Implementation evidence:** `Dockerfile`, `docker-compose.yml`, `docker/supervisor/supervisord.conf`, `bootstrap/app.php` health.
- **Verification method:** File presence / architecture audit (no timed clean-room log in final-measurements).
- **Measured result:** **IMPLEMENTED BUT UNMEASURED** for clean-room bring-up time / multi-node HA.
- **Reproducibility artifact:** Docker files; `docs/FINAL_NFR_ARCHITECTURE_EVIDENCE_AUDIT.md` §4.x Availability/Portability.
- **Safe report wording:** “The API is packaged with Docker Compose including a queue worker; a formal availability SLA was not measured.”
- **Claim limitations:** **DO NOT CLAIM** 99.9% availability, zero-downtime rolling deploy, or CI/CD.

---

## 14. AI Agent safety and verification

### Claim — hybrid agent: LLM proposes; backend executes after confirmation

- **Claim / Requirement:** AI must not silently mutate domain state unsafely.
- **Design decision:** Gemini JSON proposals → pending workflow / confirmation → **same** domain services; ownership + stale guards; bilingual agent locale services.
- **Implementation evidence:** `app/Modules/AIAgent/*`, `config/ai.php` (Gemini), pending action / confirm routes.
- **Verification method:** `AIAgent*` Feature suites + Unit locale detectors; reliability matrix AI section; LOC-AI-LOCALE-METHODS **43**.
- **Measured result:** Strong automated coverage for confirm/reject/stale/ownership paths (suite green). No isolated Gemini latency SLA in performance artifacts (correctly excluded from ping/applications profiles).
- **Reproducibility artifact:** `tests/Feature/AIAgent*.php`, `RELIABILITY_CONCURRENCY_EVIDENCE_MATRIX.md` §13, `LOCALIZATION_EVIDENCE_MATRIX.md` AI rows, architecture audits.
- **Safe report wording:** “The AI Agent is a hybrid design: Gemini proposes structured actions; the Laravel backend executes confirmed actions through the same domain services with automated safety tests.”
- **Claim limitations:** **DO NOT CLAIM** RAG/vector DB, OpenAI provider, unsupervised autonomous mutation, or LLM answer correctness. External Gemini latency must stay in a separate bucket.

---

## 15. Known limitations

| Topic | Limitation |
|-------|------------|
| Architecture | Not microservices, not event-driven, not true AOP, repositories not universal |
| Mobile | No Flutter repository in audited evidence |
| Dashboard i18n | Do not claim bilingual dashboard UI |
| Security | Not “complete security”; critical mutate-route 401 incomplete (7/13); no pentest report |
| Rate limiting | Positive 429 proven on **4** representative routes only |
| Concurrency | Not race-free globally; PHPUnit ≠ OS-parallel load |
| Reliability | Not exactly-once / not disaster-recovery certified |
| Performance | Local paced results ≠ production capacity; unpaced run is OS/TCP diagnostic |
| Availability | No 99.9% SLA measurement |
| CI/CD | Not present in evidence |
| AI | No RAG/vector store; confirmation required for mutations |
| Localization | EN/AR key parity incomplete (66.24% union) |
| Application FSM | No central illegal-transition matrix in `transitionStatus()` |
| Frontend tests | Dashboard Next.js: 0 automated tests |

---

## FINAL REPORT CLAIM MATRIX

| ID | Area | Claim | Evidence | Metric | Confidence | Limitation |
|----|------|-------|----------|--------|------------|------------|
| C01 | Baseline | Stack/commit recorded | version + commit files | PHP 8.4.24 / Laravel 12.66.0 / `0c27c3df…` | High | Drift after commit |
| C02 | Testability | Backend suite green after hardening | `backend_full_suite_after_hardening.txt` | 1058 tests / 6694 asserts / 0 fail / 258.15s | High | FE tests = 0; older docs quote 1043 |
| C03 | Architecture | Modular monolith API | `app/Modules/*`, NFR audit | 18 modules | High | Not microservices/AOP/EDA |
| C04 | Functional | License lifecycle automated | Feature suite | Covered in C02 suite | High | No global FSM guard |
| C05 | Security | Unauth rejection tested | Security matrix | SEC-AUTHN-401 = 26 | High | Not all routes |
| C06 | Security | Authz denial tested | Security matrix | SEC-AUTHZ-403 = 95 | High | Overlaps other metrics |
| C07 | Security | IDOR negatives tested | Security matrix | 21 scenarios | Medium-High | Finite resource set |
| C08 | Security | Critical mutate 403 | Hardening result | 13/13 (100%) | High | — |
| C09 | Security | Critical mutate 401 | Hardening result | 7/13 (53.8%) | Medium | Gaps remain |
| C10 | Security | Positive rate-limit 429 | `RateLimitEvidenceTest` | 4 routes | Medium | Not all 39 throttles |
| C11 | Reliability | Idempotency methods | Reliability matrix | 36 methods | High | Scoped domains |
| C12 | Reliability | DB uniqueness invariants | Hardening result | 12/12 tested | High | Inventoried set only |
| C13 | Reliability | Payment reconcile safe | Hardening + Feature | mocked Stripe reconcile | High | Not live Stripe soak |
| C14 | Concurrency | No overbook capacity=1 | Reliability matrix | CONC-APPOINTMENT methods | Medium | Sequential PHPUnit |
| C15 | Concurrency | Optimistic 409 | Reliability matrix | 3 entities | High | Only those entities |
| C16 | Auditability | Critical AuditLog coverage | Audit matrix + hardening | 36/36 impl + tested | High | History tables separate |
| C17 | Performance | Ping baseline | ping summary/console | 10203 req / 170.044 rps / med 5.72 / p95 6.63 / p99 7.36 / 0% err | High | Infra only |
| C18 | Performance | Paced apps 10 VU | paced-10 summary | 590 req / 9.64 rps / p95 42.16 / p99 54.67 / 0% fail | High | Local loopback |
| C19 | Performance | Paced apps 25 VU | paced-25 summary | 1450 / 23.92 / 55.97 / 80.47 / 0% | High | Local loopback |
| C20 | Performance | Paced apps 50 VU | paced-50 summary | 2944 / 48.08 / 62.91 / 109.07 / 0% | High | Local loopback |
| C21 | Performance | Paced apps 100 VU | paced-100 summary | 5842 / 95.36 / 65.76 / 212.17 / 0% | High | Local loopback |
| C22 | Performance | Unpaced ≠ capacity | unpaced console + TCP notes | fail 35.44% (run1) / 21.01% (retry); TIME_WAIT≈16318 vs pool 16384; HY000/2002 | High | Diagnostic only |
| C23 | Localization | Citizen AR/EN API | Localization matrix | EN/AR coverage 72.66%; parity 66.24% | High | Not dashboard bilingual |
| C24 | AI safety | Confirm-then-execute hybrid agent | AIAgent tests + audits | Suite green; LOC-AI 43 | High | No RAG; not unsupervised |
| C25 | Deployment | Docker/Compose packaging | Docker files | Present | Medium | Bring-up unmeasured; no 99.9% |
| C26 | Clients | Next.js dashboard exists; Flutter absent | Project audit | Flutter Not Found | High | Do not claim mobile architecture |

---

### Quick “DO NOT CLAIM” checklist (copy into report appendix)

- Microservices / event-driven architecture / true AOP  
- Repository pattern everywhere  
- CI/CD  
- RAG / vector database  
- 99.9% availability  
- Race-free or exactly-once platform guarantees  
- Complete / pentest-proven security  
- Production scalability proven by local unpaced saturation  
- Dashboard bilingual UI  
- Flutter mobile architecture (no Flutter repo in evidence)  
- Unpaced k6 failure rates as application capacity  

---

*End of FINAL_REPORT_EVIDENCE.md — cite child matrices and `docs/evidence/performance/**` for deep detail; keep this file as the committee-facing index of verified claims.*
