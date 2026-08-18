# Citizen Fine Payment — Step 6 Postman + Flutter Handoff

## Decision: Canonical collection

**Updated in place:** `postman/SYRTAK_Flutter_API.postman_collection.json`

Other Postman files (dashboard collections, phase 9, root `DLMS_API_Postman_Collection.json`) were **not** modified. They are not the Flutter kit.

Environment (additive variable only): `postman/SYRTAK_Local.postman_environment.json`  
(`stripe_session_id` added; `fine_id` / `payment_id` / `checkout_url` already existed)

## Collection changes

| Folder | Requests |
|--------|----------|
| `09 - Fines` | Get My Fines, Get Fine Detail, Pay Fine, Get Fine Payment Status, Confirm Fine Payment [Mock Only] |
| `09b - My Payments` | Get My Payments, Get Payment Detail, Get Completed Payments, Get Fine Payments, Get Application Payments |
| `09c - Payment Return Pages` | Processing, Cancel, Success (session_id) — display-only |

Scripts store `fine_id` (prefer unpaid), `payment_id`, `checkout_url` (Stripe) via existing `pm.environment.set` conventions.

## Flutter handoff

`docs/CITIZEN_FINE_PAYMENT_FLUTTER_HANDOFF.md`

## Application code

**Changed: NO** (docs + Postman + optional helper scripts only)

## Route verification (Step 6)

Citizen Fine APIs:

| Method | Path | Middleware |
|--------|------|------------|
| GET | `/api/fines` | sanctum, locale, citizen |
| GET | `/api/fines/{fine}` | sanctum, locale, citizen |
| POST | `/api/fines/{fine}/payments` | + profile.approved, throttle:15,1 |
| GET | `/api/fines/{fine}/payments/{payment}/status` | sanctum, locale, citizen |
| POST | `/api/fines/{fine}/payments/{payment}/confirm` | + profile.approved, throttle:15,1 |

My Payments:

| Method | Path | Middleware |
|--------|------|------------|
| GET | `/api/payments` | sanctum, locale, citizen |
| GET | `/api/payments/{payment}` | sanctum, locale, citizen |

Return pages: `web` only (public).

## Validation

- Collection JSON: valid
- Environment JSON: valid
- Secrets scan: OK (no live tokens/Stripe secrets committed)
- Application code changed: **NO** — full suite not re-run (docs/Postman only)

## Helper scripts

- `scripts/update_flutter_postman_step6.php`
- `scripts/verify_postman_step6.php`
