# SYRTAK Premium Driving License Implementation

## 1. Summary

The issued-license experience is now an ID-1 landscape credential (`85.60 mm × 53.98 mm`) instead of an A4 report or a generic dashboard panel.

Dashboard preview, employee print PDF, and citizen PDF download share one visual language and one Backend renderer. Portrait comes from approved application documents. QR still encodes only `{LICENSE_VERIFICATION_PUBLIC_URL}/{verification_token}`. Public verification JSON is unchanged. Issuance, eligibility, tokens, payments, and status rules were not redesigned.

## 2. Files Changed

### Backend (`DLMS_Project`)

- `app/Modules/Licenses/Support/LicensePortraitResolver.php` (new)
- `app/Modules/Licenses/Support/DigitalLicensePresenter.php`
- `app/Modules/Licenses/Services/LicensePrintService.php`
- `app/Modules/Licenses/Controllers/LicenseController.php`
- `app/Modules/Dashboard/Controllers/DashboardIssuedLicenseController.php`
- `app/Modules/Dashboard/Services/DashboardIssuedLicenseService.php`
- `app/Modules/Dashboard/Routes/dashboard.php`
- `routes/api.php`
- `resources/views/licenses/digital-card.blade.php`
- `resources/lang/ar/messages.php`
- `resources/lang/en/messages.php`
- `.env.example`
- `public/branding/syrtak-license-logo.png` (copy of Dashboard `public/logos/image.png`)
- `postman/SYRTAK_Flutter_API.postman_collection.json`
- `tests/Feature/LicensePrintingTest.php`
- `tests/Feature/LicensePortraitEndpointTest.php` (new)
- `tests/Feature/LicenseCitizenDownloadTest.php` (new)
- `docs/LICENSE_CARD_REDESIGN_IMPLEMENTATION.md` (this file)

### Dashboard (`DLMS_Dashboard`)

- `src/features/licenses/components/DigitalLicenseCard.tsx`
- `src/features/licenses/components/LicenseCardFront.tsx` (new)
- `src/features/licenses/components/LicenseCardBack.tsx` (new)
- `src/features/licenses/components/LicensePortrait.tsx` (new)
- `src/features/licenses/components/LicenseQrCode.tsx` (new)
- `src/features/licenses/components/LicenseField.tsx` (new)
- `src/features/licenses/components/LicenseStatusMark.tsx` (new)
- `src/features/licenses/components/LicenseSecurityPattern.tsx` (new)
- `src/features/licenses/LicenseDetailsPage.tsx`
- `src/features/licenses/utils/licenseFormatters.ts`
- `src/api/dashboardLicensesApi.ts`
- `src/lib/api/endpoints.ts`
- `src/types/licenseManagement.ts`
- `src/features/public-license-verify/PublicLicenseVerifyPage.tsx`
- `src/features/public-license-verify/components/PublicVerifyLicenseCard.tsx`
- `src/features/public-license-verify/components/PublicVerifyStatusHero.tsx`
- `src/features/public-license-verify/components/PublicVerifyTrustNote.tsx`

`src/features/applications/ApplicationDetailsPage.tsx` was already dirty in this working tree from unrelated unblock work and was not part of this redesign.

## 3. Final Architecture

```text
Backend
├── LicensePortraitResolver     approved JPEG/PNG from application docs
├── DigitalLicensePresenter     card payload, labels, verification URL/host
├── LicensePrintService
│   ├── renderCredentialPdf()   shared ID-1 front/back mPDF
│   ├── printPdf()              employee print metadata
│   └── downloadPdf()           citizen download, no print_count
└── resources/views/licenses/digital-card.blade.php

Dashboard
├── DigitalLicenseCard          front/back tabs; both sides on xl
├── LicenseCardFront / Back
├── LicensePortrait             blob object URL from protected endpoint
└── LicenseQrCode               encodeQR from existing `qr` package
```

Issuance remains `POST /api/admin/applications/{application}/issue-license`. Domain classes listed in the audit were not refactored.

## 4. Portrait Resolution

`LicensePortraitResolver` is read-only.

Path: license → source application → latest approved `personal_photo` / `recent_personal_photo` → local private disk → JPEG/PNG bytes.

Priority:

- `new_license`: `personal_photo`, then `recent_personal_photo`
- renew / replacement / other: `recent_personal_photo`, then `personal_photo`

If the current application has no usable image, the resolver walks `previous_license_id` (max 12 hops). Rejected, pending, PDF, and files outside the local disk root are skipped.

Missing portrait: Dashboard and PDF show a neutral silhouette. The portrait endpoint returns 404 JSON (`messages.licenses.portrait_unavailable`). PDF generation does not fail.

Public verification never receives portrait bytes or a portrait URL.

## 5. Dashboard Front Card

ID-1 aspect ratio (`85.6 / 53.98`), max width ~760px, SYRTAK greens and gold `#b9a779`.

Shows: logo, configured authority/title, portrait, holder name, license number (`dir="ltr"` + mono), type (+ subtle code), status, issue/expiry.

Invalid licenses get a translucent `غير سارية / INVALID` overlay from existing effective status (`is_valid`).

## 6. Dashboard Back Card

Verification-first: branding, bilingual verify heading, license identity fields, high-contrast QR, host extracted from the real verification URL (localhost / `.local` / `.test` are replaced by the fallback “منصة سيرتك / SYRTAK”, not shown as an official production host).

## 7. Dashboard QR

`LicenseQrCode` uses the existing `qr` dependency (`encodeQR`), ECC medium, quiet zone `border: 4`, `#002623` on white, no logo overlay.

Payload is exactly `digital_license.verification_url` from the Backend. Fines QR was not changed.

## 8. PDF Front

mPDF custom format `[85.60, 53.98]`, zero margins, two pages. Page 1 is the front credential (tables, DejaVu, Backend copy of the SYRTAK logo, portrait or silhouette). Arabic labels come from `licenses.digital.*`.

## 9. PDF Back

Page 2: QR PNG from `endroid/qr-code` (280px, margin 12), verification instruction, official host or fallback brand text, license number/type/dates/status, security-inspired line pattern. Same URL semantics as before.

## 10. Citizen PDF Download API

```text
POST /api/licenses/{license}/download
```

- Middleware: `auth:sanctum`, `locale`, `citizen`
- Ownership: `LicenseService::showForCitizen` (same 404 as `GET /api/licenses/{license}`)
- Renderer: `LicensePrintService::renderCredentialPdf()` → same Blade as employee print
- Does **not** increment `print_count`, `printed_at`, or `printed_by`
- Writes audit action `license.downloaded` with `{ source: citizen }`
- Filename: `SYRTAK-License-{license_number}.pdf`
- Headers: `Content-Type: application/pdf`, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`
- Throttle: `15,1`

POST was chosen to match the existing employee print architecture (operational, non-cacheable document).

## 11. Public Verification UI

`/licenses/verify/[verificationToken]` was restyled to the same SYRTAK palette: official header + logo, VALID/INVALID hero, bilingual public fields, verification timestamp, trust note.

Still no portrait, national ID, DOB, phone, email, or address. Copy does not claim cryptographic QR signatures, blockchain, NFC, or anti-counterfeit certification.

`GET /api/licenses/verify/{token}` contract is unchanged.

## 12. Localization

Backend keys under `licenses.digital.*` (authority, title, bilingual field labels, front/back, invalid, verify copy). Compact AR/EN labels appear together on the credential.

Dashboard chrome stays Arabic RTL. Credential labels are bilingual without introducing a Dashboard i18n framework.

Machine codes (`private`, `public`, `truck`, `bus`) remain untranslated; localized type names still come from existing catalog data.

## 13. Security Boundaries Preserved

- No public filesystem URL for portraits
- No portrait or extra PII on public verify JSON
- No Authorization tokens in query strings (blob fetch uses the existing Axios client)
- QR payload remains URL + 48-character token
- Private document storage path confinement in the resolver
- Citizen download is authenticated and ownership-scoped
- Employee portrait requires `view_licenses` or `manage_licenses`

## 14. Compatibility

Existing licenses keep working with missing portrait, backfilled verification tokens, renewal/replacement lineage, blocked/expired/renewed states. One visual template is used for new, renew, lost, and damaged licenses. No schema migration.

## 15. Tests Added

- `tests/Feature/LicensePortraitEndpointTest.php`
  - authorized JPEG response + `nosniff`
  - unauthenticated 401
  - unauthorized dashboard employee 403
  - no usable image → 404
- `tests/Feature/LicenseCitizenDownloadTest.php`
  - own license → 200 `%PDF`
  - foreign citizen → 404
  - unauthenticated → 401
  - blocked payload `is_valid = false`; download still 200
  - QR URL still `{public_url}/{token}`
  - citizen download does not set `print_count` / `printed_by`
- `LicensePrintingTest` still checks PDF, QR shape, print metadata, permissions, plus `is_valid` / `labels` / `has_portrait` and a larger binary after the ID-1 conversion

## 16. Regression Results

### Baseline (before edits)

Combined filter (first six suites): **40 passed**.  
`OtherLicenseServicesFlowTest`: **15 passed**.  
Baseline license group: **55 passed**.

### After implementation

```text
php artisan test --filter="LicenseFlowTest|LicensePrintingTest|LicenseVerificationTest|LicenseVerificationLocalizationTest|DashboardLicenseIssuanceQueueTest|DashboardIssuedLicensesTest|OtherLicenseServicesFlowTest|LicenseUnblockFlowTest|LicenseExpirySyncTest|LicensePortraitEndpointTest|LicenseCitizenDownloadTest"
```

**91 passed (662 assertions).** Duration ~44s.

Unauthenticated helper tests originally inherited `Sanctum::actingAs` from issuance setup; they now call `$this->app['auth']->forgetGuards()` so they assert true guests.

Full `php artisan test` (entire suite) was not required to complete this feature; the license-related group above is the contract for this change.

## 17. Dashboard Build Results

Commands from `package.json`: `lint` (`eslint`), `build` (`next build`). There is no `typecheck` script; `npx tsc --noEmit` was run as well.

| Command | Result |
|---|---|
| `npm run lint` | PASS (0 errors; 2 pre-existing warnings in `FinesPage.tsx`) |
| `npx tsc --noEmit` | PASS |
| `npm run build` | PASS (compiled, linted, 34 pages generated) |

## 18. Runtime Visual Verification

**UNKNOWN WITHOUT RUNTIME**

Laravel was already serving on this machine, but this pass did not complete an authenticated employee browser session against a running Next.js Dashboard. No browser automation was available. Visual acceptance (portrait, QR scan, PDF pages, responsive layout) must be confirmed with the manual steps below.

Automated evidence that does exist:

- ID-1 PDF binaries begin with `%PDF` and exceed 2000 bytes
- Dashboard QR payload is `digital_license.verification_url`
- Portrait endpoint returns `image/jpeg` for an approved JPEG fixture

## 19. Production Configuration Required

```text
LICENSE_VERIFICATION_PUBLIC_URL=https://<official-syrtak-host>/licenses/verify
```

`.env.example` documents that the local default (`http://localhost:3000/licenses/verify`) is for development only. Production must not leave this as localhost. The card back will then display the real host extracted from that URL.

The Backend logo file `public/branding/syrtak-license-logo.png` must be deployed with the API (do not point at the Dashboard repo at runtime).

## 20. Flutter Integration Contract

Citizen PDF download (new):

| Item | Value |
|---|---|
| METHOD | `POST` |
| PATH | `/api/licenses/{license}/download` |
| AUTH | Sanctum bearer token, citizen user |
| HEADERS | `Authorization: Bearer {token}`, `Accept: application/pdf`, `Accept-Language: ar` or `en` |
| RESPONSE TYPE | raw PDF bytes (`application/pdf`) |
| SUCCESS | `200`, `Content-Disposition: attachment; filename="SYRTAK-License-{number}.pdf"`, `X-Content-Type-Options: nosniff` |
| 401 | missing/invalid token |
| 403 | authenticated non-citizen |
| 404 | unknown id **or** another citizen’s license (same as `GET /api/licenses/{license}`) |

Example Dart notes (not implemented here):

```dart
final res = await http.post(
  Uri.parse('$baseUrl/api/licenses/$licenseId/download'),
  headers: {
    'Authorization': 'Bearer $token',
    'Accept': 'application/pdf',
    'Accept-Language': 'ar',
  },
);
if (res.statusCode == 200) {
  // persist res.bodyBytes; do not cache as a public URL
}
```

Portrait is **not** a citizen/Flutter endpoint. Public verify remains `GET /api/licenses/verify/{token}` (JSON, no photo).

## 21. Remaining Limitations

Out of scope (unchanged, as required):

- QR is still a lookup URL, not a signed payload
- No national ID, DOB, blood type, A/B/C/D classes, signature, or nationality on the card
- Dashboard is still Arabic-only chrome
- Flutter UI is not in this workspace
- Citizen renew shortcut, duplicate admin/dashboard block routes, and validity-years architecture were not touched
- Visual QA of the physical card on screen/print must still be done manually
- If `LICENSE_VERIFICATION_PUBLIC_URL` stays on localhost, the printed QR will still encode localhost (correct for local dev; wrong for production cards)
