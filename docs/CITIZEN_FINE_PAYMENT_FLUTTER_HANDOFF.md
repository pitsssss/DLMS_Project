# Citizen Fine Payment — Flutter Backend Handoff

Backend Steps 2–5 are complete. This document is the **Flutter integration contract**.

Canonical Postman kit:

- Collection: `postman/SYRTAK_Flutter_API.postman_collection.json`
- Environment: `postman/SYRTAK_Local.postman_environment.json`

Related folders in the collection:

| Folder | Contents |
|--------|----------|
| `09 - Fines` | List, detail, pay, status, mock confirm |
| `09b - My Payments` | History list, detail, filters |
| `09c - Payment Return Pages` | Public browser pages (QA only) |

Application fee payments remain under `06 - Payments` (unchanged).

---

## Screen flow

```text
A. My Fines
B. Fine Detail
C. Stripe Checkout (system browser / WebView — Flutter team choice)
D. SYRTAK payment result page (backend HTML — display only)
E. My Payments
F. Payment Detail (optional)

Flow:
My Fines
  → Fine Detail
  → Pay Fine
  → checkout_url (Stripe)
  → browser / Stripe Checkout
  → SYRTAK /payment/success|processing|cancel
  → citizen returns to app
  → Flutter refreshes backend APIs (status + Fine detail)
```

**Critical rule:** Returning to `/payment/success` in the browser is **not** proof of payment. Only authenticated backend status is authoritative.

---

## A. My Fines

```http
GET /api/fines
Authorization: Bearer {citizen_token}
Accept-Language: ar|en
```

Use to render:

- `amount`, `currency`, `reason`, `status`
- Pay CTA only when backend says the Fine is payable (`status = unpaid` and/or `is_payable = true`)

Do not invent payable rules in the client — backend still enforces on pay.

---

## B. Fine Detail

```http
GET /api/fines/{fine}
```

Foreign Fine → **404** (non-disclosing).

Authoritative fields:

| Field | Notes |
|-------|--------|
| `amount` | Display amount |
| `currency` | e.g. `USD` (machine code) |
| `status` | `unpaid` \| `paid` \| `cancelled` |
| `reason` | Text |
| `paid_at` | ISO when paid |
| `is_payable` | UI hint for Pay button |

Show **Pay Fine** only when unpaid/payable. For `paid` / `cancelled`, do not start payment.

---

## C. Pay Fine

```http
POST /api/fines/{fine}/payments
Content-Type: application/json

{}
```

**Do not send** `amount`, `currency`, or `fine_id` in the body. Fine ID is the route param. Amount/currency are copied from the Fine on the server.

### Stripe response (`PAYMENT_PROVIDER=stripe`)

```json
{
  "success": true,
  "message": "...",
  "data": {
    "payment": {
      "id": 123,
      "payment_number": "PAY-...",
      "amount": "25.00",
      "currency": "USD",
      "status": "pending",
      "provider": "stripe"
    },
    "provider": "stripe",
    "checkout_url": "https://checkout.stripe.com/...",
    "publishable_key": "pk_..."
  }
}
```

Flutter should:

1. Store `data.payment.id` for polling.
2. Open `data.checkout_url` (do not build Stripe URLs; do not send card data to Laravel).

### Mock response (`PAYMENT_PROVIDER=mock`)

`data` is the Payment resource directly (includes `id`, `amount`, `currency`, `status`). Then use mock confirm **only in local/dev**.

---

## D. Open Checkout & return pages

After Stripe Checkout, the browser lands on backend pages:

| URL | Meaning |
|-----|---------|
| `GET /payment/success?session_id=cs_...&lang=ar\|en` | Display success **or** processing based on **local** Payment status |
| `GET /payment/processing?lang=...` | Confirming / verifying messaging |
| `GET /payment/cancel?lang=...` | Citizen left Checkout |

These pages:

- are **public HTML**
- are **display-only**
- **never** mark Payment completed or Fine paid
- are **not** Flutter API calls

Locale is preserved via `lang=` from the Fine Checkout creation (same as `Accept-Language` on the pay call).

Deep links / “Open app” buttons are **out of scope** for now — instruct the user to close the page and return to the app.

---

## E. Payment completion (authoritative)

After the citizen returns to the app:

```http
GET /api/fines/{fine}/payments/{payment}/status
```

Machine `status` values:

| Status | Flutter UX |
|--------|------------|
| `pending` | Processing / pull-to-refresh |
| `completed` | Success; refresh Fine |
| `failed` | Allow retry (new Pay Fine after failed attempt) |
| `under_verification` | “Payment under verification” — do not claim success |

Then refresh Fine:

```http
GET /api/fines/{fine}
```

Expected when settled:

```text
status = paid
paid_at != null
```

Webhook + reconciliation remain the source of truth. Status poll may help settle Stripe sessions; still do not invent completion in the client.

---

## F. Mock confirm — local only

```http
POST /api/fines/{fine}/payments/{payment}/confirm
```

- **Only** when `PAYMENT_PROVIDER=mock`
- **Must not** ship in production Stripe builds
- Stripe production completion = webhook / reconciliation / status poll

Postman request name: `Confirm Fine Payment [Mock Only]`

---

## G. My Payments (مدفوعاتي)

Sidebar entry: **مدفوعاتي / My Payments**

```http
GET /api/payments
GET /api/payments?page=1&per_page=15
```

Optional filters:

```http
GET /api/payments?status=completed
GET /api/payments?type=fine
GET /api/payments?type=application
```

List item fields for UI:

| Field | Use |
|-------|-----|
| `purpose.label` | Title (localized) |
| `purpose.code` | Machine code (`fine`, `application_fee`, `unblock_fee`, …) |
| `amount` / `currency` | **Historical Payment** amounts — not live Fine/Fee catalog |
| `status` | Machine code |
| `status_label` | Localized display |
| `paid_at` | Prefer for completed “payment date” |
| `created_at` | Fallback for pending/failed |
| `related.type` / `related.id` | Navigation hints (`fine` \| `application`) |

**Do not** rebuild titles from `fine_id` / fee codes in Flutter.

Empty list → HTTP **200** + empty `items` + pagination (not 404).

### Optional Payment Detail

```http
GET /api/payments/{payment}
```

Foreign payment → **404**.

Use `detail` according to `related.type`. Do not display raw `metadata` (API does not expose it on this resource).

---

## Locale

Continue sending:

```http
Accept-Language: ar
```

or

```http
Accept-Language: en
```

matching the in-app language. Fine Stripe return pages inherit that locale via `lang=` on the success/cancel URLs.

Machine codes (`completed`, `fine`, `USD`, `stripe`) stay untranslated.

---

## License unblock relationship

After a Fine payment succeeds:

- Fine becomes `paid`
- Later **license unblock eligibility** no longer sees that Fine as an unpaid blocker

Fine payment does **not** automatically unblock a license. Unblock remains its own application/service flow.

---

## Manual E2E — Stripe

```text
1. Login as demo.fine.happy@syrtak.local (after migrate:fresh --seed)
2. Get My Fines → select FINE-01 ([CFP-FINE-01]) — fresh unpaid Fine with no pending Payment
   Do not pick an arbitrary unpaid Fine (FINE-02 / FINE-OTHER have seeded pending fixtures)
3. Get Fine Detail
4. Pay Fine → copy checkout_url
5. Open checkout_url → complete Stripe Checkout
6. See SYRTAK success/processing page
7. Return to app / Postman
8. Get Fine Payment Status → completed
9. Get Fine Detail → paid + paid_at
10. Get My Payments → Fine payment listed
```

## Manual E2E — Mock

```text
1. Login as demo.fine.happy@syrtak.local
2. Get unpaid Fine FINE-01 (fresh; no seeded pending Payment)
3. Pay Fine
4. Confirm Fine Payment [Mock Only]
5. Get Fine Payment Status
6. Get Fine Detail
7. Get My Payments
```

Same final domain state: Payment `completed`, Fine `paid`.

---

## Production backend configuration (ops)

```text
APP_URL=https://<public-https-backend-origin>
PAYMENT_PROVIDER=stripe
FINE_CURRENCY=USD
STRIPE_PUBLISHABLE_KEY=...
STRIPE_SECRET_KEY=...
STRIPE_WEBHOOK_SECRET=...
STRIPE_CURRENCY=usd
```

**APP_URL warning:** Fine Checkout return URLs are built from `APP_URL`. If production still uses `http://127.0.0.1:8000`, Stripe redirects will be wrong. Set the real public HTTPS backend origin. Do not hardcode the domain in app code.

Application fee Stripe (folder `06 - Payments`) still uses:

```text
STRIPE_SUCCESS_URL
STRIPE_CANCEL_URL
```

when that flow is Stripe-enabled.

Never commit real secrets into Postman or this repo.

---

## What Flutter must not do

- Send amount/currency on Pay Fine
- Treat browser success page as payment proof
- Call mock confirm in production Stripe builds
- Reconstruct My Payments titles from IDs
- Assume Fine pay auto-unblocks a license
- Send card data to Laravel
- Depend on raw payment metadata / checkout internals in My Payments

---

## Backend remaining / out of scope

Not in this handoff:

- Deep links / app links
- Receipt PDF
- Refunds
- AI Agent fine payment tools
- Bulk fine payment
- Flutter UI implementation
