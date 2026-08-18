# Citizen Fine Payment — Backend Architecture Audit

**Project:** DLMS / SYRTAK  
**Scope:** Read-only audit of Fines + Payments + Stripe for citizen electronic fine payment and “My Payments”  
**Date:** 2026-08-18  
**Constraint:** No application code changes in this step.

---

## 1. Executive Summary

The codebase already has a mature **application-fee payment stack** (create pending payment → Stripe Checkout → webhook → lifecycle → notifications/audit) and a separate **fines domain** (citizen list, employee CRUD, statuses `unpaid|paid|cancelled`, `paid_at`).

**Schema already anticipates fine payments:** `payments.fine_id` FK exists, `Fine::payments()` / `Payment::fine()` exist, seeders create completed fine-linked payments, and dashboard payment queries explicitly **exclude** `fine_id IS NOT NULL`.

**Runtime payment paths do not support fines today.** Checkout, webhook lookup, reconciliation, and `PaymentLifecycleService::completeVerifiedPayment()` all assume `isApplicationPayment()` (`fine_id === null && application_id !== null`). A fine-linked payment would be **ignored by webhook finders** or **rejected at completion**.

**Verdict: GO with additive extension** — reuse Payment model, obligation keys pattern, webhook controller, gateway events, notifications, and audit. Do **not** invent a second Stripe/webhook system. Expected work: FinePayment service (mirror ApplicationPaymentService), generalize Stripe session creation + lifecycle side effects, citizen fine show + pay + status APIs, citizen My Payments list, optional success/cancel Blade pages, currency policy for fines vs Stripe USD.

---

## 2. Existing Citizen Fine Flow

### 2.1 Citizen Fine APIs (actual)

| Item | Value |
|------|--------|
| **METHOD / path** | `GET /api/fines` |
| **middleware** | `auth:sanctum`, `locale`, `citizen` |
| **controller** | `App\Modules\Fines\Controllers\FineController@index` |
| **request** | None (plain `Request`) |
| **resource** | `App\Modules\Fines\Resources\FineResource` |
| **purpose** | List all fines for authenticated citizen (`citizen_id = user.id`), with `license`, ordered by `id` desc |

**Not present today:**

- `GET /api/fines/{fine}` — **no citizen fine detail endpoint**
- Status filter query params on citizen list (unpaid/paid) — **not implemented** (client must filter locally)
- Any citizen pay / payment status under fines

Postman documents citizen fines list only (`SYRTAK_Flutter_API.postman_collection.json`).

### 2.2 Related employee / dashboard fine APIs

| METHOD / path | Purpose |
|---------------|---------|
| `GET/POST /api/admin/fines`, `PUT /api/admin/fines/{fine}` | CRUD + mark paid/cancelled (`permission:manage_fines`) |
| `GET /api/dashboard/citizens/{citizen}/fines` | Paginated fines for a citizen |
| `GET /api/dashboard/reports/fines` | Reporting (`view_fines` / `manage_fines`) |

---

## 3. Existing Fine Schema and States

### 3.1 Table `fines` (migration `2026_05_10_100010_create_fines_table`)

| Column | Notes |
|--------|--------|
| `id` | PK |
| `citizen_id` | FK → `users`, `restrictOnDelete` |
| `license_id` | nullable FK → `licenses`, `nullOnDelete` |
| `amount` | `decimal(12,2)` |
| `reason` | text |
| `status` | string(32) |
| `paid_at` | nullable timestamp |
| `timestamps` | yes |
| `softDeletes` | yes |
| index | `(citizen_id, status)` |

**No `currency` column on `fines`.** Amount is numeric only; currency for a future fine payment must be decided in payment layer (config / policy), not read from the fine row.

### 3.2 Model `App\Models\Fine`

- Fillable: `citizen_id`, `license_id`, `amount`, `reason`, `status`, `paid_at`
- Casts: `amount` decimal:2, `status` → `FineStatus`, `paid_at` datetime
- Relations: `citizen()`, `license()`, `payments()` HasMany

### 3.3 Fine statuses (`App\Enums\FineStatus`)

| Value | Meaning |
|-------|---------|
| `unpaid` | Default on create |
| `paid` | Sets `paid_at = now()` in `FineService::update` |
| `cancelled` | Cannot cancel if already paid |

### 3.4 Ownership / linkage

- **Ownership:** `fines.citizen_id` = citizen user id. Repository: `FineRepository::listForCitizen` filters `where('citizen_id', $citizen->id)`.
- **License:** optional `license_id`; create validates license belongs to same citizen.
- **`paid_at`:** yes, nullable.
- **Payment relationship:** Eloquent `payments()` exists; **no production citizen/employee path creates Payment when marking paid**.
- **State transitions:** `FineService::create` / `FineService::update` (employee-driven). No dedicated FinePayment transition service.

---

## 4. Existing Employee Fine Management

| Action | How |
|--------|-----|
| Create | `POST /api/admin/fines` → `StoreFineRequest` → `FineService::create` → status `unpaid`, audit `fine.created`, notify `FineCreated` |
| Update amount/reason/status | `PUT /api/admin/fines/{fine}` → `UpdateFineRequest` → `FineService::update` |
| Cancel | status → `cancelled` (blocked if paid) |
| Mark paid | status → `paid`, `paid_at = now()`, notify `FinePaid`, audit `fine.updated` |

**Employee can set `status = paid` manually** with permission `manage_fines`.

| Side effect when employee marks paid | Present? |
|--------------------------------------|----------|
| Creates `Payment` row | **No** |
| Audit log | **Yes** (`fine.updated`) |
| Notification | **Yes** (`NotificationType::FinePaid`) |

This is important: electronic fine payment must not double-notify or fight with manual mark-paid unless rules are explicit (idempotent Fine → paid).

---

## 5. Existing Payment Architecture

### 5.1 Table `payments` (create + lifecycle migrations)

| Column | Notes |
|--------|--------|
| `id` | PK |
| `payment_number` | unique string |
| `user_id` | FK users |
| `application_id` | **nullable** FK license_applications |
| `fine_id` | **nullable** FK fines |
| `fee_id` | nullable FK fees |
| `payable_type` / `payable_id` | nullable morphs (seeders use; production app path sets null) |
| `amount` | decimal(12,2) |
| `currency` | string(8), historical default SYP; app fees standardized to **USD** |
| `status` | pending / completed / failed / under_verification |
| `provider` | default `mock` |
| `provider_reference` | nullable; unique with provider |
| `paid_at` | nullable |
| `metadata` | json |
| `failure_code`, `failure_message`, `failed_at`, `last_verified_at` | lifecycle |
| `settled_obligation_key`, `active_obligation_key` | unique when set |

Indexes include `payments_fine_status_created_index` on `(fine_id, status, created_at)`.

### 5.2 Payment statuses (`PaymentStatus`)

`pending` | `completed` | `failed` | `under_verification`

### 5.3 Helpers on `Payment`

- `obligationKey($applicationId, $feeId)` → `"application:{id}:fee:{id}"` — **application-only today**
- `isApplicationPayment()` → `fine_id === null && application_id !== null`
- `isTerminalCompleted()`, `isActiveAttempt()`

### 5.4 Dual FK design (not exclusive XOR)

There is **no DB check** preventing `application_id` and `fine_id` both non-null. Production create path sets `fine_id = null` for applications. Seeders set `application_id = null` for fine payments. **Integrity is application-level only.**

Polymorphic `payable_*` exists but is **not** the authoritative citizen payment path.

---

## 6. Existing Stripe Checkout Architecture

### Flow (application fees — real code)

```text
POST /api/applications/{application}/payments
  middleware: auth:sanctum, locale, citizen, profile.approved, throttle:15,1
  → ApplicationPaymentController::store
  → StoreApplicationPaymentRequest (metadata optional only — NO amount from client)
  → ApplicationPaymentService::createPendingPayment
       → lock application; require PaymentPending
       → ApplicationFeeResolver::resolve → Fee amount/currency from DB
       → reuse active_obligation_key OR create Payment (fine_id=null)
       → if stripe: StripePaymentGatewayService::createCheckoutSession(Payment, Fee, User, LicenseApplication)
       → store provider_reference + metadata.checkout_url
  → response: { payment, provider, checkout_url, publishable_key }  (stripe)
            or PaymentResource only (mock)
```

**Amount:** from `Fee` via resolver — not client.  
**Currency:** from `Fee`; Stripe requires match with `config('payment.stripe.currency')` (typically `usd`).  
**Duplicate protection:** unique `active_obligation_key` / `settled_obligation_key`; reuse pending; reject if already completed.  
**Idempotency:** Stripe API key `dlms-payment-{payment_number}` on session create.  
**Checkout create failure:** `PaymentLifecycleService::markFailed(CheckoutCreationFailed)` + 503.  
**success_url / cancel_url:** from env `STRIPE_SUCCESS_URL` / `STRIPE_CANCEL_URL` via `config/payment.php` — **not** generated per payment; not Blade in this repo.

### Stripe provider coupling

`StripePaymentGatewayService::createCheckoutSession` **requires** `Fee` + `LicenseApplication`. Product name hardcoded `"DLMS Application Fee"`. Metadata includes `application_id`, `application_number`.

**Not generic for Fine as-is** — must be extended or overloaded with a Fine-specific session builder while keeping the same StripeClient/config.

### Config / env

- `PAYMENT_PROVIDER` = `mock` | `stripe`
- `STRIPE_PUBLISHABLE_KEY`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`
- `STRIPE_CURRENCY`, `STRIPE_SUCCESS_URL`, `STRIPE_CANCEL_URL`
- `PaymentProviderManager`, `MockPaymentGatewayService` (manual confirm only)

---

## 7. Existing Stripe Webhook Flow

```text
POST /api/webhooks/stripe  (throttle:100,1 — no auth)
  → StripeWebhookController::handle
  → Webhook::constructEvent (signature)
  → PaymentGatewayEventService::reserve (unique provider+event_id → duplicate returns OK)
  → switch event type:
       checkout.session.completed / async_payment_succeeded (paid)
         → ApplicationPaymentService::completeStripePaymentFromSession
       async_payment_failed / expired
         → handleStripeCheckoutSessionFailed → lifecycle markFailed
  → markProcessed / markIgnored / markFailed on gateway event
  → always HTTP 200 OK after reserve (errors reported, event marked failed)
```

### Lookup assumptions (critical)

`findStripePaymentBySessionId` / `findStripePaymentBySessionMetadata` both:

```php
->whereNull('fine_id')
```

**If `payment.application_id = null` and `payment.fine_id = X`:**

1. Webhook signature/reserve still works.
2. Payment **will not be found**.
3. Completion is a no-op (`if ($payment === null) return`).
4. Event may still be marked processed with `payment_id = null`.
5. Fine stays unpaid; Payment stays pending.

Additionally, even if found, `PaymentLifecycleService::completeVerifiedPayment` throws when `!isApplicationPayment()`.

**Webhook is currently application-payment-only.**

---

## 8. Payment Reconciliation

| Component | Role |
|-----------|------|
| `PaymentLifecycleService` | State machine: complete / fail / under_verification; application status transitions; notifications |
| `PaymentReconciliationService::reconcile` | Retrieve Stripe session; validate amount/currency; complete or UV; **rejects non-application payments** |
| `ReconcilePendingPaymentsCommand` | Scheduled; `whereNull('fine_id')` only |
| Citizen `GET .../payments/{payment}/status` | Optional poll; can complete from Stripe if paid |

**Best extension point for Fine → paid:**

Inside `PaymentLifecycleService::completeVerifiedPayment` (after Payment → completed), branch:

- if application payment → existing application workflow
- if fine payment → set Fine `paid` + `paid_at`, notify FinePaid (or dedicated payment+fine), audit

Avoid duplicating completion logic in webhook vs status poll vs reconcile — they already call lifecycle.

---

## 9. Fine ↔ Payment Existing Relationship

| Question | Answer |
|----------|--------|
| Does payments support Fine? | **Yes at schema + Eloquent** |
| FK | `payments.fine_id` → `fines.id` nullable, `nullOnDelete` |
| Constraints | No XOR with application_id |
| Indexes | `(fine_id, status, created_at)` |
| Production create path | **None** for citizen/employee electronic fine pay |
| Existing usage | Seeders (`FullLifecycleKit` paid fine + Payment), tests that prove dashboard **excludes** fine payments (`DashboardPaymentManagementTest::test_list_excludes_fine_linked_payments`) |

---

## 10. Citizen Payment APIs

**Application-scoped only:**

| METHOD | Path | Purpose |
|--------|------|---------|
| GET | `/api/applications/{application}/fee` | Required fee |
| GET | `/api/applications/{application}/payments` | List payments for that application (`whereNull('fine_id')`) |
| POST | `/api/applications/{application}/payments` | Initiate payment / Stripe session |
| GET | `/api/applications/{application}/payments/{payment}/status` | Status (+ optional Stripe poll complete) |
| POST | `/api/applications/{application}/payments/{payment}/confirm` | Mock confirm only |

**No** `/api/payments`, `/api/my-payments`, or `/api/citizen/payments`.

Dashboard: `/api/dashboard/payments*` — employees; application payments only.

---

## 11. Existing Success / Cancel Return Flow

| Item | Status |
|------|--------|
| Public Blade success/cancel pages in repo | **Not found** |
| `routes/web.php` | Dev dashboard only + `/` |
| Config URLs | External `STRIPE_SUCCESS_URL` / `STRIPE_CANCEL_URL` (tests use `http://localhost:3000/payment/success|cancel`) |
| Deep links / custom URL schemes | **Not found** in backend |

Success/cancel pages must be **display-only**; authoritative state remains webhook/reconciliation.

---

## 12. Citizen "My Payments" Existing Support

**Does not exist.** Closest:

- Per-application payment list
- Dashboard global payment list (staff, excludes fines)

A new citizen endpoint is required for “مدفوعاتي”.

---

## 13. Ownership and Authorization

| Layer | Behavior |
|-------|----------|
| Middleware | `auth:sanctum` + `EnsureCitizen` (`isCitizen()` + active) |
| Policies | No Fine/Payment Policy classes; ownership in services |
| Application payments | `requireOwnedApplication` / `citizen_id` match + `user_id` on Payment |
| Fines list | `citizen_id` filter only |

**Recommended for Fine pay (match conventions):**

1. Resolve Fine by id where `citizen_id = auth id` (404 if missing — same as applications).
2. Create Payment with `user_id = citizen`, `fine_id = fine.id`, `application_id = null`.
3. Status endpoints: match `payment.user_id` + `payment.fine_id`.
4. Optionally require `profile.approved` for pay (applications do); list can stay without it like current `GET /fines`.

---

## 14. Amount Integrity

Application pattern is correct and reusable:

- Client sends **no amount** (`StoreApplicationPaymentRequest` → optional `metadata` only).
- Server loads Fee / Fine amount and currency.

**Fine payment must:**

```text
Client → fine id only
Backend → Fine.amount (+ currency policy)
```

**Currency risk:** Fines have no currency column; seeders often use large SYP-like amounts (`75000`) while Stripe path requires USD matching `STRIPE_CURRENCY`. Implementation must define an authoritative fine-payment currency (likely USD catalog or convert/store policy) before Stripe create — otherwise checkout will fail with `provider_currency_unsupported` / RuntimeException.

---

## 15. Idempotency / Duplicate Protection

| Mechanism | Application fees | Reusable for fines? |
|-----------|------------------|---------------------|
| `active_obligation_key` unique | `application:{id}:fee:{id}` | Yes — need `fine:{id}` (or similar) key |
| `settled_obligation_key` unique | same | Yes |
| Reuse pending checkout URL | Yes | Yes |
| Stripe idempotency_key | `dlms-payment-{payment_number}` | Yes |
| Gateway event unique | `payment_gateway_events` | Yes 100% |
| Lifecycle completed short-circuit | Yes | Yes after fine branch added |
| Reject create if settled | Yes | Yes |
| Manual mark paid without Payment | N/A | Must block electronic pay if Fine already `paid` |

---

## 16. Localization

Conventions:

- API messages via keys → `CitizenMessageTranslator` / `__('messages.*')`
- Fine messages under `messages.fines.*`
- Payment messages under `messages.payments.*`
- Catalog fee labels via `CitizenCatalogLabel::fee($code)` → `messages.fees.codes.*`
- Notification titles/bodies: `NotificationType::*Key()` + replace params
- Machine codes (`payment.completed`, fee codes, statuses) stay untranslated in `type` / `code` fields; labels localized separately

Fine payment must add keys (messages + optional catalog purpose label), **no hardcoded AR/EN in controllers**.

---

## 17. Notifications

| Type | When today |
|------|------------|
| `payment.completed` / `failed` / `under_verification` | `PaymentLifecycleService` (application-oriented body with `application_number`) |
| `fine.created` / `fine.paid` / `fine.cancelled` | `FineService` |
| Channels | DB notification + push planning (`NotificationService` → `PushDeliveryService`) |

**Recommendation:**

- On electronic fine settle: keep **`FinePaid`** (already exists; matches employee mark-paid UX) **and/or** extend `PaymentCompleted` data keys to allow `fine_id` without requiring `application_id`.
- Prefer not a brand-new `FinePaymentCompletedNotification` class (project uses enum + NotificationService, not per-feature Notification classes).
- Adjust payment notification copy/data if emitting `PaymentCompleted` for fines (body currently assumes application).

---

## 18. Audit Logging

`AuditLogService::log($actor, $action, $entityType, $entityId, $old, $new)`.

Existing payment actions: `payment.created`, `payment.initiated`, `payment.completed`, `payment.failed`, `payment.under_verification`, `payment.verified`.

Fine actions: `fine.created`, `fine.updated`.

**Later fine electronic pay should log:** payment created/initiated/completed (with `fine_id` in new_values) + fine status transition (or include fine id on payment.completed payload). Follow same pattern; do not invent a parallel activity system.

---

## 19. License Unblock Interaction

Unpaid fines are checked **live** against `fines.status = unpaid` for the citizen (no cache table).

| Checkpoint | Location |
|------------|----------|
| Create unblock eligibility | `LicenseServiceEligibilityService::checkUnblock` |
| Final employee unblock from application | `ApplicationUnblockService::assertReadyForUnblockAction` |
| Direct admin license unblock | `LicenseService::unblock` |
| Issue license readiness | `LicenseIssuanceEligibilityService` |
| Renew path | `LicenseService::assertCitizenHasNoUnpaidFines` |

**If Fine becomes `paid`:** next eligibility check passes automatically. No separate cache/state to clear.

Paying a fine does **not** auto-unblock a license; it only removes the unpaid-fine blocker for subsequent unblock/issue/renew flows.

---

## 20. Existing Automated Tests

| Test file | Covers | DB | Provider |
|-----------|--------|----|----------|
| `tests/Feature/PaymentFlowTest.php` | Mock create + confirm; application status | mysql `dlms_testing` (RefreshDatabase) | mock |
| `tests/Feature/PaymentStripeTest.php` | Checkout URL, webhook complete/idempotent/expired, ownership, duplicate | mysql | mocked Stripe gateway + signed webhook |
| `tests/Feature/PaymentConcurrencyAndIntegrityTest.php` | Concurrent create / integrity | mysql | mock/stripe fixtures |
| `tests/Feature/PaymentReconciliationAndDbInvariantEvidenceTest.php` | Reconcile + DB unique keys | mysql | mocked Stripe |
| `tests/Feature/DashboardPaymentManagementTest.php` | Dashboard list excludes fine payments; verify | mysql | mock/stripe mock |
| `tests/Feature/ApplicationFeeUsdCatalogTest.php` | USD catalog, amount/currency mismatch → UV | mysql | stripe mock |
| `tests/Feature/LicenseFlowTest.php` | Admin create/mark fine paid; `GET /api/fines`; unpaid blocks issue | mysql | N/A |
| `tests/Feature/LicenseUnblockFlowTest.php` | Unpaid fines block create + final unblock | mysql | N/A |
| `tests/Feature/CitizenBilingualMessagesTest.php` | `/api/fines` messages AR/EN | mysql | N/A |
| Notification* tests | FineCreated/FinePaid event keys | mysql | N/A |

No dedicated `FinePayment*Test` yet.

---

## 21. Reusable Components (100% or near)

- `Payment` model + migrations (`fine_id`, obligation unique keys, gateway events)
- `PaymentGatewayEventService` + webhook signature/reserve pattern
- `PaymentProviderManager` / config / env
- `Money` / amount mismatch validation pattern
- `AuditLogService`, `NotificationService`, `FineStatus`, `FineResource` base fields
- Citizen envelope `{ success, message, data }` via `ApiResponse`
- Ownership-by-query conventions
- Mock confirm pattern (if fine mock pay desired for tests)
- Dashboard separation of fine payments (already excluded)

---

## 22. Missing Components

- Citizen `GET /api/fines/{fine}`
- Citizen initiate fine payment + status endpoints
- `FinePaymentService` (or generalized payable payment service)
- Fine obligation key helper
- Stripe checkout path without Fee/Application
- Lifecycle branch for fine completion (Fine → paid)
- Webhook/status/reconcile finders that include fine payments
- Citizen My Payments list (+ optional show)
- Success / cancel / processing public pages (optional but requested)
- Localization keys for fine payment purpose labels
- Feature tests for fine Stripe + mock flows
- Currency policy for fines vs Stripe USD

---

## 23. Recommended Backend API Contract

Align with existing nested citizen routes (not invent `/api/citizen/...`):

```http
GET    /api/fines
GET    /api/fines/{fine}
POST   /api/fines/{fine}/payments
GET    /api/fines/{fine}/payments/{payment}/status
GET    /api/payments
GET    /api/payments/{payment}
```

Notes:

- Mirror applications: nested create/status under parent entity; top-level My Payments for cross-purpose history.
- Middleware: same as applications for pay (`profile.approved` + throttle); list/detail like current fines.
- Mock: optional `POST /api/fines/{fine}/payments/{payment}/confirm` only when provider=mock (parity with applications).

---

## 24. Recommended Implementation Architecture

```text
FineController::show / storePayment / paymentStatus
  → FinePaymentService (new; mirror ApplicationPaymentService)
       createPendingPayment(Fine): amount from Fine, currency from policy
       obligationKey = "fine:{id}"
  → StripePaymentGatewayService::createCheckoutSessionForPayment(...)  // generalize
  → StripeWebhookController (unchanged entry)
       → find payment WITHOUT whereNull('fine_id') only, or dual finder
  → PaymentLifecycleService::completeVerifiedPayment
       if isApplicationPayment → existing
       if fine payment → Fine paid + paid_at + notify/audit
  → PaymentResource (+ purpose fields for My Payments)
```

Do **not** add: second webhook, FineStripeService as separate Stripe client, parallel payment tables.

---

## 25. Database Changes Required — if any

| Change | Needed? |
|--------|---------|
| Add `fine_id` column | **No** — exists |
| XOR check constraint application vs fine | Optional hardening (not required for GO) |
| `fines.currency` | **Recommended** if fines are not always USD; otherwise document fixed currency for fine Stripe payments |
| Fine obligation keys | No schema change if reusing `active_obligation_key` / `settled_obligation_key` strings |
| New tables | **No** |

Minimal path: **no migration** if currency policy is “fine Stripe payments always USD and Fine.amount stored as USD”. Safer product path: add `fines.currency` + backfill.

---

## 26. Files Expected to Change

| Area | Files (expected) |
|------|------------------|
| Routes | `routes/api.php` |
| Fines | `FineController`, possibly `FineService`/`FineRepository`, new requests |
| Payments | New `FinePaymentService`; `PaymentLifecycleService`; `StripePaymentGatewayService`; `ApplicationPaymentService` finders **or** shared Stripe completion helper; `Payment` helper for fine obligation key; `PaymentResource` |
| Webhook | Likely thin changes via service finders only |
| Reconcile command | Include fine Stripe payments if desired |
| Lang | `resources/lang/{ar,en}/messages.php` |
| Views (optional) | New Blade success/cancel/processing |
| Config | Possibly success URLs / fine currency |
| Tests | New FinePayment* + regression suites below |
| Docs/Postman | Flutter contract + collection (later step) |

---

## 27. Tests That Must Be Added

- Citizen cannot pay another citizen’s fine (404)
- Pay unpaid fine → pending Payment + checkout_url (stripe mock)
- Amount/currency from backend only
- Reuse active pending; reject if Fine already paid / settled obligation
- Webhook completes Payment + Fine → paid + paid_at
- Duplicate webhook idempotent
- Mock confirm path (if implemented)
- My Payments lists application + fine payments for owner only
- After pay, unblock eligibility no longer blocked by that fine
- Employee mark-paid still works; electronic pay blocked if already paid

---

## 28. Regression Tests That Must Be Run

```text
php artisan test --filter=PaymentFlowTest
php artisan test --filter=PaymentStripeTest
php artisan test --filter=PaymentConcurrencyAndIntegrityTest
php artisan test --filter=PaymentReconciliationAndDbInvariantEvidenceTest
php artisan test --filter=DashboardPaymentManagementTest
php artisan test --filter=ApplicationFeeUsdCatalogTest
php artisan test --filter=LicenseFlowTest
php artisan test --filter=LicenseUnblockFlowTest
php artisan test --filter=CitizenBilingualMessagesTest
```

---

## 29. Risks / Edge Cases

1. **Webhook silently no-ops** on fine payments until finders + lifecycle updated.
2. **Currency mismatch** Fine amounts (SYP-like) vs Stripe USD.
3. **Employee mark paid without Payment** vs citizen electronic Payment — double settlement rules.
4. **Both application_id and fine_id set** possible at DB level.
5. **PaymentCompleted notification** assumes application_number.
6. **Dashboard still excludes fine payments** — intentional today; clarify product if staff need visibility.
7. **Success page must not mark paid.**
8. **Cancelled fines** must not be payable.
9. **Soft-deleted fines** should 404 for citizen pay.
10. Concurrent double-click: rely on `active_obligation_key` unique like applications.

---

## 30. Final GO / NO-GO Recommendation

### Architecture matrix

| Capability | Exists | Reusable | Change Required | File(s) |
| ----------------------------- | -----: | -------: | --------------: | ------- |
| Citizen fines list | Yes | Yes | No (optional filters later) | `FineController`, `FineRepository` |
| Citizen fine detail | No | Partial (`FineResource`) | Yes — new show | `FineController`, routes |
| Fine ownership | Yes | Yes | Wire into pay | `FineRepository` / FinePaymentService |
| Fine payment relation | Schema yes / runtime no | Yes | Yes — create path | `Payment`, FinePaymentService |
| Stripe checkout | Yes (apps) | Partial | Yes — Fine session | `StripePaymentGatewayService` |
| Stripe webhook | Yes | Yes entry | Yes — finders + complete | `ApplicationPaymentService` / shared, `PaymentLifecycleService` |
| Payment reconciliation | Yes (apps) | Partial | Yes — allow fine | `PaymentReconciliationService`, command |
| Fine → paid transition | Employee only | Partial (`FineService`) | Yes — from lifecycle | `PaymentLifecycleService`, optionally `FineService` |
| Payment notification | Yes | Partial | Adjust data/copy for fine | `PaymentLifecycleService`, lang, `NotificationType` data keys |
| Success page | No (env URL only) | — | Optional create | Blade + web route |
| Cancel page | No | — | Optional create | Blade + web route |
| My Payments list | No | Partial (`PaymentResource`) | Yes | New controller/service, routes |
| Payment detail | App-nested / dashboard only | Partial | Yes citizen show | routes + resource |
| License unblock compatibility | Yes (live unpaid check) | Yes | No change if Fine→paid | `*Unblock*`, eligibility services |

### Proposed end-to-end flow (real names)

```text
Flutter
  ↓
GET /api/fines/{fine}  → FineController / FineService / FineResource
  ↓
POST /api/fines/{fine}/payments  → FinePaymentService::createPendingPayment
  ↓ ownership: Fine.citizen_id == auth
  ↓ Payment (fine_id set, application_id null, active_obligation_key=fine:{id})
  ↓
StripePaymentGatewayService (extended checkout)
  ↓
checkout_url → Flutter opens Stripe
  ↓
POST /api/webhooks/stripe → StripeWebhookController
  ↓ PaymentGatewayEventService::reserve
  ↓ completeStripePaymentFromSession (finder includes fine payments)
  ↓ PaymentLifecycleService::completeVerifiedPayment
  ↓ Payment.status=completed; Fine.status=paid; Fine.paid_at=now
  ↓ NotificationService (FinePaid / PaymentCompleted) + AuditLogService
  ↓
STRIPE_SUCCESS_URL page (display only)
  ↓
Flutter GET status / GET fines / GET payments → refresh UI
```

### Final verdict answers

1. **Suitable without redesigning Payment system?** Yes — additive extension of existing Payment + Stripe stack.  
2. **100% reusable?** Webhook entry, gateway events, Payment schema/`fine_id`, Money validation pattern, audit, notification infrastructure, Sanctum/citizen middleware, Fine list/resource, mock/stripe provider switch.  
3. **Files needing change?** See §26 (routes, FinePaymentService, lifecycle, Stripe gateway, finders, resources, lang, tests; optional Blade).  
4. **New migration?** Not strictly required for FK; **recommended** if fine currency must be stored; optional XOR constraint.  
5. **Webhook need change?** Yes — payment resolution + completion must handle fine payments (controller can stay thin).  
6. **PaymentResource need change?** Yes for My Payments purpose/title/related entity; keep additive fields.  
7. **FineResource need change?** Optional (e.g. expose whether payable / active payment); show endpoint needed.  
8. **Fine detail endpoint required?** Yes for the described Flutter flow.  
9. **My Payments endpoint required?** Yes — none exists.  
10. **Success/cancel pages?** Not in repo; create display-only pages or host externally via env URLs.  
11. **Fine payment with Mock in tests?** Yes, if implement mock confirm parity like applications.  
12. **Paying fine auto-enables unblock?** Removes unpaid-fine blocker on next check; does not auto-unblock license.  
13. **Smallest safe implementation?** Fine show + FinePaymentService (obligation keys) + generalize Stripe session + lifecycle fine branch + fix webhook finders + status endpoint + My Payments list + tests; optional Blade pages.  
14. **Suggested implementation order after audit:**  
    (1) Currency policy decision  
    (2) Fine obligation + FinePaymentService create/reuse  
    (3) Stripe session generalization  
    (4) Lifecycle Fine→paid  
    (5) Webhook/status/reconcile finders  
    (6) Citizen APIs (show, pay, status, my payments)  
    (7) Localization + notification data  
    (8) Success/cancel pages  
    (9) Feature + regression tests  
    (10) Postman / Flutter docs  

**Overall: GO** — proceed to Step 2 design/implementation after reviewing this audit; do not fork a second payment system.

---

*End of audit. No application code was modified in this step; only this documentation file was added.*
