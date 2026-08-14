# Benchmark Dataset (SYRTAK)

Deterministic, benchmark-only volume data for performance / k6 load testing.

## Guards

- `APP_ENV=benchmark` (seeder aborts otherwise)
- Database name must be `dlms_benchmark` (seeder aborts otherwise)
- No Stripe / Gemini / FCM / email / network calls
- No fixed Sanctum API tokens (k6 authenticates normally)

## Targets

| Entity | Target |
|--------|--------|
| Citizens | 2,000 |
| Employees (incl. benchmark admin) | ~20 |
| `license_applications` | 5,000 |
| `application_documents` | ~15,000 |
| `payments` | ~4,000 |
| `test_appointments` | ~5,000 |
| `test_results` | ~4,000 |
| `licenses` | ~2,000 |
| `fines` | ~1,500 |
| `notifications` | 15,000 |
| `audit_logs` | 25,000 |
| `application_status_histories` | ~15,000 |
| `license_status_histories` | ~5,000 |

## Generation method

1. Reuse catalog seeders: roles, permissions, license types, service types, test types, required documents, fees, appointment centers.
2. Create fixed high-capacity appointment slots on a fixed calendar (epoch `2026-08-15`) — not `now()`.
3. Bulk/chunk `DB::table()->insert()` for large tables (chunk size 500).
4. Shared bcrypt hash for all seeded passwords (computed once).
5. Deterministic numbers/emails/phones/national IDs (`APP-BM-*`, `LIC-BM-*`, `PAY-BM-*`, `bm.citizen.####@…`).
6. Domain-safe mixes:
   - application statuses (draft → issued / rejected / waiting payment / waiting test, etc.)
   - service types (`new_license`, renew, lost/damaged replacement, unblock)
   - tests only on `new_license` flows
   - related licenses for non-new services
   - paid/unpaid/cancelled fines
   - `mock` payment provider only

## Fixed accounts

| Role | Email | Password |
|------|-------|----------|
| Dashboard admin | `benchmark.admin@syrtak.local` | `Benchmark!2026` |
| Citizen (approved) | `benchmark.citizen@syrtak.local` | `Benchmark!2026` |

## Command

Do **not** run against local/staging/production business DBs.

```bash
# Ensure .env.benchmark is active (APP_ENV=benchmark, DB_DATABASE=dlms_benchmark),
# migrate the empty benchmark DB, then:

php artisan db:seed --class=Database\\Seeders\\BenchmarkPerformanceSeeder --env=benchmark
```

Windows PowerShell example:

```powershell
php artisan db:seed --class=Database\Seeders\BenchmarkPerformanceSeeder --env=benchmark
```

Seeder console output includes seed duration, final row counts, and the benchmark credentials above.
