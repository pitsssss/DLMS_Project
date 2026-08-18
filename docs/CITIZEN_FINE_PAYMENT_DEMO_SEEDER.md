# Citizen Fine Payment — Demo Seeder

## 1. Purpose

Deterministic **local/testing** fixtures for:

- Fine list / detail / pay
- Fine payment statuses (pending, completed, failed, under_verification)
- My Payments mixed history (fine + application fees)
- Stripe return-page local lookup (`cs_test_seed_return_*`)
- Blocked license + unpaid / paid fine
- Ownership isolation (Citizen A vs Other)

No real Stripe API calls. No mail/FCM from this seeder.

## 2. Seeder command

### Included in unified local reset

```bash
php artisan migrate:fresh --seed
```

When `APP_ENV` is `local` or `testing`, `DatabaseSeeder` → `DevelopmentDemoSeeder` → `CitizenFinePaymentDemoSeeder`.

See [`DEVELOPMENT_DATABASE_SEEDING.md`](DEVELOPMENT_DATABASE_SEEDING.md).

### Standalone

```bash
php artisan db:seed --class=CitizenFinePaymentDemoSeeder
```

**Not** included in production `DatabaseSeeder` path. Requires catalog seeders (roles, fees, license/service/test types). The kit calls them via `ensureCatalog()`.

## 3. Demo accounts

| Role | Email | Password |
|------|-------|----------|
| Happy-path fines | `demo.fine.happy@syrtak.local` | `password` |
| Mixed My Payments | `demo.fine.payments@syrtak.local` | `password` |
| Blocked license scenarios | `demo.fine.blocked@syrtak.local` | `password` |
| Ownership / other citizen | `demo.fine.other@syrtak.local` | `password` |

Password matches `FullLifecycleKit::PASSWORD` (`password`) — development only.

## 4. Scenario matrix (Fines)

| Code | Fine | Payment | License | Purpose |
|------|------|---------|---------|---------|
| FINE-01 | unpaid | **none** | active (happy) | **Fresh Pay Fine / Stripe happy path** — preferred Checkout scenario |
| FINE-02 | unpaid | pending (seeded fixture) | active (mixed) | **Processing / status poll UI only** — already has a pending Payment; not the preferred fresh Checkout scenario |
| FINE-03 | paid (amount 30 after pay) | completed 25.00 USD | active (happy) | Success + historical amount |
| FINE-04 | unpaid | failed | active (mixed) | Retry |
| FINE-05 | unpaid | under_verification | active (mixed) | Verifying UI |
| FINE-06 | cancelled | none | active (happy) | Not payable |
| FINE-07 | unpaid | none | **blocked** | Blocks unblock |
| FINE-08 | paid | completed | **blocked** | Fine cleared, license still blocked |
| FINE-OTHER | unpaid | pending (seeded) | active (other) | **Ownership isolation** — not for happy-path pay |

Stable markers: Fine `reason` contains `[CFP-FINE-…]`; payments use `PAY-CFP-…`.

## 5. Fine states

Covered: `unpaid`, `paid`, `cancelled`. All use `currency = USD`.

## 6. Payment states (Fine-linked)

| Status | Payment number | `provider_reference` (return page) |
|--------|----------------|-------------------------------------|
| pending | `PAY-CFP-FINE-02-PENDING` | `cs_test_seed_return_pending` |
| completed | `PAY-CFP-FINE-03-COMPLETED` | `cs_test_seed_return_success` |
| failed | `PAY-CFP-FINE-04-FAILED` | `cs_test_seed_return_failed` |
| under_verification | `PAY-CFP-FINE-05-UV` | `cs_test_seed_return_verifying` |

Obligation keys use `Payment::fineObligationKey()` (`fine:{id}`).

Providers: **stripe** (fixtures) and **mock**.

## 7. My Payments data

Citizen: `demo.fine.payments@syrtak.local`

Includes Fine payments + Application payments for:

| Fee / purpose | Notes |
|---------------|--------|
| `application_fee` | new license |
| `renewal_fee` | renew |
| `lost_replacement_fee` | lost |
| `damaged_replacement_fee` | damaged |
| `unblock_fee` | license_unblock |
| `vision_test_fee` | test fee |

Statuses across history: completed, pending, failed, under_verification.

## 8. License unblock scenarios

| Email | Scenario |
|-------|----------|
| `demo.fine.blocked@syrtak.local` | License `LIC-CFP-BLOCKED-UNPAID` + unpaid FINE-07 |
| same | License `LIC-CFP-BLOCKED-PAID` + paid FINE-08 + completed payment |

Paying FINE-07 removes the unpaid-fine blocker only; it does **not** auto-unblock the license.

## 9. Return-page fixtures

Local only (no Stripe session exists remotely):

```text
/payment/success?session_id=cs_test_seed_return_success&lang=ar
/payment/success?session_id=cs_test_seed_return_pending&lang=ar
/payment/success?session_id=cs_test_seed_return_verifying&lang=ar
/payment/success?session_id=cs_test_seed_return_failed&lang=en
```

## 10. Postman usage

1. `php artisan db:seed --class=CitizenFinePaymentDemoSeeder` (or unified `migrate:fresh --seed`)
2. Login as `demo.fine.happy@syrtak.local` / `password`
3. **Get My Fines** → script prefers **FINE-01** (`[CFP-FINE-01]`) for fresh Pay Fine — do **not** pick an arbitrary unpaid Fine (FINE-02 / FINE-OTHER already have seeded pending Payments)
4. **Get Fine Detail** → Pay Fine
5. For Stripe UI states without Checkout: open return URLs above while logged out of Postman (browser)
6. Login as `demo.fine.payments@syrtak.local` → **Get My Payments**
7. Login as `demo.fine.other@syrtak.local` and try happy citizen’s Fine/Payment IDs → expect 404

| Need | Account / fixture |
|------|-------------------|
| Fresh Stripe / mock Pay Fine | happy → **FINE-01** only |
| Processing UI / status poll | mixed → FINE-02 (seeded pending) |
| Success page | `cs_test_seed_return_success` |
| Processing return page | `cs_test_seed_return_pending` |
| Verifying | `cs_test_seed_return_verifying` |
| Failed payment | FINE-04 / `PAY-CFP-FINE-04-FAILED` |
| Cancelled fine | FINE-06 |
| My Payments | `demo.fine.payments@syrtak.local` |
| Ownership | happy vs `demo.fine.other@syrtak.local` |
| Unblock blocker | `demo.fine.blocked@syrtak.local` + FINE-07 |

## 11. Flutter usage

| UI | Account / data |
|----|----------------|
| My Fines + **fresh** Pay / Stripe Checkout | happy → **FINE-01** (no seeded Payment) |
| Paid badge | happy → FINE-03 |
| Cancelled | happy → FINE-06 |
| Processing (seeded pending) | mixed → FINE-02 — not for fresh Checkout |
| Under verification | mixed → FINE-05 |
| Failed / retry | mixed → FINE-04 |
| My Payments mixed list | `demo.fine.payments@syrtak.local` |
| Blocked + unpaid | blocked citizen |
| Blocked + paid (still blocked) | blocked citizen FINE-08 |
| Ownership isolation | other → FINE-OTHER |

## 12. Credentials policy

Development demo password only. Never use these accounts/passwords in production.

## 13. Safety / production warning

- Seeder **throws** outside `local` / `testing`
- Not wired into production `DatabaseSeeder`
- Fake Stripe session ids only (`cs_test_seed_*`)
- No secrets committed

## 14. Files changed

| File | Role |
|------|------|
| `database/seeders/CitizenFinePaymentDemoSeeder.php` | Entry point |
| `database/seeders/Support/CitizenFinePaymentDemoKit.php` | Scenario builders |
| `tests/Feature/CitizenFinePaymentDemoSeederTest.php` | Coverage |
| `docs/CITIZEN_FINE_PAYMENT_DEMO_SEEDER.md` | This doc |

## 15. Tests

```bash
php artisan test --filter=CitizenFinePaymentDemoSeederTest
```

Covers: production guard, fine/payment states, XOR shapes, USD, obligation keys, mixed fees, blocked scenarios, idempotent second run, API + return-page smoke.

### Regression / full suite (Step 7)

| Suite | Result |
|-------|--------|
| `CitizenFinePaymentDemoSeederTest` | PASS — 4 tests / 73 assertions |
| Fine/Payment/Unblock/Currency/Return page filters | PASS |
| Full suite | **1243 passed / 8131 assertions** |

Baseline before Step 7: 1239 passed / 8058 assertions.
