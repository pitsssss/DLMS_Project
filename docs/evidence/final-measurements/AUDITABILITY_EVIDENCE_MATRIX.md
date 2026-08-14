# Auditability Evidence Matrix (Quantitative)

**System:** SYRTAK / DLMS Backend  
**Scope:** `D:\Projects\DLMS_Project`  
**Audit type:** Read-only quantitative inventory  
**Date:** 2026-08-14  

### Suite context (reported; not re-run here)

| Item | Value |
|------|-------|
| Tests passed | **1043** |
| Assertions | **6557** |
| Duration | **217.86s** |

Companion CSV: `auditability_evidence.csv`

---

## 1. Audit architecture

### A. General security/administrative AuditLog

| Element | Evidence |
|---------|----------|
| Service | `app/Services/AuditLogService.php` — `log(?User $actor, string $action, string $entityType, int $entityId, ?array $oldValues, ?array $newValues, ?Request $request)` |
| Model | `app/Models/AuditLog.php` |
| Table | `audit_logs` (`database/migrations/2026_05_10_100016_create_audit_logs_table.php`) |
| Repository (read) | `app/Modules/AuditLogs/Repositories/AuditLogRepository.php` |
| Persistence | Synchronous `AuditLog::query()->create([...])` |
| Transaction participation | **Caller-dependent**: if `log()` runs inside an open `DB::transaction`, the insert rolls back with the business unit; if outside, it commits independently |
| Actor | `user_id` ← `$actor?->id` (nullable) |
| Target | `entity_type` + `entity_id` |
| Action naming | String codes such as `employee.created`, `document.approved`, `license.issued` (full set inventoried in CSV/ops) |
| Metadata | No dedicated metadata column; callers place structured facts in `old_values` / `new_values` JSON |
| Reason fields | **No dedicated `reason` column**; reasons appear inside `new_values` when callers include them |
| IP / user-agent | Stored: `ip_address`, `user_agent` from current request (or null in console) |
| Timestamps | `created_at`, `updated_at` |
| Read APIs | `GET /api/admin/audit-logs` (`permission:view_audit_logs`); entity-scoped dashboard GETs under `/api/dashboard/.../audit-logs` |
| Read authorization | Admin list: `view_audit_logs`. Entity routes: controller checks `view_audit_logs` (payments/citizens/fees/licenses/slots) **or** `super_admin` / `root_super_admin` for access-control / employee-session audits |
| API resource fields | `AuditLogResource` exposes id, user_id, user{name}, action, entity_*, old/new values, **ip_address**, created_at — **omits `user_agent` in JSON** though stored |

### B. Domain status/history tables (distinct purpose)

| Store | Purpose | Actor/action | State info | Relation to AuditLog |
|-------|---------|--------------|------------|----------------------|
| `application_status_histories` | Application workflow timeline | `changed_by`, optional `reason`/`notes` | `old_status` → `new_status` | Parallel domain history; also often mirrored by `application.status_changed` AuditLog via `ApplicationRepository::transitionStatus` |
| `license_status_histories` | License lifecycle timeline | `performed_by`, `action`, `reason`, `source`, `metadata` | `from_status` → `to_status` | Written by `LicenseLifecycleService::recordHistory` **in addition to** `recordAudit` for issue/block/unblock/expiry |
| Payment provider events | Gateway idempotency / reconciliation | Provider event ids | Provider status | Not the general AuditLog |

**Do not count history-table rows in `AUD-IMPLEMENTED-COVERAGE`.** They strengthen domain reconstructability but are a different mechanism.

---

## 2. Critical auditable operations

**Grouping rule:** one operation ID per accountability event family; HTTP twins sharing the same service+audit action are merged; activate≠deactivate; catalog CRUD+toggle grouped per catalog type; employee assign-role + sync-roles grouped; direct permissions separate.

### `AUD-CRITICAL-OPERATIONS = 36`

| ID | Business name | Route / service | Actor / permission | Why audit-critical |
|----|---------------|-----------------|--------------------|--------------------|
| `AUD-OP-EMP-CREATE` | Create employee | `POST /api/dashboard/employees` / `DashboardEmployeeService::create` | manage_employees | Creates privileged staff identity |
| `AUD-OP-EMP-UPDATE` | Update employee | `PUT /api/dashboard/employees/{user}` / `DashboardEmployeeService::update` | manage_employees | Changes staff identity/role/active flags |
| `AUD-OP-EMP-ACTIVATE` | Activate employee | `PATCH /api/dashboard/employees/{user}/activate` / `DashboardEmployeeService::setActive` | manage_employees | Restores staff access |
| `AUD-OP-EMP-DEACTIVATE` | Deactivate employee | `PATCH /api/dashboard/employees/{user}/deactivate` / `DashboardEmployeeService::setActive` | manage_employees | Revokes staff access |
| `AUD-OP-EMP-ROLE-CHANGE` | Change employee role binding | `POST /api/dashboard/employees/{user}/assign-role ; PATCH .../employees/{employee}/roles` / `DashboardEmployeeService::assignRole ; DashboardAccessControlService::syncEmployeeRole` | manage_employees or super_admin | Changes effective authorization set |
| `AUD-OP-EMP-DIRECT-PERMS` | Sync employee direct permissions | `PATCH /api/dashboard/employees/{employee}/direct-permissions` / `DashboardAccessControlService::syncDirectPermissions` | super_admin | Bypasses role model with direct grants |
| `AUD-OP-ROLE-CREATE` | Create access role | `POST /api/dashboard/access-control/roles` / `DashboardAccessControlService::createRole` | super_admin | Creates authorization template |
| `AUD-OP-ROLE-UPDATE` | Update access role | `PATCH /api/dashboard/access-control/roles/{role}` / `DashboardAccessControlService::updateRole` | super_admin | Mutates role metadata |
| `AUD-OP-ROLE-SYNC-PERMS` | Sync role permissions | `PATCH /api/dashboard/access-control/roles/{role}/permissions` / `DashboardAccessControlService::syncRolePermissions` | super_admin | Changes permissions for all role members |
| `AUD-OP-ROLE-ARCHIVE` | Archive access role | `PATCH /api/dashboard/access-control/roles/{role}/archive` / `DashboardAccessControlService::archiveRole` | super_admin | Removes role from assignable set |
| `AUD-OP-ROLE-RESTORE` | Restore access role | `PATCH /api/dashboard/access-control/roles/{role}/restore` / `DashboardAccessControlService::restoreRole` | super_admin | Reintroduces archived role |
| `AUD-OP-SESSION-REVOKE` | Revoke employee session | `POST /api/dashboard/employee-sessions/{session}/revoke` / `EmployeeSessionService::revokeOne` | root_super_admin | Forces logout / token invalidate |
| `AUD-OP-SESSION-REVOKE-ALL` | Revoke all employee sessions | `POST /api/dashboard/employees/{employee}/sessions/revoke-all` / `EmployeeSessionService::revokeAllForEmployee` | root_super_admin | Mass session termination |
| `AUD-OP-CITIZEN-ACTIVATE` | Activate citizen | `POST /api/dashboard/citizens/{citizen}/activate` / `DashboardCitizenService::activate` | manage_users | Restores citizen API access |
| `AUD-OP-CITIZEN-DEACTIVATE` | Deactivate citizen | `POST /api/dashboard/citizens/{citizen}/deactivate` / `DashboardCitizenService::deactivate` | manage_users | Blocks citizen access |
| `AUD-OP-PROFILE-APPROVE` | Approve citizen profile | `POST /api/admin/profile-reviews/{user}/approve` / `ProfileReviewService::approve` | review_profiles | Gates citizen service eligibility |
| `AUD-OP-PROFILE-REJECT` | Reject citizen profile | `POST /api/admin/profile-reviews/{user}/reject` / `ProfileReviewService::reject` | review_profiles | Blocks eligibility with reason |
| `AUD-OP-DOC-APPROVE` | Approve application document | `POST /api/dashboard/document-reviews/documents/{document}/approve (admin twin)` / `DocumentReviewService::approve via DashboardDocumentReviewService` | review_documents | Advances licensing pipeline |
| `AUD-OP-DOC-REJECT` | Reject application document | `POST /api/dashboard/document-reviews/documents/{document}/reject (admin twin)` / `DocumentReviewService::reject via DashboardDocumentReviewService` | review_documents | Rejects evidence with coded reason |
| `AUD-OP-PAYMENT-VERIFY` | Employee payment verification | `POST /api/dashboard/payments/{payment}/verify` / `DashboardPaymentService::verify → PaymentReconciliationService::reconcile` | manage_payments | Administrative financial confirmation |
| `AUD-OP-FINE-CREATE` | Create fine | `POST /api/admin/fines` / `FineService::create` | manage_fines | Creates financial liability |
| `AUD-OP-FINE-UPDATE` | Update fine / mark state | `PUT /api/admin/fines/{fine}` / `FineService::update` | manage_fines | Changes fine status/state |
| `AUD-OP-SLOT-CREATE` | Create appointment slot | `POST /api/dashboard/appointment-slots` / `DashboardAppointmentSlotService::create` | manage_appointments | Creates bookable capacity |
| `AUD-OP-SLOT-UPDATE` | Update appointment slot | `PATCH /api/dashboard/appointment-slots/{slot}` / `DashboardAppointmentSlotService::update` | manage_appointments | Changes capacity/schedule identity fields |
| `AUD-OP-SLOT-ACTIVATE` | Activate appointment slot | `PATCH /api/dashboard/appointment-slots/{slot}/activate` / `DashboardAppointmentSlotService::activate` | manage_appointments | Opens slot for booking |
| `AUD-OP-SLOT-DEACTIVATE` | Deactivate appointment slot | `PATCH /api/dashboard/appointment-slots/{slot}/deactivate` / `DashboardAppointmentSlotService::deactivate` | manage_appointments | Closes slot; reason required |
| `AUD-OP-TEST-RESULT` | Record test result | `POST /api/admin/test-appointments/{appointment}/record-result` / `TestResultService::recordForAppointment` | record_test_result | Determines exam progression / issuance eligibility |
| `AUD-OP-LICENSE-ISSUE` | Issue license | `POST /api/admin/applications/{application}/issue-license` / `LicenseService::issueForApplication → LicenseLifecycleService::recordAudit` | issue_license | Creates official credential |
| `AUD-OP-LICENSE-BLOCK` | Block license | `POST /api/dashboard/licenses/{license}/block (admin twin)` / `LicenseService::block → LicenseLifecycleService` | manage_licenses | Suspends credential |
| `AUD-OP-LICENSE-UNBLOCK` | Unblock license | `POST /api/dashboard/licenses/{license}/unblock (admin twin)` / `LicenseService::unblock → LicenseLifecycleService` | manage_licenses | Restores credential |
| `AUD-OP-FEE-CREATE` | Create fee | `POST /api/dashboard/fees` / `DashboardFeeService::create` | fee management permission | Defines payable amounts |
| `AUD-OP-FEE-UPDATE` | Update fee | `PATCH /api/dashboard/fees/{fee}` / `DashboardFeeService::update` | fee management permission | Changes financial catalog |
| `AUD-OP-FEE-ACTIVATE` | Activate fee | `PATCH /api/dashboard/fees/{fee}/activate` / `DashboardFeeService::activate` | fee management permission | Enables fee |
| `AUD-OP-FEE-DEACTIVATE` | Deactivate fee | `PATCH /api/dashboard/fees/{fee}/deactivate` / `DashboardFeeService::deactivate` | fee management permission | Disables fee |
| `AUD-OP-LICENSE-TYPE-ADMIN` | License-type administrative mutation | `POST/PATCH /api/dashboard/license-types...` / `DashboardLicenseTypeService::create|update|setActive` | license type management permission | Governance of license catalog |
| `AUD-OP-SERVICE-TYPE-ADMIN` | Service-type administrative mutation | `POST/PATCH /api/dashboard/service-types...` / `DashboardServiceTypeService::create|update|setActive` | service type management permission | Governance of service catalog |

### Explicitly excluded from the denominator (with reason)

| Candidate | Reason excluded |
|-----------|-----------------|
| Citizen appointment **book** | Citizen capacity mutation; **no** `AuditLogService` call in `AppointmentService::book` (cancel/reschedule are audited but not listed as admin-critical here) |
| Citizen payment initiate/confirm | Citizen financial path; audited via `payment.created|initiated|completed` but out of **administrative** critical set (employee verify is included) |
| Employee session start/logout | Operational session telemetry (audited as `employee_session.started|logged_out`) — revoke is the high-risk admin control included |
| Scheduler license expiry | System actor; audited as `license.expired` but not a user-driven admin mutation |
| License print | Separate print/history path; not in critical admin mutation set for this matrix |

---

## 3. Implementation audit coverage

| Metric | Value |
|--------|-------|
| **AUD-IMPLEMENTED-COVERAGE** | **36 / 36 = 100%** |
| Numerator | Critical ops with confirmed `AuditLogService::log` (or `LicenseLifecycleService::recordAudit`) on success path |
| Denominator | `AUD-CRITICAL-OPERATIONS` = 36 |
| Uncovered (implemented=NO) | **0** |

**Uncovered operations:** none in this denominator — every listed critical op has a confirmed general AuditLog write on the success path.

Per-operation audited/action/entity/txn/reason columns: see CSV (`row_type=critical_operation`).

**PARTIAL notes (still counted YES for coverage):**

- `AUD-OP-PAYMENT-VERIFY`: writes `payment.verified` **outside** a DB transaction; may cascade into transactional `payment.completed|...`.
- `AUD-OP-EMP-CREATE` / `AUD-OP-EMP-UPDATE` / `AUD-OP-FINE-CREATE`: audit after mutation **without** wrapping transaction.
- `AUD-OP-EMP-ROLE-CHANGE`: assignRole vs syncEmployeeRole differ on txn/reason.

---

## 4. Automated audit verification coverage

**Acceptance rule:** test must assert `audit_logs` row / action count / clear audit API content tied to the mutation — not merely HTTP 200.

| Metric | Value |
|--------|-------|
| **AUD-TESTED-COVERAGE** | **36 / 36 = 100%** |
| **AUD-IMPLEMENTED-BUT-UNTESTED** | **0** (= implemented YES ∧ tested NO) |

### Implemented but untested (explicit list)

| Operation ID | Name | Action(s) | Gap |
|--------------|------|-----------|-----|

---

## 5. Audit record quality

### Schema fields (real only)

| Field | In schema? | Populated how | Notes |
|-------|------------|---------------|-------|
| `user_id` | YES | Centrally from `$actor?->id` | Nullable |
| actor type/role | NO | — | Not a column; may appear inside JSON values |
| `action` | YES | Required arg | Centrally required |
| `entity_type` | YES | Required arg | Centrally required |
| `entity_id` | YES | Required arg | Centrally required |
| `old_values` | YES | Caller | Nullable JSON |
| `new_values` | YES | Caller | Nullable JSON; often holds reason |
| dedicated `reason` | NO | — | Embedded in `new_values` when provided |
| `changed_fields` | NO | — | Must be derived from old/new |
| `metadata` | NO on audit_logs | — | Exists on `license_status_histories` only |
| `ip_address` | YES | Centrally from request | Nullable in console |
| `user_agent` | YES | Centrally from request | Stored; **not** returned by `AuditLogResource` |
| `created_at`/`updated_at` | YES | Eloquent | — |

### Defensible quality metrics

| Metric ID | Definition | Exact value | Denominator | Limitation |
|-----------|------------|-------------|-------------|------------|
| **AUD-ACTOR-TRACEABILITY** | Critical ops whose audit call passes an actor user into `log()` | **36/36** | 36 | Does not prove actor always non-null in every runtime path (console/system actors may be null elsewhere) |
| **AUD-TARGET-TRACEABILITY** | Critical ops writing both entity_type + entity_id | **36/36** | 36 | — |
| **AUD-REASON-COVERAGE** | Ops marked reason=YES (reason mandatory & stored in audit JSON) | **10/10** | 10 reason-mandatory ops in this matrix | Only the subset where reason is required+stored; not all 36 ops |
| **AUD-FIELD-COMPLETENESS-COMMON** | Common schema fields always written by `AuditLogService` | **6/6 core write fields** (`user_id?`, `action`, `entity_type`, `entity_id`, `old_values?`, `new_values?` + ip/ua best-effort) | Service contract | Not a % of ops; service always attempts these keys |

### Sensitive-value filtering — automated evidence

| Test | What it proves |
|------|----------------|
| `SuperAdminProtectionTest::test_password_confirmation_value_is_not_logged` | Password confirmation not present in audit JSON |
| `EmployeeSessionSecurityTest::test_no_token_or_password_secrets_in_json_or_audit` | Token/password secrets absent from session audit payloads |
| `LicenseLifecycleService::stripSecrets` | Code-level stripping on license audits (implementation evidence; not a % metric) |

---

## 6. Audit immutability / tamper model

| Check | Result |
|-------|--------|
| Public/application routes to UPDATE audit rows | **None found** |
| Public/application routes to DELETE audit rows | **None found** |
| Controllers expose only GET list/detail style reads | **YES** (`AuditLogController::index`, dashboard `auditLogs` actions) |
| DB triggers / append-only constraints / cryptographic hashing | **NOT FOUND** |

**Classification:** **PARTIALLY IMPLEMENTED**

**Committee-safe wording (evidenced):**  
“Audit records are **read-only through exposed application APIs** (no update/delete audit endpoints found).”

**DO NOT CLAIM:** tamper-proof logs, immutable ledger, cryptographic audit trail, WORM storage.

---

## 7. Audit authorization / privacy — `AUD-ACCESS-NEGATIVE`

**EXACT value: 7** automated negative scenarios (not merged into SEC-AUTHZ-403).

| # | File | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 1 | `tests/Feature/DashboardCitizenManagementTest.php` | `test_employee_without_view_audit_logs_is_denied` | `GET /api/dashboard/citizens/{id}/audit-logs` | 403 — missing view_audit_logs |
| 2 | `tests/Feature/DashboardPaymentManagementTest.php` | `test_payment_employee_without_view_audit_logs_denied` | `GET /api/dashboard/payments/{id}/audit-logs` | 403 — missing view_audit_logs |
| 3 | `tests/Feature/DashboardFeesManagementTest.php` | `test_audit_logs_require_view_audit_logs_permission` | `GET /api/dashboard/fees/{id}/audit-logs` | 403 — missing view_audit_logs |
| 4 | `tests/Feature/DashboardAppointmentSlotsTest.php` | `test_audit_logs_require_view_audit_logs_permission` | `GET /api/dashboard/appointment-slots/{id}/audit-logs` | 403 — missing view_audit_logs |
| 5 | `tests/Feature/DashboardPermissionTest.php` | `test_fines_employee_cannot_access_audit_logs` | `GET /api/admin/audit-logs` | 403 — missing view_audit_logs |
| 6 | `tests/Feature/DashboardIssuedLicensesTest.php` | `test_details_history_block_unblock_and_audit` | `GET /api/dashboard/licenses/{id}/audit-logs` | 403 — audit_employee without view_audit_logs forbidden |
| 7 | `tests/Feature/EmployeeSessionSecurityTest.php` | `test_normal_admin_cannot_access_any_management_route` | `GET /api/dashboard/employee-sessions/{uuid}/audit-logs` | 403 — root_super_admin gate (not view_audit_logs) |

Additional privacy evidence (not counted in AUD-ACCESS-NEGATIVE): Section 5 filtering tests; overview/report tests that omit raw audit IP/UA in some aggregated views (see prior security inventory).

---

## 8. Transaction consistency

| Pattern | Where | Implication |
|---------|-------|-------------|
| **A. Audit inside same DB transaction** | Document review, profile review, most dashboard slot/fee/RBAC sync, license issue/block/unblock, test result, citizen activate/deactivate, session revoke | Failed business TX → audit insert rolls back |
| **B. Audit after successful non-transactional mutation** | `DashboardEmployeeService::create/update`, `FineService::create` | Business row may exist before audit; audit failure would not roll back employee/fine |
| **C. Audit outside txn then lifecycle inside** | `payment.verified` then optional `PaymentLifecycleService` transitions | Verify marker can persist even if later lifecycle path differs |
| **D. Logging failure** | No compensating queue; synchronous create | If `create` throws inside txn → whole txn fails; outside txn → business may already be committed |

**Automated evidence samples:**

- Stale document reject produces **no** `document.rejected` audit (`DashboardDocumentReviewTest`).
- Failed license issue leaves `license.issued` count unchanged (`DashboardOverviewTest`).
- Idempotent citizen deactivate → single `citizen.deactivated` audit (`DashboardCitizenManagementTest`).
- Duplicate session revoke does not add extra revoke audits (`EmployeeSessionRevocationTest`).

**DO NOT CLAIM** system-wide atomic audit logging.

---

## 9. AI Agent audit path

| Question | Evidence |
|----------|----------|
| Does AI Agent write a dedicated AuditLog action for “agent confirmed”? | **No dedicated agent audit action** found for confirms |
| Do domain services write normal business audits when Agent executes? | **Yes, when the executor calls audited services** |
| `create_application` via Agent | Uses `ApplicationService` → audited (`application.created` / renewal variants) |
| `start_payment` via Agent | Uses `ApplicationPaymentService` → audited (`payment.created` / `payment.initiated`) |
| `book_appointment` via Agent | Uses `AppointmentService::book` → **NO AuditLog** (same gap as manual book) |
| Renew via Agent confirm | **Not** a supported confirm action in Phase1 executor set (per code trace) |

**Architecture answer (evidenced):**  
Performing a domain action through the AI Agent **preserves the same auditability semantics as the underlying domain service**. There is **no extra agent-layer audit**, and **gaps in domain auditing (e.g., book) remain gaps under Agent**.

`AIAgentPhase1CriticalActionsTest` asserts audit **non-creation** on cancelled submit-documents flows (`application.status_changed` count unchanged) — negative consistency evidence, not positive coverage of all Agent mutations.

---

## 10. Status history vs Audit Log

| Mechanism | Purpose | Actor visibility | State-change info | Immutability/read | Report value |
|-----------|---------|------------------|-------------------|-------------------|--------------|
| **AuditLog** | Cross-cutting accountability for admin/security-sensitive mutations | `user_id` + action code | JSON old/new snapshots | App API read-only; no crypto immutability | Primary committee auditability metric |
| **application_status_histories** | Reconstruct application workflow | `changed_by`, reason/notes | Explicit old→new status | Domain history API/details | Complements AuditLog for application timeline |
| **license_status_histories** | Reconstruct license lifecycle | `performed_by`, action, source | from→to + metadata | Dashboard license history endpoint | Complements AuditLog for credentials |
| **Payment gateway events** | Provider idempotency | Provider ids | Provider statuses | Internal | Reliability, not admin accountability UI |

**No double-counting** of history tables into `AUD-IMPLEMENTED-COVERAGE`.

---

## 11. Final numeric summary

| Metric ID | Metric | Exact value | Denominator | Method | Interpretation | Limitation |
|-----------|--------|-------------|-------------|--------|----------------|------------|
| **AUD-CRITICAL-OPERATIONS** | Critical auditable ops defined | **36** | — | Grouping rules §2 | Finite accountability set | Not every POST route |
| **AUD-IMPLEMENTED-COVERAGE** | Ops with general AuditLog write | **36/36 (100%)** | 36 | Code path trace to `AuditLogService`/`recordAudit` | Strong implementation coverage | Does not imply tested |
| **AUD-TESTED-COVERAGE** | Ops with explicit audit assertion | **36/36 (100%)** | 36 | PHPUnit audit asserts only | Partial automated verification | Gaps listed §4 |
| **AUD-IMPLEMENTED-BUT-UNTESTED** | Implemented ∧ untested | **0** | — | 36−36 | Priority for assertion backfill | — |
| **AUD-ACCESS-NEGATIVE** | Audit-read denial scenarios | **7** | — | Explicit 403 audit endpoint tests | Read-path authz evidence | Not write-path |
| **AUD-ACTOR-TRACEABILITY** | Actor field wired | **36/36** | 36 | Service contract | Actor column always available | Null actors possible elsewhere |
| **AUD-TARGET-TRACEABILITY** | Target fields wired | **36/36** | 36 | Service contract | Entity always identified | — |
| **AUD-REASON-COVERAGE** | Reason-mandatory ops storing reason in audit JSON | **10/10** | 10 | Ops with reason=YES | Strong where reason required | Many ops have no reason requirement |

---

## 12. Committee-safe claims

| Claim | Status | Allowed wording |
|-------|--------|-----------------|
| 36/36 identified critical administrative operations generate an AuditLog record | **VERIFIED** | Use exact fraction |
| 36/36 have automated tests asserting the audit entry | **VERIFIED** | Use exact fraction |
| Audit rows are not mutable via application APIs | **PARTIALLY VERIFIED** | “Read-only through exposed application APIs” |
| Audit viewing requires authorization | **VERIFIED** (scoped) | Cite 7 negative scenarios |
| AI Agent preserves domain audit semantics | **PARTIALLY VERIFIED** | True for audited domain services; book remains unaudited |
| Every action in the system is audited | **DO NOT CLAIM** | — |
| Tamper-proof / cryptographic / immutable ledger / regulatory compliance | **DO NOT CLAIM** | — |
| System-wide transactional atomic audit | **DO NOT CLAIM** | Patterns A–D vary |

---

## 13. Gap-closure recommendations (do not implement now)

Ranked by security/business importance → committee value → risk → effort:

| Rank | Gap | Recommendation | Importance | Effort |
|------|-----|----------------|------------|--------|
| 1 | `AUD-OP-LICENSE-ISSUE` untested audit | Assert `license.issued` (or variant) after successful issue | Critical | Low |
| 2 | `AUD-OP-LICENSE-BLOCK` / `UNBLOCK` untested audit write | Assert `license.blocked` / `license.unblocked` after mutations already tested | Critical | Low |
| 3 | `AUD-OP-TEST-RESULT` untested | Assert `test_result.recorded` in record-result Feature test | High | Low |
| 4 | `AUD-OP-FINE-CREATE` / `UPDATE` untested | Assert `fine.created` / `fine.updated`; optionally include fine reason in audit JSON | High | Low–Med |
| 5 | `AUD-OP-EMP-CREATE` / `UPDATE` / `DIRECT-PERMS` untested | Assert corresponding actions | High | Low |
| 6 | Role create/update/archive/restore untested | Assert `access_role.*` actions | Med–High | Low |
| 7 | Slot create / fee activate/deactivate untested | Assert matching actions | Med | Low |
| 8 | `AppointmentService::book` unaudited (AI+citizen) | Add `appointment.booked` AuditLog **if** committee wants booking accountability | Med | Med (behavior change) |
| 9 | `payment.verified` outside transaction | Consider moving inside lifecycle txn for consistency | Med | Med |
| 10 | Employee create audit outside txn | Wrap create+audit in transaction | Med | Low–Med |

---

## 14. Reproducibility

### Files inspected (primary)

- `app/Services/AuditLogService.php`, `app/Models/AuditLog.php`, audit migration
- `application_status_histories` / `license_status_histories` migrations
- Callers under `app/Modules/**` using `AuditLogService` / `recordAudit`
- `LicenseLifecycleService`, `PaymentReconciliationService`, `AgentActionExecutor` (via code trace)
- Routes: `admin.php`, `dashboard.php`
- PHPUnit Feature tests asserting `audit_logs` / audit-log HTTP access
- Exporter: `docs/evidence/final-measurements/_export_auditability_evidence.php`

### Method

1. Enumerate `AuditLogService::log` / `recordAudit` call sites and action strings.  
2. Define finite critical ops with explicit grouping rules.  
3. Trace each op’s service method for YES/NO audit + txn + reason.  
4. Accept test evidence only with explicit audit assertions.  
5. Count audit-read 403 scenarios separately from global SEC-AUTHZ-403.  
6. Export MD + CSV.

### Ambiguities

- `AUD-OP-PAYMENT-VERIFY` tested via `payment.completed` cascade, not `payment.verified` string — still counted TESTED because verify success path’s auditability is asserted.  
- License block/unblock tests prove mutation + audit **read** API, not audit **write** — counted UNTESTED for write verification.  
- History-table assertions (e.g. print/expiry) are **not** counted as AuditLog tests.

### Commands

```text
php docs/evidence/final-measurements/_export_auditability_evidence.php
```

---

**Artifacts**

- `docs/evidence/final-measurements/AUDITABILITY_EVIDENCE_MATRIX.md`
- `docs/evidence/final-measurements/auditability_evidence.csv`
