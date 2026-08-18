# Development Database Seeding

## Canonical reset command

```bash
php artisan migrate:fresh --seed
```

- `migrate:fresh` alone = migrations only (no seed)
- `--seed` runs `DatabaseSeeder`

See also: Fine Payment fixtures in [`CITIZEN_FINE_PAYMENT_DEMO_SEEDER.md`](CITIZEN_FINE_PAYMENT_DEMO_SEEDER.md).

---

## Architecture

```text
DatabaseSeeder
├── Production-safe catalogs + SuperAdmin bootstrap (all environments)
└── if local|testing OR DEMO_SEEDING_ENABLED=true
       └── DevelopmentDemoSeeder
              ├── DashboardEmployees / Admin / Employee / Citizen users
              ├── FullLifecycleSeeder          (FLOW-* lifecycle coverage)
              ├── DashboardDocumentReviewDemoSeeder
              ├── DemoLicenseServiceTestingSeeder
              ├── DashboardCitizenLicensesFinesDemoSeeder
              ├── LostReplacementTestCitizenSeeder
              ├── CommitteeDemoSeeder          (committee queue demos)
              └── CitizenFinePaymentDemoSeeder (PAY-CFP-*, [CFP-*])
```

Gate: `Database\Seeders\Support\DemoSeeding` — environment **or** explicit flag. **Never** driven by `APP_URL`.

---

## Exact seeder order

### Always (production-safe)

1. `RolesSeeder`
2. `PermissionsSeeder`
3. `LicenseTypesSeeder`
4. `ServiceTypesSeeder`
5. `TestTypesSeeder`
6. `RequiredDocumentsSeeder`
7. `FeesSeeder`
8. `FaqSeeder`
9. `AppointmentCentersSeeder`
10. `AppointmentSlotsSeeder`
11. `SuperAdminUserSeeder`

### Demo dataset (`DevelopmentDemoSeeder`)

Runs when `APP_ENV` is `local` / `testing`, **or** `DEMO_SEEDING_ENABLED=true`:

12. `DashboardEmployeesSeeder`
13. `AdminUserSeeder`
14. `EmployeeUserSeeder`
15. `CitizenUserSeeder`
16. `FullLifecycleSeeder`
17. `DashboardDocumentReviewDemoSeeder`
18. `DemoLicenseServiceTestingSeeder`
19. `DashboardCitizenLicensesFinesDemoSeeder`
20. `LostReplacementTestCitizenSeeder`
21. `CommitteeDemoSeeder`
22. `CitizenFinePaymentDemoSeeder`

---

## What gets seeded (demo path)

| Area | Source |
|------|--------|
| Catalogs (roles, fees, types, slots, FAQ) | Core `DatabaseSeeder` |
| Super admin bootstrap | `SuperAdminUserSeeder` |
| Full citizen/employee lifecycle demos | `FullLifecycleSeeder` / `FullLifecycleKit` |
| Dashboard document / license / fine demos | Dashboard* seeders |
| Committee test-result / issuance queues | `CommitteeDemoSeeder` / `CommitteeDemoKit` |
| Citizen Fine Payment + My Payments | `CitizenFinePaymentDemoSeeder` |

### Fine Payment demo accounts

| Email | Password |
|-------|----------|
| `demo.fine.happy@syrtak.local` | `password` |
| `demo.fine.payments@syrtak.local` | `password` |
| `demo.fine.blocked@syrtak.local` | `password` |
| `demo.fine.other@syrtak.local` | `password` |

---

## Production behavior (real production)

```env
APP_ENV=production
DEMO_SEEDING_ENABLED=false
```

- Only catalog + `SuperAdminUserSeeder` run
- **No** FLOW-*, PAY-CFP-*, committee demos, demo citizens, fake Stripe session ids
- Standalone `CitizenFinePaymentDemoSeeder` / `CommitteeDemoSeeder` also refuse unless the flag is true

Do **not** run `migrate:fresh` against a real production database.

---

## Hosted Demo / QA Server

Use a production-style deploy **without** changing `APP_ENV` to `local`, and **without** URL-based seeding logic.

### Required variables

```env
APP_ENV=production
DEMO_SEEDING_ENABLED=true
```

### Seed command

```bash
php artisan migrate:fresh --seed --force
```

That runs catalogs + SuperAdmin **and** the full `DevelopmentDemoSeeder` graph (FullLifecycle, dashboard demos, Committee, Citizen Fine Payment).

### Warnings

> **Never enable `DEMO_SEEDING_ENABLED` on a real production database.**  
> **`migrate:fresh` permanently deletes all existing database data.**

Keep real production as:

```env
APP_ENV=production
DEMO_SEEDING_ENABLED=false
```

Config key: `config('dlms.demo_seeding_enabled')` ← `.env` `DEMO_SEEDING_ENABLED` (default `false`).

---

## CommitteeDemoKit decision

**Included** in `DevelopmentDemoSeeder`.

Rationale: supports daily dashboard QA for test-results / license-issuance queues. Guarded by the same `DemoSeeding` gate as Fine Payment demos.

---

## Standalone Fine Payment seeder

Still supported without a full reset:

```bash
php artisan db:seed --class=CitizenFinePaymentDemoSeeder
```

Requires `local` / `testing`, **or** `DEMO_SEEDING_ENABLED=true`.

`ensureCatalog()` remains so this works on an empty-enough DB. Catalog upserts are idempotent (no duplicate Fees/Roles).

Targeted purge uses only:

- payment numbers `PAY-CFP-*`
- fine reasons containing `[CFP-`
- application / license prefixes `APP-CFP-*` / `LIC-CFP-*`

It does **not** delete FullLifecycle `FLOW-*` data.

---

## Idempotency notes

| Mode | Expectation |
|------|-------------|
| `migrate:fresh --seed` | Always clean success |
| Re-run `db:seed` on demo/local | Catalogs upsert safely; CFP kit is deterministic; some older demos assume relatively fresh data — prefer `migrate:fresh --seed` for a clean slate |

---

## Safety / external side effects

Demo seeders remain fixture-only even when `DEMO_SEEDING_ENABLED=true` under `APP_ENV=production`:

- No live Stripe Checkout / webhook calls from demo kits
- No mail / FCM / SMS from Fine Payment or Committee demo kits
- FullLifecycle uses DB fixtures (mock payment metadata), not live Stripe

Confirm `APP_ENV`, `DEMO_SEEDING_ENABLED`, and `DB_DATABASE` before any `migrate:fresh`.
