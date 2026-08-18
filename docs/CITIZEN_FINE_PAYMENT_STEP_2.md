# Citizen Fine Payment — Step 2

## Decision: Fine currency persisted

Electronic fine currency for this DLMS version is authoritative and persisted on each fine.

| Item | Value |
|------|--------|
| Canonical currency | `USD` (uppercase machine code) |
| Config | `config('payment.fine_currency')` ← `FINE_CURRENCY` env (default `USD`) |
| Client may choose currency? | **No** (`currency` is `prohibited` on store/update) |
| Amount conversion on backfill? | **No** — metadata assignment only |

## Migration

| Item | Value |
|------|--------|
| File | `database/migrations/2026_08_18_100000_add_currency_to_fines_table.php` |
| Column | `fines.currency` `string(8)` |
| Nullable | **No** (default `USD`) |
| Default | `USD` |
| Backfill | `UPDATE fines SET currency = 'USD'` (all rows; no FX) |

Verified on local `dlms` database: migration **PASS**.

## Files changed

- `config/payment.php` — `fine_currency`
- `.env.example` — `FINE_CURRENCY=USD`
- `database/migrations/2026_08_18_100000_add_currency_to_fines_table.php`
- `app/Models/Fine.php` — fillable `currency`
- `app/Modules/Fines/Services/FineService.php` — assign currency on create; ignore on update
- `app/Modules/Fines/Requests/StoreFineRequest.php` — `currency` prohibited
- `app/Modules/Fines/Requests/UpdateFineRequest.php` — `currency` prohibited
- `app/Modules/Fines/Resources/FineResource.php` — expose `currency`
- `tests/Feature/FineCurrencyFoundationTest.php` — focused tests A–E
- `tests/Feature/LicenseFlowTest.php` — assert `currency` on create/list/paid
- Demo/seed normalization (below)

## Seed / demo amount normalization

Human-facing demo values that were SYP-like large integers were normalized to realistic USD demo amounts (scenario meaning preserved). No FX math.

| Location | Old | New | Why |
|----------|-----|-----|-----|
| `FullLifecycleKit` unpaid fine | `75000` | `25.00` | Demo unpaid fine under USD policy |
| `FullLifecycleKit` paid fine | `40000` | `15.00` | Demo paid fine |
| `FullLifecycleKit` cancelled fine | `25000` | `10.00` | Demo cancelled fine |
| `FullLifecycleKit` linked Payment currency | `SYP` | `USD` via `config('payment.fine_currency')` | Match fine currency |
| `DashboardCitizenLicensesFinesDemoSeeder` | `75000` / `50000` / `120000` | `25.00` / `15.00` / `50.00` | Dashboard demo realism |
| `CommitteeDemoKit::createUnpaidFine` | `1500` | `25.00` | Demo unpaid fine; avoid looking like mid/large SYP under USD label |
| `BenchmarkPerformanceSeeder` fine inserts | (no currency col) | `currency => USD` | Required column for raw inserts |

**Not changed:** assertion-specific test amounts (`1000`, `5000`, `10000`, `100.00`, overview `250.50`, etc.) — they remain fixture values for blockers/KPIs, not user-facing demo pricing.

## Behavior summary

| Area | Behavior |
|------|----------|
| Fine create | Server sets `currency = config('payment.fine_currency')` |
| Fine update | Currency immutable; client `currency` → 422 validation |
| `GET /api/fines` | Returns `amount` + `currency` |
| Admin fine CRUD | Unchanged permissions/flow; create returns `currency: USD` |
| Payments / Stripe / webhook / lifecycle | **Not modified** in this step |

## Tests

Focused suite: `tests/Feature/FineCurrencyFoundationTest.php`

| Suite | Result |
|-------|--------|
| `FineCurrencyFoundationTest` | **PASS** (6 tests, 33 assertions) |
| `LicenseFlowTest` | **PASS** (6 tests, 29 assertions) |
| `LicenseUnblockFlowTest` | **PASS** (24 tests, 171 assertions) |
| `CitizenBilingualMessagesTest` | **PASS** (8 tests, 88 assertions) |

Full `php artisan test` was **not** declared PASS in this step due to concurrent `dlms_testing` contention from other local PHPUnit processes; required suites above were run serially after resetting `dlms_testing`.

Regression commands used:

```bash
php artisan test tests/Feature/FineCurrencyFoundationTest.php
php artisan test tests/Feature/LicenseFlowTest.php
php artisan test tests/Feature/LicenseUnblockFlowTest.php
php artisan test tests/Feature/CitizenBilingualMessagesTest.php
```

## Remaining work for Step 3

- `FinePaymentService` (create/reuse Payment from `Fine.amount` + `Fine.currency`)
- Stripe checkout generalization for fines
- Lifecycle Fine → `paid` on payment completion
- Webhook/status finders including fine payments
- Citizen pay/status endpoints
- My Payments (later step)

## Note on test execution environment

Concurrent PHPUnit processes sharing `dlms_testing` caused MySQL **metadata lock** / deadlock waits. Run Step 2 focused/regression suites **serially** when no other suite is using that database.
