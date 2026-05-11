# Digital License Management System (DLMS)

**DLMS** is a Laravel 11 RESTful API backend for a government-style digital driving license management platform.

The system manages the full lifecycle of driving license services: citizen registration, profile completion, license applications, document upload and review, mock electronic payments, test appointment booking, test result recording, license issuance, license renewal, lost/damaged replacement, license unblocking, fines, notifications, audit logs, reports, and a simple chatbot assistant.

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
* Postman Collection
* Project Structure
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
* Use a simple chatbot assistant.

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
| Payment Gateway      | Mock external payment provider used for development.                                                                              |
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
| Payments      | Mock payment processing and payment records.                                     |
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
    Chatbot/
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

## Mock Payment Gateway

* Used for development and testing.
* Simulates payment success by default.
* Stores provider reference.
* Does not store card data.

---

# Future Enhancements

Possible future improvements:

* Real payment gateway integration.
* Real SMS gateway integration.
* Push notifications.
* Advanced chatbot integration.
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
9. Testing and documentation.
