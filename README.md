# Digital License Management System (DLMS)

**DLMS** is a Laravel 11 RESTful API backend for a government-style digital driving license management platform.

The system manages the full lifecycle of driving license services: citizen registration, profile completion, license applications, document upload and review, mock electronic payments, test appointment booking, test result recording, license issuance, license renewal, lost/damaged replacement, license unblocking, fines, notifications, audit logs, reports, and a controlled AI service agent for citizens (Phase 9A).

---

# Table of Contents

* Project Overview
* Main Objectives
* Core Features
* System Actors
* Main Workflow
* Tech Stack
* Architecture
* Modules
* Database Overview
* Business Rules
* Application Statuses
* Authentication and Security
* API Response Format
* Installation
* Environment Configuration
* Default Seeded Users
* API Routes Summary
* Testing
* Developer Testing Dashboard
* Postman Collection
* AI Service Agent (Phase 9)
* Project Structure
* Docker & Ghaymah Cloud Deployment
* Development Guidelines
* Mock Services
* Future Enhancements

---

# Project Overview

The **Digital License Management System (DLMS)** is designed to digitize and organize driving license services in a realistic public-sector environment.

The platform provides:

* A mobile API for citizens.
* A single admin dashboard API for employees and administrators.
* A secure, modular, maintainable Laravel backend.
* A structured workflow for license services from application submission to final license issuance.

The project focuses on:

* Functional requirements.
* Non-functional requirements.
* Workflow design.
* Security.
* Scalability.
* Maintainability.
* Auditability.

---

# Main Objectives

The system aims to:

1. Digitize driving license services.
2. Reduce manual paperwork.
3. Organize citizen applications and employee processing.
4. Enforce business rules automatically.
5. Provide clear application tracking.
6. Support sequential driving tests.
7. Provide mock payment handling.
8. Support role-based access control.
9. Store audit logs for sensitive actions.
10. Provide a maintainable modular backend architecture.

---

# Core Features

## Citizen Features

* Register account.
* Verify phone number using mock OTP.
* Login and logout using Laravel Sanctum.
* Complete and update profile.
* Submit new license application.
* Upload required documents.
* Pay service, test, and fine fees.
* Book test appointments.
* Reschedule or cancel appointments.
* View test results.
* Retake failed tests.
* Track application status.
* View issued licenses.
* Request license renewal.
* Request lost/damaged license replacement.
* Request license unblock.
* View notifications.
* Use the controlled AI service agent (Phase 9A) to navigate license services step by step.

---

## Employee Features

* Login to admin dashboard API.
* View license applications.
* Review citizen documents.
* Approve or reject documents.
* Record test results.
* Issue licenses.
* Manage applications according to assigned permissions.

---

## Admin Features

* Manage users.
* Manage roles and permissions.
* Manage license types.
* Manage service types.
* Manage test types.
* Manage required documents.
* Manage fees.
* Manage appointment slots.
* Manage fines.
* Block or unblock licenses.
* View reports.
* View audit logs.
* View application status histories.

---

# System Actors

| Actor                | Description                                                                                                                       |
| -------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| Citizen              | Uses the mobile app to submit applications, upload documents, pay fees, book appointments, and track application status.          |
| Employee             | Uses the admin dashboard to process applications, review documents, record test results, and issue licenses based on permissions. |
| Admin                | Has full access to system settings, users, roles, reports, audit logs, and administrative operations.                             |
| Payment Gateway      | Configurable: mock confirmation or Stripe Checkout (test/live keys via `.env`).                                                                 |
| Notification Service | Internal service for storing and sending database notifications.                                                                  |
| OTP Service          | Mock OTP service used for phone verification.                                                                                     |

---

# Main Workflow

The main workflow for issuing a new driving license is:

1. Citizen registers and verifies phone number.
2. Citizen completes profile.
3. Citizen submits a new license application.
4. Citizen uploads required documents.
5. Employee reviews documents.
6. Citizen pays required fees.
7. Citizen books test appointments.
8. Employee records test results.
9. System enforces the test sequence:

   * Vision Test
   * Theory Test
   * Practical Test
10. System verifies all requirements.
11. Employee issues the license.
12. Citizen receives notification.

---

# Tech Stack

* Backend Framework: Laravel 11
* Language: PHP 8.2+
* Database: MySQL
* Authentication: Laravel Sanctum
* API Type: RESTful API
* Validation: Laravel Form Requests
* Authorization: Custom RBAC with middleware
* Architecture: Modular architecture with service and repository layers
* Testing: Laravel Feature Tests
* File Storage: Laravel Storage
* API Testing: Postman

---

# Architecture

The project follows a clean modular architecture.

## Main Principles

* Controllers receive requests and return responses only.
* Services contain business logic.
* Repositories handle database access.
* Form Requests validate request data.
* API Resources format responses.
* Middleware protects routes based on role and permission.
* Enums/constants define system statuses and types.
* Audit logs record sensitive actions.
* Important database operations use transactions.

---

# Modules

| Module        | Responsibility                                                                   |
| ------------- | -------------------------------------------------------------------------------- |
| Auth          | Registration, OTP verification, login, logout, profile, and password management. |
| Users         | User management, roles, and permissions.                                         |
| Applications  | License service requests and application lifecycle.                              |
| Documents     | Document upload, review, approval, and rejection.                                |
| Payments      | Application fees: mock confirmation or Stripe Checkout with webhook completion.   |
| Appointments  | Appointment slots, booking, rescheduling, and cancellation.                      |
| Tests         | Test results, test sequence, and retake logic.                                   |
| Licenses      | License issuance, renewal, replacement, blocking, and unblocking.                |
| Settings      | License types, service types, test types, fees, and required documents.          |
| Fines         | Fine creation, updating, and payment handling.                                   |
| Notifications | Database notifications.                                                          |
| AuditLogs     | Sensitive operation logging.                                                     |
| Reports       | Admin reports and analytics.                                                     |
| Chatbot       | Simple rule-based assistant endpoint.                                            |

---

# Database Overview

The main database tables include:

* users
* roles
* permissions
* permission_role
* otps
* license_types
* service_types
* test_types
* required_documents
* fees
* license_applications
* application_status_histories
* application_documents
* payments
* appointment_slots
* test_appointments
* test_results
* licenses
* fines
* notifications
* audit_logs

---

# Business Rules

The system enforces these key rules:

1. A citizen cannot submit a service request before completing their profile.
2. A citizen cannot create duplicate active applications for the same license type and service type.
3. Required documents must be uploaded before review.
4. Uploaded documents are not automatically approved.
5. Rejected documents require a rejection reason.
6. Payment is required before booking tests.
7. Duplicate successful payments are prevented.
8. Tests must follow this order:

   * Vision
   * Theory
   * Practical
9. A citizen cannot skip tests.
10. If a citizen fails a test, they can retake only the same failed test.
11. No-show is different from failed.
12. License issuance requires approved documents, completed payments, passed tests, and no active blocking fines.
13. License numbers must be unique.
14. Important actions must be recorded in audit logs.
15. Important records must not be hard-deleted if they have dependencies.

---

# Application Statuses

| Status                 | Meaning                                                   |
| ---------------------- | --------------------------------------------------------- |
| draft                  | Application is created but not submitted for review.      |
| documents_under_review | Required documents are waiting for employee review.       |
| documents_rejected     | One or more documents were rejected.                      |
| payment_pending        | Application is waiting for payment.                       |
| payment_completed      | Required payment has been completed.                      |
| appointment_pending    | Citizen can book required test appointment.               |
| in_testing             | Application is in the testing stage.                      |
| waiting_retest         | Citizen must retake a failed test.                        |
| approved               | Application meets requirements and is ready for issuance. |
| license_issued         | License has been issued.                                  |
| rejected               | Application has been rejected.                            |
| cancelled              | Application has been cancelled.                           |
| administrative_review  | Application requires administrative review.               |

---

# Authentication and Security

The project uses **Laravel Sanctum** for API authentication.

## Security Features

* Token-based authentication.
* Password hashing.
* Role-based access control.
* Permission-based middleware.
* Profile completion middleware.
* Citizen resource ownership checks.
* Validation through Form Requests.
* No sensitive payment data storage.
* Audit logging for sensitive operations.

---

# API Response Format

All API responses follow a unified structure.

## Success Response

```json
{
  "success": true,
  "message": "Success message",
  "data": {}
}
```

---

## Error Response

```json
{
  "success": false,
  "message": "Error message",
  "errors": {}
}
```

---

# Installation

## 1. Clone the Repository

```bash
git clone <repository-url>
cd dlms-backend
```

---

## 2. Install Dependencies

```bash
composer install
```

---

## 3. Create Environment File

```bash
cp .env.example .env
```

---

## 4. Generate Application Key

```bash
php artisan key:generate
```

---

## 5. Install API and Sanctum

```bash
php artisan install:api
```

---

## 6. Configure Database

Update `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dlms_backend
DB_USERNAME=root
DB_PASSWORD=
```

---

## 7. Run Migrations and Seeders

```bash
php artisan migrate:fresh --seed
```

---

## 8. Create Storage Link

```bash
php artisan storage:link
```

---

## 9. Start Development Server

```bash
php artisan serve
```

The API will be available at:

```text
http://127.0.0.1:8000/api
```

---

# Default Seeded Users

## Admin

```text
Phone: 0999999999
Password: password
Role: admin
```

---

## Employee

```text
Phone: 0988888888
Password: password
Role: employee
```

---

## Citizen

```text
Phone: 0977777777
Password: password
Role: citizen
```

---

## Demo citizens — Other License Services testing

These accounts are seeded by `DemoLicenseServiceTestingSeeder` (included in `php artisan db:seed`).

| Flow | Email | Password |
| ---- | ----- | -------- |
| Renewal | renew.citizen@example.com | password123 |
| Lost replacement | lost.citizen@example.com | password123 |
| Damaged replacement | damaged.citizen@example.com | password123 |

Each account has an approved profile and one private license ready for manual testing. No active service applications are pre-created.

**Testing steps:**

1. Login with the desired citizen (`POST /api/auth/login`).
2. `GET /api/licenses` — copy `id` and check eligibility flags (`can_renew`, `can_request_lost_replacement`, `can_request_damaged_replacement`).
3. `POST /api/applications` with `service_type_code` and `related_license_id`:
   - `renew_license`
   - `lost_replacement`
   - `damaged_replacement`
4. Continue with required documents, payment, and employee issuance.

**Run seeder only:**

```bash
php artisan db:seed --class=DemoLicenseServiceTestingSeeder
```

---

# AI Service Agent (Phase 9)

Phase 9 adds a **controlled AI agent** for citizens — not a generic chatbot. The backend owns safety rules, structured model output validation, session history, proposed actions, and audit-friendly evaluations.

## Phase plan

| Sub-phase | Scope |
| --------- | ----- |
| **9A (current)** | Foundation: Gemini integration, intent/slot handling, sessions/messages/actions/evaluations, **proposed actions only** (no execution). |
| **9B (current — batch 1)** | Confirm/cancel endpoints; safe read/create actions via existing services. |
| **9B (later)** | Payments, appointments, and reschedule/cancel via AI agent. |
| **9C (future)** | Admin monitoring APIs and analytics reports. |

## Phase 9A limitations

* Citizen-only endpoints (`auth:sanctum` + `citizen` middleware).
* No real system actions are executed (no applications, payments, appointments, document submission, etc.).
* Proposed actions are stored as `pending` or `awaiting_confirmation` only.
* Admin-only operations are rejected with a polite message.
* Invalid or failed Gemini responses fall back to safe rule-based replies.

## Environment variables

Set storage and business timezones:

```env
APP_TIMEZONE=UTC
BUSINESS_TIMEZONE=Asia/Damascus
```

- `APP_TIMEZONE` controls Laravel’s application/storage datetime convention (UTC).
- `BUSINESS_TIMEZONE` controls Syrian business-day calculations (Overview periods, “today”, chart buckets).
- After changes, run `php artisan config:clear` locally, or `php artisan config:cache` in production.
- Do not migrate historical timestamps solely for a timezone config change.

Add to `.env` (see `.env.example`):

```env
AI_PROVIDER=gemini
GEMINI_API_KEY=your-gemini-api-key
GEMINI_MODEL=gemini-2.5-flash
AI_AGENT_ENABLED=true
AI_AGENT_REQUIRE_CONFIRMATION=true
AI_AGENT_MAX_HISTORY_MESSAGES=10
AI_AGENT_TEMPERATURE=0.2
```

Configuration is read from `config/ai.php`. **Never expose `GEMINI_API_KEY` to the Flutter/mobile client or Postman collection variables** — only the Laravel backend calls Gemini.

## Citizen API routes

| Method | Route | Description |
| ------ | ----- | ----------- |
| POST | `/api/ai-agent/message` | Send a message; creates or continues a session. |
| GET | `/api/ai-agent/sessions` | List the citizen’s AI sessions. |
| GET | `/api/ai-agent/sessions/{session}` | Show session messages and proposed actions. |
| POST | `/api/ai-agent/sessions/{session}/documents` | Upload a required document inside an AI agent session (multipart/form-data). |

Example `POST /api/ai-agent/message` response:

```json
{
  "success": true,
  "message": "AI agent response generated successfully.",
  "data": {
    "session_id": 1,
    "reply": "ما نوع الرخصة التي تريدها؟",
    "intent": "create_new_license_application",
    "confidence": 0.91,
    "missing_slots": ["license_type"],
    "requires_confirmation": false,
    "pending_action": null
  }
}
```

When slots are complete, `pending_action` may be returned with `status: awaiting_confirmation` — the action is **not executed** until the citizen confirms it (Phase 9B).

## Phase 9B — Controlled action execution (first batch)

Citizens can confirm or cancel their own pending actions:

| Method | Route | Description |
| ------ | ----- | ----------- |
| POST | `/api/ai-agent/actions/{action}/confirm` | Confirm and execute an action in `awaiting_confirmation`. |
| POST | `/api/ai-agent/actions/{action}/cancel` | Cancel a pending action. |

**Executable in this batch:** `create_application`, `get_application_status`, `get_required_documents`, `get_fines`, `get_licenses`, `get_test_results` (read-only), `submit_documents_for_review` (requires confirmation).

**Not yet executable via AI agent:** payments, appointments, reschedule/cancel appointment.

Execution uses existing services only (`ApplicationService`, `ApplicationDocumentService`, `FineService`, `LicenseService`). Admin-only actions are rejected with **403** and marked `failed`.

## AI Agent Phase 2 — Document Upload Workflow

Citizens can upload required documents from inside the chat UI via:

`POST /api/ai-agent/sessions/{session}/documents`

### Request (multipart/form-data)
- `application_id` (int)
- `required_document_id` (int)
- `file` (uploaded file)

### Behavior (Phase 2.1)
- Upload uses the same `ApplicationDocumentService::upload()` rules as the REST document endpoints.
- **Real MIME validation** uses Fileinfo-backed `$file->getMimeType()` (not client-declared MIME alone), mapped via `AllowedDocumentMime` (`pdf`→`application/pdf`, `jpg/jpeg`→`image/jpeg`, `png`→`image/png`). Spoofed files (e.g. text renamed to `.pdf`) are rejected.
- **Approved documents cannot be replaced.** Rejected documents can be re-uploaded while the application is still editable (`Draft` / `DocumentsRejected`).
- Replacement of a non-approved previous upload **deletes the previous DB row** and creates a new one. There is **no document version history / lineage** and no antivirus scanning in this phase.
- Files are stored on the private `local` disk under `application_documents/{application_id}/` with UUID filenames.
- The AI agent does **not** analyze or read the file content (Gemini is not used for the upload endpoint).
- Upload does **not** transition the application automatically to `documents_under_review`.
  - `Draft` stays `Draft`
  - `DocumentsRejected` stays `DocumentsRejected`
- After all required documents are uploaded, the citizen must send a separate message to propose `submit_documents_for_review`, then confirm. The application then appears in the **shared** dashboard document-review queue for any employee with `review_documents` (not assigned to a single employee).

### Session context after upload
Safe referential IDs only:
- `last_application_id`
- `last_uploaded_document_id` (`application_documents.id`)
- `last_required_document_id` (`required_documents.id`)

Never stores filename, storage path, MIME, binary, Base64, or URLs.

### Response (data)
The endpoint returns a stable checklist payload (completed/missing/rejected/pending_review), plus:
- `checklist.all_required_uploaded`
- `checklist.can_submit_for_review`
- `agent_reply` (instructional text for the next step)

### Errors (typical)
- `401` if not authenticated as citizen
- `404` if session/application not found for this citizen
- `422` if the file is invalid (type/extension/MIME/size/empty), application status does not allow edits, document type is not applicable, or an Approved document would be replaced

### Remaining known limitations
- No document version history / soft-delete lineage on replacement.
- No external antivirus / malware scanning beyond MIME + extension checks.
- No Flutter `message_type` / `ui_payload` contract yet (later phase).

### End-to-end citizen flow

1. `POST /api/ai-agent/message` — e.g. `بدي رخصة جديدة`
2. `POST /api/ai-agent/message` with `session_id` — e.g. `رخصة خاصة` → `pending_action` created
3. `POST /api/ai-agent/actions/{id}/confirm` → application created (draft)
4. `POST /api/ai-agent/sessions/{session}/documents` — upload each required document
5. `POST /api/ai-agent/message` — `أرسل الوثائق للمراجعة` → pending submit action
6. `POST /api/ai-agent/actions/{id}/confirm` → `documents_under_review` + shared review queue
7. Or `POST /api/ai-agent/actions/{id}/cancel` to abort without status change

## Postman (Phase 9A + 9B)

Import `DLMS_API_Postman_Collection.json`. After citizen login:

**Phase 9A - AI Agent**

1. **Send AI Agent Message** — saves `ai_agent_session_id` (and `ai_agent_action_id` when present).
2. **Continue AI Agent Session** — send `session_id` with a follow-up message (e.g. license type).
3. **List / Show AI Agent Sessions**.

**Phase 9B - AI Agent Actions**

4. **Confirm AI Agent Action** — executes the pending action; may save `application_id`.
5. **Cancel AI Agent Action** — cancels instead of executing.

## Testing the AI agent

```bash
php artisan test --filter=AIAgent
```

Tests mock `GeminiAgentClient` and do not call the real Gemini API.

---

# Developer Testing Dashboard

**Internal developer tool only — not the production admin dashboard.**

| Item | Detail |
|------|--------|
| URL | `GET /dev-dashboard` |
| Allowed environments | `local`, `staging`, `testing` only |
| Production | Returns **404** (middleware `EnsureDevDashboardAllowed`) |

## Purpose

- Exercise DLMS API flows without Postman
- Run citizen, employee, admin, and AI agent steps from one Blade UI
- Store tokens and entity IDs in the Laravel **session**
- View raw JSON responses, HTTP status, and saved variables after each action

## Usage

1. Start the app locally (`php artisan serve` or Docker).
2. Open `/dev-dashboard` in the browser.
3. Use section buttons (Auth → Applications → Documents → …) or **One-click Scenarios**.
4. Watch the **Status Panel** and **Raw API Response** at the top.
5. Click **Clear Session** or **Reset Dashboard Session** to wipe stored tokens/IDs.

## Session variables

Examples: `citizen_token`, `employee_token`, `admin_token`, `application_id`, `payment_id`, `appointment_id`, `license_id`, `ai_agent_session_id`, `ai_agent_action_id`, and related IDs. Tokens are shown shortened in the UI; full values stay in session server-side.

## Flows covered

Auth (register/OTP/login/profile), applications, documents (upload/review), payments (mock confirm), appointments & test results, licenses & fines, notifications, reports, audit logs, AI agent (message/confirm/cancel), and chained scenarios.

## Limitations

- Does **not** replace Postman for every edge case or Stripe webhooks (use Stripe CLI).
- Calls real `/api/*` routes via Laravel HTTP client (no direct service bypass).
- Document upload scenarios may still need a real file where required.
- **Never deploy or expose `/dev-dashboard` in production.**

---

# Testing

Run all tests:

```bash
php artisan test
```

---

# Project Structure

```text
app/
  Enums/
  Models/
  Modules/
    Auth/
    Users/
    Applications/
    Documents/
    Payments/
    Appointments/
    Tests/
    Licenses/
    Settings/
    Reports/
    AIAgent/
    Notifications/
    AuditLogs/

database/
  migrations/
  seeders/

routes/
  api.php

tests/
  Feature/
```

---

# Docker & Ghaymah Cloud Deployment

This project includes a production-ready Docker setup for deploying the Laravel API on **Ghaymah Cloud** (Docker-only deployments) and a **docker-compose** stack for local testing only.

## Files added for Docker

| File | Purpose |
| ---- | ------- |
| `Dockerfile` | Production image: Laravel, PHP 8.2-FPM, Nginx, Supervisor (port 80) |
| `.dockerignore` | Excludes secrets, vendor, caches, and local-only files from the image |
| `docker-compose.yml` | Local testing only: app + MySQL + phpMyAdmin |
| `.env.docker.example` | Example production environment variables (no real secrets) |
| `docker/nginx/default.conf` | Nginx virtual host for Laravel |
| `docker/php/php.ini` | PHP upload limits and Opcache settings |
| `docker/php/entrypoint.sh` | Container startup: permissions, optional migrations, Supervisor |
| `docker/supervisor/supervisord.conf` | Runs PHP-FPM and Nginx in one container |

The production image does **not** include MySQL, phpMyAdmin, or a `.env` file.

---

## Local Docker (docker-compose)

`docker-compose.yml` is for **local development and testing only**. It is not the Ghaymah production deployment method.

### 1. Configure `.env` for local Docker

Copy or adjust your local `.env` (your normal XAMPP workflow is unchanged). For Docker Compose, use the compose MySQL service:

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=dlms_backend
DB_USERNAME=dlms_user
DB_PASSWORD=dlms_password
```

**Important:** `DB_HOST=mysql` is only valid inside docker-compose (the MySQL service name). It must **not** be used on Ghaymah production.

Because the app directory is mounted into the container, Laravel reads `.env` from your project root. If `vendor/` is missing on the host, install dependencies inside the container:

```bash
docker compose exec app composer install
```

### 2. Build and run

```bash
docker compose build
docker compose up -d
```

### 3. First-time setup (local)

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan storage:link
```

### 4. Useful local commands

```bash
docker compose down
docker compose exec app bash
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan route:list
docker compose exec app php artisan test
```

### 5. Local URLs

| Service | URL |
| ------- | --- |
| API | http://localhost:8000/api |
| Health check | http://localhost:8000/api/ping |
| phpMyAdmin | http://localhost:8080 |

MySQL from the host machine: `127.0.0.1:3307` (user `dlms_user`, database `dlms_backend`).

### 6. Stripe webhook (local Docker)

```bash
stripe listen --forward-to http://localhost:8000/api/webhooks/stripe
```

Use the signing secret from the CLI as `STRIPE_WEBHOOK_SECRET`, then `docker compose exec app php artisan config:clear`.

---

## Production Docker image (Ghaymah Cloud)

Ghaymah Cloud deploys the **Dockerfile** image. The container exposes **port 80**. Database access uses **Ghaymah Managed MySQL** via environment variables only—no MySQL inside the container.

### Build production image locally

```bash
docker build -t dlms-api .
```

### Run production image locally (smoke test)

Copy `.env.docker.example` to a local file, fill in placeholders (including `APP_KEY`), then:

```bash
docker run --rm -p 8000:80 --env-file .env.docker.example dlms-api
```

Test: http://localhost:8000/api/ping

---

## Ghaymah Cloud environment variables

On Ghaymah Cloud, configure all values in the platform **environment variables** panel. Do not commit real secrets. See `.env.docker.example` for the full list.

| Variable | Notes |
| -------- | ----- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | Your real Ghaymah HTTPS domain |
| `APP_KEY` | Generate with `php artisan key:generate --show` and set in Ghaymah (required) |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` | Host from **Ghaymah Managed MySQL** (not `mysql`, not `127.0.0.1`) |
| `DB_PORT` | Port from Ghaymah (often `3306`) |
| `DB_DATABASE` | Managed database name |
| `DB_USERNAME` | Managed database user |
| `DB_PASSWORD` | Managed database password |
| `FILESYSTEM_DISK` | `public` (after `php artisan storage:link` on deploy) |
| `MAIL_*` | Gmail SMTP (or your provider); Laravel 11 uses `MAIL_SCHEME=tls` |
| `PAYMENT_PROVIDER` | `stripe` for production Checkout |
| `STRIPE_*` | Test/live keys and webhook secret from Stripe Dashboard |
| `OTP_*` | OTP settings; leave `OTP_FIXED_CODE` empty in production |

Optional container flags (Ghaymah env):

| Variable | Default | Purpose |
| -------- | ------- | ------- |
| `RUN_MIGRATIONS` | `false` | Set `true` once to run `php artisan migrate --force` on startup |
| `RUN_SEEDERS` | `false` | Set `true` to run `php artisan db:seed --force` (use with care) |

Never enable `migrate:fresh` via environment variables.

---

## Ghaymah Managed MySQL

| Environment | `DB_HOST` |
| ----------- | --------- |
| Local docker-compose | `mysql` (Docker service name) |
| Ghaymah production | Hostname provided by Ghaymah Managed MySQL |
| Local XAMPP (non-Docker) | `127.0.0.1` |

XAMPP MySQL is **not** deployed automatically to Ghaymah. You must provision Ghaymah Managed MySQL and point the app at it with the variables above.

---

## Database migration on Ghaymah

**Option 1 — recommended (clean demo/academic deploy):**

```bash
# One-time via Ghaymah shell, or set RUN_MIGRATIONS=true once
php artisan migrate --force
php artisan db:seed --force
```

**Option 2:** Export SQL from local XAMPP phpMyAdmin and import into Ghaymah Managed MySQL.

For most deployments, migrations and seeders are preferable to copying a dev database.

---

## Storage and uploads

Uploaded files use Laravel `storage/app` (including `storage/app/public` and `storage/app/private`). The entrypoint ensures directories exist and permissions are set for `www-data`.

After deploy, run once:

```bash
php artisan storage:link
```

This links `public/storage` to `storage/app/public` for public disk files. Private document behavior is unchanged.

---

## Flutter / mobile client base URL

| Environment | `baseUrl` |
| ----------- | --------- |
| Ghaymah production | `https://your-ghaymah-domain.com/api` |
| Local Docker (desktop) | `http://localhost:8000/api` |
| Android emulator → host Docker | `http://10.0.2.2:8000/api` |

Do **not** use `http://127.0.0.1:8000/api` for a backend deployed on Ghaymah.

---

## Stripe on Ghaymah production

1. Set `PAYMENT_PROVIDER=stripe` and all `STRIPE_*` variables in Ghaymah.
2. Set `STRIPE_SUCCESS_URL` and `STRIPE_CANCEL_URL` to your real frontend URLs on the Ghaymah domain.
3. In [Stripe Dashboard → Webhooks](https://dashboard.stripe.com/webhooks), add endpoint:

   ```text
   https://your-ghaymah-domain.com/api/webhooks/stripe
   ```

4. Copy the signing secret to `STRIPE_WEBHOOK_SECRET` on Ghaymah.
5. Use HTTPS only in production.

---

## How the production Dockerfile works

1. **Base:** `php:8.2-fpm` with Nginx, Supervisor, and PHP extensions required by Laravel (`pdo_mysql`, `gd`, `zip`, `intl`, `opcache`, etc.).
2. **Build:** Copies the application, runs `composer install --no-dev --optimize-autoloader`.
3. **Config:** Installs Nginx site, custom `php.ini`, Supervisor, and `entrypoint.sh`.
4. **Permissions:** `storage` and `bootstrap/cache` owned by `www-data`.
5. **Runtime:** `entrypoint.sh` prepares storage, optionally clears caches (if `APP_KEY` is set), optionally runs migrations/seeders, then starts Supervisor.
6. **Supervisor** runs PHP-FPM (`127.0.0.1:9000`) and Nginx (port 80) with logs on stdout/stderr for Ghaymah log collection.

No `.env`, migrations, or `key:generate` run at image build time.

---

## Production warnings

* Set `APP_ENV=production` and `APP_DEBUG=false`.
* Set `APP_URL` to your real Ghaymah HTTPS domain.
* Set `APP_KEY` in Ghaymah environment variables (never in the image).
* Use **Ghaymah Managed MySQL**; do not use XAMPP or in-container MySQL in production.
* Do not expose phpMyAdmin in production.
* Do not commit `.env`, real Stripe keys, or Gmail app passwords.
* Configure Stripe webhook to `https://your-ghaymah-domain.com/api/webhooks/stripe`.
* Run migrations deliberately; back up the production database before schema changes.
* Ensure storage permissions remain writable after deploy.
* Use HTTPS for all client and webhook traffic.

---

# Development Guidelines

1. Do not put business logic inside controllers.
2. Use services for business workflows.
3. Use repositories for database queries.
4. Use Form Requests for validation.
5. Use API Resources for response formatting.
6. Use middleware for role and permission protection.
7. Use transactions for sensitive operations.
8. Use enums/constants for statuses.
9. Do not hard-delete important records with dependencies.
10. Record sensitive operations in audit logs.
11. Store notifications in the database.
12. Keep the system modular and maintainable.

---

# Mock Services

## Mock OTP

* Development OTP code: `123456`
* OTP expires after 10 minutes.

---

## Payment provider (`PAYMENT_PROVIDER`)

Set in `.env` (see `.env.example`):

* `mock` — citizen creates a payment, then calls `POST /applications/{id}/payments/{payment}/confirm` to complete it (local and automated tests default to this via `phpunit.xml`).
* `stripe` — citizen creates a payment and receives `checkout_url` plus `publishable_key`; completion is driven by Stripe webhooks and by polling `GET .../payments/{payment}/status`. Manual confirm is disabled for Stripe.

Secrets (`STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`) are read only from server config and are never returned by the API.

---

## Mock Payment Gateway

* Used when `PAYMENT_PROVIDER=mock`.
* Simulates payment success after manual confirm.
* Stores a mock provider reference.
* Does not store card data.

---

## Stripe Checkout (test mode)

1. Add Stripe values to `.env` (use test keys only; never commit real keys):

   * `PAYMENT_PROVIDER=stripe`
   * `STRIPE_PUBLISHABLE_KEY`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`
   * `STRIPE_CURRENCY`, `STRIPE_SUCCESS_URL`, `STRIPE_CANCEL_URL` (success URL may include Stripe’s `{CHECKOUT_SESSION_ID}` placeholder).

2. Clear config cache: `php artisan config:clear`

3. Start Laravel: `php artisan serve`

4. Move an application to `payment_pending` (same flow as before: citizen register → verify email → login → complete profile → create application → upload documents → submit → employee approves all documents).

5. `POST /api/applications/{application}/payments` — copy `data.checkout_url` from the JSON response.

6. Open `checkout_url` in a browser and pay with Stripe test card `4242 4242 4242 4242`, any future expiry, any CVC, any postal code.

7. Optionally call `GET /api/applications/{application}/payments/{payment}/status` to see internal status and latest Stripe session fields.

8. Webhook (source of truth): install [Stripe CLI](https://stripe.com/docs/stripe-cli), run `stripe login`, then:

   ```bash
   stripe listen --forward-to http://127.0.0.1:8000/api/webhooks/stripe
   ```

   Use the signing secret printed by the CLI as `STRIPE_WEBHOOK_SECRET` in `.env`, run `php artisan config:clear`, and repeat a checkout. When the webhook is delivered, the payment should become `completed` and the application should move to `appointment_pending`.

---

# Future Enhancements

Possible future improvements:

* Real SMS gateway integration.
* Push notifications.
* Phase 9B extension: payments and appointments via AI agent confirm flow.
* Phase 9C: AI agent admin logs and analytics.
* PDF license generation.
* QR code verification for licenses.
* Advanced reporting dashboard.
* Multi-branch traffic department support.
* Advanced workflow configuration engine.
* Integration with government identity systems.

---

# License

This project is developed for academic and educational purposes as part of a software engineering project.

---

# Project Status

```text
Planning and backend implementation preparation
```

Recommended implementation phases:

1. Database and models.
2. Authentication and RBAC.
3. Settings module.
4. Applications and documents.
5. Payments.
6. Appointments and tests.
7. Licenses and fines.
8. Notifications, audit logs, reports.
9. AI service agent (9A foundation; 9B execution; 9C admin reports).
10. Testing and documentation.
