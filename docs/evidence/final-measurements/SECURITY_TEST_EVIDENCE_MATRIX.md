# Security Test Evidence Matrix (Quantitative)

**System:** SYRTAK / DLMS Backend  
**Scope:** `D:\Projects\DLMS_Project\tests` (PHPUnit Feature + Unit)  
**Audit type:** Read-only quantitative inventory  
**Date:** 2026-08-14  

### Suite context (provided by project; not re-run in this inventory)

| Item | Value |
|------|-------|
| Latest full suite | **1043 passed** |
| Assertions | **6557** |
| Duration | **217.86s** |
| Test files inspected | **106** (`tests/Feature` + `tests/Unit`) |
| Method parser inventory | **1024** `test*` methods (datasets may explain 1043 PHPUnit tests) |

### Counting discipline

| Rule | Application |
|------|-------------|
| Exact vs heuristic | Numbers below labeled **EXACT** are curated from method bodies + assertions. Parser-assisted candidates were manually accepted/rejected. |
| Scenario vs method | `SEC-AUTHN-401`, `SEC-AUTHZ-403`, `SEC-TRUST-BOUNDARY`, `SEC-IDOR-*` count **scenarios** (file + method + endpoint/operation). `SEC-DATA-EXPOSURE-NEGATIVE` counts **methods**. |
| No internal double-count | Within one metric ID, each scenario/method appears once. |
| Cross-metric overlap | Allowed (e.g., a 403 citizen→dashboard row is also a trust-boundary scenario). **Do not sum metrics.** |
| Machine-readable companion | `security_test_evidence.csv` |

---

## 1. Unauthenticated / 401 — `SEC-AUTHN-401`

**Definition used:** request has no valid authenticated actor/token; asserts HTTP **401** / `assertUnauthorized` for a protected resource (or revoked token).

**EXACT value: 26**

### Included scenarios

| # | File | Method | Endpoint family | Status |
|---|------|--------|-----------------|--------|
| 1 | `tests/Feature/AIAgentActionExecutionTest.php` | `test_guest_cannot_confirm_action` | `POST /api/ai-agent/actions/{id}/confirm` | 401 |
| 2 | `tests/Feature/AIAgentFlowTest.php` | `test_guest_cannot_use_ai_agent` | `POST /api/ai-agent/message` | 401 |
| 3 | `tests/Feature/CitizenLanguagePreferenceTest.php` | `test_citizen_authorization_unchanged_for_settings` | `GET /api/settings` | 401 |
| 4 | `tests/Feature/DashboardAccessControlTest.php` | `test_guest_receives_401` | `GET /api/dashboard/access-control/overview` | 401 |
| 5 | `tests/Feature/DashboardAppointmentSlotsTest.php` | `test_unauthenticated_receives_401` | `GET /api/dashboard/appointment-slots` | 401 |
| 6 | `tests/Feature/DashboardAuthTest.php` | `test_logout_revokes_token` | `GET /api/dashboard/auth/me` | 401 |
| 7 | `tests/Feature/DashboardCitizenManagementTest.php` | `test_unauthenticated_list_returns_401` | `GET /api/dashboard/citizens` | 401 |
| 8 | `tests/Feature/DashboardDocumentReviewTest.php` | `test_unauthenticated_queue_returns_401` | `GET /api/dashboard/document-reviews` | 401 |
| 9 | `tests/Feature/DashboardEmployeeSessionsTest.php` | `test_guest_receives_401` | `GET /api/dashboard/employee-sessions` | 401 |
| 10 | `tests/Feature/DashboardFeesManagementTest.php` | `test_unauthenticated_receives_401` | `GET /api/dashboard/fees` | 401 |
| 11 | `tests/Feature/DashboardLicenseIssuanceQueueTest.php` | `test_unauthenticated_request_is_rejected` | `GET /api/dashboard/license-issuance/applications` | 401 |
| 12 | `tests/Feature/DashboardLicenseTypesTest.php` | `test_unauthenticated_receives_401` | `GET /api/dashboard/license-types` | 401 |
| 13 | `tests/Feature/DashboardOverviewTest.php` | `test_unauthenticated_returns_401` | `GET /api/dashboard/overview` | 401 |
| 14 | `tests/Feature/DashboardPaymentManagementTest.php` | `test_unauthenticated_list_returns_401` | `GET /api/dashboard/payments` | 401 |
| 15 | `tests/Feature/DashboardServiceTypesTest.php` | `test_unauthenticated_receives_401` | `GET /api/dashboard/service-types` | 401 |
| 16 | `tests/Feature/DashboardTestAppointmentListTest.php` | `test_unauthenticated_request_is_rejected` | `GET /api/dashboard/test-appointments` | 401 |
| 17 | `tests/Feature/DashboardTestAppointmentListTest.php` | `test_unauthenticated_dashboard_request_without_json_accept_returns_401_json` | `GET /api/dashboard/test-appointments` | 401 |
| 18 | `tests/Feature/DashboardTestAppointmentListTest.php` | `test_unauthenticated_admin_record_result_without_json_accept_returns_401_json` | `POST /api/admin/test-appointments/{id}/record-result` | 401 |
| 19 | `tests/Feature/EmployeeManagementTest.php` | `test_unauthenticated_user_cannot_list_employees` | `GET /api/dashboard/employees` | 401 |
| 20 | `tests/Feature/EmployeeSessionLastSeenTest.php` | `test_guest_cannot_heartbeat` | `POST /api/dashboard/session/heartbeat` | 401 |
| 21 | `tests/Feature/NotificationCenterApiTest.php` | `test_unauthenticated_and_employee_are_rejected` | `GET /api/notifications/unread-count` | 401 |
| 22 | `tests/Feature/NotificationCenterApiTest.php` | `test_unauthenticated_and_employee_are_rejected` | `PUT /api/notifications/read-all` | 401 |
| 23 | `tests/Feature/NotificationCenterApiTest.php` | `test_unauthenticated_and_employee_are_rejected` | `GET /api/notifications` | 401 |
| 24 | `tests/Feature/PushDeviceSecurityTest.php` | `test_unauthenticated_requests_are_rejected` | `POST /api/devices/push-token` | 401 |
| 25 | `tests/Feature/PushDeviceSecurityTest.php` | `test_unauthenticated_requests_are_rejected` | `DELETE /api/devices/push-token` | 401 |
| 26 | `tests/Feature/SettingsTest.php` | `test_settings_require_authentication` | `GET /api/settings` | 401 |

### Explicitly excluded from SEC-AUTHN-401 (still assert 401)

| File | Method | Why excluded |
|------|--------|--------------|
| `tests/Feature/DashboardAuthTest.php` | `test_invalid_credentials_return_arabic_message` | Credential failure on login — not unauthenticated access to a protected resource |
| `tests/Feature/PasswordResetFlowTest.php` | `test_reset_password_updates_password_and_revokes_tokens` | Old-password login after reset — credential failure side-assert |
| `tests/Feature/RequestLocaleTest.php` | `test_dashboard_routes_do_not_use_citizen_locale_middleware` | Invalid login used only to probe Content-Language header |

**Parser raw 401 assert scenarios before curation:** 29  
**Excluded:** 3 credential/locale login probes  
**Final SEC-AUTHN-401:** 26
---

## 2. Unauthorized / 403 — `SEC-AUTHZ-403`

**Definition used:** authenticated (or valid-credential wrong-persona) actor is denied by permission, persona, profile gate, or ownership/authz gate; asserts **403** / `assertForbidden`.

**EXACT value: 95**

Full scenario list is in `security_test_evidence.csv` filtered by `metric_id=SEC-AUTHZ-403`.

### Explicitly excluded

| File | Method | Why excluded |
|------|--------|--------------|
| `tests/Feature/DashboardAuthTest.php` | `test_inactive_employee_cannot_login` | Account inactive at login — not authenticated RBAC/persona denial |

**Parser raw 403 assert scenarios:** 96  
**Excluded:** 1  
**Final SEC-AUTHZ-403:** 95
**Note:** Ownership/IDOR cases that return 403 (license renew for foreign license) are included here **and** in IDOR (cross-metric overlap).

---

## 3. Ownership / IDOR — `SEC-IDOR-NEGATIVE`

**Definition used:** authenticated user A attempts to read/mutate user B’s resource (or B’s session/action/token); rejection or foreign resource unaffected.

**EXACT SEC-IDOR-NEGATIVE: 21**  
**SEC-IDOR-READ: 5**  
**SEC-IDOR-WRITE: 16**  
**Resources covered (10):** `application`, `document`, `payment`, `ai_session`, `appointment`, `notification`, `license`, `device`, `ai_action`, `ai_token`

| # | File | Method | Resource | Op | Endpoint | Outcome |
|---|------|--------|----------|----|----------|---------|
| 1 | `tests/Feature/ApplicationFlowTest.php` | `test_citizen_cannot_view_another_citizens_application` | application | read | `GET /api/applications/{id}` | 404 |
| 2 | `tests/Feature/DocumentFlowTest.php` | `test_citizen_cannot_view_another_citizens_required_checklist` | document | read | `GET /api/applications/{id}/required-documents` | 404 |
| 3 | `tests/Feature/PaymentStripeTest.php` | `test_citizen_cannot_view_another_citizens_payment_status` | payment | read | `GET /api/applications/{id}/payments/{paymentId}/status` | 404 |
| 4 | `tests/Feature/AIAgentFlowTest.php` | `test_citizen_cannot_access_another_citizen_session` | ai_session | read | `GET /api/ai-agent/sessions/{id}` | 404 |
| 5 | `tests/Feature/AIAgentActionExecutionTest.php` | `test_get_application_status_requires_owned_application` | application | read | `POST /api/ai-agent/actions/{id}/confirm` | 404 |
| 6 | `tests/Feature/AIAgentFlowTest.php` | `test_citizen_cannot_access_another_citizen_session` | ai_session | write | `POST /api/ai-agent/message` | 404 |
| 7 | `tests/Feature/AppointmentNotificationTest.php` | `test_foreign_citizen_cannot_cancel_and_creates_no_notification_for_owner` | appointment | write | `SERVICE AppointmentService::cancel` | foreign_unaffected |
| 8 | `tests/Feature/NotificationSecurityTest.php` | `test_mark_read_foreign_notification_returns_not_found` | notification | write | `PUT /api/notifications/{id}/read` | 404 |
| 9 | `tests/Feature/NotificationCenterApiTest.php` | `test_mark_one_owner_foreign_and_idempotent` | notification | write | `PUT /api/notifications/{id}/read` | 404 |
| 10 | `tests/Feature/NotificationCenterApiTest.php` | `test_user_id_in_request_cannot_affect_another_citizen` | notification | write | `PUT /api/notifications/read-all` | foreign_unaffected |
| 11 | `tests/Feature/NotificationReadAllTest.php` | `test_read_all_marks_only_current_user_unread_and_is_idempotent` | notification | write | `PUT /api/notifications/read-all` | foreign_unaffected |
| 12 | `tests/Feature/OtherLicenseServicesFlowTest.php` | `test_cannot_use_another_citizens_license` | license | write | `POST /api/applications` | 403 |
| 13 | `tests/Feature/CitizenHardcodeLocalizationTest.php` | `test_license_eligibility_failures_are_bilingual` | license | write | `POST /api/applications` | 403 |
| 14 | `tests/Feature/PushDeviceSecurityTest.php` | `test_request_user_id_cannot_register_for_another_user` | device | write | `POST /api/devices/push-token` | foreign_unaffected |
| 15 | `tests/Feature/PushDeviceSecurityTest.php` | `test_citizen_cannot_unregister_another_citizens_device` | device | write | `DELETE /api/devices/push-token` | foreign_unaffected |
| 16 | `tests/Feature/AIAgentActionExecutionTest.php` | `test_citizen_cannot_confirm_another_citizen_action` | ai_action | write | `POST /api/ai-agent/actions/{id}/confirm` | 404 |
| 17 | `tests/Feature/AIAgentDocumentUploadTest.php` | `test_upload_agent_document_rejects_foreign_session` | ai_session | write | `POST /api/ai-agent/sessions/{id}/documents` | 404 |
| 18 | `tests/Feature/AIAgentDocumentUploadTest.php` | `test_upload_agent_document_rejects_foreign_application_id` | application | write | `POST /api/ai-agent/sessions/{id}/documents` | 404 |
| 19 | `tests/Feature/AIAgentConversationalDocumentFlowTest.php` | `test_application_selection_token_from_other_citizen_is_rejected` | ai_token | write | `POST /api/ai-agent/sessions/{id}/interactions` | 404 |
| 20 | `tests/Feature/AIAgentApplicationSelectionFlowTest.php` | `test_tampered_and_foreign_tokens_are_rejected` | ai_token | write | `POST /api/ai-agent/sessions/{id}/interactions` | 404 |
| 21 | `tests/Feature/AIAgentAppointmentMultiSlotFlowTest.php` | `test_stale_slot_and_expired_and_foreign_token` | ai_token | write | `POST /api/ai-agent/sessions/{id}/interactions` | 404 |

### Excluded / not counted as IDOR

| Case | Reason |
|------|--------|
| `DashboardEmployeeSessionsTest::test_idor_unknown_session_returns_404` | Unknown id ≠ proven other-user ownership |
| `NotificationUnreadCountTest::test_unread_count_returns_integer_and_ignores_read_and_foreign` | Owner reads own count; foreign isolation is incidental, not an attack attempt |
| Concurrent booking between two citizens | Concurrency, not horizontal authz |
| Citizen denied dashboard routes | Trust-boundary / 403, not IDOR |

---

## 4. Sensitive data / privacy — `SEC-DATA-EXPOSURE-NEGATIVE`

**EXACT method count: 34**

| # | File | Method | Data class protected |
|---|------|--------|----------------------|
| 1 | `tests/Feature/PushDeviceSecurityTest.php` | `test_cross_user_token_reassignment_is_atomic_and_private` | `fcm_token` |
| 2 | `tests/Feature/PushDeviceSecurityTest.php` | `test_token_is_not_logged_during_normal_registration` | `fcm_token` |
| 3 | `tests/Feature/PushDeviceRegistrationTest.php` | `test_authenticated_citizen_can_register_a_device` | `fcm_token` |
| 4 | `tests/Feature/PushDeviceRegistrationTest.php` | `test_token_is_stored_encrypted_and_hash_is_deterministic` | `fcm_token` |
| 5 | `tests/Feature/EmployeeSessionSecurityTest.php` | `test_no_token_or_password_secrets_in_json_or_audit` | `token` |
| 6 | `tests/Feature/DashboardEmployeeSessionsTest.php` | `test_details_hide_token_secrets_and_me_exposes_flags` | `token` |
| 7 | `tests/Feature/EmployeeSessionLifecycleTest.php` | `test_dashboard_login_creates_linked_session` | `token` |
| 8 | `tests/Feature/DashboardCitizenManagementTest.php` | `test_list_does_not_include_password_fields` | `password` |
| 9 | `tests/Feature/EmployeeManagementTest.php` | `test_mutation_responses_do_not_expose_sensitive_fields` | `password` |
| 10 | `tests/Feature/EmployeeManagementTest.php` | `test_list_returns_required_fields_without_sensitive_data` | `password` |
| 11 | `tests/Feature/DashboardPaymentManagementTest.php` | `test_list_hides_metadata_and_serializes_amount_as_string` | `payment_meta` |
| 12 | `tests/Feature/SendPushNotificationJobTest.php` | `test_job_payload_contains_delivery_id_only` | `job_payload` |
| 13 | `tests/Feature/SendPushNotificationJobTest.php` | `test_payload_builder_stringifies_and_strips_forbidden_keys` | `token` |
| 14 | `tests/Feature/SendPushNotificationJobTest.php` | `test_token_not_logged_on_failure` | `fcm_token` |
| 15 | `tests/Feature/PushProductionCertificationTest.php` | `test_no_secret_in_job_serialization` | `job_payload` |
| 16 | `tests/Feature/PushProductionCertificationTest.php` | `test_no_token_in_failed_job_payload_shape` | `fcm_token` |
| 17 | `tests/Feature/NotificationArchitectureTest.php` | `test_payload_normalization_strips_disallowed_keys` | `pii` |
| 18 | `tests/Feature/NotificationArchitectureTest.php` | `test_status_transition_emits_registered_type_with_lean_data` | `pii` |
| 19 | `tests/Feature/NotificationArchitectureTest.php` | `test_resource_contract_excludes_internal_event_key` | `audit_secret` |
| 20 | `tests/Feature/FirebaseCredentialsTest.php` | `test_project_mismatch_fails` | `token` |
| 21 | `tests/Feature/FirebaseCredentialsTest.php` | `test_valid_credentials_decode_and_never_expose_private_key_in_exception_messages` | `token` |
| 22 | `tests/Feature/FirebaseAuthenticationTest.php` | `test_access_token_is_returned_and_cached` | `token` |
| 23 | `tests/Feature/FcmClientTest.php` | `test_authorization_header_and_token_are_not_logged` | `fcm_token` |
| 24 | `tests/Feature/OtpDebugLoggingTest.php` | `test_otp_is_not_logged_in_production_environment` | `otp` |
| 25 | `tests/Feature/OtpDebugLoggingTest.php` | `test_register_and_forgot_password_use_same_otp_debug_logging_path` | `otp` |
| 26 | `tests/Feature/SuperAdminProtectionTest.php` | `test_password_confirmation_value_is_not_logged` | `password` |
| 27 | `tests/Feature/LicenseVerificationTest.php` | `test_active_token_verifies_as_valid_without_pii` | `pii` |
| 28 | `tests/Feature/DashboardDocumentReviewTest.php` | `test_queue_does_not_expose_pii_and_search_ignores_phone_email_national_id` | `pii` |
| 29 | `tests/Feature/DashboardDocumentReviewTest.php` | `test_details_contract_actions_rejection_options_and_no_storage_path` | `file_path` |
| 30 | `tests/Feature/DashboardOverviewTest.php` | `test_recent_applications_privacy_and_limits` | `pii` |
| 31 | `tests/Feature/DashboardOverviewTest.php` | `test_recent_activities_privacy_and_permission` | `audit_secret` |
| 32 | `tests/Feature/DashboardReportsTest.php` | `test_employee_report_privacy_and_metrics` | `audit_secret` |
| 33 | `tests/Feature/DashboardAppointmentSlotsTest.php` | `test_bookings_endpoint_is_safe_and_filterable` | `pii` |
| 34 | `tests/Feature/AIAgentConversationalDocumentFlowTest.php` | `test_select_document_issues_one_time_upload_token_without_ids_for_flutter` | `token` |

---

## 5. Persona / trust boundary — `SEC-TRUST-BOUNDARY`

**EXACT distinct scenarios: 32**

Includes citizen→dashboard/admin denials, employee→citizen denials, and one public verify limited-payload success.

**Overlap with SEC-AUTHZ-403:** essentially all denial rows also appear under 403 (except public verify 200 privacy row). Do **not** add SEC-TRUST-BOUNDARY + SEC-AUTHZ-403.

Full list: CSV `metric_id=SEC-TRUST-BOUNDARY`.

---

## 6. Critical-operation authorization test coverage

**Name:** Critical-operation authorization test coverage (not “global security coverage”).

**Denominator:** 13 curated critical mutating operations (finite list below).

| Operation | Route | Permission/persona | Authorized? | 401? | 403? | IDOR? |
|-----------|-------|--------------------|-------------|------|------|-------|
| approve/reject citizen profile | `POST /api/admin/profile-reviews/{user}/approve|reject` | permission:review_profiles | YES | **NO** | YES | N/A |
| approve/reject application document | `POST /api/dashboard/document-reviews/documents/{document}/approve|reject` | permission:review_documents | YES | YES | YES | N/A |
| verify/manage payment | `POST /api/dashboard/payments/{payment}/verify ; POST /api/applications/{application}/payments/{payment}/confirm` | manage_payments / citizen+profile.approved | YES | **NO** | YES | **NO** |
| create/manage appointment slot | `POST|PATCH /api/dashboard/appointment-slots...` | permission:manage_appointments | YES | **NO** | YES | N/A |
| record test result | `POST /api/admin/test-appointments/{appointment}/record-result` | permission:record_test_result | YES | YES | YES | N/A |
| issue license | `POST /api/admin/applications/{application}/issue-license` | permission:issue_license | YES | **NO** | YES | N/A |
| block/unblock license | `POST /api/dashboard/licenses/{license}/block|unblock` | permission:manage_licenses | YES | YES | YES | N/A |
| create/update/deactivate employee | `POST|PUT|PATCH /api/dashboard/employees...` | permission:manage_employees | YES | **NO** | YES | N/A |
| assign/sync role/permissions | `POST/PATCH access-control + assign-role routes` | manage_employees / super_admin | YES | YES | YES | N/A |
| revoke employee session | `POST /api/dashboard/employee-sessions/{session}/revoke` | root_super_admin | YES | **NO** | YES | N/A |
| manage citizen active status | `POST /api/dashboard/citizens/{citizen}/activate|deactivate` | permission:manage_users | YES | YES | YES | N/A |
| create/mark fine | `POST|PUT /api/admin/fines...` | permission:manage_fines | YES | YES | YES | N/A |
| sensitive AI confirmed actions | `POST /api/ai-agent/actions/{action}/confirm|cancel` | auth:sanctum + citizen | YES | YES | YES | YES |

### Coverage percentages (explicit denominators)

| Metric ID | Formula | Value |
|-----------|---------|-------|
| **SEC-CRITICAL-AUTHORIZED-COVERAGE** | 13 / 13 with authorized-success evidence | **100%** (13/13) |
| **SEC-CRITICAL-401-COVERAGE** | 7 / 13 with unauthenticated-negative on the mutating route | **53.8%** (7/13) |
| **SEC-CRITICAL-403-COVERAGE** | 13 / 13 with unauthorized-negative on the mutating route | **100%** (13/13) |
| **SEC-CRITICAL-IDOR-COVERAGE** | 1 / 2 IDOR-applicable ops | **50%** (1/2) |

**Interpretation limit:** Sibling GET 401/403 on the same module does **not** satisfy mutating-route negative evidence.

---

## 7. Security mechanism test counts (overlapping categories)

**Do not sum these into a total.** Categories intentionally overlap with Sections 1–5.

| Mechanism category | Exact countable unit | Exact value | Traceability |
|--------------------|----------------------|-------------|--------------|
| RBAC / permissions (primary denial methods) | Curated methods below | **26 methods** | List below |
| Super Admin protection suite | `public function test_*` in `SuperAdminProtectionTest.php` | **5 methods** | File method list |
| Employee sessions family | `test_*` in `EmployeeSessionLifecycleTest` (6) + `EmployeeSessionRevocationTest` (6) + `EmployeeSessionLastSeenTest` (5) + `EmployeeSessionSecurityTest` (4) + `DashboardEmployeeSessionsTest` (11) | **32 methods** | Those 5 files |
| Authentication / password reset / OTP (selected suites) | `DashboardAuthTest` (9) + `PasswordResetFlowTest` (8) + `OtpDebugLoggingTest` (4) | **21 methods** | Those 3 files |
| Ownership / IDOR | `SEC-IDOR-NEGATIVE` scenarios | **21 scenarios** | Section 3 |
| Document ownership + review privacy | IDOR document/application checklist rows (2) + privacy methods with `file_path`/`pii` on document review (2) | **4 items** (mixed units; do not blend with scenario totals blindly) | Sections 3–4 |
| FCM / device token security | Privacy methods with `fcm_token`/`job_payload` plus device IDOR writes | **9 privacy methods** + **2 device IDOR scenarios** | Sections 3–4 |
| Public verification privacy | Privacy method on public verify allowlist | **1 method** | `LicenseVerificationTest::test_active_token_verifies_as_valid_without_pii` |

RBAC curated primary denial methods (26):

- `DashboardPermissionTest::test_profile_reviewer_cannot_manage_employees`
- `DashboardPermissionTest::test_fines_employee_cannot_access_audit_logs`
- `DashboardPermissionTest::test_employee_without_permission_gets_403`
- `DashboardAccessControlTest::test_normal_employee_receives_403`
- `DashboardAccessControlTest::test_manage_employees_without_super_admin_receives_403`
- `DashboardAppointmentSlotsTest::test_dashboard_user_without_permission_receives_403`
- `DashboardFeesManagementTest::test_dashboard_user_without_permission_receives_403`
- `DashboardLicenseTypesTest::test_dashboard_user_without_permission_receives_403`
- `DashboardServiceTypesTest::test_dashboard_user_without_permission_receives_403`
- `DashboardDocumentReviewTest::test_employee_without_review_documents_permission_gets_403`
- `DashboardPaymentManagementTest::test_employee_without_permission_returns_403`
- `DashboardPaymentManagementTest::test_view_payments_can_list_and_cannot_verify`
- `DashboardLicenseIssuanceQueueTest::test_unauthorized_employee_cannot_access`
- `DashboardLicenseIssuanceQueueTest::test_can_issue_license_respects_permission`
- `DashboardIssuedLicensesTest::test_unauthorized_employee_gets_403`
- `DashboardTestAppointmentListTest::test_unauthorized_employee_cannot_access`
- `DashboardReportsTest::test_reports_require_view_reports_permission`
- `DashboardReportsTest::test_domain_report_routes_enforce_permissions`
- `DashboardCitizenManagementTest::test_employee_without_manage_users_returns_403`
- `DashboardApplicationDetailsTest::test_unauthorized_user_cannot_view_application_details`
- `EmployeeManagementTest::test_non_authorized_employee_cannot_manage_employees`
- `EmployeeManagementTest::test_unauthorized_cannot_activate_or_deactivate`
- `EmployeeManagementTest::test_unauthorized_user_cannot_toggle_employee`
- `DocumentReviewerAuthorizationTest::test_reviewer_can_access_document_review_and_not_general_applications`
- `ProfileApprovalFlowTest::test_employee_without_review_profiles_permission_cannot_approve`
- `LicensePrintingTest::test_unauthorized_print_forbidden`

---

## 8. Rate limiting status — `SEC-RATE-LIMIT-429`

| Item | Exact value | How counted |
|------|-------------|-------------|
| `throttle:` middleware attachments in route files | **39** | Occurrences in `routes/api.php` (13) + `dashboard.php` (10) + `admin.php` (10) + `ai-agent.php` (5) + `content.php` (1) |
| Positive tests asserting HTTP 429 | **0** | Grep `assertStatus(429)` / `assertTooManyRequests` across `tests/**` → **no matches** |
| Test files disabling `ThrottleRequests` | **81** | Files containing `withoutMiddleware([ThrottleRequests::class])` |

**Measurement #5 needed:** add positive 429 Feature tests without disabling throttle for representative routes (forgot-password, public verify, payment initiate).

---

## 9. Security claims we can make

| Claim | Status | Allowed wording |
|-------|--------|-----------------|
| Unauthenticated rejection is automated for 26 protected-resource scenarios | **VERIFIED** | “Unauthenticated access rejection is supported by 26 automated 401 scenarios.” |
| Authorization denials are automated for 95 scenarios | **VERIFIED** | “RBAC/persona denial is supported by 95 automated 403 scenarios.” |
| Horizontal ownership (IDOR) negatives exist for 21 scenarios across 10 resource types | **VERIFIED** | Cite exact resources; do not claim all resources covered |
| Sensitive data non-exposure has 34 dedicated methods | **VERIFIED** (scoped) | Name data classes; not “no leaks possible” |
| Trust boundaries citizen↔employee↔public are tested | **PARTIALLY VERIFIED** | 32 scenarios; not every route pair |
| Critical mutating ops authorized-success coverage 13/13 | **VERIFIED** | Use the named metric only |
| Critical mutating ops 401/403 on the mutate route | **PARTIALLY VERIFIED** | 7/13 and 13/13 |
| Rate limiting works | **NOT MEASURED** | Configured in routes; **0** positive 429 tests |
| “The system is completely secure” | **DO NOT CLAIM** | — |
| Pentest-grade assurance | **DO NOT CLAIM** | — |

---

## 10. Final numeric summary

| Metric ID | Metric | Exact value | Denominator | Evidence source | Interpretation | Limitation |
|-----------|--------|-------------|-------------|-----------------|----------------|------------|
| SEC-AUTHN-401 | Unauthenticated rejection scenarios | **26** | — | Section 1 + CSV | Automated guest/revoked-token denials | Not all routes; excludes credential-failure logins |
| SEC-AUTHZ-403 | Authorization denial scenarios | **95** | — | Section 2 + CSV | Automated permission/persona denials | Overlaps IDOR/trust; not route-complete |
| SEC-IDOR-NEGATIVE | Horizontal authz negatives | **21** | — | Section 3 | Ownership protections tested | Finite resource set |
| SEC-IDOR-READ | Read IDOR scenarios | **5** | — | Section 3 | Read isolation | — |
| SEC-IDOR-WRITE | Write IDOR scenarios | **16** | — | Section 3 | Mutation isolation | Includes one service-level case |
| SEC-DATA-EXPOSURE-NEGATIVE | Privacy negative methods | **34** | — | Section 4 | Secrets/PII non-exposure asserts | Method≠field count |
| SEC-TRUST-BOUNDARY | Trust-boundary scenarios | **32** | — | Section 5 | Persona separation evidence | Overlaps 403 |
| SEC-CRITICAL-AUTHORIZED-COVERAGE | Critical ops with success authz evidence | **13/13 (100%)** | 13 ops | Section 6 | Happy-path authz present | Does not imply negatives |
| SEC-CRITICAL-401-COVERAGE | Critical ops with mutate-route 401 | **7/13 (53.8%)** | 13 ops | Section 6 | Gap-heavy | Sibling GET 401 not counted |
| SEC-CRITICAL-403-COVERAGE | Critical ops with mutate-route 403 | **13/13 (100%)** | 13 ops | Section 6 | Partial | — |
| SEC-CRITICAL-IDOR-COVERAGE | IDOR evidence among IDOR-applicable critical ops | **1/2 (50%)** | 2 applicable | Section 6 | Payment confirm IDOR missing | Only 2 applicable ops |
| SEC-RATE-LIMIT-429 | Positive 429 assertions | **0** | 39 throttle attachments | Section 8 | Unverified throttles | 81 files disable throttle |

---

## 11. Reproducibility

### Files inspected

- All `tests/Feature/*Test.php` and `tests/Unit/*Test.php` (106 files)
- Route throttle sources: `routes/api.php`, `app/Modules/*/Routes/*.php`
- Helper outputs: `_security_inventory_raw.json`, `_review_401.csv`, `_review_403.csv`

### Method

1. PHP parser `_inventory_security_tests.php` extracted `test*` methods and nearest HTTP call for each `assertStatus(401|403)` / `assertUnauthorized` / `assertForbidden`.
2. Manual curation applied exclusion rules (credential login 401s; inactive login 403).
3. IDOR/privacy/trust/critical matrices curated by reading method bodies (not keyword-only).
4. Exporter `_export_security_evidence.php` (this file) writes MD + CSV from curated arrays.

### False-positive avoidance

- Require actual assert, not comments.
- 401 metric excludes wrong-password login probes.
- IDOR requires cross-user resource attempt, not concurrency or dashboard persona denial.
- Critical 401/403 require evidence on the **mutating** route, not sibling list GETs.
- Rate-limit “configured” ≠ “tested”.

### Ambiguous / excluded highlights

- 3× login credential 401 asserts excluded from SEC-AUTHN-401.
- 1× inactive employee login 403 excluded from SEC-AUTHZ-403.
- `AppointmentNotificationTest` foreign cancel is **service-level** (still counted as write IDOR).
- `DashboardEmployeeSessionsTest::test_idor_unknown_session_returns_404` excluded from IDOR.

### Commands used

```text
php docs/evidence/final-measurements/_inventory_security_tests.php
php docs/evidence/final-measurements/_summarize_inventory.php
php docs/evidence/final-measurements/_export_security_evidence.php
```

### Suite numbers

Full-suite **1043 / 6557 / 217.86s** are taken from the project’s latest reported run (not re-executed by this inventory).

---

**Artifacts**

- `docs/evidence/final-measurements/SECURITY_TEST_EVIDENCE_MATRIX.md` (this file)
- `docs/evidence/final-measurements/security_test_evidence.csv`
