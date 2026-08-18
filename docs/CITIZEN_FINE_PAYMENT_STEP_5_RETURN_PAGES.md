# Citizen Fine Payment — Step 5: Stripe Return Pages

## 1. Goal

Public, mobile-first **SYRTAK / سيرتك** browser pages shown after Stripe Checkout for citizen Fine payments:

| State | Route | Purpose |
|-------|-------|---------|
| Success | `GET /payment/success` | Payment locally `completed` |
| Processing | rendered from success (or `GET /payment/processing`) | Pending / verifying / inconclusive |
| Cancel | `GET /payment/cancel` | Citizen left Stripe Checkout |

## 2. Routes

| Method | Path | Name | Auth |
|--------|------|------|------|
| GET | `/payment/success` | `payment.return.success` | Public |
| GET | `/payment/processing` | `payment.return.processing` | Public |
| GET | `/payment/cancel` | `payment.return.cancel` | Public |

Controller: `App\Modules\Payments\Controllers\PaymentReturnController`

## 3. Public security model

- No `auth:sanctum`, `citizen`, or dashboard middleware.
- Display locale from `?lang=ar|en` only (display-only; invalid → default locale, usually `ar`).
- No PII, amounts, Fine reason, session secrets, or `checkout_url` in HTML.
- `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`

## 4. Financial authority model

These pages are **DISPLAY ONLY**.

They **never**:

- mark Payment completed
- mark Fine paid
- call mock confirm / lifecycle / reconcile
- call Stripe API
- trust query params as proof of payment

Authoritative completion remains:

```text
Stripe webhook → PaymentLifecycleService
(+ reconciliation / authenticated status poll)
```

## 5. Success flow

```text
Stripe → /payment/success?session_id={CHECKOUT_SESSION_ID}&lang=ar
       → local Payment where provider=stripe AND provider_reference=session_id
       → status=completed → success Blade
```

## 6. Processing flow

| Local status | Page variant |
|--------------|--------------|
| `pending` | confirming (processing copy) |
| `under_verification` | verifying copy (does not claim success) |
| `failed` | inconclusive (non-success) |
| missing / malformed / unknown session | inconclusive |

Direct `GET /payment/processing` shows confirming copy.

## 7. Cancel flow

`GET /payment/cancel?lang=…` — informational only. No Payment/Fine mutation. Stripe expiration/webhook remain authoritative for failed/expired attempts.

## 8. Stripe Fine checkout URL integration

`StripePaymentGatewayService::createFineCheckoutSession` builds:

```text
success_url = {APP_URL}/payment/success?session_id={CHECKOUT_SESSION_ID}&lang={locale}
cancel_url  = {APP_URL}/payment/cancel?lang={locale}
```

Helpers: `buildFineSuccessUrl()`, `buildFineCancelUrl()`.

**Application** Checkout still uses `config('payment.stripe.success_url' / cancel_url')` from `STRIPE_SUCCESS_URL` / `STRIPE_CANCEL_URL` — unchanged.

## 9. Locale propagation

Locale at Fine checkout creation (`app()->getLocale()` from citizen `locale` middleware) is embedded in return URLs as `lang=ar|en`. Return pages prefer this query over browser `Accept-Language`.

## 10. Visual / brand design

- Logo: `public/branding/syrtak-license-logo.png`
- Palette from email brand: forest `#054239` / deep `#002623` / gold `#B9A779` / sand `#EDEBE0`
- Shared Blade layout + inline CSS (no CDN fonts/icons)
- Mobile-first centered card; success / clock / cancel SVG icons

## 11. Privacy decisions

No amount, Fine reason, license numbers, emails, or raw Stripe session IDs shown. Session id is used only as a lookup key server-side.

## 12. No deep-link decision

Instructional text only: close page and return to the SYRTAK app. No fake “Open app” button; no `syrtak://` scheme in this step.

## 13. Tests

`tests/Feature/PaymentReturnPageTest.php` — success/pending/UV/failed/unknown/missing, cancel, AR/EN + RTL/LTR, no mutation, Fine URL builders, cache headers.

`FinePaymentStripeTest` — asserts Fine return URLs point to backend `/payment/*` pages.

## 14. Regression results

Serial runs against `dlms_testing`:

| Suite | Result |
|-------|--------|
| `PaymentReturnPageTest` | PASS — 11 tests / 63 assertions |
| `FinePaymentStripeTest` | PASS |
| `FinePaymentFlowTest` | PASS |
| `PaymentStripeTest` | PASS |
| `PaymentFlowTest` | PASS |
| `CitizenPaymentHistoryTest` | PASS |
| `CitizenBilingualMessagesTest` | PASS |
| `PaymentConcurrencyAndIntegrityTest` | PASS |
| `PaymentReconciliationAndDbInvariantEvidenceTest` | PASS |
| `DashboardPaymentManagementTest` | PASS |
| `ApplicationFeeUsdCatalogTest` | PASS |
| `FineCurrencyFoundationTest` | PASS |
| `LicenseUnblockFlowTest` | PASS |

## 15. Full suite

```text
Tests:    1239 passed (8058 assertions)
```

Step 4 baseline: **1227 passed / 7988 assertions**.

## 16. Production env requirements

```text
APP_URL=https://<public-backend-host>
```

Must be the HTTPS origin Stripe redirects to (trusted proxies / Railway already handled by Laravel when configured).

Fine Checkout does **not** require `STRIPE_SUCCESS_URL` / `STRIPE_CANCEL_URL`.

Application Checkout still requires those env vars when using Stripe for application fees.

## 17. Files changed

| File | Role |
|------|------|
| `routes/web.php` | Public return routes |
| `PaymentReturnController.php` | Read-only state resolution |
| `resources/views/payments/*` | Layout + success/processing/cancel |
| `StripePaymentGatewayService.php` | Fine return URL wiring only |
| `resources/lang/{ar,en}/messages.php` | Return copy |
| `.env.example` | APP_URL / Fine vs Application URL notes |
| `PaymentReturnPageTest.php` | New tests |
| `FinePaymentStripeTest.php` | URL assertions |
| `docs/CITIZEN_FINE_PAYMENT_STEP_5_RETURN_PAGES.md` | This doc |

## 18. Remaining Flutter work

- Open Stripe Checkout URL from Fine pay flow
- After return, refresh Fine / My Payments via existing authenticated APIs
- Future: verified deep links / “Open app” button
- Do not treat return page as payment confirmation
