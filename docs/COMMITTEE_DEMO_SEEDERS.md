# Committee Demo Seeders

Deterministic **local/testing** data for the SYRTAK committee demo. Seeders refuse to run in production. They do not call `migrate:fresh` and do not delete unrelated project data.

Re-running is safe: committee users and `DEMO-COMMITTEE-*` applications are reset/recreated; other citizens/applications/licenses are left alone.

## Seeder files

| File | Role |
|---|---|
| `database/seeders/Support/CommitteeDemoKit.php` | Shared catalog lookup, employees, reset, documents, payments, appointments |
| `database/seeders/CommitteeTestResultSeeder.php` | Scenarios A, B, C |
| `database/seeders/CommitteeLicenseIssuanceSeeder.php` | Scenario D |
| `database/seeders/CommitteeDemoSeeder.php` | Calls the two above |
| `tests/Feature/CommitteeDemoSeederTest.php` | HTTP verification through real record-result and issue-license |
| `docs/COMMITTEE_DEMO_SEEDERS.md` | This file |

Catalog rows are resolved by **code** (`private`, `new_license`, `vision` / `theory` / `practical`). No hardcoded IDs.

## Demo credentials

Local/testing password for both employees (not stored in application config):

```text
CommitteeDemo!2026
```

| Role | Email | RBAC role | What they can do |
|---|---|---|---|
| Examiner | `committee.examiner@syrtak.local` | `test_employee` | Dashboard, test-result queue, `record_test_result` |
| License issuer | `committee.issuer@syrtak.local` | `license_employee` | Dashboard, `issue_license`, view applications, view issued licenses |

## Commands

Seeders **only** run when `APP_ENV` is `local` or `testing`. If `.env` is `production`, they throw and do nothing.

```bash
php artisan db:seed --class=CommitteeTestResultSeeder
php artisan db:seed --class=CommitteeLicenseIssuanceSeeder
php artisan db:seed --class=CommitteeDemoSeeder
```

For a machine whose `.env` is currently `production` but the database is a local copy, run once with a local environment (PowerShell):

```powershell
$env:APP_ENV='local'; php artisan db:seed --class=CommitteeDemoSeeder
```

Do **not** point this at a real production database.

Console output prints numeric `application_id` and `appointment_id` for the current database. Application numbers below are stable.

## Scenario IDs

| Scenario | Citizen | Application number | Pre-demo status | Appointment | Dashboard |
|---|---|---|---|---|---|
| **A** End-to-end final test → issuance | مواطن تجريبي - إصدار بعد الاختبار (`committee.scenario-a@syrtak.local`) | `DEMO-COMMITTEE-A` | `in_testing`, `current_test_type` = practical | Booked **practical**, no result | نتائج الاختبارات then إصدار الرخص |
| **B** Failed result | مواطن تجريبي - رسوب (`committee.scenario-b@syrtak.local`) | `DEMO-COMMITTEE-B` | `in_testing` | Booked **vision**, no result | نتائج الاختبارات |
| **C** No-show | مواطن تجريبي - عدم حضور (`committee.scenario-c@syrtak.local`) | `DEMO-COMMITTEE-C` | `in_testing` | Booked **vision**, no result | نتائج الاختبارات |
| **D** Ready-to-issue fallback | مواطن تجريبي - جاهز للإصدار (`committee.scenario-d@syrtak.local`) | `DEMO-COMMITTEE-D` | `approved`, all tests already passed | none waiting | إصدار الرخص |

Numeric ids change per database. After seeding, use the artisan output or search the queue by application number / citizen name.

### Scenario A preconditions

- Approved profile, `new_license` / `private`
- Required documents approved, application fee completed, no unpaid fines, no license on this application
- Vision **passed**, theory **passed**, practical **booked** with no result
- Appears in `GET /api/dashboard/test-appointments` with `actions.can_record_result = true` for the examiner

Recording practical `passed` through the real `TestResultService` marks the appointment completed, records the pass, sees all required tests passed, and moves the application to `approved`. It then appears in `GET /api/dashboard/license-issuance/applications`. Issuing uses the real `POST /api/admin/applications/{id}/issue-license`.

### Scenario D

Satisfies `LicenseIssuanceEligibilityService` immediately: `readiness.is_ready = true` and `actions.can_issue_license = true` for the issuer. Use if A was already consumed.

## Committee demo script

1. Login examiner: `committee.examiner@syrtak.local` / `CommitteeDemo!2026`
2. Open **نتائج الاختبارات** (`/dashboard/test-appointments`)
3. Find **مواطن تجريبي - إصدار بعد الاختبار** / `DEMO-COMMITTEE-A` (practical)
4. Record result:

```json
{
  "result": "passed",
  "notes": "نجاح الاختبار العملي - بيانات تجريبية للجنة"
}
```

5. Logout. Login issuer: `committee.issuer@syrtak.local` / `CommitteeDemo!2026`
6. Open **إصدار الرخص** (`/dashboard/license-issuance`)
7. Find the same application (`DEMO-COMMITTEE-A`). Confirm Issue is enabled.
8. Issue (empty body): `POST /api/admin/applications/{id}/issue-license`
9. On 200, open the issued license with **`data.id`**: `GET /api/dashboard/licenses/{data.id}` (or the licenses screen)
10. Print / verify from that license page as usual

Optional: as examiner, record `failed` on Scenario B and `no_show` on Scenario C. Existing domain logic moves those applications to `waiting_retest` (no-show also sets the appointment to `no_show`).

If A is already issued, issue Scenario D (`DEMO-COMMITTEE-D`) instead.

GET readiness is informational. POST issue-license still re-runs `assertReady()`. Handle 422 if state changed.

## Stale / re-seed

If the demo was consumed, run `CommitteeDemoSeeder` again. It resets **only** `DEMO-COMMITTEE-*` rows (including a license issued from those applications) and restores the pre-recording / ready-to-issue states.

## Test results

`tests/Feature/CommitteeDemoSeederTest.php`

1. Seeder refuses `production`
2. Real HTTP: A in waiting-result queue → record practical passed → application `approved` → eligibility ready → issue-license → license created → `license_issued` → B `failed` → `waiting_retest` → C `no_show` → `waiting_retest` + appointment `no_show` → D independently ready
3. Seeder is idempotent (second run does not duplicate committee applications)

**3 passed** (51 assertions)
