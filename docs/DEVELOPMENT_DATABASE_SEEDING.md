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
└── if APP_ENV = local | testing
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

### Local / testing only (`DevelopmentDemoSeeder`)

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

## What gets seeded (local/testing)

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

## Production behavior

When `APP_ENV=production`:

- Only catalog + `SuperAdminUserSeeder` run
- **No** FLOW-*, PAY-CFP-*, committee demos, demo citizens, fake Stripe session ids
- `CitizenFinePaymentDemoSeeder` also refuses production if invoked directly

Do **not** run `migrate:fresh` against production.

---

## CommitteeDemoKit decision

**Included** in local/testing `DevelopmentDemoSeeder`.

Rationale: it was already part of the previous local-only `DatabaseSeeder` branch and supports daily dashboard QA for test-results / license-issuance queues. It remains environment-guarded (local/testing only).

---

## Standalone Fine Payment seeder

Still supported without a full reset:

```bash
php artisan db:seed --class=CitizenFinePaymentDemoSeeder
```

`ensureCatalog()` remains so this works on an empty-enough local DB. Catalog upserts are idempotent (no duplicate Fees/Roles).

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
| Re-run `db:seed` on local | Catalogs upsert safely; CFP kit is deterministic; some older demos assume relatively fresh data — prefer `migrate:fresh --seed` for a clean slate |

---

## Safety

- No Stripe / mail / FCM from Fine Payment demo kit
- FullLifecycle uses DB fixtures (mock payment metadata), not live Stripe
- Confirm `APP_ENV` and `DB_DATABASE` before any `migrate:fresh`

### Step 8 smoke (verified)

```text
APP_ENV=testing
DB_DATABASE=dlms_testing
php artisan migrate:fresh --seed --force → exit 0
FLOW apps present; CFP citizens/fines/payments present
```

Full suite after Step 8: **1245 passed / 8160 assertions** (baseline before: 1243 / 8131).
