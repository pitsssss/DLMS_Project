# Citizen My Payments — Step 4

## 1. Goal

Citizen-facing **مدفوعاتي / My Payments** API so an authenticated citizen can list and view **all of their own payments**, regardless of purpose (application fees and fine payments), without redesigning the Step 3 payment lifecycle.

## 2. Routes

| Method | Path | Controller |
|--------|------|------------|
| `GET` | `/api/payments` | `CitizenPaymentController@index` |
| `GET` | `/api/payments/{payment}` | `CitizenPaymentController@show` |

`{payment}` is constrained with `whereNumber('payment')`.

These are **not** dashboard routes and do not collide with:

- `/api/applications/{application}/payments…`
- `/api/fines/{fine}/payments…`
- `/api/dashboard/payments…`

## 3. Middleware

```text
auth:sanctum → locale → citizen
```

**Not** applied: `profile.approved`.

Rationale: other citizen read-only history surfaces (e.g. fines list, notifications) use `auth:sanctum` + `locale` + `citizen` without requiring an approved profile. Historical payment evidence should remain visible under the same rule.

## 4. Ownership model

```text
payments.user_id === authenticated citizen.id
```

Foreign payment detail uses non-disclosing **404** (`messages.payments.not_found`), not 403.

## 5. Query scope

`CitizenPaymentHistoryService` scopes to the citizen and only **valid XOR shapes**:

| Shape | Condition |
|-------|-----------|
| Application | `application_id IS NOT NULL AND fine_id IS NULL` |
| Fine | `fine_id IS NOT NULL AND application_id IS NULL` |

**Malformed dual-linked rows** (`application_id` and `fine_id` both set) are **excluded** from list and return **404** on detail. They are not classified as either valid type.

Dashboard list behavior (still excludes fine-linked payments) is unchanged.

## 6. Pagination

Envelope matches citizen list conventions (`items` + `pagination`):

```json
{
  "items": [],
  "pagination": {
    "current_page": 1,
    "per_page": 15,
    "total": 0,
    "last_page": 1
  }
}
```

- Default `per_page`: **15**
- Optional: `per_page` (1–100), `page` (≥ 1)

Empty history: **200** + empty `items` (not 404).

## 7. Filters

| Query | Semantics |
|-------|-----------|
| `status` | Exact `PaymentStatus` value: `pending`, `completed`, `failed`, `under_verification` |
| `type=fine` | Valid fine shape only |
| `type=application` | Valid application shape only |

Validated by `CitizenPaymentIndexRequest`. Invalid values → **422** localized validation errors.

No free-form sort fields. No granular `purpose=` filter in Step 4.

## 8. Payment purpose architecture

`CitizenPaymentPurposeResolver` maps a `Payment` to:

```json
"purpose": { "code": "...", "label": "..." }
"related": { "type": "...", "id": ..., ... }
```

| Payment shape | `purpose.code` | Label source |
|---------------|----------------|--------------|
| Fine | `fine` | `messages.payments.purposes.fine` |
| Application | stored `fee.code` (e.g. `application_fee`, `renewal_fee`, `unblock_fee`, …) | `CitizenCatalogLabel::fee` → `messages.fees.codes.*` |

Canonical fee codes are reused; no second naming system.

**Retest limitation:** Retest/test fees use fee codes such as `vision_test_fee` / `theory_test_fee` / `practical_test_fee` when present on the payment’s `fee_id`. There is no separate invent­ed `retest` purpose code. If a payment lacks a resolvable fee, purpose falls back to `application_fee` label resolution with catalog/fallback behavior.

## 9. AR / EN labels

- Fine: `messages.payments.purposes.fine` — AR «دفع غرامة مرورية» / EN «Traffic fine payment»
- Application: existing `messages.fees.codes.{fee_code}`
- Additive `status_label` via `messages.payments.statuses.{status}` (machine `status` remains English)

Locale from `Accept-Language` via existing `locale` middleware + translators.

## 10. Fine payment representation (list)

- Amount/currency from **Payment** row
- `purpose.code = fine`
- `related.type = fine`, `related.id`, optional `fine_status`
- No raw `metadata`, no `checkout_url`, no `provider_reference` in My Payments resource

## 11. Application payment representation (list)

- Amount/currency from **Payment** row
- `purpose.code = fee.code`
- `related.type = application`, `id`, `application_number`, `service_type_code`, `fee_code`

## 12. Payment detail representation

Same core fields as list, plus `detail`:

- Fine: id, amount, currency, reason, status, paid_at, license_id / license_number (when present)
- Application: application id/number/status/service_type_code + fee id/code/localized name

Implemented as **`CitizenPaymentResource`** (Option B) so application/fine payment APIs keep using `PaymentResource` unchanged.

## 13. Historical financial integrity

List/detail always expose stored `Payment.amount` and `Payment.currency`. Changing Fine or Fee catalog amounts afterward does not rewrite history.

## 14. Security

- List isolation by `user_id`
- Detail foreign → 404
- No write endpoints on `/api/payments`
- Metadata / checkout secrets not exposed

## 15. Performance / eager loading

List eager-loads: `fee`, `fine`, `application.serviceType`.

Detail additionally: `fine.license`, `application.licenseType`.

No Redis/cache in this step.

## 16. Tests

`tests/Feature/CitizenPaymentHistoryTest.php` covers empty history, fine/application/mixed visibility, ownership list+detail, ordering, pagination, status/type filters, invalid filters, AR/EN labels, machine codes, historical integrity, malformed dual-link exclusion, unauthenticated 401.

## 17. Regression results

Serial runs against `dlms_testing` (no overlapping PHPUnit processes):

| Suite | Result |
|-------|--------|
| `CitizenPaymentHistoryTest` | PASS — 21 tests / 102 assertions |
| `FinePaymentFlowTest` | PASS |
| `FinePaymentStripeTest` | PASS |
| `PaymentFlowTest` | PASS |
| `PaymentStripeTest` | PASS |
| `PaymentConcurrencyAndIntegrityTest` | PASS |
| `PaymentReconciliationAndDbInvariantEvidenceTest` | PASS |
| `DashboardPaymentManagementTest` | PASS |
| `ApplicationFeeUsdCatalogTest` | PASS |
| `LicenseFlowTest` | PASS |
| `LicenseUnblockFlowTest` (+ related filter matches) | PASS |
| `CitizenBilingualMessagesTest` | PASS |
| `FineCurrencyFoundationTest` | PASS |

## 18. Full suite result

```text
Tests:    1227 passed (7988 assertions)
Duration: ~349s
```

Step 3 baseline was **1206 passed / 7886 assertions**. Step 4 adds My Payments coverage; full suite remains green.

## 19. Files changed

| File | Role |
|------|------|
| `app/Modules/Payments/Controllers/CitizenPaymentController.php` | Index/show |
| `app/Modules/Payments/Services/CitizenPaymentHistoryService.php` | Scoped query + ownership |
| `app/Modules/Payments/Support/CitizenPaymentPurposeResolver.php` | Purpose + related |
| `app/Modules/Payments/Resources/CitizenPaymentResource.php` | Citizen-safe resource |
| `app/Modules/Payments/Requests/CitizenPaymentIndexRequest.php` | Filters |
| `routes/api.php` | Routes |
| `resources/lang/en/messages.php` | history + purpose keys |
| `resources/lang/ar/messages.php` | history + purpose keys |
| `tests/Feature/CitizenPaymentHistoryTest.php` | New tests |
| `docs/CITIZEN_MY_PAYMENTS_STEP_4.md` | This document |

## 20. Remaining Step 5 work

Out of scope for Step 4 (do not implement here):

- Stripe success / cancel / processing Blade pages
- Flutter My Payments UI
- Receipt PDF
- Refunds / AI Agent payment tools
- Deep links
