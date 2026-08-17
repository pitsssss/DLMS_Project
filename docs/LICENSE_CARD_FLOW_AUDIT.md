# SYRTAK Driving License Card / PDF / QR Audit

**Status:** read-only audit. No application code, APIs, templates, CSS, schema, or tests were modified.

**Repositories inspected:**

- Backend: `D:\Projects\DLMS_Project` (Laravel 12 API)
- Employee Dashboard: `D:\Projects\DLMS_Dashboard` (Next.js 15 / React 19 / Tailwind)

**Flutter client:** **NOT FOUND** in either repository (no `.dart` files). Citizen behavior is audited from the backend contract and Postman collection only.

**Runtime in this session:**

| Surface | Result |
|---|---|
| Laravel `GET /api/ping` on `127.0.0.1:8000` | **CONFIRMED** running |
| Next.js Dashboard on `127.0.0.1:3000` | **NOT** running (`Unable to connect`) |
| Authenticated Dashboard license preview | **UNKNOWN WITHOUT RUNTIME** — no employee session and no Dashboard process |
| Public verify JSON | **CONFIRMED** live sample below |
| Printed PDF visual | **UNKNOWN WITHOUT RUNTIME** — reconstructed from Blade + mPDF config |

Confidence labels used throughout: **CONFIRMED**, **PARTIALLY CONFIRMED**, **NOT FOUND**, **NOT IMPLEMENTED**, **UNKNOWN WITHOUT RUNTIME**.

---

## 1. Executive Summary

The official issuance contract is still:

```text
POST /api/admin/applications/{application}/issue-license
```

That historical path was **verified in current routes**. It was not renamed. The Dashboard does **not** have its own issue-license POST. Employees use a readiness queue at `GET /api/dashboard/license-issuance/applications`, then the frontend calls the admin issuance route.

There is **no ID-1 physical driving-license card** in the product today. What exists is:

1. A Dashboard **information preview** (`DigitalLicenseCard`) — cream/green Tailwind panel, no photo, no QR graphic.
2. An on-demand **A4 PDF** (`resources/views/licenses/digital-card.blade.php` via mPDF) — navy bordered document, QR PNG, no photo, hardcoded Arabic field labels.
3. A public **verification webpage** (`/licenses/verify/{token}`) that calls `GET /api/licenses/verify/{token}` — status hero + data card, no photo, no QR.

These three surfaces **do not share a renderer**. Palette, labels, and layout already diverge. Redesigning one will not update the others unless all three are changed together.

QR content is a **public frontend URL + random 48-character token**, not a signed payload and not `/api/licenses/verify/...`. Token is created at license-row insert. PDF is generated on demand and is not stored. Citizen PDF/QR download is **NOT IMPLEMENTED**.

Citizen portrait exists only as required **application documents** (`personal_photo` / `recent_personal_photo`). It is never copied onto `licenses` and never rendered on card/PDF/verify.

---

## 2. Current End-to-End Flow

```text
Dashboard /dashboard/license-issuance  (LicenseIssuancePage)
   ↓  GET /api/dashboard/license-issuance/applications
DashboardLicenseIssuanceController → DashboardLicenseIssuanceService
   ↓  employee clicks «إصدار الرخصة»
IssueLicenseDialog → useIssueLicense → dashboardLicenseIssuanceApi.issueLicense
   ↓  POST /api/admin/applications/{id}/issue-license   body: {}
auth:sanctum + dashboard + permission:issue_license + throttle:30,1
   ↓
ApplicationLicenseController::issue
   ↓
LicenseService::issueForApplication
   DB::transaction + lockForUpdate(application)
   LicenseIssuanceEligibilityService::assertReady
   match service: new / renew / lost / damaged
   create licenses row (number, dates, verification_token)
   application.status → license_issued
   audit + history + NotificationType::LicenseIssued
   ↓
LicenseResource JSON
   ↓
Dashboard toast + router.push(/dashboard/licenses/{id})
   ↓
LicenseDetailsPage → DigitalLicenseCard (preview, no QR)
   ↓  optional employee action
POST /api/dashboard/licenses/{id}/print  → mPDF A4 blob download
   QR PNG embedded in PDF only
   ↓  scan
http://localhost:3000/licenses/verify/{token}   (config default; .env override NOT FOUND)
   ↓
PublicLicenseVerifyPage → GET /api/licenses/verify/{token}
   JSON { valid, holder_name, license_number, ... }
```

**CONFIRMED.** Flutter is outside these repos; citizen list/details exist as `GET /api/licenses` and `GET /api/licenses/{id}` with **no** download/PDF/QR fields.

---

## 3. License Issuance Backend Flow

| Step | Actual class / file |
|---|---|
| Route | `app/Modules/Admin/Routes/admin.php` lines 48–54 |
| Full URL | `POST /api/admin/applications/{application}/issue-license` |
| Middleware | `auth:sanctum`, `dashboard` (`EnsureDashboardUser`), `permission:issue_license`, `throttle:30,1`, `{application}` numeric |
| FormRequest | **none** — empty JSON body `{}` |
| Controller | `App\Modules\Admin\Controllers\ApplicationLicenseController::issue` |
| Service | `App\Modules\Licenses\Services\LicenseService::issueForApplication` |
| Eligibility | `App\Modules\Licenses\Services\LicenseIssuanceEligibilityService::assertReady` |
| Number | `App\Modules\Licenses\Repositories\LicenseRepository::generateUniqueLicenseNumber` |
| Token | `App\Modules\Licenses\Services\LicenseLifecycleService::generateVerificationToken` |
| Response | `App\Modules\Licenses\Resources\LicenseResource` wrapped by `ApiResponse::successResponse` |

### License number — CONFIRMED

Format `LIC-{YYYY}-{10 uppercase alphanumerics}` via `Str::random(10)`, 12 uniqueness retries, then `LIC-{YYYY}-{UUID}`.

### Dates / type — CONFIRMED

| Field | New license / employee renew | Lost / damaged replacement |
|---|---|---|
| `issue_date` | `now()->toDateString()` | same |
| `expiry_date` | `now()->addYears(config('license.validity_years', 10))` — **default 10** | **copied from old license** |
| `license_type_id` | from application | from application (copied from related license’s type in practice via the application) |
| `status` | `active` | `active` |
| `issued_by` | employee id | employee id |
| `previous_license_id` | null / old id | old id |
| `verification_token` | new `Str::random(48)` | new token |
| `print_count` | 0 | 0 |

**License category (A/B/C/D pictograms):** **NOT FOUND.** Catalog types are `private`, `public`, `truck`, `bus` (`LicenseTypesSeeder`). Seeded `license_types.validity_years` is **5**; issuance **does not read that column**. It uses `LICENSE_VALIDITY_YEARS` default **10**. `.env` currently has **neither** `LICENSE_VALIDITY_YEARS` nor `LICENSE_VERIFICATION_PUBLIC_URL` (**CONFIRMED** grep).

### Application state — CONFIRMED

- Required before issue: `ApplicationStatus::Approved`.
- After issue: `license_issued` (terminal). `issued_at` set. `approved_at` set if missing.
- Unblock applications are **excluded** from this path (`license_unblock` → `messages.licenses.use_unblock_endpoint`).

### Side effects — CONFIRMED

| Effect | When |
|---|---|
| QR token | Immediately at `licenses` insert. Not at print. Print only backfills if missing (`ensureVerificationToken`). |
| PDF | **Not** at issuance. On-demand print only. Binary not stored. |
| Notification | Employee issuance only: `NotificationType::LicenseIssued` (`license.issued`), data `license_id`, `application_id`. |
| Audit | `license.issued` / `license.renewed` / `license.lost_replacement_issued` / `license.damaged_replacement_issued`. Token stripped from payloads. |
| History | `license_status_histories` action `issued` (and `renewed`/`replaced` on the old row). |

### API response after issue — CONFIRMED

```json
{
  "success": true,
  "message": "<messages.licenses.issued>",
  "data": { /* LicenseResource */ }
}
```

`LicenseResource` does **not** include `verification_token`, QR URL, photo, national ID, or print metadata.

Dashboard then navigates to details, which **does** include `digital_license` and `verification.url`.

---

## 4. Issuance Preconditions

Source of truth: `LicenseIssuanceEligibilityService::assertReady()` then `LicenseService` extra related-license lock.

Issuance failures use `ApiException($messageKey, 422)` **without** `$errorCode`. HTTP body therefore has **no stable machine `code`**. Queue `evaluate()` **does** emit blocker `code` strings.

Envelope: `{ success: false, message, errors: {} }`.

| Check | Location | Condition | HTTP | JSON `code` | Message key |
|---|---|---|---|---|---|
| Auth | Sanctum | missing token | 401 | none | `messages.http.unauthenticated` |
| Dashboard user | `EnsureDashboardUser` | citizen / inactive / no `access_dashboard` | 403 | none | `messages.dashboard.*` |
| Permission | `EnsurePermission` | no `issue_license` | 403 | none | `messages.middleware.permission_denied` |
| Application exists | `LicenseService` 62–64 | missing row | 404 | none | `messages.applications.not_found` |
| Unblock service | eligibility 44–46 | `license_unblock` | 422 | none (`use_unblock_endpoint` on queue only) | `messages.licenses.use_unblock_endpoint` |
| Issuable service | 48–50 | not new/renew/lost/damaged | 422 | none (`service_not_issuable`) | `messages.licenses.service_not_issuable` |
| Approved | 52–54 | status ≠ `approved` | 422 | none (`must_be_approved`) | `messages.licenses.must_be_approved` |
| Duplicate issue | 56–58 | `licenses.application_id` exists | 422 | none (`already_issued`) | `messages.licenses.already_issued` |
| Fee paid | 60–62 | completed payment for service fee code | 422 | none (`payment_required`) | `messages.licenses.payment_required` |
| Documents | 64–66 | every required active doc latest status = approved | 422 | none (`documents_required`) | `messages.licenses.documents_required` |
| Tests | 68–71 | `new_license` only: all required active tests passed | 422 | none (`tests_required`) | `messages.licenses.tests_required` |
| Unpaid fines | 73–75 | any citizen fine `unpaid` | 422 | none (`unpaid_fines_issue`) | `messages.licenses.unpaid_fines_issue` |
| Related license | `requireRelatedLicense` | renew/replace missing `related_license_id` | 422 | none (`related_license_required` on queue) | `messages.applications.related_license_required` |
| Successor | `LicenseTransitionPolicy::assertCanBecomeSuccessor` | old already has `replacedBy` | 422 | none | `messages.licenses.already_has_successor` |
| Concurrency | `LicenseService` | `lockForUpdate` on application + old license | — | — | — |

Fee codes (`ApplicationFeeResolver` / `ServiceWorkflow`): `application_fee`, `renewal_fee`, `lost_replacement_fee`, `damaged_replacement_fee`.

**Not checked at issue (CONFIRMED):** another active license of the same type for the citizen; `license_types.validity_years`; presence of a usable portrait file; photo quality.

Queue blocker codes (GET issuance queue only): `use_unblock_endpoint`, `service_not_issuable`, `must_be_approved`, `already_issued`, `payment_required`, `documents_required`, `tests_required`, `unpaid_fines_issue`, `related_license_required`.

---

## 5. Dashboard Issuance Flow

Employees do **not** issue from application details. **NOT FOUND** in `src/features/applications`.

| Item | Value | Confidence |
|---|---|---|
| Next.js route | `/dashboard/license-issuance` | CONFIRMED |
| Page file | `DLMS_Dashboard/src/app/(dashboard)/dashboard/license-issuance/page.tsx` | CONFIRMED |
| Page component | `LicenseIssuancePage` → `LicenseIssuanceListContent` | CONFIRMED |
| Guard | `PermissionGuard` + `LICENSE_ISSUANCE_READ_PERMISSIONS` = `issue_license` **OR** `view_applications` **OR** `manage_applications` | CONFIRMED |
| Sidebar | title **إصدار الرخص**, Stamp icon | CONFIRMED |
| Home queue | `OperationalQueues` key `licenses_ready_for_issuance` | CONFIRMED |
| Table / mobile | `LicenseIssuanceTable` / `LicenseIssuanceMobileCard` | CONFIRMED |
| Button | **إصدار الرخصة** | CONFIRMED |
| Confirm | `IssueLicenseDialog` — **تأكيد إصدار الرخصة** / **تأكيد الإصدار** | CONFIRMED |
| API list | `GET /dashboard/license-issuance/applications` | CONFIRMED |
| API issue | `POST /admin/applications/{id}/issue-license` with `{}` | CONFIRMED |
| After success | toast; refresh queue; `router.push(/dashboard/licenses/{id})` if `license.id` present | CONFIRMED |
| 422 stale | toast error; refresh queue; stay on page | CONFIRMED |
| Unblock exclusion | `isUnblockService()` hides issue button | CONFIRMED |

Frontend client: `DLMS_Dashboard/src/api/dashboardLicenseIssuanceApi.ts` (`issueLicense`, `getReadyApplications`, `getIssuanceDetails`).

Issue button also requires `actions.can_issue_license` from backend (`DashboardLicenseIssuanceActions`: `issue_license` **AND** `readiness.is_ready`).

---

## 6. Current Driving License UI

There is **no** component named `LicenseCard`, `DrivingLicense`, `LicensePreview`, `PrintLicense`, or `DownloadLicense`. **NOT FOUND.** No CSS Modules for licenses.

### Renderer A — Dashboard digital preview

```text
File: DLMS_Dashboard/src/features/licenses/components/DigitalLicenseCard.tsx
Component: DigitalLicenseCard
Used by: LicenseDetailsPage overview tab only
Purpose: On-screen “digital license preview”
```

Reusable client component (`"use client"`). Tailwind + one inline style (dot grid). **No photo. No QR.** Copy tells the employee the official QR is in the PDF.

### Renderer B — Public verification card

```text
File: DLMS_Dashboard/src/features/public-license-verify/components/PublicVerifyLicenseCard.tsx
Component: PublicVerifyLicenseCard
Used by: PublicLicenseVerifyPage
Route: /licenses/verify/[verificationToken]
Purpose: Public verification data card (unauthenticated)
```

Client Tailwind. **No photo. No QR.**

### Renderer C — Printed PDF (not React)

```text
File: DLMS_Project/resources/views/licenses/digital-card.blade.php
Used by: LicensePrintService::renderPdf() via mPDF
Purpose: Official printable A4 document
```

Inline HTML/CSS. QR `<img>` 140×140. **No photo.**

### Supporting Dashboard UI (not a license card)

| File | Component | Purpose |
|---|---|---|
| `.../licenses/components/LicenseDetailsHeader.tsx` | `LicenseDetailsHeader` | Title, status, print/block/unblock |
| `.../licenses/components/LicenseInformationGrid.tsx` | `LicenseInformationGrid` | Admin field groups |
| `.../licenses/components/LicenseValidityBanner.tsx` | `LicenseValidityBanner` | Validity banner |
| `.../licenses/components/LicenseStatusBadge.tsx` | `LicenseStatusBadge` | Status pill |
| `.../licenses/components/LicensesTable.tsx` | `LicensesTable` | Issued-license list |
| `.../licenses/components/LicenseFilters.tsx` | `LicenseFilters` | Search/filters |
| `.../licenses/components/LicenseStatsGrid.tsx` | `LicenseStatsGrid` | KPI tiles |
| `.../licenses/components/LicenseHistoryTimeline.tsx` | `LicenseHistoryTimeline` | History tab |
| `.../licenses/components/LicenseAuditList.tsx` | `LicenseAuditList` | Audit tab |
| `.../licenses/components/BlockLicenseDialog.tsx` | `BlockLicenseDialog` | Block modal |
| `.../licenses/components/UnblockLicenseDialog.tsx` | `UnblockLicenseDialog` | Unblock confirm |
| `.../public-license-verify/components/PublicVerifyStatusHero.tsx` | `PublicVerifyStatusHero` | Verify status hero |
| `.../public-license-verify/components/PublicVerifyTrustNote.tsx` | `PublicVerifyTrustNote` | Trust note |
| `.../citizens/components/CitizenRelatedTab.tsx` | related licenses table | Not a card |

`FineQrCode` exists for **fines**, not licenses.

Dev-only Blade: `resources/views/dev-dashboard/partials/licenses-fines-section.blade.php` — internal testing UI, not production license art.

---

## 7. Current Visual Structure

### A. `DigitalLicenseCard` (Dashboard) — CONFIRMED from JSX

Display order:

1. Outer section — `rounded-2xl`, border `#054239/20`, bg `#f7f4ef`
2. Header bar — bg `#054239`, white text
   - `digital.authority`
   - hardcoded **معاينة الرخصة الرقمية**
3. Body — radial-dot grid `rgba(5,66,57,0.08)` every 14px
4. Inner card — `rounded-xl`, white/90, gold bar `w-1.5 bg-[#c9a227]` on the RTL leading edge
5. License number (LTR mono) + status pill
6. `digital.title`
7. 2-col grid: holder, type, issue date, expiry
8. Dashed footer: `verification_guidance` + hardcoded QR-in-PDF note + optional public verify link

| Visible label | Property | Data source | Required? | Derived where |
|---|---|---|---|---|
| (none) | `authority` | `details.digital_license.authority` ← `Msg::get('licenses.digital.authority')` | display | Backend (`Msg` = always Arabic) |
| معاينة الرخصة الرقمية | — | hardcoded AR | — | Frontend |
| رقم الرخصة | `license_number` | `licenses.license_number` | yes | Backend |
| (pill) | `status_label` | effective status via `LicenseEffectiveStatus` | yes | Backend |
| (none) | `title` | `Msg::get('licenses.digital.title')` | display | Backend |
| حامل الرخصة | `holder_name` | `users.name` | nullable in payload | Backend |
| نوع الرخصة | `license_type` | `license_types.name` (raw DB, usually Arabic) | if type loaded | Backend |
| تاريخ الإصدار | `issue_date` | `licenses.issue_date` formatted `ar-SY-u-nu-latn` | yes | Backend date / frontend format |
| تاريخ الانتهاء | `expiry_date` | `licenses.expiry_date` | yes | same |
| (guidance) | `verification_guidance` | `Msg::get('licenses.digital.verification_guidance')` | display | Backend |
| رمز QR الرسمي يظهر في ملف PDF... | — | hardcoded AR | — | Frontend |
| فتح صفحة التحقق العامة | mapped path | `verification_url` → `/licenses/verify/{token}` | if token exists | Backend URL / frontend path |

**Dimensions:** fluid width. **No ID-1 ratio.** No fixed height. Photo **NOT IMPLEMENTED**. QR graphic **NOT IMPLEMENTED**. Font: Cairo (`globals.css`). RTL from `<html dir="rtl">`. License number `dir="ltr"`.

Gold token inconsistency: card uses `#c9a227`; Tailwind `syrtak.gold` is `#b9a779`. **PARTIALLY CONFIRMED.**

### B. Public verify card — CONFIRMED

Order: gradient bar → eyebrow **بيانات الرخصة العامة** → holder, number, type, status, issue, expiry.

Outer `rounded-[28px]`. Page `bg-[#f3efe7]`, `max-w-md sm:max-w-lg`, `dir="rtl"`. Logo `/logos/image.png` 64×64.

### C. PDF Blade — CONFIRMED

Order: authority → title → رقم الرخصة → اسم حامل الرخصة → فئة الرخصة → تاريخ الإصدار → تاريخ الانتهاء → الحالة → QR 140×140 → guidance → `generated_at`.

| CSS | Value |
|---|---|
| Page | mPDF `format => A4`, portrait default, `directionality => rtl` |
| Card | `border: 2px solid #0b3d5c; border-radius: 8px; padding: 24px; margin-top: 40px` |
| Authority | 14pt bold `#0b3d5c` |
| Title | 16pt bold |
| Labels | 10pt `#555` **hardcoded Arabic** |
| Values | 12pt bold |
| Status | bordered pill |
| QR | generated 220px, displayed 140×140 |
| Font | DejaVu Sans |
| Margins | **not set in code** — mPDF defaults (**PARTIALLY CONFIRMED**) |

PDF navy `#0b3d5c` **does not match** Dashboard greens `#054239` / `#002623`. **CONFIRMED.**

No emblem, signature, hologram, category pictogram, or bilingual name block. **NOT IMPLEMENTED.**

### Screenshot / browser inspection

**UNKNOWN WITHOUT RUNTIME** for the Dashboard card and PDF pixels.

This session: API was up; Dashboard was not. No employee login was used. Public verify HTML was not rendered.

Live JSON sample (`GET /api/licenses/verify/short`): HTTP 200, `success: true`, `data.valid: false`, identity fields null, Arabic `messages.licenses.verification.not_found`. **CONFIRMED.**

---

## 8. License Data Contract

### Citizen `LicenseResource`

`id`, `license_number`, `status` (effective), `status_label`, `stored_status`, `effective_status`, `issue_date`, `expiry_date`, `days_remaining`, `is_expiring_soon`, `license_type{id,name,code}`, `application{id,application_number,status}`, `created_at`, optional `can_renew` / `can_request_lost_replacement` / `can_request_damaged_replacement` / `can_request_unblock`.

**Omitted:** token, QR URL, photo, national ID, DOB, address, print metadata, `issued_by`, block reason.

### Dashboard details (`DashboardIssuedLicenseService::details`)

List identity plus `citizen{id,name,national_id?}`, `source_service`, `issued_by`, `block`, `previous_license`, `replaced_by`, `lineage`, `print`, `digital_license`, `verification.url`, `actions`, `links`, optional `fines_summary`.

`national_id` only if actor has `manage_users`.

### `digital_license` payload (`DigitalLicensePresenter`)

`authority`, `title`, `license_number`, `holder_name`, `license_type{code,label}`, `issue_date`, `expiry_date`, `status`, `status_label`, `verification_url`, `verification_guidance`, `days_remaining`, `is_expiring_soon`.

**Stale frontend comment:** `DigitalLicensePayload` in `licenseManagement.ts` says `verification_url` is the backend API URL. Tests and presenter prove it is the **public Next.js page** URL. The comment is wrong.

### Public verify `data`

`valid`, `status`, `status_label`, `license_number`, `holder_name`, `license_type{code,label}`, `issue_date`, `expiry_date`, `message`, `verified_at`.

Frontend `publicLicensesApi.normalizePublicLicenseVerification` **whitelists** these keys and drops extras.

---

## 9. License Database Model

Table `licenses` — `App\Models\License`.

Migrations:

- `database/migrations/2026_05_10_100009_create_licenses_table.php`
- `database/migrations/2026_07_28_160000_add_issued_licenses_management_fields_to_licenses_table.php`

| Column | Type | Nullable | Purpose | Card? | PDF? | Verify? |
|---|---|---|---|---|---|---|
| `id` | bigint PK | no | identity | no | no | no |
| `license_number` | string unique | no | public number | yes | yes | yes |
| `citizen_id` | FK users | no | holder | name only | name only | name only |
| `license_type_id` | FK license_types | no | class/type | yes | yes | yes |
| `application_id` | FK license_applications | no | source application | admin grid | no | no |
| `issued_by` | FK users | yes | issuing employee | admin grid | no | no |
| `previous_license_id` | FK licenses | yes | lineage | admin grid | no | no |
| `status` | string 32 / `LicenseStatus` | no | stored status | effective | effective | effective |
| `issue_date` | date | no | issue | yes | yes | yes |
| `expiry_date` | date | no | expiry | yes | yes | yes |
| `blocked_at` | timestamp | yes | block metadata | admin | no | no (status only) |
| `blocked_by` | FK users | yes | block actor | admin | no | no |
| `block_reason` | text | yes | block reason | admin | no | no |
| `verification_token` | string 64 unique | yes | QR lookup | URL only | encoded in QR | lookup key, not returned |
| `printed_at` | timestamp | yes | last print | admin | footer time is generate time | no |
| `printed_by` | FK users | yes | printer | admin | no | no |
| `print_count` | unsigned int | no | print tally | admin | no | no |
| timestamps / `deleted_at` | | | audit / soft delete | no | no | no |

Statuses: `active`, `expired`, `suspended`, `blocked`, `renewed`, `inactive`. Effective expiry: stored `active` + past `expiry_date` → effective `expired` (`LicenseEffectiveStatus`).

Related:

- Citizen: `users.name`, `national_id`, `birth_date`, `governorate`, `address`, `phone`, `email`. **No photo column.**
- `license_types`: `name`, `code`, `minimum_age`, `validity_years`, `is_active`.
- `license_status_histories`.
- Replacement/renewal: new row + `previous_license_id` + `replacedBy` HasOne.
- Verification token: column on `licenses`, **not** a separate table.
- **LicenseCategory model:** **NOT FOUND.**

---

## 10. Citizen Photo Source

**The current license artifact does not contain a citizen photo.** **CONFIRMED.**

| Candidate | Result |
|---|---|
| `users` photo/avatar column | **NOT FOUND** |
| Dedicated license portrait column | **NOT FOUND** |
| Application documents `personal_photo` (new) / `recent_personal_photo` (renew/replace) | **CONFIRMED** required docs; files in `application_documents.file_path` on local disk |
| Dashboard document preview | `GET /api/dashboard/document-reviews/documents/{id}/preview` — `review_documents` only |
| License PDF / DigitalLicenseCard / verify | do not load documents | 

Photo therefore exists as a **workflow document**, not as license master data. A future card that shows a portrait needs an explicit data decision (copy onto license vs resolve latest approved document). That is a backend contract change.

---

## 11. PDF Generation Architecture

### A. Trigger — CONFIRMED

On-demand employee action only: Dashboard details **طباعة الرخصة** → `POST /api/dashboard/licenses/{id}/print`.

Not generated at issuance. No citizen trigger. No scheduler. Printable even if blocked/expired — **no status gate** in `printPdf`.

### B. Technology — CONFIRMED

| Item | Value |
|---|---|
| Package | `mpdf/mpdf` `^8.3`, lock **v8.3.1** |
| QR package | `endroid/qr-code` `^6.1`, lock **6.1.3** |
| Not used | Dompdf, Snappy, Browsershot, wkhtmltopdf |

### C. Template — CONFIRMED

| Piece | Path |
|---|---|
| Controller | `DashboardIssuedLicenseController::print` |
| Service | `LicensePrintService::printPdf` |
| Blade | `resources/views/licenses/digital-card.blade.php` |
| CSS | inline in that Blade file |
| Font | mPDF DejaVu Sans; `utf-8`; `autoScriptToLang` / `autoLangToFont` |
| Assets | none (no logo file in PDF) |

### D. Data passed to template

`$payload` from `DigitalLicensePresenter::payload` plus `$qr` data URI plus `$generated_at` (`BusinessClock` `Y-m-d H:i`).

### E. Output — CONFIRMED

| Item | Value |
|---|---|
| Paper | A4 portrait |
| Filename | `license-{license_number}.pdf` |
| Content-Type | `application/pdf` |
| Disposition | `attachment` |
| Extra header | `X-Content-Type-Options: nosniff` |
| Frontend | Axios `responseType: "blob"` → `downloadBlob()` object URL, hidden `<a download>`, revoke after 60s |
| Storage | **not stored**. `Mpdf::Output('', 'S')`. Temp dir `storage/app/mpdf-temp` |
| Caching | none |
| Side effect | `LicenseService::recordPrint` increments `print_count`, sets `printed_at`/`printed_by`, history `printed`, audit `license.printed` |

Throttle: `30,1`.

### F. Arabic — PARTIALLY CONFIRMED

RTL + DejaVu is configured. Authority/title/guidance come from `Msg` (always Arabic translator). **Field captions in Blade are hardcoded Arabic** (`رقم الرخصة`, …). English PDF labels **NOT IMPLEMENTED**. Citizen verify API **is** bilingual via `Accept-Language`.

---

## 12. Dashboard Preview vs PDF Rendering

## Case B, with a third duplicate (public verify)

**Dashboard license preview and downloaded PDF do not share one rendering source.**

| Surface | Stack | Photo | QR | Palette |
|---|---|---|---|---|
| Dashboard preview | React + Tailwind | no | no (link only) | SYRTAK green `#054239` / gold `#c9a227` |
| PDF | Blade + mPDF | no | yes PNG | navy `#0b3d5c` |
| Public verify | React + Tailwind | no | no | green/gold, different radius/layout |

**Not Case A** (shared HTML). **Not Case C** (PDF does not capture Dashboard DOM).

Shared **data** source for preview + PDF: `DigitalLicensePresenter`. Shared **layout**: no.

If the redesign must look identical on screen and on paper, both `DigitalLicenseCard.tsx` and `digital-card.blade.php` must be updated (or a later shared renderer introduced). That is a future implementation choice; this audit does not propose it.

---

## 13. QR Generation

| Question | Answer | Confidence |
|---|---|---|
| Library | `endroid/qr-code` 6.1.3 `Builder` + `PngWriter` / `SvgWriter` | CONFIRMED |
| Where generated | Backend `LicensePrintService::qrPngDataUri` at **print** time | CONFIRMED |
| Frontend license QR | **NOT IMPLEMENTED** (fines use npm `qr` separately) | CONFIRMED |
| Payload | Public frontend URL only: `{LICENSE_VERIFICATION_PUBLIC_URL}/{token}` | CONFIRMED |
| Default URL | `http://localhost:3000/licenses/verify/{token}` | CONFIRMED |
| Contains PII / license number / id | **No** | CONFIRMED |
| Token generation | `Str::random(48)` alphanumeric, loop until unique | CONFIRMED |
| Stored | `licenses.verification_token` unique index | CONFIRMED |
| Unique | yes | CONFIRMED |
| Changes on renew/replace | **yes** — new row, new token. Old token still resolves to old row | CONFIRMED |
| Revocable / rotate API | **NOT IMPLEMENTED** (lang key `verification_token_rotated` exists; no endpoint) | CONFIRMED |
| Blocked/expired still scanable | **Yes**. `valid: false` but identity fields still returned if token found | CONFIRMED |
| Appears on Dashboard preview | **No** | CONFIRMED |
| Appears on PDF | **Yes** | CONFIRMED |
| Appears on citizen API | **No** | CONFIRMED |
| Appears on public verify page | **No** (token is in the URL) | CONFIRMED |

`qrSvg()` exists on `LicensePrintService` and is **never called**. Dead helper.

`.env` does not set `LICENSE_VERIFICATION_PUBLIC_URL`. Printed QR in this environment would encode **localhost:3000** unless production config is supplied elsewhere. **CONFIRMED** for this checkout.

---

## 14. Public QR Verification

```text
QR PNG (PDF)
  ↓  URL http(s)://{host}/licenses/verify/{token}
Next.js PublicLicenseVerifyPage
  ↓  GET /api/licenses/verify/{token}
routes/api.php  locale + throttle:30,1  token [A-Za-z0-9]+
LicenseVerificationController::show
LicenseVerificationService::verify
License lookup by verification_token
JSON successResponse { data: { valid, status, ... } }
```

| Piece | File |
|---|---|
| Frontend page | `DLMS_Dashboard/src/app/licenses/verify/[verificationToken]/page.tsx` |
| UI | `.../features/public-license-verify/PublicLicenseVerifyPage.tsx` |
| API client | `.../src/api/publicLicensesApi.ts` |
| Laravel route | `routes/api.php` 56–58 |
| Controller | `app/Modules/Licenses/Controllers/LicenseVerificationController.php` |
| Service | `app/Modules/Licenses/Services/LicenseVerificationService.php` |

Response type: **JSON API** wrapped in `{ success, message, data }`. The **webpage** is a separate Next.js client that consumes that JSON. **No** Laravel HTML verify view (`routes/web.php` has no license verify). **No** redirect.

HTTP **200** for unknown/short tokens (not 404). Short token (`< 20` chars) or unknown → `valid: false`, all identity fields **null**.

`valid` is true **only** if effective status is `active`.

### Publicly exposed when token is found

- `holder_name` (`users.name`)
- `license_number`
- `license_type` code + localized label
- `issue_date`, `expiry_date`
- `status`, `status_label`
- `message`, `verified_at`

**Not exposed (tests assert):** `national_id`, `phone`, `email`, `citizen_id`, photo, address, DOB.

PII note: full name + license number **are** returned for blocked/expired/renewed/inactive tokens, not only active ones. That is a product/privacy choice already encoded in tests.

Layout `robots: { index: false, follow: false }` on the verify page. **CONFIRMED.**

---

## 15. QR / Verification Security Review

Architectural review only. Not a penetration test.

| Question | Current state |
|---|---|
| Is the QR merely a URL? | **Yes.** |
| Does authenticity depend on the official domain? | **Yes.** The officer must trust they opened the real SYRTAK host. |
| Can an attacker print a QR to a fake site? | **Yes.** Nothing in the QR cryptographically binds it to SYRTAK. |
| Officer distinguish official vs fake? | Weak. Official page has SYRTAK logo + copy **تم التحقق مباشرة من نظام سيرتك**, but a fake site can imitate that. No signature, hologram, or certificate viewer. |
| Signed payload | **NOT IMPLEMENTED** |
| Random verification token | **Yes** — `Str::random(48)` |
| UUID | Fallback only for license **numbers**, not tokens |
| Cryptographic signature of card data | **NOT IMPLEMENTED** |
| Server-side lookup | **Yes** |
| Status validation | **Yes** (`valid` flag) |
| Revoked/replaced detection | Old token still finds the **old** row; `valid` false if not effective-active. Successor has a new token. |
| Expiry validation | Effective status treats past `expiry_date` as expired |
| Token guessable? | 48 alphanumeric ≈ 48 log₂(62) bits. Not sequential. Practically unguessable without a leak. |
| Sequential IDs usable instead? | Public verify route accepts token charset only, not numeric license id. Citizen `GET /licenses/{id}` is authenticated + owned. |
| Rate limiting | `throttle:30,1` on verify. Covered by `RateLimitEvidenceTest`. |
| Endpoint public intentionally? | **Yes** |
| Information leak | Found tokens disclose name + number + type + dates + status. Unknown tokens disclose nothing. HTTP 200 for both avoids some enumeration-by-status, but timing/existence of long tokens is still theoretically observable. |

Production risk: if `LICENSE_VERIFICATION_PUBLIC_URL` stays at localhost, printed QR codes will not open the real public page.

---

## 16. Citizen License Download Flow

Flutter app is **NOT FOUND** here. Backend contract:

| # | Endpoint | Auth | Ownership |
|---|---|---|---|
| 1 List | `GET /api/licenses` | `auth:sanctum`, `locale`, `citizen` | `citizen_id = auth` |
| 2 Details | `GET /api/licenses/{license}` | same | `findOwnedByCitizen` → **404** `messages.licenses.not_found` (not 403). IDOR closed |
| 3 PDF/download | — | — | **NOT IMPLEMENTED** |
| 4 QR URL | — | — | **NOT IMPLEMENTED** on citizen resources |

Related citizen mutations (not downloads):

- `POST /api/licenses/{id}/renew` — **shortcut that immediately creates a new license row** (message text says “application submitted”)
- `POST /api/licenses/{id}/replacement` body `{ type: lost\|damaged }` — same shortcut
- `POST /api/licenses/{id}/unblock-request` — **DEPRECATED**, does not create an application
- Official path: `POST /api/applications` with `renew_license` / `lost_replacement` / `damaged_replacement` / `license_unblock`

Citizen localization: `CitizenMessageTranslator` + `Accept-Language`. Catalog type names via `CitizenCatalogLabel`.

```text
CITIZEN_LICENSE_DOWNLOAD_ENDPOINT = NOT IMPLEMENTED
```

Do not infer citizen download from employee print.

---

## 17. Issued Licenses Dashboard Management

| Capability | Route / UI | Notes |
|---|---|---|
| List | `/dashboard/licenses` → `LicensesListPage` | Title **إدارة الرخص الصادرة** |
| Details | `/dashboard/licenses/[licenseId]` → `LicenseDetailsPage` | Tabs: نظرة عامة / سجل التتبع / سجل التدقيق |
| Search | `LicenseFilters` | number, citizen name, application number |
| Filters | status, type, service, expiry filter, issued_by, date ranges | `GET /dashboard/licenses/options` |
| Stats | `GET /dashboard/licenses/stats` | |
| Print | details header only | **NOT** on list rows |
| Block | `BlockLicenseDialog`, reason required 3–1000 chars | |
| Unblock | `UnblockLicenseDialog` | stored status must be `blocked` |
| History | `GET .../history` | |
| Audit | `GET .../audit-logs` + extra `view_audit_logs` | |
| Citizen related | citizen details tab | `GET /dashboard/citizens/{id}/licenses` |
| Reports | `GET /dashboard/reports/licenses` | analytics, not the registry |
| Types catalog | `/dashboard/license-types` | CRUD, not issued licenses |

**Actions:** backend returns `actions` (`DashboardLicenseActions::for`). Frontend **ANDs** with JWT permissions (`licensePermissions.ts`).

```text
can_view: true
can_print: true          // always true if the user reached the endpoint
can_block: manage_licenses && effective status === active
can_unblock: manage_licenses && stored status === blocked
can_view_application: view/manage applications && application_id
can_view_history: true
can_view_audit_logs: view_audit_logs
```

Parallel admin block/unblock (no list/print):

- `POST /api/admin/licenses/{id}/block`
- `POST /api/admin/licenses/{id}/unblock`

Application unblock (not issuance): `POST /api/dashboard/applications/{id}/unblock-license`.

---

## 18. Authorization & Permissions

Actual names from `config/dashboard_permissions.php`:

| Permission | Risk | Typical roles |
|---|---|---|
| `issue_license` | critical | `license_employee`, generic `employee` |
| `view_licenses` | normal | `license_employee`, `fines_employee` |
| `manage_licenses` | critical | `license_employee`, generic `employee` |
| `view_audit_logs` | — | `audit_employee` |
| `view_applications` / `manage_applications` | — | issuance queue GET; open application |
| `manage_users` | — | national_id on license payloads; citizen licenses list |
| `view_reports` | — | reports/licenses (also needs view/manage/issue license) |
| `access_dashboard` | — | all dashboard users |
| `print_license` | — | **NOT FOUND** |

`EnsurePermission` is **OR** across listed names.

| Action | Frontend guard | Backend | Permission |
|---|---|---|---|
| Open issuance queue | `PermissionGuard` issue **OR** view/manage applications | same OR | `issue_license,view_applications,manage_applications` |
| Click issue | `issue_license` AND `actions.can_issue_license` AND not unblock | `permission:issue_license` | `issue_license` |
| Open issued list/details | view **OR** manage licenses | same OR | `view_licenses,manage_licenses` |
| Print | (view OR manage) AND `actions.can_print` | same group + throttle | no dedicated print permission |
| Block | `manage_licenses` AND `actions.can_block` | `permission:manage_licenses` | `manage_licenses` |
| Unblock | `manage_licenses` AND `actions.can_unblock` | `permission:manage_licenses` | `manage_licenses` |
| Audit tab | `view_audit_logs` AND `actions` AND `links` | route group view/manage licenses **plus** in-controller `view_audit_logs` | `view_audit_logs` |
| Public verify | none | `locale` + throttle | public |

Mismatch notes:

- `can_print` is always `true` in the resource. Hide/show is effectively the route permission. A `fines_employee` with `view_licenses` **can print**.
- Generic `employee` role has `issue_license` and `manage_licenses` but **not** `view_licenses`. Print/list still work via `manage_licenses` OR.
- UI can hide issue for unblock services; backend also rejects unblock on issue-license. Aligned.
- Issuance POST is on `/api/admin/...` while the queue is `/api/dashboard/...`. Easy to miss in docs; both are current.

---

## 19. License Lifecycle / States

Stored: `active`, `expired`, `suspended`, `blocked`, `renewed`, `inactive`.

Effective: if stored `active` and `expiry_date` < today → `expired` without requiring the row update. Command `licenses:sync-expired` persists expiry (daily 00:15 Asia/Damascus per existing ops docs/tests).

Application after official issue: `license_issued`. Unblock applications: `completed`.

Action eligibility is **layered**:

1. Domain: `LicenseIssuanceEligibilityService`, `LicenseTransitionPolicy`, `LicenseServiceEligibilityService`
2. Resource `actions` flags
3. Policies via permissions middleware
4. Frontend AND of permission + `actions`

Invalid actions are blocked in more than one layer for issue/block/unblock. Print is **not** state-gated.

---

## 20. Renewal / Lost / Damaged Replacement Impact

Same PDF/preview templates are reused. No replacement marker on the card. **CONFIRMED.**

| | New record? | New number? | New QR token? | Old status | Expiry |
|---|---|---|---|---|---|
| Employee renew (`renew_license` + issue-license) | yes | yes | yes | `renewed` | new 10-year window |
| Employee lost replace | yes | yes | yes | `inactive` | **kept** |
| Employee damaged replace | yes | yes | yes | `inactive` | **kept** |
| Citizen shortcut renew/replace | yes | yes | yes | same as above | same rules |
| Unblock | no | no | no | `active` or `expired` if past expiry | unchanged |
| Block | no | no | no | `blocked` | unchanged |

Old QR still verifies the **predecessor** (`valid: false` once not effective-active).

Citizen shortcuts **bypass** documents/fees/employee issue and reuse the old `application_id` with `issued_by = null`. Official Flutter/product path is `POST /api/applications`. Both exist today.

Renewal eligibility (application create flags): within `LICENSE_RENEWAL_GRACE_DAYS` default 90 days before expiry (or already expired), not blocked, no newer active same type.

---

## 21. Localization AR/EN

| Surface | Mechanism | AR/EN |
|---|---|---|
| Dashboard UI strings | hardcoded Arabic. **No** next-intl / locale files | EN UI **NOT IMPLEMENTED** |
| Dashboard employee catalog labels | `EmployeeMessageTranslator` (`employee.license_types.*`, `employee.services.*`) | Arabic-oriented |
| Dashboard license status in details/list | `Msg` → `ArabicMessageTranslator` **always Arabic**, even if app locale is English | CONFIRMED by `LicenseVerificationLocalizationTest` |
| Issue/print API `message` envelope | `CitizenMessageTranslator` via `successResponse` | follows `Accept-Language` |
| PDF authority/title/guidance | `Msg` (Arabic) | EN **not** used in PDF |
| PDF field captions | hardcoded Arabic in Blade | EN **NOT IMPLEMENTED** |
| Public verify API | `CitizenMessageTranslator` + `CitizenCatalogLabel` | bilingual **CONFIRMED** |
| Public verify page chrome | hardcoded Arabic | EN **NOT IMPLEMENTED** |
| Machine codes | queue blocker `code`; issuance HTTP has **no** `code` | codes must stay stable |

Hardcoded license-related Arabic (non-exhaustive): `DigitalLicenseCard`, `PublicVerifyLicenseCard`, `PublicVerifyStatusHero`, `PublicVerifyTrustNote`, `LicenseDetailsHeader`, Blade captions, issuance toasts.

Future redesign should move **display** strings into existing `messages.licenses.digital.*` / Dashboard translation architecture, and keep machine codes untranslated.

---

## 22. Government / SYRTAK Brand Assets

Backend `public/` has **no** license logo PNG/SVG (favicon only in tree search). **CONFIRMED.**

Dashboard assets that actually exist:

```text
File: DLMS_Dashboard/public/logo1.png
Dimensions/type: 268×268 PNG, 86,466 bytes. Rounded square, gold shield+star+road on dark green.
Currently used where: LoginPage
Suitable for PDF: technically yes (small)
Suitable for license card: yes as a mark, low resolution vs print
```

```text
File: DLMS_Dashboard/public/logos/image.png
Dimensions/type: 1024×1024 PNG, 590,804 bytes. Same SYRTAK emblem, transparent outside rounded square.
Currently used where: Sidebar, PublicLicenseVerifyPage, Open Graph in app/layout.tsx
Suitable for PDF: yes (higher res)
Suitable for license card: yes
```

**Not used** on `DigitalLicenseCard` or the PDF Blade. Government coat of arms / ministry emblem **NOT FOUND**.

Other public files: `SYRTAK_Damascus_Clean_8s.mp4` (login background), `window.svg`, `file.svg`, `vercel.svg`, `icons.svg` — not license art.

Brand colors in Dashboard Tailwind: `#002623`, `#054239`, `#428177`, gold `#b9a779`, bg `#f5f7f6`.

No new logos were created in this audit.

---

## 23. Current Design Assessment

Code-based assessment (pixels **UNKNOWN WITHOUT RUNTIME**):

| Criterion | Assessment |
|---|---|
| Visual hierarchy | Preview is an admin “info panel”, not a credential. Number is the strongest element. |
| Governmental identity | SYRTAK greens appear on Dashboard/verify; PDF uses a different navy. Logo unused on the license itself. |
| Realism as a driving license | Low. No photo, no ID-1 proportions, no categories, no security pattern beyond a dotted background. |
| Card proportions | Fluid dashboard card; A4 PDF document rather than CR80/ID-1. |
| Information hierarchy | Adequate for clerks; weak for roadside inspection. |
| Citizen photo | Absent. |
| QR placement | PDF only, centered under fields. Dashboard explicitly omits it. |
| Authenticity cues | Trust copy on verify page; QR is a URL; no signature strip, microtext, or hologram. |
| Typography | Cairo on web; DejaVu on PDF. Two type systems. |
| Arabic/English layout | Arabic-only card/PDF/verify chrome. No bilingual name block. |
| Print quality | A4 with simple CSS; DejaVu is robust for Arabic but not a branded license face. |
| PDF consistency with preview | **Inconsistent** (Case B). |
| Responsive behavior | Preview stacks; verify page is phone-width (`max-w-md`). Not a scaled physical card. |

This is a **digital record printout**, not a premium governmental license face. No redesign is proposed in this document.

---

## 24. Safe Redesign Surface

### SAFE VISUAL CHANGES (same business behavior)

If the goal is look-and-feel only, using fields already in `DigitalLicensePresenter`:

- `DLMS_Dashboard/src/features/licenses/components/DigitalLicenseCard.tsx` (HTML/Tailwind)
- `DLMS_Project/resources/views/licenses/digital-card.blade.php` (PDF HTML/CSS)
- Optionally `PublicVerifyLicenseCard.tsx` / `PublicVerifyStatusHero.tsx` for visual family
- Embedding existing `public/logos/image.png` into Dashboard card CSS (frontend-only)
- PDF: add the same logo **only if** the Blade can reach a filesystem copy (backend template change, still presentation)

### REQUIRES BACKEND CONTRACT CHANGE

- Citizen photo on card/PDF/verify
- English full name, nationality, blood type, restrictions, signature, place of issue, vehicle category pictograms
- Returning `birth_date` / `national_id` on `digital_license` or public verify
- Citizen PDF/QR download endpoints
- QR signed payload or token rotation
- Using `license_types.validity_years` instead of global 10
- ID-1 / CR80 physical dimensions if print pipeline must change paper size (`format => A4` today)
- Machine `code` on issuance 422 errors

### DO NOT TOUCH (for a visual redesign)

- `LicenseService::issueForApplication` and private issue/renew/replace methods
- `LicenseIssuanceEligibilityService`
- `LicenseTransitionPolicy`
- `LicenseRepository::generateUniqueLicenseNumber`
- `LicenseLifecycleService::generateVerificationToken` semantics
- Route URLs, middleware, permission names
- `LicenseVerificationService` validity rules (unless product explicitly changes public PII)
- Application status machine
- Payment / document / test gates
- Block/unblock rules
- Citizen ownership checks

---

## 25. Data Missing for New Card Design

### AVAILABLE NOW (on digital_license / PDF / verify as applicable)

- Holder name (`users.name`, single field, typically Arabic)
- License number
- License type code + name (`private` / `public` / `truck` / `bus`)
- Issue date, expiry date
- Effective status + Arabic (or locale-aware on verify) label
- Issuing authority **string** (`licenses.digital.authority`)
- Title string (`licenses.digital.title`)
- Verification URL / token (token not in citizen API)
- Days remaining / expiring soon (preview payload, not PDF)

### AVAILABLE IN DATABASE BUT NOT RETURNED ON THE CARD CONTRACT

| Field | Where it lives | Who can see it today |
|---|---|---|
| National ID | `users.national_id` | Dashboard details if `manage_users`; **not** on digital_license/PDF/verify |
| Date of birth | `users.birth_date` | profile flows; **not** license card APIs |
| Governorate / address / phone / email | `users` | not on card |
| `license_types.validity_years` | catalog (seeded 5) | public `GET /api/license-types`; **not used** at issuance |
| Portrait file | `application_documents.file_path` | document review preview only |
| Print count / printer | `licenses.print_*` | Dashboard details |
| Issued-by employee | `issued_by` | Dashboard details |
| Lineage | `previous_license_id` / `replacedBy` | Dashboard details |

### NOT AVAILABLE AT ALL (no column / no API)

- English full name (`name_en`)
- Nationality
- Blood type
- Restrictions / conditions
- Vehicle category pictograms / UN codes
- Signature image
- Place of issue / office
- Structured issuing-authority entity (only a translated sentence)
- Dedicated license portrait
- Hologram / ghost image / MRZ

---

## 26. Tests & Regression Coverage

| File | Covers |
|---|---|
| `tests/Feature/LicenseFlowTest.php` | employee issue, unpaid fines, block/unblock, citizen renew shortcut |
| `tests/Feature/LicensePrintingTest.php` | PDF bytes, print metadata, QR public URL shape, 403 print |
| `tests/Feature/LicenseVerificationTest.php` | valid without extra PII; expired/blocked/renewed/unknown |
| `tests/Feature/LicenseVerificationLocalizationTest.php` | ar/en verify messages; dashboard Arabic status labels |
| `tests/Feature/LicenseExpirySyncTest.php` | effective expiry, sync command, token backfill |
| `tests/Feature/LicenseUnblockFlowTest.php` | unblock E2E; issue-license rejects unblock apps |
| `tests/Feature/DashboardLicenseIssuanceQueueTest.php` | ready queue, permissions, stale 422, successful issue |
| `tests/Feature/DashboardIssuedLicensesTest.php` | list/stats/block/unblock/lineage/audit |
| `tests/Feature/OtherLicenseServicesFlowTest.php` | application renew/lost/damaged + employee issue |
| `tests/Feature/DashboardLicenseTypesTest.php` | types CRUD |
| `tests/Feature/RateLimitEvidenceTest.php` | verify 429 |
| `tests/Feature/CriticalMutationAuthorizationTest.php` | dashboard block/unblock authz |
| Plus | `DashboardOverviewTest`, `CommitteeDemoSeederTest`, `FullLifecycleSeederTest`, `PaymentReconciliationAndDbInvariantEvidenceTest` (token unique) |

### Missing regression tests that matter **before** presentation redesign

Do not create these now; list only:

- Pixel/HTML fixture assertion that Blade still contains QR `<img>` and required fields after CSS restyle
- Assertion that PDF still starts with `%PDF` after template CSS changes (already partially in `LicensePrintingTest`)
- Dashboard component test that `DigitalLicenseCard` still reads `digital_license.*` keys
- Citizen download — **none**, because feature is absent
- Photo-on-license — **none**, because feature is absent
- Visual parity preview vs PDF — **none** (they are not the same renderer)
- Issuance 422 **machine codes** — none on POST body today
- Print status gate — none, because ungated

Protect issuance/QR/verify tests; they are the safety net for a visual-only change.

---

## 27. Legacy / Duplicate Code

Do **not** delete in this phase. Likely leftover / duplicate:

| Item | Note |
|---|---|
| `LicensePrintService::qrSvg` | defined, never called |
| Lang `licenses.actions.verification_token_rotated` | no rotate API |
| `POST /api/licenses/{id}/unblock-request` | deprecated; does not create application |
| Citizen `POST /licenses/{id}/renew` and `/replacement` | shortcuts vs official `POST /applications` |
| Admin block/unblock vs Dashboard block/unblock | parallel controllers, same `LicenseService` |
| `DigitalLicensePayload` TS comment | claims API verify URL; actual is frontend page |
| `docs/PUBLIC_LICENSE_VERIFICATION_FRONTEND_IMPLEMENTATION.md` | says logo is on login; login uses `/logo1.png`, verify uses `/logos/image.png` |
| Dev dashboard Blade licenses section | not production card |
| Three visual templates | Dashboard / PDF / public verify |
| `employee` role missing `view_licenses` | still can print via `manage_licenses` |

Historical paths **not** removed:

- `POST /api/admin/applications/{application}/issue-license` — **still the issuance endpoint**
- `/api/dashboard/licenses` — **still the registry**
- `GET /api/licenses/verify/{verificationToken}` — **still the JSON verifier**

Added since those historical notes: issuance **queue**, **print**, public **Next.js** page, dashboard block/unblock, application unblock.

**NOT FOUND:** `POST /api/dashboard/applications/{id}/issue-license`.

---

## 28. Complete File Dependency Map

```text
LICENSE ISSUANCE
Dashboard
├── src/app/(dashboard)/dashboard/license-issuance/page.tsx
├── src/features/license-issuance/LicenseIssuancePage.tsx
├── src/features/license-issuance/components/IssueLicenseDialog.tsx
├── src/features/license-issuance/components/LicenseIssuanceTable.tsx
├── src/features/license-issuance/components/LicenseIssuanceMobileCard.tsx
├── src/features/license-issuance/hooks/useIssueLicense.ts
├── src/features/license-issuance/hooks/useLicenseIssuanceQueue.ts
└── src/features/license-issuance/utils/licenseIssuancePermissions.ts

API Client
├── src/api/dashboardLicenseIssuanceApi.ts
└── src/lib/api/endpoints.ts   (licenseIssuance.issue → /admin/applications/{id}/issue-license)

Backend Route
└── app/Modules/Admin/Routes/admin.php
    (loaded from routes/api.php)

Controller
└── app/Modules/Admin/Controllers/ApplicationLicenseController.php

Service
├── app/Modules/Licenses/Services/LicenseService.php
├── app/Modules/Licenses/Services/LicenseIssuanceEligibilityService.php
├── app/Modules/Licenses/Services/LicenseLifecycleService.php
├── app/Modules/Licenses/Services/LicenseTransitionPolicy.php
└── app/Modules/Applications/Support/ServiceWorkflow.php

Resource
└── app/Modules/Licenses/Resources/LicenseResource.php

Models
├── app/Models/License.php
├── app/Models/LicenseApplication.php
├── app/Models/LicenseType.php
├── app/Models/LicenseStatusHistory.php
└── app/Models/User.php

Queue (readiness, not mutate)
├── app/Modules/Dashboard/Routes/dashboard.php
├── app/Modules/Dashboard/Controllers/DashboardLicenseIssuanceController.php
├── app/Modules/Dashboard/Services/DashboardLicenseIssuanceService.php
├── app/Modules/Dashboard/Resources/DashboardLicenseIssuanceApplicationResource.php
└── app/Modules/Dashboard/Support/DashboardLicenseIssuanceActions.php

ISSUED LICENSE UI
Dashboard
├── src/app/(dashboard)/dashboard/licenses/page.tsx
├── src/app/(dashboard)/dashboard/licenses/[licenseId]/page.tsx
├── src/features/licenses/LicensesListPage.tsx
├── src/features/licenses/LicenseDetailsPage.tsx
├── src/features/licenses/components/DigitalLicenseCard.tsx
├── src/features/licenses/components/LicenseInformationGrid.tsx
├── src/features/licenses/components/LicenseDetailsHeader.tsx
├── src/api/dashboardLicensesApi.ts
└── src/types/licenseManagement.ts

Backend
├── app/Modules/Dashboard/Controllers/DashboardIssuedLicenseController.php
├── app/Modules/Dashboard/Services/DashboardIssuedLicenseService.php
├── app/Modules/Dashboard/Support/DashboardLicenseActions.php
└── app/Modules/Licenses/Support/DigitalLicensePresenter.php

PDF
├── app/Modules/Licenses/Services/LicensePrintService.php
└── resources/views/licenses/digital-card.blade.php

QR
└── LicensePrintService::qrPngDataUri (endroid/qr-code)

VERIFICATION
Dashboard
├── src/app/licenses/verify/[verificationToken]/page.tsx
├── src/features/public-license-verify/PublicLicenseVerifyPage.tsx
├── src/features/public-license-verify/components/PublicVerifyLicenseCard.tsx
└── src/api/publicLicensesApi.ts

Backend
├── routes/api.php
├── app/Modules/Licenses/Controllers/LicenseVerificationController.php
└── app/Modules/Licenses/Services/LicenseVerificationService.php

CITIZEN CONTRACT
├── app/Modules/Licenses/Controllers/LicenseController.php
└── app/Modules/Licenses/Resources/LicenseResource.php

LOCALIZATION
├── resources/lang/ar/messages.php
├── resources/lang/en/messages.php
├── app/Support/Msg.php
├── app/Support/ArabicMessageTranslator.php
├── app/Support/CitizenMessageTranslator.php
└── app/Support/EmployeeMessageTranslator.php

CONFIG
├── config/license.php
└── config/dashboard_permissions.php
```

---

## 29. API Contract Table

Prefix: Laravel `routes/api.php` is mounted at `/api`.

| Purpose | Method | Endpoint | Auth | Permission | Request | Response | Frontend consumer |
|---|---|---|---|---|---|---|---|
| Issuance queue list | GET | `/api/dashboard/license-issuance/applications` | Sanctum + dashboard + session track | `issue_license` OR `view_applications` OR `manage_applications` | query: search, service/license type, dates, page | items + readiness + actions | `dashboardLicenseIssuanceApi.getReadyApplications` |
| Issuance queue detail | GET | `/api/dashboard/license-issuance/applications/{application}` | same | same | — | one queue item | `getIssuanceDetails` |
| **Issue license** | POST | `/api/admin/applications/{application}/issue-license` | Sanctum + dashboard | `issue_license` | `{}` | `LicenseResource` | `issueLicense` |
| Issued list | GET | `/api/dashboard/licenses` | Sanctum + dashboard + session | `view_licenses` OR `manage_licenses` | filters | items + pagination | `dashboardLicensesApi.getLicenses` |
| Issued stats | GET | `/api/dashboard/licenses/stats` | same | same | filters | counts | `getLicenseStats` |
| Issued options | GET | `/api/dashboard/licenses/options` | same | same | — | select options | `getLicenseOptions` |
| Issued details | GET | `/api/dashboard/licenses/{license}` | same | same | — | details + `digital_license` + `actions` | `getLicenseDetails` |
| History | GET | `/api/dashboard/licenses/{license}/history` | same | same | `per_page` | history page | `getLicenseHistory` |
| Audit logs | GET | `/api/dashboard/licenses/{license}/audit-logs` | same + in-controller `view_audit_logs` | `view_audit_logs` | `per_page` | audit page | `getLicenseAuditLogs` |
| **Print PDF** | POST | `/api/dashboard/licenses/{license}/print` | same | view OR manage licenses | empty | `application/pdf` blob | `printLicensePdf` |
| Dashboard block | POST | `/api/dashboard/licenses/{license}/block` | same | `manage_licenses` | `{ reason }` | details | `blockLicense` |
| Dashboard unblock | POST | `/api/dashboard/licenses/{license}/unblock` | same | `manage_licenses` | — | details | `unblockLicense` |
| Admin block | POST | `/api/admin/licenses/{license}/block` | Sanctum + dashboard | `manage_licenses` | `{ reason }` | `LicenseResource` | none in Dashboard client |
| Admin unblock | POST | `/api/admin/licenses/{license}/unblock` | same | `manage_licenses` | — | `LicenseResource` | none |
| Application unblock | POST | `/api/dashboard/applications/{application}/unblock-license` | Sanctum + dashboard | `manage_licenses` | — | application/license payload | applications UI |
| Reject approved unblock | POST | `/api/dashboard/applications/{application}/reject` | same | `manage_licenses` | `{ reason }` | | applications UI |
| Public verify JSON | GET | `/api/licenses/verify/{verificationToken}` | public + locale | none | — | `{ success, message, data }` | `publicLicensesApi.verify` |
| Citizen list | GET | `/api/licenses` | Sanctum + citizen | n/a | — | `LicenseResource[]` | Flutter (external) |
| Citizen details | GET | `/api/licenses/{license}` | same | n/a | — | `LicenseResource` | Flutter (external) |
| Citizen renew shortcut | POST | `/api/licenses/{license}/renew` | + `profile.approved` | n/a | — | `LicenseResource` | Flutter (external) |
| Citizen replacement shortcut | POST | `/api/licenses/{license}/replacement` | same | n/a | `{ type: lost\|damaged }` | `LicenseResource` | Flutter (external) |
| Citizen unblock ack (deprecated) | POST | `/api/licenses/{license}/unblock-request` | same | n/a | — | ack | Flutter (external) |
| Citizen PDF | — | — | — | — | — | — | **NOT IMPLEMENTED** |
| Public license types | GET | `/api/license-types` | public + locale | none | — | catalog | lookups |
| Dashboard license types | GET/POST/PATCH… | `/api/dashboard/license-types` | dashboard | `manage_settings` | | | settings UI |
| Citizen licenses (admin) | GET | `/api/dashboard/citizens/{citizen}/licenses` | dashboard | `manage_users` | — | `LicenseResource` collection | citizen details |
| Reports | GET | `/api/dashboard/reports/licenses` | dashboard | `view_reports` AND (view/manage/issue licenses) | | | reports UI |

---

## 30. Recommendations for the Next Implementation Prompt

These are handoff constraints, not a design:

1. Treat **three faces** as in-scope if visual consistency is required: Dashboard preview, PDF Blade, public verify card. Changing only React will leave the official printed license looking as it does today.
2. Keep `POST /api/admin/applications/{id}/issue-license` and `GET /api/licenses/verify/{token}` stable.
3. Do not put PII into the QR. Keep URL + token unless product explicitly wants a signed payload (that is a security project, not CSS).
4. Do not assume a citizen download exists. Add it only as a new contract with ownership tests.
5. Photo is the largest gap for a “world-class” card. It requires a data decision, not only CSS.
6. Move hardcoded Arabic labels into `messages.licenses.digital.*` (and Dashboard i18n if introduced) so AR/EN can stay aligned. Keep blocker/permission machine codes stable.
7. Set `LICENSE_VERIFICATION_PUBLIC_URL` in production **before** printing real cards; current default is localhost.
8. Run existing `LicensePrintingTest`, `LicenseVerificationTest`, `LicenseFlowTest`, and `DashboardLicenseIssuanceQueueTest` after any template change.
9. Decide whether PDF stays A4 document or becomes ID-1. That is a print-pipeline change (`Mpdf` `format`), not a Tailwind tweak.
10. Do not “fix” citizen renew/replace shortcuts or dual admin/dashboard block routes as part of a visual redesign unless explicitly scoped.

---

# IMPLEMENTATION HANDOFF FOR CHATGPT

```text
CURRENT_CARD_COMPONENT=DLMS_Dashboard/src/features/licenses/components/DigitalLicenseCard.tsx
CURRENT_CARD_STYLES=Tailwind utility classes inside DigitalLicenseCard.tsx (no CSS module). PDF styles are inline in digital-card.blade.php
CURRENT_LICENSE_DETAILS_PAGE=DLMS_Dashboard/src/features/licenses/LicenseDetailsPage.tsx  (route /dashboard/licenses/[licenseId])
CURRENT_ISSUANCE_PAGE=DLMS_Dashboard/src/features/license-issuance/LicenseIssuancePage.tsx  (route /dashboard/license-issuance)

ISSUE_LICENSE_ENDPOINT=POST /api/admin/applications/{application}/issue-license
ISSUE_LICENSE_FRONTEND_CLIENT=DLMS_Dashboard/src/api/dashboardLicenseIssuanceApi.ts :: issueLicense
ISSUE_LICENSE_CONTROLLER=App\Modules\Admin\Controllers\ApplicationLicenseController::issue
ISSUE_LICENSE_SERVICE=App\Modules\Licenses\Services\LicenseService::issueForApplication

LICENSE_RESOURCE=App\Modules\Licenses\Resources\LicenseResource
LICENSE_MODEL=App\Models\License
LICENSE_TYPE_SOURCE=license_types.code (private|public|truck|bus) via applications.license_type_id; NOT A/B/C categories

PDF_ENDPOINT=POST /api/dashboard/licenses/{license}/print
PDF_CONTROLLER=App\Modules\Dashboard\Controllers\DashboardIssuedLicenseController::print
PDF_SERVICE=App\Modules\Licenses\Services\LicensePrintService::printPdf
PDF_TEMPLATE=resources/views/licenses/digital-card.blade.php
PDF_LIBRARY=mpdf/mpdf v8.3.1
PDF_CARD_DIMENSIONS=A4 portrait (not ID-1). QR displayed 140x140, generated 220px.

QR_LIBRARY=endroid/qr-code 6.1.3
QR_GENERATOR=App\Modules\Licenses\Services\LicensePrintService::qrPngDataUri
QR_PAYLOAD=public frontend URL only: {config license.verification_public_url}/{verification_token}
QR_VERIFICATION_URL=default http://localhost:3000/licenses/verify/{token}  (.env LICENSE_VERIFICATION_PUBLIC_URL NOT FOUND in this checkout)
QR_VERIFY_ROUTE=GET /api/licenses/verify/{verificationToken}
QR_VERIFY_CONTROLLER=App\Modules\Licenses\Controllers\LicenseVerificationController::show
QR_VERIFY_RESPONSE=JSON { success, message, data: { valid, status, status_label, license_number, holder_name, license_type, issue_date, expiry_date, message, verified_at } }  HTTP 200 even when valid=false. Next.js page /licenses/verify/[verificationToken] consumes this.

CITIZEN_LICENSE_LIST_ENDPOINT=GET /api/licenses
CITIZEN_LICENSE_DETAILS_ENDPOINT=GET /api/licenses/{license}
CITIZEN_LICENSE_DOWNLOAD_ENDPOINT=NOT IMPLEMENTED

PHOTO_SOURCE=NOT ON LICENSE. Application documents personal_photo / recent_personal_photo (application_documents.file_path). users table has no photo column.

LOGO_ASSETS=DLMS_Dashboard/public/logo1.png (268x268, login); DLMS_Dashboard/public/logos/image.png (1024x1024, sidebar + public verify + OG). Neither used on PDF or DigitalLicenseCard. Backend public/ has no license logo.

LOCALIZATION_FILES=DLMS_Project/resources/lang/ar/messages.php ; DLMS_Project/resources/lang/en/messages.php ; Msg/ArabicMessageTranslator for dashboard+PDF digital strings ; CitizenMessageTranslator for citizen/verify API ; Dashboard UI hardcoded Arabic (no next-intl)

CARD_FIELDS=authority, title, license_number, status_label, holder_name, license_type, issue_date, expiry_date, verification_guidance, public verify link (no photo, no QR graphic)
PDF_FIELDS=authority, title, license_number, holder_name, license_type.label, issue_date, expiry_date, status_label, QR image, verification_guidance, generated_at
VERIFY_FIELDS=valid, status, status_label, license_number, holder_name, license_type, issue_date, expiry_date, message, verified_at

PERMISSIONS=issue_license ; view_licenses ; manage_licenses ; view_audit_logs ; view_applications ; manage_applications ; manage_users (national_id) ; access_dashboard. print_license NOT FOUND.

RENEWAL_BEHAVIOR=new licenses row, new number, new token, old status=renewed, new expiry = now + config validity_years (default 10). Same card/PDF template.
LOST_REPLACEMENT_BEHAVIOR=new licenses row, new number, new token, old status=inactive, expiry copied from old. Same template. No “lost” marker on card.
DAMAGED_REPLACEMENT_BEHAVIOR=same as lost replacement (service_code distinguished in history metadata only). Same template. No “damaged” marker on card.

SAFE_FILES_TO_REDESIGN=DLMS_Dashboard/src/features/licenses/components/DigitalLicenseCard.tsx ; DLMS_Project/resources/views/licenses/digital-card.blade.php ; optionally PublicVerifyLicenseCard.tsx / PublicVerifyStatusHero.tsx / PublicLicenseVerifyPage.tsx for visual family
FILES_THAT_MUST_NOT_CHANGE=LicenseService issuance/renew/replace/block ; LicenseIssuanceEligibilityService ; LicenseTransitionPolicy ; LicenseRepository::generateUniqueLicenseNumber ; LicenseLifecycleService token generation rules ; LicenseVerificationService validity/PII rules ; route URLs and permission names ; citizen ownership queries

MISSING_DATA=English name ; portrait on license ; nationality ; blood type ; restrictions ; signature ; place of issue ; vehicle category pictograms ; structured issuing authority ; birth_date/national_id on card payload
KNOWN_LIMITATIONS=three divergent renderers ; PDF navy vs dashboard green ; no citizen PDF/QR ; issuance 422 has no machine code ; validity_years catalog unused (10 vs seeded 5) ; LICENSE_VERIFICATION_PUBLIC_URL default localhost ; can_print always true ; citizen renew/replace shortcuts issue immediately ; Dashboard Next.js was not running so pixels UNKNOWN WITHOUT RUNTIME ; Flutter not in these repos
TESTS_PROTECTING_FLOW=tests/Feature/LicenseFlowTest.php ; LicensePrintingTest.php ; LicenseVerificationTest.php ; LicenseVerificationLocalizationTest.php ; DashboardLicenseIssuanceQueueTest.php ; DashboardIssuedLicensesTest.php ; OtherLicenseServicesFlowTest.php ; LicenseUnblockFlowTest.php ; LicenseExpirySyncTest.php
```
