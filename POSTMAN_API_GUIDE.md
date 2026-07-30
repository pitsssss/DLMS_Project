# POSTMAN API GUIDE - DLMS / SYRTAK

## 1) Import and Setup
- Import `DLMS_API_Postman_Collection.json` into Postman.
- Collection variables:
  - `base_url = http://127.0.0.1:8000`
  - `api_prefix = /api`
- Keep all token variables empty initially (`citizen_token`, `employee_token`, `admin_token`, and role-specific dashboard tokens).

## Collection Layout
Top-level folders:
1. `00. Public & System` — ping, public content, reference data, license verify
2. `01. Citizen App` — all citizen modules (Auth → Profile → Applications → … → AI Agent)
3. `02. Dashboard` — all dashboard/admin modules in one place
4. `03. Payment Callbacks & Webhooks`
5. `04. Error & Security Scenarios`

Request names describe **who** + **what** (example: `Citizen - Register New Account`).

## 2) Authentication and Tokens
- Citizen authentication (order matters):
  1. `Citizen - Register New Account`
  2. `Citizen - Verify OTP (Registration)`
  3. `Citizen - Login` → stores `citizen_token`
- Dashboard authentication:
  - `Dashboard - Employee/Admin Login` → stores `employee_token` / `admin_token`
- For permission testing, copy `employee_token` into role-specific token variables as needed.

## 3) Citizen Demo Flow
1. Login citizen.
2. Check/update/complete profile.
3. Create application.
4. Get required documents.
5. Upload application documents.
6. Submit documents.
7. Check application fee.
8. Start payment and follow status/confirm endpoints.
9. Get available tests and appointment slots.
10. Book appointment (and optionally reschedule/cancel).
11. Read test results.
12. Read licenses and fines.

## 4) Dashboard Demo Flow
1. Login dashboard employee.
2. Open overview and reports.
3. Review documents (approve/reject).
4. Manage citizens and applications.
5. Manage appointment slots.
6. Record test result.
7. Issue license.
8. Manage fines, payments, and system settings.

## 5) AI Agent Flow
1. Login citizen.
2. Send message (`/ai-agent/message`) to create/continue session.
3. List/show sessions.
4. Confirm/cancel pending action.
5. Upload session document (`/ai-agent/sessions/{session}/documents`).

## 6) File Upload Guidance
- Endpoints use `form-data`.
- Use:
  - `required_document_id` (text)
  - `file` (file picker in Postman)
- Do not manually set `multipart/form-data` content type; Postman generates boundary.
- Use test files only (no private sensitive files).

## 7) Error and Security Scenarios
- Folder `28. Error & Security Scenarios` includes ready-to-run examples for:
  - `401` unauthenticated
  - `403` wrong role
  - `422` validation failure
- Additional security checks can be run by calling foreign resource IDs (`foreign_application_id`, etc.) to verify `403/404` behavior.

## 8) Variables and Auto-ID Behavior
- Collection has IDs for citizen/applications/documents/payments/appointments/licenses/fines/AI sessions/actions.
- Some create/login requests automatically store IDs/tokens using test scripts.
- Pre-request script updates `unique_suffix` (timestamp) for generating non-colliding demo values.

## 9) Webhook Notes
- `27. Payment Callbacks & Webhooks` includes Stripe webhook endpoint.
- Requires local gateway/signature setup; request does not imply successful payment without provider integration.
- No real secrets are stored in collection.

## 10) Coverage Notes
- Source of truth: `php artisan route:list --path=api`.
- Total discovered API routes: `173`.
- Included in collection: `173`.
- Excluded: `0`.
- Previous collection request count: `108`.