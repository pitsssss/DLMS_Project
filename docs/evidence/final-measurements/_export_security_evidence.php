<?php

/**
 * Curated security evidence exporter (read-only).
 * Regenerates SECURITY_TEST_EVIDENCE_MATRIX.md + security_test_evidence.csv
 * from manually reviewed method inventories.
 */

declare(strict_types=1);

$outDir = __DIR__;

$rows = []; // CSV rows

function addRow(array &$rows, array $r): void
{
    $rows[] = $r;
}

// ---------------------------------------------------------------------------
// SEC-AUTHN-401 — EXACT scenarios (excluded credential-failure logins)
// ---------------------------------------------------------------------------
$authn401 = [
    ['tests/Feature/AIAgentActionExecutionTest.php', 'test_guest_cannot_confirm_action', 'POST', '/api/ai-agent/actions/{id}/confirm', '401'],
    ['tests/Feature/AIAgentFlowTest.php', 'test_guest_cannot_use_ai_agent', 'POST', '/api/ai-agent/message', '401'],
    ['tests/Feature/CitizenLanguagePreferenceTest.php', 'test_citizen_authorization_unchanged_for_settings', 'GET', '/api/settings', '401'],
    ['tests/Feature/DashboardAccessControlTest.php', 'test_guest_receives_401', 'GET', '/api/dashboard/access-control/overview', '401'],
    ['tests/Feature/DashboardAppointmentSlotsTest.php', 'test_unauthenticated_receives_401', 'GET', '/api/dashboard/appointment-slots', '401'],
    ['tests/Feature/DashboardAuthTest.php', 'test_logout_revokes_token', 'GET', '/api/dashboard/auth/me', '401'],
    ['tests/Feature/DashboardCitizenManagementTest.php', 'test_unauthenticated_list_returns_401', 'GET', '/api/dashboard/citizens', '401'],
    ['tests/Feature/DashboardDocumentReviewTest.php', 'test_unauthenticated_queue_returns_401', 'GET', '/api/dashboard/document-reviews', '401'],
    ['tests/Feature/DashboardEmployeeSessionsTest.php', 'test_guest_receives_401', 'GET', '/api/dashboard/employee-sessions', '401'],
    ['tests/Feature/DashboardFeesManagementTest.php', 'test_unauthenticated_receives_401', 'GET', '/api/dashboard/fees', '401'],
    ['tests/Feature/DashboardLicenseIssuanceQueueTest.php', 'test_unauthenticated_request_is_rejected', 'GET', '/api/dashboard/license-issuance/applications', '401'],
    ['tests/Feature/DashboardLicenseTypesTest.php', 'test_unauthenticated_receives_401', 'GET', '/api/dashboard/license-types', '401'],
    ['tests/Feature/DashboardOverviewTest.php', 'test_unauthenticated_returns_401', 'GET', '/api/dashboard/overview', '401'],
    ['tests/Feature/DashboardPaymentManagementTest.php', 'test_unauthenticated_list_returns_401', 'GET', '/api/dashboard/payments', '401'],
    ['tests/Feature/DashboardServiceTypesTest.php', 'test_unauthenticated_receives_401', 'GET', '/api/dashboard/service-types', '401'],
    ['tests/Feature/DashboardTestAppointmentListTest.php', 'test_unauthenticated_request_is_rejected', 'GET', '/api/dashboard/test-appointments', '401'],
    ['tests/Feature/DashboardTestAppointmentListTest.php', 'test_unauthenticated_dashboard_request_without_json_accept_returns_401_json', 'GET', '/api/dashboard/test-appointments', '401'],
    ['tests/Feature/DashboardTestAppointmentListTest.php', 'test_unauthenticated_admin_record_result_without_json_accept_returns_401_json', 'POST', '/api/admin/test-appointments/{id}/record-result', '401'],
    ['tests/Feature/EmployeeManagementTest.php', 'test_unauthenticated_user_cannot_list_employees', 'GET', '/api/dashboard/employees', '401'],
    ['tests/Feature/EmployeeSessionLastSeenTest.php', 'test_guest_cannot_heartbeat', 'POST', '/api/dashboard/session/heartbeat', '401'],
    ['tests/Feature/NotificationCenterApiTest.php', 'test_unauthenticated_and_employee_are_rejected', 'GET', '/api/notifications/unread-count', '401'],
    ['tests/Feature/NotificationCenterApiTest.php', 'test_unauthenticated_and_employee_are_rejected', 'PUT', '/api/notifications/read-all', '401'],
    ['tests/Feature/NotificationCenterApiTest.php', 'test_unauthenticated_and_employee_are_rejected', 'GET', '/api/notifications', '401'],
    ['tests/Feature/PushDeviceSecurityTest.php', 'test_unauthenticated_requests_are_rejected', 'POST', '/api/devices/push-token', '401'],
    ['tests/Feature/PushDeviceSecurityTest.php', 'test_unauthenticated_requests_are_rejected', 'DELETE', '/api/devices/push-token', '401'],
    ['tests/Feature/SettingsTest.php', 'test_settings_require_authentication', 'GET', '/api/settings', '401'],
];

$excluded401 = [
    ['tests/Feature/DashboardAuthTest.php', 'test_invalid_credentials_return_arabic_message', 'POST', '/api/dashboard/auth/login', '401', 'Credential failure on login — not unauthenticated access to a protected resource'],
    ['tests/Feature/PasswordResetFlowTest.php', 'test_reset_password_updates_password_and_revokes_tokens', 'POST', '/api/auth/login', '401', 'Old-password login after reset — credential failure side-assert'],
    ['tests/Feature/RequestLocaleTest.php', 'test_dashboard_routes_do_not_use_citizen_locale_middleware', 'POST', '/api/dashboard/auth/login', '401', 'Invalid login used only to probe Content-Language header'],
];

foreach ($authn401 as $a) {
    addRow($rows, [
        'metric_id' => 'SEC-AUTHN-401',
        'category' => 'unauthenticated_rejection',
        'file' => $a[0],
        'method' => $a[1],
        'http_method' => $a[2],
        'endpoint' => $a[3],
        'expected_status' => $a[4],
        'resource' => '',
        'operation' => 'read_or_write',
        'data_class' => '',
        'notes' => 'Guest/invalid-token request rejected',
    ]);
}

// ---------------------------------------------------------------------------
// SEC-AUTHZ-403 — from inventory minus excluded account-status login
// Load from review CSV and filter
// ---------------------------------------------------------------------------
$csv403 = file($outDir.'/_review_403.csv', FILE_IGNORE_NEW_LINES);
array_shift($csv403);
$authz403 = [];
$excluded403 = [];
foreach ($csv403 as $line) {
    $cols = str_getcsv($line);
    // file,method,http,endpoint,status,assert_kind,has_actingAs,authz_heuristic
    if (count($cols) < 5) {
        continue;
    }
    [$file, $method, $http, $endpoint, $status] = $cols;
    if ($method === 'test_inactive_employee_cannot_login') {
        $excluded403[] = [$file, $method, $http, $endpoint, $status, 'Account inactive at login — not authenticated RBAC/persona denial'];
        continue;
    }
    $authz403[] = [$file, $method, $http ?: '', $endpoint ?: '', $status];
    addRow($rows, [
        'metric_id' => 'SEC-AUTHZ-403',
        'category' => 'authorization_denial',
        'file' => $file,
        'method' => $method,
        'http_method' => $http,
        'endpoint' => $endpoint,
        'expected_status' => $status,
        'resource' => '',
        'operation' => '',
        'data_class' => '',
        'notes' => 'Authenticated actor denied by permission/persona/profile/ownership gate',
    ]);
}

// ---------------------------------------------------------------------------
// SEC-IDOR — curated scenarios (method may yield multiple rows)
// ---------------------------------------------------------------------------
$idor = [
    // READ
    ['tests/Feature/ApplicationFlowTest.php', 'test_citizen_cannot_view_another_citizens_application', 'application', 'read', 'GET', '/api/applications/{id}', '404'],
    ['tests/Feature/DocumentFlowTest.php', 'test_citizen_cannot_view_another_citizens_required_checklist', 'document', 'read', 'GET', '/api/applications/{id}/required-documents', '404'],
    ['tests/Feature/PaymentStripeTest.php', 'test_citizen_cannot_view_another_citizens_payment_status', 'payment', 'read', 'GET', '/api/applications/{id}/payments/{paymentId}/status', '404'],
    ['tests/Feature/AIAgentFlowTest.php', 'test_citizen_cannot_access_another_citizen_session', 'ai_session', 'read', 'GET', '/api/ai-agent/sessions/{id}', '404'],
    ['tests/Feature/AIAgentActionExecutionTest.php', 'test_get_application_status_requires_owned_application', 'application', 'read', 'POST', '/api/ai-agent/actions/{id}/confirm', '404'],
    // WRITE
    ['tests/Feature/AIAgentFlowTest.php', 'test_citizen_cannot_access_another_citizen_session', 'ai_session', 'write', 'POST', '/api/ai-agent/message', '404'],
    ['tests/Feature/AppointmentNotificationTest.php', 'test_foreign_citizen_cannot_cancel_and_creates_no_notification_for_owner', 'appointment', 'write', 'SERVICE', 'AppointmentService::cancel', 'foreign_unaffected'],
    ['tests/Feature/NotificationSecurityTest.php', 'test_mark_read_foreign_notification_returns_not_found', 'notification', 'write', 'PUT', '/api/notifications/{id}/read', '404'],
    ['tests/Feature/NotificationCenterApiTest.php', 'test_mark_one_owner_foreign_and_idempotent', 'notification', 'write', 'PUT', '/api/notifications/{id}/read', '404'],
    ['tests/Feature/NotificationCenterApiTest.php', 'test_user_id_in_request_cannot_affect_another_citizen', 'notification', 'write', 'PUT', '/api/notifications/read-all', 'foreign_unaffected'],
    ['tests/Feature/NotificationReadAllTest.php', 'test_read_all_marks_only_current_user_unread_and_is_idempotent', 'notification', 'write', 'PUT', '/api/notifications/read-all', 'foreign_unaffected'],
    ['tests/Feature/OtherLicenseServicesFlowTest.php', 'test_cannot_use_another_citizens_license', 'license', 'write', 'POST', '/api/applications', '403'],
    ['tests/Feature/CitizenHardcodeLocalizationTest.php', 'test_license_eligibility_failures_are_bilingual', 'license', 'write', 'POST', '/api/applications', '403'],
    ['tests/Feature/PushDeviceSecurityTest.php', 'test_request_user_id_cannot_register_for_another_user', 'device', 'write', 'POST', '/api/devices/push-token', 'foreign_unaffected'],
    ['tests/Feature/PushDeviceSecurityTest.php', 'test_citizen_cannot_unregister_another_citizens_device', 'device', 'write', 'DELETE', '/api/devices/push-token', 'foreign_unaffected'],
    ['tests/Feature/AIAgentActionExecutionTest.php', 'test_citizen_cannot_confirm_another_citizen_action', 'ai_action', 'write', 'POST', '/api/ai-agent/actions/{id}/confirm', '404'],
    ['tests/Feature/AIAgentDocumentUploadTest.php', 'test_upload_agent_document_rejects_foreign_session', 'ai_session', 'write', 'POST', '/api/ai-agent/sessions/{id}/documents', '404'],
    ['tests/Feature/AIAgentDocumentUploadTest.php', 'test_upload_agent_document_rejects_foreign_application_id', 'application', 'write', 'POST', '/api/ai-agent/sessions/{id}/documents', '404'],
    ['tests/Feature/AIAgentConversationalDocumentFlowTest.php', 'test_application_selection_token_from_other_citizen_is_rejected', 'ai_token', 'write', 'POST', '/api/ai-agent/sessions/{id}/interactions', '404'],
    ['tests/Feature/AIAgentApplicationSelectionFlowTest.php', 'test_tampered_and_foreign_tokens_are_rejected', 'ai_token', 'write', 'POST', '/api/ai-agent/sessions/{id}/interactions', '404'],
    ['tests/Feature/AIAgentAppointmentMultiSlotFlowTest.php', 'test_stale_slot_and_expired_and_foreign_token', 'ai_token', 'write', 'POST', '/api/ai-agent/sessions/{id}/interactions', '404'],
];

$idorRead = 0;
$idorWrite = 0;
$idorResources = [];
foreach ($idor as $i) {
    if ($i[3] === 'read') {
        $idorRead++;
    } else {
        $idorWrite++;
    }
    $idorResources[$i[2]] = true;
    addRow($rows, [
        'metric_id' => $i[3] === 'read' ? 'SEC-IDOR-READ' : 'SEC-IDOR-WRITE',
        'category' => 'ownership_idor',
        'file' => $i[0],
        'method' => $i[1],
        'http_method' => $i[4],
        'endpoint' => $i[5],
        'expected_status' => $i[6],
        'resource' => $i[2],
        'operation' => $i[3],
        'data_class' => '',
        'notes' => 'Horizontal authorization negative',
    ]);
    addRow($rows, [
        'metric_id' => 'SEC-IDOR-NEGATIVE',
        'category' => 'ownership_idor',
        'file' => $i[0],
        'method' => $i[1],
        'http_method' => $i[4],
        'endpoint' => $i[5],
        'expected_status' => $i[6],
        'resource' => $i[2],
        'operation' => $i[3],
        'data_class' => '',
        'notes' => 'Counted in SEC-IDOR-NEGATIVE',
    ]);
}

// ---------------------------------------------------------------------------
// Privacy / data exposure — METHODS
// ---------------------------------------------------------------------------
$privacy = [
    ['tests/Feature/PushDeviceSecurityTest.php', 'test_cross_user_token_reassignment_is_atomic_and_private', 'fcm_token'],
    ['tests/Feature/PushDeviceSecurityTest.php', 'test_token_is_not_logged_during_normal_registration', 'fcm_token'],
    ['tests/Feature/PushDeviceRegistrationTest.php', 'test_authenticated_citizen_can_register_a_device', 'fcm_token'],
    ['tests/Feature/PushDeviceRegistrationTest.php', 'test_token_is_stored_encrypted_and_hash_is_deterministic', 'fcm_token'],
    ['tests/Feature/EmployeeSessionSecurityTest.php', 'test_no_token_or_password_secrets_in_json_or_audit', 'token'],
    ['tests/Feature/DashboardEmployeeSessionsTest.php', 'test_details_hide_token_secrets_and_me_exposes_flags', 'token'],
    ['tests/Feature/EmployeeSessionLifecycleTest.php', 'test_dashboard_login_creates_linked_session', 'token'],
    ['tests/Feature/DashboardCitizenManagementTest.php', 'test_list_does_not_include_password_fields', 'password'],
    ['tests/Feature/EmployeeManagementTest.php', 'test_mutation_responses_do_not_expose_sensitive_fields', 'password'],
    ['tests/Feature/EmployeeManagementTest.php', 'test_list_returns_required_fields_without_sensitive_data', 'password'],
    ['tests/Feature/DashboardPaymentManagementTest.php', 'test_list_hides_metadata_and_serializes_amount_as_string', 'payment_meta'],
    ['tests/Feature/SendPushNotificationJobTest.php', 'test_job_payload_contains_delivery_id_only', 'job_payload'],
    ['tests/Feature/SendPushNotificationJobTest.php', 'test_payload_builder_stringifies_and_strips_forbidden_keys', 'token'],
    ['tests/Feature/SendPushNotificationJobTest.php', 'test_token_not_logged_on_failure', 'fcm_token'],
    ['tests/Feature/PushProductionCertificationTest.php', 'test_no_secret_in_job_serialization', 'job_payload'],
    ['tests/Feature/PushProductionCertificationTest.php', 'test_no_token_in_failed_job_payload_shape', 'fcm_token'],
    ['tests/Feature/NotificationArchitectureTest.php', 'test_payload_normalization_strips_disallowed_keys', 'pii'],
    ['tests/Feature/NotificationArchitectureTest.php', 'test_status_transition_emits_registered_type_with_lean_data', 'pii'],
    ['tests/Feature/NotificationArchitectureTest.php', 'test_resource_contract_excludes_internal_event_key', 'audit_secret'],
    ['tests/Feature/FirebaseCredentialsTest.php', 'test_project_mismatch_fails', 'token'],
    ['tests/Feature/FirebaseCredentialsTest.php', 'test_valid_credentials_decode_and_never_expose_private_key_in_exception_messages', 'token'],
    ['tests/Feature/FirebaseAuthenticationTest.php', 'test_access_token_is_returned_and_cached', 'token'],
    ['tests/Feature/FcmClientTest.php', 'test_authorization_header_and_token_are_not_logged', 'fcm_token'],
    ['tests/Feature/OtpDebugLoggingTest.php', 'test_otp_is_not_logged_in_production_environment', 'otp'],
    ['tests/Feature/OtpDebugLoggingTest.php', 'test_register_and_forgot_password_use_same_otp_debug_logging_path', 'otp'],
    ['tests/Feature/SuperAdminProtectionTest.php', 'test_password_confirmation_value_is_not_logged', 'password'],
    ['tests/Feature/LicenseVerificationTest.php', 'test_active_token_verifies_as_valid_without_pii', 'pii'],
    ['tests/Feature/DashboardDocumentReviewTest.php', 'test_queue_does_not_expose_pii_and_search_ignores_phone_email_national_id', 'pii'],
    ['tests/Feature/DashboardDocumentReviewTest.php', 'test_details_contract_actions_rejection_options_and_no_storage_path', 'file_path'],
    ['tests/Feature/DashboardOverviewTest.php', 'test_recent_applications_privacy_and_limits', 'pii'],
    ['tests/Feature/DashboardOverviewTest.php', 'test_recent_activities_privacy_and_permission', 'audit_secret'],
    ['tests/Feature/DashboardReportsTest.php', 'test_employee_report_privacy_and_metrics', 'audit_secret'],
    ['tests/Feature/DashboardAppointmentSlotsTest.php', 'test_bookings_endpoint_is_safe_and_filterable', 'pii'],
    ['tests/Feature/AIAgentConversationalDocumentFlowTest.php', 'test_select_document_issues_one_time_upload_token_without_ids_for_flutter', 'token'],
];

foreach ($privacy as $p) {
    addRow($rows, [
        'metric_id' => 'SEC-DATA-EXPOSURE-NEGATIVE',
        'category' => 'data_exposure_negative',
        'file' => $p[0],
        'method' => $p[1],
        'http_method' => '',
        'endpoint' => '',
        'expected_status' => '',
        'resource' => '',
        'operation' => '',
        'data_class' => $p[2],
        'notes' => 'Asserts secret/PII/internal field not exposed',
    ]);
}

// ---------------------------------------------------------------------------
// Trust boundary — distinct scenarios (no internal double-count)
// ---------------------------------------------------------------------------
$trust = [
    ['tests/Feature/DashboardAuthTest.php', 'test_citizen_cannot_login_to_dashboard', 'POST', '/api/dashboard/auth/login', '403', 'citizen→dashboard login'],
    ['tests/Feature/DashboardAccessControlTest.php', 'test_citizen_cannot_access_access_control', 'GET', '/api/dashboard/access-control/overview', '403', 'citizen→dashboard'],
    ['tests/Feature/DashboardAppointmentSlotsTest.php', 'test_citizen_receives_403', 'GET', '/api/dashboard/appointment-slots', '403', 'citizen→dashboard'],
    ['tests/Feature/DashboardCitizenManagementTest.php', 'test_citizen_cannot_access_dashboard_citizen_management', 'GET', '/api/dashboard/citizens', '403', 'citizen→dashboard'],
    ['tests/Feature/DashboardDocumentReviewTest.php', 'test_citizen_cannot_access_document_review_endpoints', 'GET', '/api/dashboard/document-reviews', '403', 'citizen→dashboard'],
    ['tests/Feature/DashboardDocumentReviewTest.php', 'test_citizen_cannot_access_document_review_endpoints', 'GET', '/api/dashboard/document-reviews/stats', '403', 'citizen→dashboard'],
    ['tests/Feature/DashboardEmployeeSessionsTest.php', 'test_citizen_receives_403', 'GET', '/api/dashboard/employee-sessions', '403', 'citizen→dashboard'],
    ['tests/Feature/DashboardFeesManagementTest.php', 'test_citizen_receives_403', 'GET', '/api/dashboard/fees', '403', 'citizen→dashboard'],
    ['tests/Feature/DashboardLicenseIssuanceQueueTest.php', 'test_citizen_cannot_access', 'GET', '/api/dashboard/license-issuance/applications', '403', 'citizen→dashboard'],
    ['tests/Feature/DashboardLicenseTypesTest.php', 'test_citizen_receives_403', 'GET', '/api/dashboard/license-types', '403', 'citizen→dashboard'],
    ['tests/Feature/DashboardOverviewTest.php', 'test_citizen_returns_403', 'GET', '/api/dashboard/overview', '403', 'citizen→dashboard'],
    ['tests/Feature/DashboardPaymentManagementTest.php', 'test_citizen_cannot_access_dashboard_payments', 'GET', '/api/dashboard/payments', '403', 'citizen→dashboard'],
    ['tests/Feature/DashboardServiceTypesTest.php', 'test_citizen_receives_403', 'GET', '/api/dashboard/service-types', '403', 'citizen→dashboard'],
    ['tests/Feature/DashboardTestAppointmentListTest.php', 'test_citizen_cannot_access', 'GET', '/api/dashboard/test-appointments', '403', 'citizen→dashboard'],
    ['tests/Feature/EmployeeManagementTest.php', 'test_citizen_cannot_list_employees', 'GET', '/api/dashboard/employees', '403', 'citizen→dashboard'],
    ['tests/Feature/EmployeeManagementTest.php', 'test_citizen_cannot_activate_or_deactivate', 'PATCH', '/api/dashboard/employees/{id}/activate', '403', 'citizen→dashboard'],
    ['tests/Feature/EmployeeManagementTest.php', 'test_citizen_cannot_activate_or_deactivate', 'PATCH', '/api/dashboard/employees/{id}/deactivate', '403', 'citizen→dashboard'],
    ['tests/Feature/EmployeeManagementTest.php', 'test_citizen_cannot_toggle_employee', 'PATCH', '/api/dashboard/employees/{id}/toggle-active', '403', 'citizen→dashboard'],
    ['tests/Feature/DocumentFlowTest.php', 'test_citizen_cannot_list_pending_review_documents', 'GET', '/api/admin/documents/pending-review', '403', 'citizen→admin'],
    ['tests/Feature/ProfileApprovalFlowTest.php', 'test_citizen_cannot_approve_own_profile', 'POST', '/api/admin/profile-reviews/{id}/approve', '403', 'citizen→admin'],
    ['tests/Feature/ApplicationFlowTest.php', 'test_employee_cannot_access_applications_routes', 'GET', '/api/applications', '403', 'employee→citizen'],
    ['tests/Feature/ApplicationFlowTest.php', 'test_employee_cannot_access_applications_routes', 'POST', '/api/applications', '403', 'employee→citizen'],
    ['tests/Feature/ApplicationFlowTest.php', 'test_employee_cannot_access_applications_routes', 'GET', '/api/applications/1', '403', 'employee→citizen'],
    ['tests/Feature/AIAgentFlowTest.php', 'test_employee_cannot_use_citizen_ai_endpoint', 'POST', '/api/ai-agent/message', '403', 'employee→citizen'],
    ['tests/Feature/AIAgentActionExecutionTest.php', 'test_employee_cannot_use_citizen_ai_action_endpoint', 'POST', '/api/ai-agent/actions/{id}/confirm', '403', 'employee→citizen'],
    ['tests/Feature/PushDeviceSecurityTest.php', 'test_employee_is_rejected_by_citizen_middleware', 'POST', '/api/devices/push-token', '403', 'employee→citizen'],
    ['tests/Feature/PushDeviceSecurityTest.php', 'test_employee_is_rejected_by_citizen_middleware', 'DELETE', '/api/devices/push-token', '403', 'employee→citizen'],
    ['tests/Feature/NotificationCenterApiTest.php', 'test_unauthenticated_and_employee_are_rejected', 'GET', '/api/notifications', '403', 'employee→citizen'],
    ['tests/Feature/NotificationCenterApiTest.php', 'test_unauthenticated_and_employee_are_rejected', 'GET', '/api/notifications/unread-count', '403', 'employee→citizen'],
    ['tests/Feature/NotificationCenterApiTest.php', 'test_unauthenticated_and_employee_are_rejected', 'PUT', '/api/notifications/read-all', '403', 'employee→citizen'],
    ['tests/Feature/CitizenLanguagePreferenceTest.php', 'test_citizen_authorization_unchanged_for_settings', 'GET', '/api/settings', '403', 'employee→citizen'],
    ['tests/Feature/LicenseVerificationTest.php', 'test_active_token_verifies_as_valid_without_pii', 'GET', '/api/licenses/verify/{token}', '200', 'public API limited payload'],
];

foreach ($trust as $t) {
    addRow($rows, [
        'metric_id' => 'SEC-TRUST-BOUNDARY',
        'category' => 'trust_boundary',
        'file' => $t[0],
        'method' => $t[1],
        'http_method' => $t[2],
        'endpoint' => $t[3],
        'expected_status' => $t[4],
        'resource' => '',
        'operation' => '',
        'data_class' => '',
        'notes' => $t[5],
    ]);
}

// Critical ops
$critical = [
    [
        'operation' => 'approve/reject citizen profile',
        'route' => 'POST /api/admin/profile-reviews/{user}/approve|reject',
        'permission' => 'permission:review_profiles',
        'authorized' => 'ProfileApprovalFlowTest::test_reviewer_can_approve_pending_profile',
        'unauth401' => 'NO',
        'unauthz403' => 'ProfileApprovalFlowTest::test_employee_without_review_profiles_permission_cannot_approve',
        'idor' => 'N/A',
        'idor_applicable' => false,
    ],
    [
        'operation' => 'approve/reject application document',
        'route' => 'POST /api/dashboard/document-reviews/documents/{document}/approve|reject',
        'permission' => 'permission:review_documents',
        'authorized' => 'DashboardDocumentReviewTest::test_approve_sets_fields_audit_notification_and_blocks_stale_second_decision',
        'unauth401' => 'CriticalMutationAuthorizationTest::test_document_approve_and_reject_require_auth_and_permission',
        'unauthz403' => 'CriticalMutationAuthorizationTest::test_document_approve_and_reject_require_auth_and_permission',
        'idor' => 'N/A',
        'idor_applicable' => false,
    ],
    [
        'operation' => 'verify/manage payment',
        'route' => 'POST /api/dashboard/payments/{payment}/verify ; POST /api/applications/{application}/payments/{payment}/confirm',
        'permission' => 'manage_payments / citizen+profile.approved',
        'authorized' => 'DashboardPaymentManagementTest::test_verify_stripe_payment_completes_idempotently',
        'unauth401' => 'NO',
        'unauthz403' => 'DashboardPaymentManagementTest::test_view_payments_can_list_and_cannot_verify',
        'idor' => 'NO',
        'idor_applicable' => true,
    ],
    [
        'operation' => 'create/manage appointment slot',
        'route' => 'POST|PATCH /api/dashboard/appointment-slots...',
        'permission' => 'permission:manage_appointments',
        'authorized' => 'DashboardAppointmentSlotsTest::test_manage_appointments_can_mutate',
        'unauth401' => 'NO',
        'unauthz403' => 'DashboardAppointmentSlotsTest::test_dashboard_user_without_permission_receives_403',
        'idor' => 'N/A',
        'idor_applicable' => false,
    ],
    [
        'operation' => 'record test result',
        'route' => 'POST /api/admin/test-appointments/{appointment}/record-result',
        'permission' => 'permission:record_test_result',
        'authorized' => 'AppointmentFlowTest::test_employee_can_record_passed_result_and_unlock_next_test',
        'unauth401' => 'DashboardTestAppointmentListTest::test_unauthenticated_admin_record_result_without_json_accept_returns_401_json',
        'unauthz403' => 'CriticalMutationAuthorizationTest::test_record_test_result_requires_permission',
        'idor' => 'N/A',
        'idor_applicable' => false,
    ],
    [
        'operation' => 'issue license',
        'route' => 'POST /api/admin/applications/{application}/issue-license',
        'permission' => 'permission:issue_license',
        'authorized' => 'LicenseFlowTest::test_employee_can_issue_license_for_approved_application',
        'unauth401' => 'NO',
        'unauthz403' => 'DashboardLicenseIssuanceQueueTest::test_can_issue_license_respects_permission',
        'idor' => 'N/A',
        'idor_applicable' => false,
    ],
    [
        'operation' => 'block/unblock license',
        'route' => 'POST /api/dashboard/licenses/{license}/block|unblock',
        'permission' => 'permission:manage_licenses',
        'authorized' => 'DashboardIssuedLicensesTest::test_details_history_block_unblock_and_audit',
        'unauth401' => 'CriticalMutationAuthorizationTest::test_license_block_and_unblock_require_auth_and_permission',
        'unauthz403' => 'CriticalMutationAuthorizationTest::test_license_block_and_unblock_require_auth_and_permission',
        'idor' => 'N/A',
        'idor_applicable' => false,
    ],
    [
        'operation' => 'create/update/deactivate employee',
        'route' => 'POST|PUT|PATCH /api/dashboard/employees...',
        'permission' => 'permission:manage_employees',
        'authorized' => 'EmployeeManagementTest::test_super_admin_can_create_employee',
        'unauth401' => 'NO',
        'unauthz403' => 'EmployeeManagementTest::test_non_authorized_employee_cannot_manage_employees',
        'idor' => 'N/A',
        'idor_applicable' => false,
    ],
    [
        'operation' => 'assign/sync role/permissions',
        'route' => 'POST/PATCH access-control + assign-role routes',
        'permission' => 'manage_employees / super_admin',
        'authorized' => 'EmployeeManagementTest::test_super_admin_can_assign_role',
        'unauth401' => 'CriticalMutationAuthorizationTest::test_role_mutations_require_auth_and_super_admin',
        'unauthz403' => 'CriticalMutationAuthorizationTest::test_role_mutations_require_auth_and_super_admin',
        'idor' => 'N/A',
        'idor_applicable' => false,
    ],
    [
        'operation' => 'revoke employee session',
        'route' => 'POST /api/dashboard/employee-sessions/{session}/revoke',
        'permission' => 'root_super_admin',
        'authorized' => 'EmployeeSessionRevocationTest::test_revoke_invalidates_token_and_audits',
        'unauth401' => 'NO',
        'unauthz403' => 'EmployeeSessionSecurityTest::test_normal_admin_cannot_access_any_management_route',
        'idor' => 'N/A',
        'idor_applicable' => false,
    ],
    [
        'operation' => 'manage citizen active status',
        'route' => 'POST /api/dashboard/citizens/{citizen}/activate|deactivate',
        'permission' => 'permission:manage_users',
        'authorized' => 'DashboardCitizenManagementTest::test_active_citizen_can_be_deactivated',
        'unauth401' => 'CriticalMutationAuthorizationTest::test_citizen_activate_and_deactivate_require_auth_and_permission',
        'unauthz403' => 'CriticalMutationAuthorizationTest::test_citizen_activate_and_deactivate_require_auth_and_permission',
        'idor' => 'N/A',
        'idor_applicable' => false,
    ],
    [
        'operation' => 'create/mark fine',
        'route' => 'POST|PUT /api/admin/fines...',
        'permission' => 'permission:manage_fines',
        'authorized' => 'LicenseFlowTest::test_admin_can_create_and_mark_fine_paid',
        'unauth401' => 'CriticalMutationAuthorizationTest::test_fine_create_and_update_require_auth_and_permission',
        'unauthz403' => 'CriticalMutationAuthorizationTest::test_fine_create_and_update_require_auth_and_permission',
        'idor' => 'N/A',
        'idor_applicable' => false,
    ],
    [
        'operation' => 'sensitive AI confirmed actions',
        'route' => 'POST /api/ai-agent/actions/{action}/confirm|cancel',
        'permission' => 'auth:sanctum + citizen',
        'authorized' => 'AIAgentActionExecutionTest::test_confirming_create_application_creates_license_application',
        'unauth401' => 'AIAgentActionExecutionTest::test_guest_cannot_confirm_action',
        'unauthz403' => 'AIAgentActionExecutionTest::test_employee_cannot_use_citizen_ai_action_endpoint',
        'idor' => 'AIAgentActionExecutionTest::test_citizen_cannot_confirm_another_citizen_action',
        'idor_applicable' => true,
    ],
];

$critTotal = count($critical);
$critAuth = count(array_filter($critical, fn ($c) => $c['authorized'] !== 'NO'));
$crit401 = count(array_filter($critical, fn ($c) => $c['unauth401'] !== 'NO'));
$crit403 = count(array_filter($critical, fn ($c) => $c['unauthz403'] !== 'NO'));
$critIdorApp = array_values(array_filter($critical, fn ($c) => $c['idor_applicable']));
$critIdorOk = count(array_filter($critIdorApp, fn ($c) => $c['idor'] !== 'NO' && $c['idor'] !== 'N/A'));

foreach ($critical as $c) {
    addRow($rows, [
        'metric_id' => 'SEC-CRITICAL-OPS-ROW',
        'category' => 'critical_operation',
        'file' => '',
        'method' => $c['operation'],
        'http_method' => '',
        'endpoint' => $c['route'],
        'expected_status' => '',
        'resource' => $c['permission'],
        'operation' => sprintf('auth=%s;401=%s;403=%s;idor=%s', $c['authorized'], $c['unauth401'], $c['unauthz403'], $c['idor']),
        'data_class' => '',
        'notes' => 'Critical-operation authorization test coverage row',
    ]);
}

// Mechanism category method counts (overlapping — do not sum)
$mech = [
    'RBAC_permissions' => [
        'note' => 'Methods whose primary purpose is permission middleware denial or permission-gated success with negative counterpart',
        'methods' => [
            'DashboardPermissionTest::test_profile_reviewer_cannot_manage_employees',
            'DashboardPermissionTest::test_fines_employee_cannot_access_audit_logs',
            'DashboardPermissionTest::test_employee_without_permission_gets_403',
            'DashboardAccessControlTest::test_normal_employee_receives_403',
            'DashboardAccessControlTest::test_manage_employees_without_super_admin_receives_403',
            'DashboardAppointmentSlotsTest::test_dashboard_user_without_permission_receives_403',
            'DashboardFeesManagementTest::test_dashboard_user_without_permission_receives_403',
            'DashboardLicenseTypesTest::test_dashboard_user_without_permission_receives_403',
            'DashboardServiceTypesTest::test_dashboard_user_without_permission_receives_403',
            'DashboardDocumentReviewTest::test_employee_without_review_documents_permission_gets_403',
            'DashboardPaymentManagementTest::test_employee_without_permission_returns_403',
            'DashboardPaymentManagementTest::test_view_payments_can_list_and_cannot_verify',
            'DashboardLicenseIssuanceQueueTest::test_unauthorized_employee_cannot_access',
            'DashboardLicenseIssuanceQueueTest::test_can_issue_license_respects_permission',
            'DashboardIssuedLicensesTest::test_unauthorized_employee_gets_403',
            'DashboardTestAppointmentListTest::test_unauthorized_employee_cannot_access',
            'DashboardReportsTest::test_reports_require_view_reports_permission',
            'DashboardReportsTest::test_domain_report_routes_enforce_permissions',
            'DashboardCitizenManagementTest::test_employee_without_manage_users_returns_403',
            'DashboardApplicationDetailsTest::test_unauthorized_user_cannot_view_application_details',
            'EmployeeManagementTest::test_non_authorized_employee_cannot_manage_employees',
            'EmployeeManagementTest::test_unauthorized_cannot_activate_or_deactivate',
            'EmployeeManagementTest::test_unauthorized_user_cannot_toggle_employee',
            'DocumentReviewerAuthorizationTest::test_reviewer_can_access_document_review_and_not_general_applications',
            'ProfileApprovalFlowTest::test_employee_without_review_profiles_permission_cannot_approve',
            'LicensePrintingTest::test_unauthorized_print_forbidden',
        ],
    ],
];

// Counts
$n401 = count($authn401);
$n403 = count($authz403);
$nIdor = count($idor);
$nPriv = count($privacy);
$nTrust = count($trust);
$throttleAttachments = 13 + 10 + 10 + 5 + 1; // api, dashboard, admin, ai-agent, content
$throttleDisableFiles = 81;
$assert429 = 0;

// Write CSV
$csvPath = $outDir.'/security_test_evidence.csv';
$fp = fopen($csvPath, 'w');
fputcsv($fp, ['metric_id', 'category', 'file', 'method', 'http_method', 'endpoint', 'expected_status', 'resource', 'operation', 'data_class', 'notes']);
foreach ($rows as $r) {
    fputcsv($fp, [
        $r['metric_id'], $r['category'], $r['file'], $r['method'], $r['http_method'],
        $r['endpoint'], $r['expected_status'], $r['resource'], $r['operation'], $r['data_class'], $r['notes'],
    ]);
}
fclose($fp);

// Build markdown
ob_start();
?>
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

**EXACT value: <?= $n401 ?>**

### Included scenarios

| # | File | Method | Endpoint family | Status |
|---|------|--------|-----------------|--------|
<?php $i = 1; foreach ($authn401 as $a): ?>
| <?= $i++ ?> | `<?= $a[0] ?>` | `<?= $a[1] ?>` | `<?= $a[2] ?> <?= $a[3] ?>` | <?= $a[4] ?> |
<?php endforeach; ?>

### Explicitly excluded from SEC-AUTHN-401 (still assert 401)

| File | Method | Why excluded |
|------|--------|--------------|
<?php foreach ($excluded401 as $e): ?>
| `<?= $e[0] ?>` | `<?= $e[1] ?>` | <?= $e[5] ?> |
<?php endforeach; ?>

**Parser raw 401 assert scenarios before curation:** 29  
**Excluded:** 3 credential/locale login probes  
**Final SEC-AUTHN-401:** <?= $n401 ?>

---

## 2. Unauthorized / 403 — `SEC-AUTHZ-403`

**Definition used:** authenticated (or valid-credential wrong-persona) actor is denied by permission, persona, profile gate, or ownership/authz gate; asserts **403** / `assertForbidden`.

**EXACT value: <?= $n403 ?>**

Full scenario list is in `security_test_evidence.csv` filtered by `metric_id=SEC-AUTHZ-403`.

### Explicitly excluded

| File | Method | Why excluded |
|------|--------|--------------|
<?php foreach ($excluded403 as $e): ?>
| `<?= $e[0] ?>` | `<?= $e[1] ?>` | <?= $e[5] ?> |
<?php endforeach; ?>

**Parser raw 403 assert scenarios:** 96  
**Excluded:** <?= count($excluded403) ?>  
**Final SEC-AUTHZ-403:** <?= $n403 ?>

**Note:** Ownership/IDOR cases that return 403 (license renew for foreign license) are included here **and** in IDOR (cross-metric overlap).

---

## 3. Ownership / IDOR — `SEC-IDOR-NEGATIVE`

**Definition used:** authenticated user A attempts to read/mutate user B’s resource (or B’s session/action/token); rejection or foreign resource unaffected.

**EXACT SEC-IDOR-NEGATIVE: <?= $nIdor ?>**  
**SEC-IDOR-READ: <?= $idorRead ?>**  
**SEC-IDOR-WRITE: <?= $idorWrite ?>**  
**Resources covered (<?= count($idorResources) ?>):** `<?= implode('`, `', array_keys($idorResources)) ?>`

| # | File | Method | Resource | Op | Endpoint | Outcome |
|---|------|--------|----------|----|----------|---------|
<?php $i = 1; foreach ($idor as $d): ?>
| <?= $i++ ?> | `<?= $d[0] ?>` | `<?= $d[1] ?>` | <?= $d[2] ?> | <?= $d[3] ?> | `<?= $d[4] ?> <?= $d[5] ?>` | <?= $d[6] ?> |
<?php endforeach; ?>

### Excluded / not counted as IDOR

| Case | Reason |
|------|--------|
| `DashboardEmployeeSessionsTest::test_idor_unknown_session_returns_404` | Unknown id ≠ proven other-user ownership |
| `NotificationUnreadCountTest::test_unread_count_returns_integer_and_ignores_read_and_foreign` | Owner reads own count; foreign isolation is incidental, not an attack attempt |
| Concurrent booking between two citizens | Concurrency, not horizontal authz |
| Citizen denied dashboard routes | Trust-boundary / 403, not IDOR |

---

## 4. Sensitive data / privacy — `SEC-DATA-EXPOSURE-NEGATIVE`

**EXACT method count: <?= $nPriv ?>**

| # | File | Method | Data class protected |
|---|------|--------|----------------------|
<?php $i = 1; foreach ($privacy as $p): ?>
| <?= $i++ ?> | `<?= $p[0] ?>` | `<?= $p[1] ?>` | `<?= $p[2] ?>` |
<?php endforeach; ?>

---

## 5. Persona / trust boundary — `SEC-TRUST-BOUNDARY`

**EXACT distinct scenarios: <?= $nTrust ?>**

Includes citizen→dashboard/admin denials, employee→citizen denials, and one public verify limited-payload success.

**Overlap with SEC-AUTHZ-403:** essentially all denial rows also appear under 403 (except public verify 200 privacy row). Do **not** add SEC-TRUST-BOUNDARY + SEC-AUTHZ-403.

Full list: CSV `metric_id=SEC-TRUST-BOUNDARY`.

---

## 6. Critical-operation authorization test coverage

**Name:** Critical-operation authorization test coverage (not “global security coverage”).

**Denominator:** <?= $critTotal ?> curated critical mutating operations (finite list below).

| Operation | Route | Permission/persona | Authorized? | 401? | 403? | IDOR? |
|-----------|-------|--------------------|-------------|------|------|-------|
<?php foreach ($critical as $c): ?>
| <?= $c['operation'] ?> | `<?= $c['route'] ?>` | <?= $c['permission'] ?> | <?= $c['authorized'] === 'NO' ? '**NO**' : 'YES' ?> | <?= $c['unauth401'] === 'NO' ? '**NO**' : 'YES' ?> | <?= $c['unauthz403'] === 'NO' ? '**NO**' : 'YES' ?> | <?= $c['idor_applicable'] ? ($c['idor'] === 'NO' ? '**NO**' : 'YES') : 'N/A' ?> |
<?php endforeach; ?>

### Coverage percentages (explicit denominators)

| Metric ID | Formula | Value |
|-----------|---------|-------|
| **SEC-CRITICAL-AUTHORIZED-COVERAGE** | <?= $critAuth ?> / <?= $critTotal ?> with authorized-success evidence | **<?= round(100 * $critAuth / $critTotal, 1) ?>%** (<?= $critAuth ?>/<?= $critTotal ?>) |
| **SEC-CRITICAL-401-COVERAGE** | <?= $crit401 ?> / <?= $critTotal ?> with unauthenticated-negative on the mutating route | **<?= round(100 * $crit401 / $critTotal, 1) ?>%** (<?= $crit401 ?>/<?= $critTotal ?>) |
| **SEC-CRITICAL-403-COVERAGE** | <?= $crit403 ?> / <?= $critTotal ?> with unauthorized-negative on the mutating route | **<?= round(100 * $crit403 / $critTotal, 1) ?>%** (<?= $crit403 ?>/<?= $critTotal ?>) |
| **SEC-CRITICAL-IDOR-COVERAGE** | <?= $critIdorOk ?> / <?= count($critIdorApp) ?> IDOR-applicable ops | **<?= count($critIdorApp) ? round(100 * $critIdorOk / count($critIdorApp), 1) : 0 ?>%** (<?= $critIdorOk ?>/<?= count($critIdorApp) ?>) |

**Interpretation limit:** Sibling GET 401/403 on the same module does **not** satisfy mutating-route negative evidence.

---

## 7. Security mechanism test counts (overlapping categories)

**Do not sum these into a total.** Categories intentionally overlap with Sections 1–5.

| Mechanism category | Exact countable unit | Exact value | Traceability |
|--------------------|----------------------|-------------|--------------|
| RBAC / permissions (primary denial methods) | Curated methods below | **<?= count($mech['RBAC_permissions']['methods']) ?> methods** | List below |
| Super Admin protection suite | `public function test_*` in `SuperAdminProtectionTest.php` | **5 methods** | File method list |
| Employee sessions family | `test_*` in `EmployeeSessionLifecycleTest` (6) + `EmployeeSessionRevocationTest` (6) + `EmployeeSessionLastSeenTest` (5) + `EmployeeSessionSecurityTest` (4) + `DashboardEmployeeSessionsTest` (11) | **32 methods** | Those 5 files |
| Authentication / password reset / OTP (selected suites) | `DashboardAuthTest` (9) + `PasswordResetFlowTest` (8) + `OtpDebugLoggingTest` (4) | **21 methods** | Those 3 files |
| Ownership / IDOR | `SEC-IDOR-NEGATIVE` scenarios | **<?= $nIdor ?> scenarios** | Section 3 |
| Document ownership + review privacy | IDOR document/application checklist rows (2) + privacy methods with `file_path`/`pii` on document review (2) | **4 items** (mixed units; do not blend with scenario totals blindly) | Sections 3–4 |
| FCM / device token security | Privacy methods with `fcm_token`/`job_payload` plus device IDOR writes | **<?= count(array_filter($privacy, fn ($p) => in_array($p[2], ['fcm_token', 'job_payload'], true))) ?> privacy methods** + **2 device IDOR scenarios** | Sections 3–4 |
| Public verification privacy | Privacy method on public verify allowlist | **1 method** | `LicenseVerificationTest::test_active_token_verifies_as_valid_without_pii` |

RBAC curated primary denial methods (<?= count($mech['RBAC_permissions']['methods']) ?>):

<?php foreach ($mech['RBAC_permissions']['methods'] as $m): ?>
- `<?= $m ?>`
<?php endforeach; ?>

---

## 8. Rate limiting status — `SEC-RATE-LIMIT-429`

| Item | Exact value | How counted |
|------|-------------|-------------|
| `throttle:` middleware attachments in route files | **<?= $throttleAttachments ?>** | Occurrences in `routes/api.php` (13) + `dashboard.php` (10) + `admin.php` (10) + `ai-agent.php` (5) + `content.php` (1) |
| Positive tests asserting HTTP 429 | **<?= $assert429 ?>** | Grep `assertStatus(429)` / `assertTooManyRequests` across `tests/**` → **no matches** |
| Test files disabling `ThrottleRequests` | **<?= $throttleDisableFiles ?>** | Files containing `withoutMiddleware([ThrottleRequests::class])` |

**Measurement #5 needed:** add positive 429 Feature tests without disabling throttle for representative routes (forgot-password, public verify, payment initiate).

---

## 9. Security claims we can make

| Claim | Status | Allowed wording |
|-------|--------|-----------------|
| Unauthenticated rejection is automated for <?= $n401 ?> protected-resource scenarios | **VERIFIED** | “Unauthenticated access rejection is supported by <?= $n401 ?> automated 401 scenarios.” |
| Authorization denials are automated for <?= $n403 ?> scenarios | **VERIFIED** | “RBAC/persona denial is supported by <?= $n403 ?> automated 403 scenarios.” |
| Horizontal ownership (IDOR) negatives exist for <?= $nIdor ?> scenarios across <?= count($idorResources) ?> resource types | **VERIFIED** | Cite exact resources; do not claim all resources covered |
| Sensitive data non-exposure has <?= $nPriv ?> dedicated methods | **VERIFIED** (scoped) | Name data classes; not “no leaks possible” |
| Trust boundaries citizen↔employee↔public are tested | **PARTIALLY VERIFIED** | <?= $nTrust ?> scenarios; not every route pair |
| Critical mutating ops authorized-success coverage <?= $critAuth ?>/<?= $critTotal ?> | **VERIFIED** | Use the named metric only |
| Critical mutating ops 401/403 on the mutate route | **PARTIALLY VERIFIED** | <?= $crit401 ?>/<?= $critTotal ?> and <?= $crit403 ?>/<?= $critTotal ?> |
| Rate limiting works | **NOT MEASURED** | Configured in routes; **0** positive 429 tests |
| “The system is completely secure” | **DO NOT CLAIM** | — |
| Pentest-grade assurance | **DO NOT CLAIM** | — |

---

## 10. Final numeric summary

| Metric ID | Metric | Exact value | Denominator | Evidence source | Interpretation | Limitation |
|-----------|--------|-------------|-------------|-----------------|----------------|------------|
| SEC-AUTHN-401 | Unauthenticated rejection scenarios | **<?= $n401 ?>** | — | Section 1 + CSV | Automated guest/revoked-token denials | Not all routes; excludes credential-failure logins |
| SEC-AUTHZ-403 | Authorization denial scenarios | **<?= $n403 ?>** | — | Section 2 + CSV | Automated permission/persona denials | Overlaps IDOR/trust; not route-complete |
| SEC-IDOR-NEGATIVE | Horizontal authz negatives | **<?= $nIdor ?>** | — | Section 3 | Ownership protections tested | Finite resource set |
| SEC-IDOR-READ | Read IDOR scenarios | **<?= $idorRead ?>** | — | Section 3 | Read isolation | — |
| SEC-IDOR-WRITE | Write IDOR scenarios | **<?= $idorWrite ?>** | — | Section 3 | Mutation isolation | Includes one service-level case |
| SEC-DATA-EXPOSURE-NEGATIVE | Privacy negative methods | **<?= $nPriv ?>** | — | Section 4 | Secrets/PII non-exposure asserts | Method≠field count |
| SEC-TRUST-BOUNDARY | Trust-boundary scenarios | **<?= $nTrust ?>** | — | Section 5 | Persona separation evidence | Overlaps 403 |
| SEC-CRITICAL-AUTHORIZED-COVERAGE | Critical ops with success authz evidence | **<?= $critAuth ?>/<?= $critTotal ?> (<?= round(100*$critAuth/$critTotal,1) ?>%)** | <?= $critTotal ?> ops | Section 6 | Happy-path authz present | Does not imply negatives |
| SEC-CRITICAL-401-COVERAGE | Critical ops with mutate-route 401 | **<?= $crit401 ?>/<?= $critTotal ?> (<?= round(100*$crit401/$critTotal,1) ?>%)** | <?= $critTotal ?> ops | Section 6 | Gap-heavy | Sibling GET 401 not counted |
| SEC-CRITICAL-403-COVERAGE | Critical ops with mutate-route 403 | **<?= $crit403 ?>/<?= $critTotal ?> (<?= round(100*$crit403/$critTotal,1) ?>%)** | <?= $critTotal ?> ops | Section 6 | Partial | — |
| SEC-CRITICAL-IDOR-COVERAGE | IDOR evidence among IDOR-applicable critical ops | **<?= $critIdorOk ?>/<?= count($critIdorApp) ?> (<?= count($critIdorApp)?round(100*$critIdorOk/count($critIdorApp),1):0 ?>%)** | <?= count($critIdorApp) ?> applicable | Section 6 | Payment confirm IDOR missing | Only 2 applicable ops |
| SEC-RATE-LIMIT-429 | Positive 429 assertions | **<?= $assert429 ?>** | <?= $throttleAttachments ?> throttle attachments | Section 8 | Unverified throttles | <?= $throttleDisableFiles ?> files disable throttle |

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
<?php
$md = ob_get_clean();
file_put_contents($outDir.'/SECURITY_TEST_EVIDENCE_MATRIX.md', $md);
fwrite(STDERR, "Wrote MD+CSV\n401=$n401 403=$n403 idor=$nIdor (R$idorRead/W$idorWrite) privacy=$nPriv trust=$nTrust\n");
fwrite(STDERR, "critical auth=$critAuth/{$critTotal} 401=$crit401/{$critTotal} 403=$crit403/{$critTotal} idor=$critIdorOk/".count($critIdorApp)."\n");
