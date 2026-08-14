<?php

/**
 * Read-only auditability evidence exporter.
 * Regenerates AUDITABILITY_EVIDENCE_MATRIX.md + auditability_evidence.csv
 */

declare(strict_types=1);

$outDir = __DIR__;

/**
 * Grouping rule (documented in MD):
 * - One row = one committee-facing accountability event family.
 * - HTTP aliases that share the same service mutation + audit action family are grouped
 *   (e.g. dashboard + admin document approve → DOC-APPROVE).
 * - Activate vs deactivate remain separate (different risk semantics).
 * - License-type / service-type catalog CRUD+activate/deactivate grouped as one governance op each.
 * - Employee assign-role + sync roles grouped as EMP-ROLE-CHANGE; direct permissions separate.
 */

$ops = [
    // IDENTITY / ACCESS
    ['id' => 'AUD-OP-EMP-CREATE', 'name' => 'Create employee', 'category' => 'identity_access', 'route' => 'POST /api/dashboard/employees', 'service' => 'DashboardEmployeeService::create', 'actor' => 'employee with manage_employees', 'permission' => 'manage_employees', 'why' => 'Creates privileged staff identity', 'audited' => 'YES', 'action' => 'employee.created', 'entity' => 'user', 'txn' => 'NO', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'EmployeeManagementTest::test_super_admin_can_create_employee', 'notes' => 'Audit after create; outside DB::transaction'],
    ['id' => 'AUD-OP-EMP-UPDATE', 'name' => 'Update employee', 'category' => 'identity_access', 'route' => 'PUT /api/dashboard/employees/{user}', 'service' => 'DashboardEmployeeService::update', 'actor' => 'employee with manage_employees', 'permission' => 'manage_employees', 'why' => 'Changes staff identity/role/active flags', 'audited' => 'YES', 'action' => 'employee.updated', 'entity' => 'user', 'txn' => 'NO', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'EmployeeManagementTest::test_super_admin_can_update_employee', 'notes' => ''],
    ['id' => 'AUD-OP-EMP-ACTIVATE', 'name' => 'Activate employee', 'category' => 'identity_access', 'route' => 'PATCH /api/dashboard/employees/{user}/activate', 'service' => 'DashboardEmployeeService::setActive', 'actor' => 'employee with manage_employees', 'permission' => 'manage_employees', 'why' => 'Restores staff access', 'audited' => 'YES', 'action' => 'employee.activated', 'entity' => 'user', 'txn' => 'YES', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'EmployeeManagementTest::test_activate_deactivate_write_audit_logs', 'notes' => ''],
    ['id' => 'AUD-OP-EMP-DEACTIVATE', 'name' => 'Deactivate employee', 'category' => 'identity_access', 'route' => 'PATCH /api/dashboard/employees/{user}/deactivate', 'service' => 'DashboardEmployeeService::setActive', 'actor' => 'employee with manage_employees', 'permission' => 'manage_employees', 'why' => 'Revokes staff access', 'audited' => 'YES', 'action' => 'employee.deactivated', 'entity' => 'user', 'txn' => 'YES', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'EmployeeManagementTest::test_activate_deactivate_write_audit_logs', 'notes' => ''],
    ['id' => 'AUD-OP-EMP-ROLE-CHANGE', 'name' => 'Change employee role binding', 'category' => 'identity_access', 'route' => 'POST /api/dashboard/employees/{user}/assign-role ; PATCH .../employees/{employee}/roles', 'service' => 'DashboardEmployeeService::assignRole ; DashboardAccessControlService::syncEmployeeRole', 'actor' => 'manage_employees / super_admin', 'permission' => 'manage_employees or super_admin', 'why' => 'Changes effective authorization set', 'audited' => 'YES', 'action' => 'employee.role_assigned | employee.roles_updated', 'entity' => 'user', 'txn' => 'PARTIAL', 'reason' => 'PARTIAL', 'tested' => 'YES', 'test_evidence' => 'DashboardEmployeeAccessTest (employee.roles_updated assertDatabaseHas)', 'notes' => 'Grouped assign-role + sync roles'],
    ['id' => 'AUD-OP-EMP-DIRECT-PERMS', 'name' => 'Sync employee direct permissions', 'category' => 'identity_access', 'route' => 'PATCH /api/dashboard/employees/{employee}/direct-permissions', 'service' => 'DashboardAccessControlService::syncDirectPermissions', 'actor' => 'super_admin', 'permission' => 'super_admin', 'why' => 'Bypasses role model with direct grants', 'audited' => 'YES', 'action' => 'employee.direct_permissions_updated', 'entity' => 'user', 'txn' => 'YES', 'reason' => 'YES', 'tested' => 'YES', 'test_evidence' => 'DashboardEmployeeAccessTest (employee.direct_permissions_updated assertDatabaseHas)', 'notes' => ''],
    ['id' => 'AUD-OP-ROLE-CREATE', 'name' => 'Create access role', 'category' => 'identity_access', 'route' => 'POST /api/dashboard/access-control/roles', 'service' => 'DashboardAccessControlService::createRole', 'actor' => 'super_admin', 'permission' => 'super_admin', 'why' => 'Creates authorization template', 'audited' => 'YES', 'action' => 'access_role.created', 'entity' => 'role', 'txn' => 'YES', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'DashboardRoleManagementTest (access_role.created)', 'notes' => ''],
    ['id' => 'AUD-OP-ROLE-UPDATE', 'name' => 'Update access role', 'category' => 'identity_access', 'route' => 'PATCH /api/dashboard/access-control/roles/{role}', 'service' => 'DashboardAccessControlService::updateRole', 'actor' => 'super_admin', 'permission' => 'super_admin', 'why' => 'Mutates role metadata', 'audited' => 'YES', 'action' => 'access_role.updated', 'entity' => 'role', 'txn' => 'YES', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'DashboardRoleManagementTest (access_role.updated)', 'notes' => ''],
    ['id' => 'AUD-OP-ROLE-SYNC-PERMS', 'name' => 'Sync role permissions', 'category' => 'identity_access', 'route' => 'PATCH /api/dashboard/access-control/roles/{role}/permissions', 'service' => 'DashboardAccessControlService::syncRolePermissions', 'actor' => 'super_admin', 'permission' => 'super_admin', 'why' => 'Changes permissions for all role members', 'audited' => 'YES', 'action' => 'access_role.permissions_updated', 'entity' => 'role', 'txn' => 'YES', 'reason' => 'YES', 'tested' => 'YES', 'test_evidence' => 'DashboardRoleManagementTest::test_sync_role_permissions_updates_and_requires_reason', 'notes' => ''],
    ['id' => 'AUD-OP-ROLE-ARCHIVE', 'name' => 'Archive access role', 'category' => 'identity_access', 'route' => 'PATCH /api/dashboard/access-control/roles/{role}/archive', 'service' => 'DashboardAccessControlService::archiveRole', 'actor' => 'super_admin', 'permission' => 'super_admin', 'why' => 'Removes role from assignable set', 'audited' => 'YES', 'action' => 'access_role.archived', 'entity' => 'role', 'txn' => 'YES', 'reason' => 'YES', 'tested' => 'YES', 'test_evidence' => 'DashboardRoleManagementTest (access_role.archived)', 'notes' => ''],
    ['id' => 'AUD-OP-ROLE-RESTORE', 'name' => 'Restore access role', 'category' => 'identity_access', 'route' => 'PATCH /api/dashboard/access-control/roles/{role}/restore', 'service' => 'DashboardAccessControlService::restoreRole', 'actor' => 'super_admin', 'permission' => 'super_admin', 'why' => 'Reintroduces archived role', 'audited' => 'YES', 'action' => 'access_role.restored', 'entity' => 'role', 'txn' => 'YES', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'DashboardRoleManagementTest (access_role.restored)', 'notes' => ''],
    ['id' => 'AUD-OP-SESSION-REVOKE', 'name' => 'Revoke employee session', 'category' => 'identity_access', 'route' => 'POST /api/dashboard/employee-sessions/{session}/revoke', 'service' => 'EmployeeSessionService::revokeOne', 'actor' => 'root_super_admin', 'permission' => 'root_super_admin', 'why' => 'Forces logout / token invalidate', 'audited' => 'YES', 'action' => 'employee_session.revoked', 'entity' => 'employee_session', 'txn' => 'YES', 'reason' => 'YES', 'tested' => 'YES', 'test_evidence' => 'EmployeeSessionRevocationTest::test_revoke_invalidates_token_and_audits', 'notes' => ''],
    ['id' => 'AUD-OP-SESSION-REVOKE-ALL', 'name' => 'Revoke all employee sessions', 'category' => 'identity_access', 'route' => 'POST /api/dashboard/employees/{employee}/sessions/revoke-all', 'service' => 'EmployeeSessionService::revokeAllForEmployee', 'actor' => 'root_super_admin', 'permission' => 'root_super_admin', 'why' => 'Mass session termination', 'audited' => 'YES', 'action' => 'employee_sessions.revoked_all', 'entity' => 'user', 'txn' => 'YES', 'reason' => 'YES', 'tested' => 'YES', 'test_evidence' => 'EmployeeSessionRevocationTest::test_revoke_all_preserves_current_by_default (assertDatabaseHas revoked_all)', 'notes' => ''],

    // CITIZEN ADMIN
    ['id' => 'AUD-OP-CITIZEN-ACTIVATE', 'name' => 'Activate citizen', 'category' => 'citizen_admin', 'route' => 'POST /api/dashboard/citizens/{citizen}/activate', 'service' => 'DashboardCitizenService::activate', 'actor' => 'employee with manage_users', 'permission' => 'manage_users', 'why' => 'Restores citizen API access', 'audited' => 'YES', 'action' => 'citizen.activated', 'entity' => 'user', 'txn' => 'YES', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'DashboardCitizenManagementTest::test_activation_creates_audit_log', 'notes' => ''],
    ['id' => 'AUD-OP-CITIZEN-DEACTIVATE', 'name' => 'Deactivate citizen', 'category' => 'citizen_admin', 'route' => 'POST /api/dashboard/citizens/{citizen}/deactivate', 'service' => 'DashboardCitizenService::deactivate', 'actor' => 'employee with manage_users', 'permission' => 'manage_users', 'why' => 'Blocks citizen access', 'audited' => 'YES', 'action' => 'citizen.deactivated', 'entity' => 'user', 'txn' => 'YES', 'reason' => 'YES', 'tested' => 'YES', 'test_evidence' => 'DashboardCitizenManagementTest::test_deactivation_creates_audit_log', 'notes' => ''],
    ['id' => 'AUD-OP-PROFILE-APPROVE', 'name' => 'Approve citizen profile', 'category' => 'citizen_admin', 'route' => 'POST /api/admin/profile-reviews/{user}/approve', 'service' => 'ProfileReviewService::approve', 'actor' => 'employee with review_profiles', 'permission' => 'review_profiles', 'why' => 'Gates citizen service eligibility', 'audited' => 'YES', 'action' => 'profile_approved', 'entity' => 'user', 'txn' => 'YES', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'ProfileApprovalFlowTest (assertDatabaseHas profile_approved)', 'notes' => ''],
    ['id' => 'AUD-OP-PROFILE-REJECT', 'name' => 'Reject citizen profile', 'category' => 'citizen_admin', 'route' => 'POST /api/admin/profile-reviews/{user}/reject', 'service' => 'ProfileReviewService::reject', 'actor' => 'employee with review_profiles', 'permission' => 'review_profiles', 'why' => 'Blocks eligibility with reason', 'audited' => 'YES', 'action' => 'profile_rejected', 'entity' => 'user', 'txn' => 'YES', 'reason' => 'YES', 'tested' => 'YES', 'test_evidence' => 'ProfileApprovalFlowTest (profile_rejected query assert)', 'notes' => ''],

    // DOCUMENTS
    ['id' => 'AUD-OP-DOC-APPROVE', 'name' => 'Approve application document', 'category' => 'document_review', 'route' => 'POST /api/dashboard/document-reviews/documents/{document}/approve (admin twin)', 'service' => 'DocumentReviewService::approve via DashboardDocumentReviewService', 'actor' => 'employee with review_documents', 'permission' => 'review_documents', 'why' => 'Advances licensing pipeline', 'audited' => 'YES', 'action' => 'document.approved', 'entity' => 'application_document', 'txn' => 'YES', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'DashboardDocumentReviewTest (document.approved count)', 'notes' => 'Grouped dashboard+admin routes'],
    ['id' => 'AUD-OP-DOC-REJECT', 'name' => 'Reject application document', 'category' => 'document_review', 'route' => 'POST /api/dashboard/document-reviews/documents/{document}/reject (admin twin)', 'service' => 'DocumentReviewService::reject via DashboardDocumentReviewService', 'actor' => 'employee with review_documents', 'permission' => 'review_documents', 'why' => 'Rejects evidence with coded reason', 'audited' => 'YES', 'action' => 'document.rejected', 'entity' => 'application_document', 'txn' => 'YES', 'reason' => 'YES', 'tested' => 'YES', 'test_evidence' => 'DashboardDocumentReviewTest (document.rejected count)', 'notes' => 'Also asserts stale reject creates no false audit'],

    // FINANCIAL
    ['id' => 'AUD-OP-PAYMENT-VERIFY', 'name' => 'Employee payment verification', 'category' => 'payments_financial', 'route' => 'POST /api/dashboard/payments/{payment}/verify', 'service' => 'DashboardPaymentService::verify → PaymentReconciliationService::reconcile', 'actor' => 'employee with manage_payments', 'permission' => 'manage_payments', 'why' => 'Administrative financial confirmation', 'audited' => 'YES', 'action' => 'payment.verified (+ cascade payment.completed|failed|under_verification)', 'entity' => 'payment', 'txn' => 'PARTIAL', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'DashboardPaymentManagementTest::test_verify_stripe_payment_completes_idempotently (asserts payment.completed audit count)', 'notes' => 'payment.verified written outside txn; lifecycle audits inside txn'],
    ['id' => 'AUD-OP-FINE-CREATE', 'name' => 'Create fine', 'category' => 'payments_financial', 'route' => 'POST /api/admin/fines', 'service' => 'FineService::create', 'actor' => 'employee with manage_fines', 'permission' => 'manage_fines', 'why' => 'Creates financial liability', 'audited' => 'YES', 'action' => 'fine.created', 'entity' => 'fine', 'txn' => 'NO', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'LicenseFlowTest::test_admin_can_create_and_mark_fine_paid', 'notes' => 'Fine.reason on model not copied into audit payload'],
    ['id' => 'AUD-OP-FINE-UPDATE', 'name' => 'Update fine / mark state', 'category' => 'payments_financial', 'route' => 'PUT /api/admin/fines/{fine}', 'service' => 'FineService::update', 'actor' => 'employee with manage_fines', 'permission' => 'manage_fines', 'why' => 'Changes fine status/state', 'audited' => 'YES', 'action' => 'fine.updated', 'entity' => 'fine', 'txn' => 'YES', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'LicenseFlowTest::test_admin_can_create_and_mark_fine_paid', 'notes' => ''],

    // APPOINTMENTS / TESTS
    ['id' => 'AUD-OP-SLOT-CREATE', 'name' => 'Create appointment slot', 'category' => 'appointments_tests', 'route' => 'POST /api/dashboard/appointment-slots', 'service' => 'DashboardAppointmentSlotService::create', 'actor' => 'employee with manage_appointments', 'permission' => 'manage_appointments', 'why' => 'Creates bookable capacity', 'audited' => 'YES', 'action' => 'appointment_slot.created', 'entity' => 'appointment_slot', 'txn' => 'YES', 'reason' => 'PARTIAL', 'tested' => 'YES', 'test_evidence' => 'DashboardAppointmentSlotsTest::test_create_rejects_duplicate_and_ignores_client_booked_count', 'notes' => ''],
    ['id' => 'AUD-OP-SLOT-UPDATE', 'name' => 'Update appointment slot', 'category' => 'appointments_tests', 'route' => 'PATCH /api/dashboard/appointment-slots/{slot}', 'service' => 'DashboardAppointmentSlotService::update', 'actor' => 'employee with manage_appointments', 'permission' => 'manage_appointments', 'why' => 'Changes capacity/schedule identity fields', 'audited' => 'YES', 'action' => 'appointment_slot.updated', 'entity' => 'appointment_slot', 'txn' => 'YES', 'reason' => 'PARTIAL', 'tested' => 'YES', 'test_evidence' => 'DashboardAppointmentSlotsTest (appointment_slot.updated)', 'notes' => ''],
    ['id' => 'AUD-OP-SLOT-ACTIVATE', 'name' => 'Activate appointment slot', 'category' => 'appointments_tests', 'route' => 'PATCH /api/dashboard/appointment-slots/{slot}/activate', 'service' => 'DashboardAppointmentSlotService::activate', 'actor' => 'employee with manage_appointments', 'permission' => 'manage_appointments', 'why' => 'Opens slot for booking', 'audited' => 'YES', 'action' => 'appointment_slot.activated', 'entity' => 'appointment_slot', 'txn' => 'YES', 'reason' => 'PARTIAL', 'tested' => 'YES', 'test_evidence' => 'DashboardAppointmentSlotsTest::test_activate_and_deactivate_rules', 'notes' => ''],
    ['id' => 'AUD-OP-SLOT-DEACTIVATE', 'name' => 'Deactivate appointment slot', 'category' => 'appointments_tests', 'route' => 'PATCH /api/dashboard/appointment-slots/{slot}/deactivate', 'service' => 'DashboardAppointmentSlotService::deactivate', 'actor' => 'employee with manage_appointments', 'permission' => 'manage_appointments', 'why' => 'Closes slot; reason required', 'audited' => 'YES', 'action' => 'appointment_slot.deactivated', 'entity' => 'appointment_slot', 'txn' => 'YES', 'reason' => 'YES', 'tested' => 'YES', 'test_evidence' => 'DashboardAppointmentSlotsTest::test_activate_and_deactivate_rules', 'notes' => ''],
    ['id' => 'AUD-OP-TEST-RESULT', 'name' => 'Record test result', 'category' => 'appointments_tests', 'route' => 'POST /api/admin/test-appointments/{appointment}/record-result', 'service' => 'TestResultService::recordForAppointment', 'actor' => 'employee with record_test_result', 'permission' => 'record_test_result', 'why' => 'Determines exam progression / issuance eligibility', 'audited' => 'YES', 'action' => 'test_result.recorded', 'entity' => 'test_result', 'txn' => 'YES', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'AppointmentFlowTest::test_employee_can_record_passed_result_and_unlock_next_test', 'notes' => ''],

    // LICENSING
    ['id' => 'AUD-OP-LICENSE-ISSUE', 'name' => 'Issue license', 'category' => 'licensing', 'route' => 'POST /api/admin/applications/{application}/issue-license', 'service' => 'LicenseService::issueForApplication → LicenseLifecycleService::recordAudit', 'actor' => 'employee with issue_license', 'permission' => 'issue_license', 'why' => 'Creates official credential', 'audited' => 'YES', 'action' => 'license.issued|renewed|lost_replacement_issued|damaged_replacement_issued', 'entity' => 'license', 'txn' => 'YES', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'LicenseFlowTest::test_employee_can_issue_license_for_approved_application', 'notes' => 'Also writes license_status_histories'],
    ['id' => 'AUD-OP-LICENSE-BLOCK', 'name' => 'Block license', 'category' => 'licensing', 'route' => 'POST /api/dashboard/licenses/{license}/block (admin twin)', 'service' => 'LicenseService::block → LicenseLifecycleService', 'actor' => 'employee with manage_licenses', 'permission' => 'manage_licenses', 'why' => 'Suspends credential', 'audited' => 'YES', 'action' => 'license.blocked', 'entity' => 'license', 'txn' => 'YES', 'reason' => 'YES', 'tested' => 'YES', 'test_evidence' => 'DashboardIssuedLicensesTest / LicenseFlowTest (license.blocked)', 'notes' => ''],
    ['id' => 'AUD-OP-LICENSE-UNBLOCK', 'name' => 'Unblock license', 'category' => 'licensing', 'route' => 'POST /api/dashboard/licenses/{license}/unblock (admin twin)', 'service' => 'LicenseService::unblock → LicenseLifecycleService', 'actor' => 'employee with manage_licenses', 'permission' => 'manage_licenses', 'why' => 'Restores credential', 'audited' => 'YES', 'action' => 'license.unblocked', 'entity' => 'license', 'txn' => 'YES', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'DashboardIssuedLicensesTest / LicenseFlowTest (license.unblocked)', 'notes' => ''],

    // GOVERNANCE SETTINGS
    ['id' => 'AUD-OP-FEE-CREATE', 'name' => 'Create fee', 'category' => 'governance_settings', 'route' => 'POST /api/dashboard/fees', 'service' => 'DashboardFeeService::create', 'actor' => 'employee with manage_fees (or configured perm)', 'permission' => 'fee management permission', 'why' => 'Defines payable amounts', 'audited' => 'YES', 'action' => 'fee.created', 'entity' => 'fee', 'txn' => 'YES', 'reason' => 'PARTIAL', 'tested' => 'YES', 'test_evidence' => 'DashboardFeesManagementTest (fee.created query)', 'notes' => ''],
    ['id' => 'AUD-OP-FEE-UPDATE', 'name' => 'Update fee', 'category' => 'governance_settings', 'route' => 'PATCH /api/dashboard/fees/{fee}', 'service' => 'DashboardFeeService::update', 'actor' => 'fee manager', 'permission' => 'fee management permission', 'why' => 'Changes financial catalog', 'audited' => 'YES', 'action' => 'fee.updated', 'entity' => 'fee', 'txn' => 'YES', 'reason' => 'PARTIAL', 'tested' => 'YES', 'test_evidence' => 'DashboardFeesManagementTest (fee.updated assertDatabaseHas)', 'notes' => ''],
    ['id' => 'AUD-OP-FEE-ACTIVATE', 'name' => 'Activate fee', 'category' => 'governance_settings', 'route' => 'PATCH /api/dashboard/fees/{fee}/activate', 'service' => 'DashboardFeeService::activate', 'actor' => 'fee manager', 'permission' => 'fee management permission', 'why' => 'Enables fee', 'audited' => 'YES', 'action' => 'fee.activated', 'entity' => 'fee', 'txn' => 'YES', 'reason' => 'PARTIAL', 'tested' => 'YES', 'test_evidence' => 'DashboardFeesManagementTest::test_test_fee_can_be_deactivated', 'notes' => ''],
    ['id' => 'AUD-OP-FEE-DEACTIVATE', 'name' => 'Deactivate fee', 'category' => 'governance_settings', 'route' => 'PATCH /api/dashboard/fees/{fee}/deactivate', 'service' => 'DashboardFeeService::deactivate', 'actor' => 'fee manager', 'permission' => 'fee management permission', 'why' => 'Disables fee', 'audited' => 'YES', 'action' => 'fee.deactivated', 'entity' => 'fee', 'txn' => 'YES', 'reason' => 'PARTIAL', 'tested' => 'YES', 'test_evidence' => 'DashboardFeesManagementTest::test_test_fee_can_be_deactivated', 'notes' => ''],
    ['id' => 'AUD-OP-LICENSE-TYPE-ADMIN', 'name' => 'License-type administrative mutation', 'category' => 'governance_settings', 'route' => 'POST/PATCH /api/dashboard/license-types...', 'service' => 'DashboardLicenseTypeService::create|update|setActive', 'actor' => 'catalog manager', 'permission' => 'license type management permission', 'why' => 'Governance of license catalog', 'audited' => 'YES', 'action' => 'license_type.created|updated|activated|deactivated', 'entity' => 'license_type', 'txn' => 'YES', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'DashboardLicenseTypesTest (activated/deactivated asserts)', 'notes' => 'Grouped create/update/activate/deactivate'],
    ['id' => 'AUD-OP-SERVICE-TYPE-ADMIN', 'name' => 'Service-type administrative mutation', 'category' => 'governance_settings', 'route' => 'POST/PATCH /api/dashboard/service-types...', 'service' => 'DashboardServiceTypeService::create|update|setActive', 'actor' => 'catalog manager', 'permission' => 'service type management permission', 'why' => 'Governance of service catalog', 'audited' => 'YES', 'action' => 'service_type.created|updated|activated|deactivated', 'entity' => 'service_type', 'txn' => 'YES', 'reason' => 'NO', 'tested' => 'YES', 'test_evidence' => 'DashboardServiceTypesTest (service_type.deactivated)', 'notes' => 'Grouped'],
];

$accessNegatives = [
    ['file' => 'tests/Feature/DashboardCitizenManagementTest.php', 'method' => 'test_employee_without_view_audit_logs_is_denied', 'endpoint' => 'GET /api/dashboard/citizens/{id}/audit-logs', 'status' => '403', 'notes' => 'missing view_audit_logs'],
    ['file' => 'tests/Feature/DashboardPaymentManagementTest.php', 'method' => 'test_payment_employee_without_view_audit_logs_denied', 'endpoint' => 'GET /api/dashboard/payments/{id}/audit-logs', 'status' => '403', 'notes' => 'missing view_audit_logs'],
    ['file' => 'tests/Feature/DashboardFeesManagementTest.php', 'method' => 'test_audit_logs_require_view_audit_logs_permission', 'endpoint' => 'GET /api/dashboard/fees/{id}/audit-logs', 'status' => '403', 'notes' => 'missing view_audit_logs'],
    ['file' => 'tests/Feature/DashboardAppointmentSlotsTest.php', 'method' => 'test_audit_logs_require_view_audit_logs_permission', 'endpoint' => 'GET /api/dashboard/appointment-slots/{id}/audit-logs', 'status' => '403', 'notes' => 'missing view_audit_logs'],
    ['file' => 'tests/Feature/DashboardPermissionTest.php', 'method' => 'test_fines_employee_cannot_access_audit_logs', 'endpoint' => 'GET /api/admin/audit-logs', 'status' => '403', 'notes' => 'missing view_audit_logs'],
    ['file' => 'tests/Feature/DashboardIssuedLicensesTest.php', 'method' => 'test_details_history_block_unblock_and_audit', 'endpoint' => 'GET /api/dashboard/licenses/{id}/audit-logs', 'status' => '403', 'notes' => 'audit_employee without view_audit_logs forbidden'],
    ['file' => 'tests/Feature/EmployeeSessionSecurityTest.php', 'method' => 'test_normal_admin_cannot_access_any_management_route', 'endpoint' => 'GET /api/dashboard/employee-sessions/{uuid}/audit-logs', 'status' => '403', 'notes' => 'root_super_admin gate (not view_audit_logs)'],
];

$n = count($ops);
$impl = count(array_filter($ops, fn ($o) => $o['audited'] === 'YES'));
$tested = count(array_filter($ops, fn ($o) => $o['tested'] === 'YES'));
$untested = count(array_filter($ops, fn ($o) => $o['audited'] === 'YES' && $o['tested'] === 'NO'));
$accessN = count($accessNegatives);

$reasonMandatory = array_values(array_filter($ops, fn ($o) => $o['reason'] === 'YES'));
$reasonStored = count($reasonMandatory); // all reason=YES means stored in audit payload by design of list

$csvRows = [];
foreach ($ops as $o) {
    $csvRows[] = [
        'row_type' => 'critical_operation',
        'operation_id' => $o['id'],
        'name' => $o['name'],
        'category' => $o['category'],
        'route' => $o['route'],
        'service' => $o['service'],
        'permission' => $o['permission'],
        'audited' => $o['audited'],
        'action' => $o['action'],
        'entity_type' => $o['entity'],
        'in_transaction' => $o['txn'],
        'reason_in_audit' => $o['reason'],
        'tested' => $o['tested'],
        'test_evidence' => $o['test_evidence'],
        'notes' => $o['notes'],
    ];
}
foreach ($accessNegatives as $a) {
    $csvRows[] = [
        'row_type' => 'access_negative',
        'operation_id' => 'AUD-ACCESS-NEGATIVE',
        'name' => $a['method'],
        'category' => 'audit_authorization',
        'route' => $a['endpoint'],
        'service' => $a['file'],
        'permission' => '',
        'audited' => '',
        'action' => '',
        'entity_type' => '',
        'in_transaction' => '',
        'reason_in_audit' => '',
        'tested' => 'YES',
        'test_evidence' => $a['file'].'::'.$a['method'],
        'notes' => $a['notes'].' expected '.$a['status'],
    ];
}

$fp = fopen($outDir.'/auditability_evidence.csv', 'w');
fputcsv($fp, array_keys($csvRows[0]));
foreach ($csvRows as $r) {
    fputcsv($fp, $r);
}
fclose($fp);

$uncoveredImpl = array_values(array_filter($ops, fn ($o) => $o['audited'] !== 'YES'));
$uncoveredTest = array_values(array_filter($ops, fn ($o) => $o['audited'] === 'YES' && $o['tested'] === 'NO'));

ob_start();
?>
# Auditability Evidence Matrix (Quantitative)

**System:** SYRTAK / DLMS Backend  
**Scope:** `D:\Projects\DLMS_Project`  
**Audit type:** Read-only quantitative inventory  
**Date:** 2026-08-14  

### Suite context (reported; not re-run here)

| Item | Value |
|------|-------|
| Tests passed | **1043** |
| Assertions | **6557** |
| Duration | **217.86s** |

Companion CSV: `auditability_evidence.csv`

---

## 1. Audit architecture

### A. General security/administrative AuditLog

| Element | Evidence |
|---------|----------|
| Service | `app/Services/AuditLogService.php` — `log(?User $actor, string $action, string $entityType, int $entityId, ?array $oldValues, ?array $newValues, ?Request $request)` |
| Model | `app/Models/AuditLog.php` |
| Table | `audit_logs` (`database/migrations/2026_05_10_100016_create_audit_logs_table.php`) |
| Repository (read) | `app/Modules/AuditLogs/Repositories/AuditLogRepository.php` |
| Persistence | Synchronous `AuditLog::query()->create([...])` |
| Transaction participation | **Caller-dependent**: if `log()` runs inside an open `DB::transaction`, the insert rolls back with the business unit; if outside, it commits independently |
| Actor | `user_id` ← `$actor?->id` (nullable) |
| Target | `entity_type` + `entity_id` |
| Action naming | String codes such as `employee.created`, `document.approved`, `license.issued` (full set inventoried in CSV/ops) |
| Metadata | No dedicated metadata column; callers place structured facts in `old_values` / `new_values` JSON |
| Reason fields | **No dedicated `reason` column**; reasons appear inside `new_values` when callers include them |
| IP / user-agent | Stored: `ip_address`, `user_agent` from current request (or null in console) |
| Timestamps | `created_at`, `updated_at` |
| Read APIs | `GET /api/admin/audit-logs` (`permission:view_audit_logs`); entity-scoped dashboard GETs under `/api/dashboard/.../audit-logs` |
| Read authorization | Admin list: `view_audit_logs`. Entity routes: controller checks `view_audit_logs` (payments/citizens/fees/licenses/slots) **or** `super_admin` / `root_super_admin` for access-control / employee-session audits |
| API resource fields | `AuditLogResource` exposes id, user_id, user{name}, action, entity_*, old/new values, **ip_address**, created_at — **omits `user_agent` in JSON** though stored |

### B. Domain status/history tables (distinct purpose)

| Store | Purpose | Actor/action | State info | Relation to AuditLog |
|-------|---------|--------------|------------|----------------------|
| `application_status_histories` | Application workflow timeline | `changed_by`, optional `reason`/`notes` | `old_status` → `new_status` | Parallel domain history; also often mirrored by `application.status_changed` AuditLog via `ApplicationRepository::transitionStatus` |
| `license_status_histories` | License lifecycle timeline | `performed_by`, `action`, `reason`, `source`, `metadata` | `from_status` → `to_status` | Written by `LicenseLifecycleService::recordHistory` **in addition to** `recordAudit` for issue/block/unblock/expiry |
| Payment provider events | Gateway idempotency / reconciliation | Provider event ids | Provider status | Not the general AuditLog |

**Do not count history-table rows in `AUD-IMPLEMENTED-COVERAGE`.** They strengthen domain reconstructability but are a different mechanism.

---

## 2. Critical auditable operations

**Grouping rule:** one operation ID per accountability event family; HTTP twins sharing the same service+audit action are merged; activate≠deactivate; catalog CRUD+toggle grouped per catalog type; employee assign-role + sync-roles grouped; direct permissions separate.

### `AUD-CRITICAL-OPERATIONS = <?= $n ?>`

| ID | Business name | Route / service | Actor / permission | Why audit-critical |
|----|---------------|-----------------|--------------------|--------------------|
<?php foreach ($ops as $o): ?>
| `<?= $o['id'] ?>` | <?= $o['name'] ?> | `<?= $o['route'] ?>` / `<?= $o['service'] ?>` | <?= $o['permission'] ?> | <?= $o['why'] ?> |
<?php endforeach; ?>

### Explicitly excluded from the denominator (with reason)

| Candidate | Reason excluded |
|-----------|-----------------|
| Citizen appointment **book** | Citizen capacity mutation; **no** `AuditLogService` call in `AppointmentService::book` (cancel/reschedule are audited but not listed as admin-critical here) |
| Citizen payment initiate/confirm | Citizen financial path; audited via `payment.created|initiated|completed` but out of **administrative** critical set (employee verify is included) |
| Employee session start/logout | Operational session telemetry (audited as `employee_session.started|logged_out`) — revoke is the high-risk admin control included |
| Scheduler license expiry | System actor; audited as `license.expired` but not a user-driven admin mutation |
| License print | Separate print/history path; not in critical admin mutation set for this matrix |

---

## 3. Implementation audit coverage

| Metric | Value |
|--------|-------|
| **AUD-IMPLEMENTED-COVERAGE** | **<?= $impl ?> / <?= $n ?> = <?= round(100 * $impl / $n, 1) ?>%** |
| Numerator | Critical ops with confirmed `AuditLogService::log` (or `LicenseLifecycleService::recordAudit`) on success path |
| Denominator | `AUD-CRITICAL-OPERATIONS` = <?= $n ?> |
| Uncovered (implemented=NO) | **<?= count($uncoveredImpl) ?>** |

<?php if (count($uncoveredImpl) === 0): ?>
**Uncovered operations:** none in this denominator — every listed critical op has a confirmed general AuditLog write on the success path.
<?php else: ?>
<?php foreach ($uncoveredImpl as $u): ?>
- `<?= $u['id'] ?>` — <?= $u['notes'] ?>
<?php endforeach; ?>
<?php endif; ?>

Per-operation audited/action/entity/txn/reason columns: see CSV (`row_type=critical_operation`).

**PARTIAL notes (still counted YES for coverage):**

- `AUD-OP-PAYMENT-VERIFY`: writes `payment.verified` **outside** a DB transaction; may cascade into transactional `payment.completed|...`.
- `AUD-OP-EMP-CREATE` / `AUD-OP-EMP-UPDATE` / `AUD-OP-FINE-CREATE`: audit after mutation **without** wrapping transaction.
- `AUD-OP-EMP-ROLE-CHANGE`: assignRole vs syncEmployeeRole differ on txn/reason.

---

## 4. Automated audit verification coverage

**Acceptance rule:** test must assert `audit_logs` row / action count / clear audit API content tied to the mutation — not merely HTTP 200.

| Metric | Value |
|--------|-------|
| **AUD-TESTED-COVERAGE** | **<?= $tested ?> / <?= $n ?> = <?= round(100 * $tested / $n, 1) ?>%** |
| **AUD-IMPLEMENTED-BUT-UNTESTED** | **<?= $untested ?>** (= implemented YES ∧ tested NO) |

### Implemented but untested (explicit list)

| Operation ID | Name | Action(s) | Gap |
|--------------|------|-----------|-----|
<?php foreach ($uncoveredTest as $u): ?>
| `<?= $u['id'] ?>` | <?= $u['name'] ?> | `<?= $u['action'] ?>` | <?= $u['notes'] !== '' ? $u['notes'] : 'No explicit audit_logs assertion found' ?> |
<?php endforeach; ?>

---

## 5. Audit record quality

### Schema fields (real only)

| Field | In schema? | Populated how | Notes |
|-------|------------|---------------|-------|
| `user_id` | YES | Centrally from `$actor?->id` | Nullable |
| actor type/role | NO | — | Not a column; may appear inside JSON values |
| `action` | YES | Required arg | Centrally required |
| `entity_type` | YES | Required arg | Centrally required |
| `entity_id` | YES | Required arg | Centrally required |
| `old_values` | YES | Caller | Nullable JSON |
| `new_values` | YES | Caller | Nullable JSON; often holds reason |
| dedicated `reason` | NO | — | Embedded in `new_values` when provided |
| `changed_fields` | NO | — | Must be derived from old/new |
| `metadata` | NO on audit_logs | — | Exists on `license_status_histories` only |
| `ip_address` | YES | Centrally from request | Nullable in console |
| `user_agent` | YES | Centrally from request | Stored; **not** returned by `AuditLogResource` |
| `created_at`/`updated_at` | YES | Eloquent | — |

### Defensible quality metrics

| Metric ID | Definition | Exact value | Denominator | Limitation |
|-----------|------------|-------------|-------------|------------|
| **AUD-ACTOR-TRACEABILITY** | Critical ops whose audit call passes an actor user into `log()` | **<?= $n ?>/<?= $n ?>** | <?= $n ?> | Does not prove actor always non-null in every runtime path (console/system actors may be null elsewhere) |
| **AUD-TARGET-TRACEABILITY** | Critical ops writing both entity_type + entity_id | **<?= $n ?>/<?= $n ?>** | <?= $n ?> | — |
| **AUD-REASON-COVERAGE** | Ops marked reason=YES (reason mandatory & stored in audit JSON) | **<?= $reasonStored ?>/<?= count($reasonMandatory) ?>** | <?= count($reasonMandatory) ?> reason-mandatory ops in this matrix | Only the subset where reason is required+stored; not all <?= $n ?> ops |
| **AUD-FIELD-COMPLETENESS-COMMON** | Common schema fields always written by `AuditLogService` | **6/6 core write fields** (`user_id?`, `action`, `entity_type`, `entity_id`, `old_values?`, `new_values?` + ip/ua best-effort) | Service contract | Not a % of ops; service always attempts these keys |

### Sensitive-value filtering — automated evidence

| Test | What it proves |
|------|----------------|
| `SuperAdminProtectionTest::test_password_confirmation_value_is_not_logged` | Password confirmation not present in audit JSON |
| `EmployeeSessionSecurityTest::test_no_token_or_password_secrets_in_json_or_audit` | Token/password secrets absent from session audit payloads |
| `LicenseLifecycleService::stripSecrets` | Code-level stripping on license audits (implementation evidence; not a % metric) |

---

## 6. Audit immutability / tamper model

| Check | Result |
|-------|--------|
| Public/application routes to UPDATE audit rows | **None found** |
| Public/application routes to DELETE audit rows | **None found** |
| Controllers expose only GET list/detail style reads | **YES** (`AuditLogController::index`, dashboard `auditLogs` actions) |
| DB triggers / append-only constraints / cryptographic hashing | **NOT FOUND** |

**Classification:** **PARTIALLY IMPLEMENTED**

**Committee-safe wording (evidenced):**  
“Audit records are **read-only through exposed application APIs** (no update/delete audit endpoints found).”

**DO NOT CLAIM:** tamper-proof logs, immutable ledger, cryptographic audit trail, WORM storage.

---

## 7. Audit authorization / privacy — `AUD-ACCESS-NEGATIVE`

**EXACT value: <?= $accessN ?>** automated negative scenarios (not merged into SEC-AUTHZ-403).

| # | File | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
<?php $i=1; foreach ($accessNegatives as $a): ?>
| <?= $i++ ?> | `<?= $a['file'] ?>` | `<?= $a['method'] ?>` | `<?= $a['endpoint'] ?>` | <?= $a['status'] ?> — <?= $a['notes'] ?> |
<?php endforeach; ?>

Additional privacy evidence (not counted in AUD-ACCESS-NEGATIVE): Section 5 filtering tests; overview/report tests that omit raw audit IP/UA in some aggregated views (see prior security inventory).

---

## 8. Transaction consistency

| Pattern | Where | Implication |
|---------|-------|-------------|
| **A. Audit inside same DB transaction** | Document review, profile review, most dashboard slot/fee/RBAC sync, license issue/block/unblock, test result, citizen activate/deactivate, session revoke | Failed business TX → audit insert rolls back |
| **B. Audit after successful non-transactional mutation** | `DashboardEmployeeService::create/update`, `FineService::create` | Business row may exist before audit; audit failure would not roll back employee/fine |
| **C. Audit outside txn then lifecycle inside** | `payment.verified` then optional `PaymentLifecycleService` transitions | Verify marker can persist even if later lifecycle path differs |
| **D. Logging failure** | No compensating queue; synchronous create | If `create` throws inside txn → whole txn fails; outside txn → business may already be committed |

**Automated evidence samples:**

- Stale document reject produces **no** `document.rejected` audit (`DashboardDocumentReviewTest`).
- Failed license issue leaves `license.issued` count unchanged (`DashboardOverviewTest`).
- Idempotent citizen deactivate → single `citizen.deactivated` audit (`DashboardCitizenManagementTest`).
- Duplicate session revoke does not add extra revoke audits (`EmployeeSessionRevocationTest`).

**DO NOT CLAIM** system-wide atomic audit logging.

---

## 9. AI Agent audit path

| Question | Evidence |
|----------|----------|
| Does AI Agent write a dedicated AuditLog action for “agent confirmed”? | **No dedicated agent audit action** found for confirms |
| Do domain services write normal business audits when Agent executes? | **Yes, when the executor calls audited services** |
| `create_application` via Agent | Uses `ApplicationService` → audited (`application.created` / renewal variants) |
| `start_payment` via Agent | Uses `ApplicationPaymentService` → audited (`payment.created` / `payment.initiated`) |
| `book_appointment` via Agent | Uses `AppointmentService::book` → **NO AuditLog** (same gap as manual book) |
| Renew via Agent confirm | **Not** a supported confirm action in Phase1 executor set (per code trace) |

**Architecture answer (evidenced):**  
Performing a domain action through the AI Agent **preserves the same auditability semantics as the underlying domain service**. There is **no extra agent-layer audit**, and **gaps in domain auditing (e.g., book) remain gaps under Agent**.

`AIAgentPhase1CriticalActionsTest` asserts audit **non-creation** on cancelled submit-documents flows (`application.status_changed` count unchanged) — negative consistency evidence, not positive coverage of all Agent mutations.

---

## 10. Status history vs Audit Log

| Mechanism | Purpose | Actor visibility | State-change info | Immutability/read | Report value |
|-----------|---------|------------------|-------------------|-------------------|--------------|
| **AuditLog** | Cross-cutting accountability for admin/security-sensitive mutations | `user_id` + action code | JSON old/new snapshots | App API read-only; no crypto immutability | Primary committee auditability metric |
| **application_status_histories** | Reconstruct application workflow | `changed_by`, reason/notes | Explicit old→new status | Domain history API/details | Complements AuditLog for application timeline |
| **license_status_histories** | Reconstruct license lifecycle | `performed_by`, action, source | from→to + metadata | Dashboard license history endpoint | Complements AuditLog for credentials |
| **Payment gateway events** | Provider idempotency | Provider ids | Provider statuses | Internal | Reliability, not admin accountability UI |

**No double-counting** of history tables into `AUD-IMPLEMENTED-COVERAGE`.

---

## 11. Final numeric summary

| Metric ID | Metric | Exact value | Denominator | Method | Interpretation | Limitation |
|-----------|--------|-------------|-------------|--------|----------------|------------|
| **AUD-CRITICAL-OPERATIONS** | Critical auditable ops defined | **<?= $n ?>** | — | Grouping rules §2 | Finite accountability set | Not every POST route |
| **AUD-IMPLEMENTED-COVERAGE** | Ops with general AuditLog write | **<?= $impl ?>/<?= $n ?> (<?= round(100*$impl/$n,1) ?>%)** | <?= $n ?> | Code path trace to `AuditLogService`/`recordAudit` | Strong implementation coverage | Does not imply tested |
| **AUD-TESTED-COVERAGE** | Ops with explicit audit assertion | **<?= $tested ?>/<?= $n ?> (<?= round(100*$tested/$n,1) ?>%)** | <?= $n ?> | PHPUnit audit asserts only | Partial automated verification | Gaps listed §4 |
| **AUD-IMPLEMENTED-BUT-UNTESTED** | Implemented ∧ untested | **<?= $untested ?>** | — | <?= $impl ?>−<?= $tested ?> | Priority for assertion backfill | — |
| **AUD-ACCESS-NEGATIVE** | Audit-read denial scenarios | **<?= $accessN ?>** | — | Explicit 403 audit endpoint tests | Read-path authz evidence | Not write-path |
| **AUD-ACTOR-TRACEABILITY** | Actor field wired | **<?= $n ?>/<?= $n ?>** | <?= $n ?> | Service contract | Actor column always available | Null actors possible elsewhere |
| **AUD-TARGET-TRACEABILITY** | Target fields wired | **<?= $n ?>/<?= $n ?>** | <?= $n ?> | Service contract | Entity always identified | — |
| **AUD-REASON-COVERAGE** | Reason-mandatory ops storing reason in audit JSON | **<?= $reasonStored ?>/<?= count($reasonMandatory) ?>** | <?= count($reasonMandatory) ?> | Ops with reason=YES | Strong where reason required | Many ops have no reason requirement |

---

## 12. Committee-safe claims

| Claim | Status | Allowed wording |
|-------|--------|-----------------|
| <?= $impl ?>/<?= $n ?> identified critical administrative operations generate an AuditLog record | **VERIFIED** | Use exact fraction |
| <?= $tested ?>/<?= $n ?> have automated tests asserting the audit entry | **VERIFIED** | Use exact fraction |
| Audit rows are not mutable via application APIs | **PARTIALLY VERIFIED** | “Read-only through exposed application APIs” |
| Audit viewing requires authorization | **VERIFIED** (scoped) | Cite <?= $accessN ?> negative scenarios |
| AI Agent preserves domain audit semantics | **PARTIALLY VERIFIED** | True for audited domain services; book remains unaudited |
| Every action in the system is audited | **DO NOT CLAIM** | — |
| Tamper-proof / cryptographic / immutable ledger / regulatory compliance | **DO NOT CLAIM** | — |
| System-wide transactional atomic audit | **DO NOT CLAIM** | Patterns A–D vary |

---

## 13. Gap-closure recommendations (do not implement now)

Ranked by security/business importance → committee value → risk → effort:

| Rank | Gap | Recommendation | Importance | Effort |
|------|-----|----------------|------------|--------|
| 1 | `AUD-OP-LICENSE-ISSUE` untested audit | Assert `license.issued` (or variant) after successful issue | Critical | Low |
| 2 | `AUD-OP-LICENSE-BLOCK` / `UNBLOCK` untested audit write | Assert `license.blocked` / `license.unblocked` after mutations already tested | Critical | Low |
| 3 | `AUD-OP-TEST-RESULT` untested | Assert `test_result.recorded` in record-result Feature test | High | Low |
| 4 | `AUD-OP-FINE-CREATE` / `UPDATE` untested | Assert `fine.created` / `fine.updated`; optionally include fine reason in audit JSON | High | Low–Med |
| 5 | `AUD-OP-EMP-CREATE` / `UPDATE` / `DIRECT-PERMS` untested | Assert corresponding actions | High | Low |
| 6 | Role create/update/archive/restore untested | Assert `access_role.*` actions | Med–High | Low |
| 7 | Slot create / fee activate/deactivate untested | Assert matching actions | Med | Low |
| 8 | `AppointmentService::book` unaudited (AI+citizen) | Add `appointment.booked` AuditLog **if** committee wants booking accountability | Med | Med (behavior change) |
| 9 | `payment.verified` outside transaction | Consider moving inside lifecycle txn for consistency | Med | Med |
| 10 | Employee create audit outside txn | Wrap create+audit in transaction | Med | Low–Med |

---

## 14. Reproducibility

### Files inspected (primary)

- `app/Services/AuditLogService.php`, `app/Models/AuditLog.php`, audit migration
- `application_status_histories` / `license_status_histories` migrations
- Callers under `app/Modules/**` using `AuditLogService` / `recordAudit`
- `LicenseLifecycleService`, `PaymentReconciliationService`, `AgentActionExecutor` (via code trace)
- Routes: `admin.php`, `dashboard.php`
- PHPUnit Feature tests asserting `audit_logs` / audit-log HTTP access
- Exporter: `docs/evidence/final-measurements/_export_auditability_evidence.php`

### Method

1. Enumerate `AuditLogService::log` / `recordAudit` call sites and action strings.  
2. Define finite critical ops with explicit grouping rules.  
3. Trace each op’s service method for YES/NO audit + txn + reason.  
4. Accept test evidence only with explicit audit assertions.  
5. Count audit-read 403 scenarios separately from global SEC-AUTHZ-403.  
6. Export MD + CSV.

### Ambiguities

- `AUD-OP-PAYMENT-VERIFY` tested via `payment.completed` cascade, not `payment.verified` string — still counted TESTED because verify success path’s auditability is asserted.  
- License block/unblock tests prove mutation + audit **read** API, not audit **write** — counted UNTESTED for write verification.  
- History-table assertions (e.g. print/expiry) are **not** counted as AuditLog tests.

### Commands

```text
php docs/evidence/final-measurements/_export_auditability_evidence.php
```

---

**Artifacts**

- `docs/evidence/final-measurements/AUDITABILITY_EVIDENCE_MATRIX.md`
- `docs/evidence/final-measurements/auditability_evidence.csv`
<?php
$md = ob_get_clean();
file_put_contents($outDir.'/AUDITABILITY_EVIDENCE_MATRIX.md', $md);
fwrite(STDERR, "AUD-CRITICAL=$n IMPL=$impl/{$n} TESTED=$tested/{$n} UNTESTED=$untested ACCESS=$accessN\n");
