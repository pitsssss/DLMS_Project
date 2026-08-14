# Evidence Hardening Sprint — Results

**System:** SYRTAK / DLMS Backend  
**Date:** 2026-08-15  
**Type:** Focused automated-evidence hardening (no product features)

---

## 1. Files changed

### New Feature tests
- `tests/Feature/RateLimitEvidenceTest.php`
- `tests/Feature/CriticalMutationAuthorizationTest.php`
- `tests/Feature/PaymentReconciliationAndDbInvariantEvidenceTest.php`

### Extended existing Feature tests (assertions / small test fixes)
- `tests/Feature/LicenseFlowTest.php` — audit asserts (issue / fine create+update / block+unblock)
- `tests/Feature/DashboardIssuedLicensesTest.php` — audit asserts (block / unblock)
- `tests/Feature/AppointmentFlowTest.php` — audit assert (`test_result.recorded`); BusinessClock slot filter
- `tests/Feature/EmployeeManagementTest.php` — audit asserts (employee create / update)
- `tests/Feature/DashboardRoleManagementTest.php` — audit asserts (create / update / archive / restore)
- `tests/Feature/DashboardEmployeeAccessTest.php` — audit assert (direct permissions)
- `tests/Feature/LicensePrintingTest.php` — brittle URL-id substring assert replaced with path-structure assert

### Seeder alignment (test data vs business clock)
- `database/seeders/AppointmentSlotsSeeder.php` — seed dates from `BusinessClock` instead of UTC `Carbon::today()`

### Evidence artifacts
- `docs/evidence/final-measurements/backend_full_suite_after_hardening.txt`
- `docs/evidence/final-measurements/EVIDENCE_HARDENING_RESULT.md` (this file)

---

## 2. Tests added

| Suite | New `test_*` methods |
|-------|---------------------:|
| `RateLimitEvidenceTest` | **4** |
| `CriticalMutationAuthorizationTest` | **5** |
| `PaymentReconciliationAndDbInvariantEvidenceTest` | **6** |
| **Total new methods** | **15** |

Baseline suite size was **1043**; final suite size is **1058** (= 1043 + 15).

---

## 3. Assertions added

| Suite | Assertions |
|-------|------------|
| Baseline | **6557** |
| After hardening | **6694** |
| **Delta** | **+137** |

---

## 4. Bugs discovered (and fixes)

| Bug | Severity | Fix |
|-----|----------|-----|
| `AppointmentSlotsSeeder` used UTC `Carbon::today()` while booking/availability validate against `BusinessClock` (`Asia/Damascus`). Near UTC midnight this made seeded “today” slots business-yesterday → booking 404/422 across many Feature tests. | High (test reliability / evidence integrity) | Seeder now starts from BusinessClock day |
| `AppointmentFlowTest::visionSlot()` filtered by UTC `now()->toDateString()` | Med (same class) | Filter uses BusinessClock |
| `LicensePrintingTest` asserted verification URL must not contain `(string) $license->id`; random tokens can coincidentally include those digits | Low (brittle assert) | Assert public path equals `/licenses/verify/{token}` |

No production business-rule changes were required for product behavior.

---

## 5. Application-code changes and why

| Change | Why |
|--------|-----|
| `AppointmentSlotsSeeder` → BusinessClock dates | Align seeded appointment calendar with the same clock used by appointment services (fixes deterministic UTC/Damascus day-boundary failures). Not a product feature. |

No throttling, auth, payment, or audit implementation changes.

---

## 6. Focused test results (by phase)

| Phase | Filter / suite | Result |
|-------|----------------|--------|
| A | `RateLimitEvidenceTest` | **4 passed** (84 assertions) |
| B | `CriticalMutationAuthorizationTest` | **5 passed** (23 assertions) |
| C | LicenseFlow / IssuedLicenses / AppointmentFlow record / EmployeeManagement / RoleManagement / EmployeeAccess (targeted) | **passed** after BusinessClock slot fix |
| D+E | `PaymentReconciliationAndDbInvariantEvidenceTest` | **6 passed** (13 assertions) |

---

## 7. Final full suite

Source: `docs/evidence/final-measurements/backend_full_suite_after_hardening.txt`

| Item | Baseline | After hardening |
|------|----------|-----------------|
| Tests | 1043 passed | **1058 passed** |
| Assertions | 6557 | **6694** |
| Duration | 217.86s | **258.15s** |
| Failures | 0 | **0** |

---

## 8. Recomputed metrics

Counting rules match prior matrices (same-route mutate negatives; distinct methods; no invented totals).

### Security

| Metric | Before | After | Evidence |
|--------|--------|-------|----------|
| **SEC-RATE-LIMIT-429** | **0** | **4** | `RateLimitEvidenceTest` (forgot-password `5/1`, license verify `30/1`, payment create `15/1`, AI message `30/1`); ThrottleRequests **not** disabled |
| **SEC-CRITICAL-401-COVERAGE** | **2/13 (15.4%)** | **7/13 (53.8%)** | Added same-route 401 for document approve/reject, license block/unblock, citizen activate/deactivate, role mutations, fine create/update (`CriticalMutationAuthorizationTest`) |
| **SEC-CRITICAL-403-COVERAGE** | **7/13 (53.8%)** | **13/13 (100%)** | Includes mutate-route 403 for `POST /api/admin/test-appointments/{id}/record-result` (`CriticalMutationAuthorizationTest::test_record_test_result_requires_permission`) |

Critical ops still lacking mutate-route **401**: profile approve/reject, payment verify/confirm, appointment-slot mutations, issue license, employee create/update, session revoke (unchanged gaps).

### Audit

| Metric | Before | After | Notes |
|--------|--------|-------|-------|
| **AUD-TESTED-COVERAGE** | **20/36** | **36/36** | Plus final micro-hardening asserts: `appointment_slot.created`, `fee.activated`, `fee.deactivated` |
| **AUD-IMPLEMENTED-BUT-UNTESTED** | **16** | **0** | All 36 critical AuditLog operations now have explicit write asserts |

### Reliability / DB / recovery

| Metric | Before | After | Notes |
|--------|--------|-------|-------|
| **REL-DB-INVARIANTS-TESTED** | **7/12** | **12/12** | Added QueryException proofs for `active_obligation_key`, `payment_gateway_events(provider,event_id)`, `licenses.license_number`, `licenses.verification_token`, `push_devices(user_id,device_id)` |
| Payment reconciliation verification | **IMPLEMENTED BUT UNMEASURED** | **VERIFIED** | `PaymentReconciliationService::reconcile` completes pending Stripe payment (mocked gateway); second reconcile → `already_completed`; no duplicate completed rows; artisan command invoked post-completion safely |

---

## 9. Committee-safe claim updates

| Claim | Status |
|-------|--------|
| Positive HTTP 429 proven on four representative throttled routes | **VERIFIED** |
| Critical mutate-route 403 coverage 13/13 | **VERIFIED** |
| Critical mutate-route 401 coverage 7/13 | **PARTIALLY VERIFIED** (improved; still incomplete) |
| Payment reconciliation recovers paid Stripe pending → completed without double settlement (mocked) | **VERIFIED** |
| All 12 inventoried critical DB uniqueness invariants have direct automated constraint evidence | **VERIFIED** |
| Platform-wide rate-limit / race-free / fault-tolerant | **DO NOT CLAIM** |

---

## 10. Remaining smallest high-value gaps

1. Mutate-route **401** for remaining critical ops (issue license, payment verify, profile review, employee create, session revoke, slot mutations)
