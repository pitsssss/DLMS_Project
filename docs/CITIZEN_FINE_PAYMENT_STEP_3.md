# Citizen Fine Payment — Step 3

## 1. Scope implemented

Core citizen fine-payment E2E backend vertical slice:

- Fine detail
- Fine payment create (mock + Stripe)
- Mock confirm
- Payment status polling
- Stripe webhook settlement
- Lifecycle Fine → paid
- Reconciliation + scheduled reconcile for fine payments
- Ownership, amount/currency integrity, race policies
- AR/EN messages
- Automated tests

**Not in this step:** My Payments, Blade success/cancel pages, Flutter, AI Agent, refunds, bulk pay.

## 2. Architecture used

Extended existing Payment + Stripe stack. No second webhook/client/table.

```text
FinePaymentController / FineController
  → FinePaymentService
  → Payment (fine_id, obligation keys)
  → StripePaymentGatewayService::createFineCheckoutSession
  → StripeWebhookController → ApplicationPaymentService finders
  → PaymentLifecycleService (application | fine branch)
```

## 3. Routes added

| Method | Path | Middleware |
|--------|------|------------|
| GET | `/api/fines/{fine}` | `auth:sanctum`, `locale`, `citizen` |
| POST | `/api/fines/{fine}/payments` | + `profile.approved`, `throttle:15,1` |
| GET | `/api/fines/{fine}/payments/{payment}/status` | `auth:sanctum`, `locale`, `citizen` |
| POST | `/api/fines/{fine}/payments/{payment}/confirm` | + `profile.approved`, `throttle:15,1` (mock only) |

## 4. FinePaymentService behavior

- Lock Fine, re-check ownership + unpaid
- Obligation key `fine:{id}`
- Reuse active pending / reject settled
- Copy `Fine.amount` + `Fine.currency` → Payment
- Stripe session or mock pending
- Status poll can complete via Stripe retrieve + lifecycle
- Mock confirm disabled when provider=stripe

## 5. Obligation / idempotency

- `Payment::fineObligationKey($fineId)` → `fine:{id}`
- Unique `active_obligation_key` / `settled_obligation_key`
- Stripe idempotency key `dlms-payment-{payment_number}`
- Gateway event reserve uniqueness unchanged

## 6. Stripe checkout changes

- Shared private builder in `StripePaymentGatewayService`
- `createCheckoutSession` (application) preserved
- `createFineCheckoutSession` added
- Metadata: `payment_type=fine`, `fine_id`, `payment_id`, `payment_number`, `citizen_id`
- Localized product name/description via `messages.payments.stripe_*`

## 7. Webhook changes

- Finders no longer `whereNull('fine_id')`
- Resolve only `isSupportedPayable()` (application XOR fine shape)
- Controller remains thin

## 8. Lifecycle changes

- `completeVerifiedPayment` branches application vs fine
- Fine settlement: Payment completed + Fine paid + `paid_at`
- Citizen notification: **FinePaid only** (not PaymentCompleted) for electronic fine settle

## 9. Reconciliation changes

- `PaymentReconciliationService` accepts fine payments
- `payments:reconcile-pending` includes valid fine Stripe rows

## 10. Fine settlement behavior

Atomic in transaction: lock Payment + Fine → complete payment → set Fine paid/paid_at → audit → FinePaid notify

## 11. Race-condition policy

| Race | Policy |
|------|--------|
| Fine already paid (employee) before webhook | Complete Payment as financial evidence; do **not** re-transition Fine; no second FinePaid |
| Fine cancelled before provider completion | Payment → `under_verification` (`workflow_conflict`); Fine stays cancelled; no silent paid |

## 12. Notifications

- Electronic settle → `NotificationType::FinePaid` (event key `fine.paid:fine:{id}`)
- Fail / under_verification → existing PaymentFailed / PaymentUnderVerification (allowed `fine_id`)

## 13. Audit events

- `payment.created` / `payment.initiated` / `payment.completed` / `payment.failed` / `payment.under_verification` (with `fine_id`)
- `fine.updated` on electronic unpaid → paid

## 14. Localization

New keys under `messages.fines.*` and `messages.payments.stripe_*` in AR + EN.

## 15. Security / ownership

- Fine show/pay/status/confirm: `citizen_id` match → else 404
- Payment must match `user_id`, `fine_id`, `application_id IS NULL`

## 16. Tests added

- `tests/Feature/FinePaymentFlowTest.php` (10)
- `tests/Feature/FinePaymentStripeTest.php` (7)

## 17. Tests run (serial, exclusive `dlms_testing`)

| Suite | Result |
|-------|--------|
| FinePaymentFlowTest | PASS |
| FinePaymentStripeTest | PASS |
| PaymentFlowTest | PASS |
| PaymentStripeTest | PASS |
| PaymentConcurrencyAndIntegrityTest | PASS |
| PaymentReconciliationAndDbInvariantEvidenceTest | PASS |
| DashboardPaymentManagementTest | PASS |
| ApplicationFeeUsdCatalogTest | PASS |
| LicenseFlowTest | PASS |
| LicenseUnblockFlowTest | PASS |
| CitizenBilingualMessagesTest | PASS |
| FineCurrencyFoundationTest | PASS |

## 18. Full suite status

```text
php artisan test
→ PASS (serial run after exclusive dlms_testing reset)
```

## 19. Files changed (main)

- `routes/api.php`
- `app/Models/Payment.php`
- `app/Modules/Payments/Services/{FinePaymentService,PaymentLifecycleService,StripePaymentGatewayService,ApplicationPaymentService,PaymentReconciliationService}.php`
- `app/Modules/Payments/Controllers/FinePaymentController.php`
- `app/Modules/Payments/Requests/{Store,Confirm}FinePaymentRequest.php`
- `app/Modules/Fines/{Controllers/FineController,Services/FineService,Resources/FineResource}.php`
- `app/Console/Commands/ReconcilePendingPaymentsCommand.php`
- `app/Enums/NotificationType.php`
- `resources/lang/{en,ar}/messages.php`
- New feature tests + this doc

## 20. Remaining Step 4 work

- `GET /api/payments` (مدفوعاتي)
- Payment purpose/title in PaymentResource
- Branded success/cancel/processing pages
- Flutter integration
- AI Agent pay_fine (optional later)
