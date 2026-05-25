<?php

/**
 * One-off script: replace English API message literals with translation keys.
 * Run: php scripts/apply-ar-message-keys.php
 */

$root = dirname(__DIR__);

$replacements = [
    // ApiException & successResponse message literals => keys
    "'This email is already registered.'" => "'messages.auth.email_registered'",
    "'Invalid verification purpose for this endpoint.'" => "'messages.auth.invalid_verification_purpose'",
    "'User not found.'" => "'messages.auth.user_not_found'",
    "'Invalid account type for this verification.'" => "'messages.auth.invalid_account_type'",
    "'Invalid or expired reset token.'" => "'messages.auth.invalid_reset_token'",
    "'Invalid credentials.'" => "'messages.auth.invalid_credentials'",
    "'This account is inactive.'" => "'messages.auth.account_inactive'",
    "'Please verify your email before logging in.'" => "'messages.auth.email_not_verified'",
    "'Only citizens can complete this profile.'" => "'messages.auth.citizen_profile_only'",
    "'Current password is incorrect.'" => "'messages.auth.current_password_wrong'",
    "'OTP channel is not configured for email delivery.'" => "'messages.auth.otp_channel_not_configured'",
    "'Unable to send verification email. Please try again later.'" => "'messages.auth.otp_send_failed'",
    "'Invalid or expired verification code.'" => "'messages.auth.otp_invalid'",
    "'Verification code has expired. Request a new code.'" => "'messages.auth.otp_expired'",
    "'Invalid verification code.'" => "'messages.auth.otp_wrong'",
    "'Application not found.'" => "'messages.applications.not_found'",
    "'Only citizens can manage license applications.'" => "'messages.applications.citizen_only'",
    "'Verify your email before creating an application.'" => "'messages.applications.verify_email_first'",
    "'Complete your profile before creating an application.'" => "'messages.applications.complete_profile_first'",
    "'Invalid required document.'" => "'messages.documents.invalid_required'",
    "'Could not store the uploaded file.'" => "'messages.documents.store_failed'",
    "'This application cannot be submitted for document review in its current state.'" => "'messages.documents.cannot_submit_status'",
    "'All required documents must be uploaded before submission.'" => "'messages.documents.all_required_missing'",
    "'Replace rejected documents before submission.'" => "'messages.documents.replace_rejected'",
    "'Documents cannot be modified for this application in its current state.'" => "'messages.documents.cannot_modify'",
    "'This document type does not apply to this application.'" => "'messages.documents.type_not_applicable'",
    "'Invalid file type for this document.'" => "'messages.documents.invalid_file_type'",
    "'Document not found.'" => "'messages.documents.review_not_found'",
    "'This document is not awaiting review.'" => "'messages.documents.not_awaiting_review'",
    "'This document has already been reviewed.'" => "'messages.documents.already_reviewed'",
    "'Payments can only be initiated when the application is awaiting payment.'" => "'messages.payments.not_awaiting_payment'",
    "'Payment already completed.'" => "'messages.payments.already_completed'",
    "'Manual confirmation is disabled for Stripe payments.'" => "'messages.payments.manual_confirm_disabled'",
    "'Payment not found.'" => "'messages.payments.not_found'",
    "'This application is not awaiting payment.'" => "'messages.payments.not_awaiting_payment'",
    "'This payment cannot be confirmed in its current state.'" => "'messages.payments.cannot_confirm_state'",
    "'Payment cannot be completed in its current state.'" => "'messages.payments.cannot_complete_state'",
    "'Stripe currency mismatch.'" => "'messages.payments.stripe_currency_mismatch'",
    "'Stripe amount mismatch.'" => "'messages.payments.stripe_amount_mismatch'",
    "'No application fee is configured for this license and service type.'" => "'messages.payments.no_fee_configured'",
    "'Test type not found.'" => "'messages.appointments.test_type_not_found'",
    "'No test is available to book for this application.'" => "'messages.appointments.no_test_available'",
    "'This appointment slot is not available.'" => "'messages.appointments.slot_unavailable'",
    "'Appointment not found.'" => "'messages.appointments.not_found'",
    "'Only booked appointments can be rescheduled.'" => "'messages.appointments.only_booked_reschedule'",
    "'Only booked appointments can be cancelled.'" => "'messages.appointments.only_booked_cancel'",
    "'The selected appointment slot is not available.'" => "'messages.appointments.slot_not_available'",
    "'Appointments cannot be booked for this application in its current status.'" => "'messages.appointments.cannot_book_status'",
    "'This test cannot be booked yet. Complete prior tests or finish an existing booking first.'" => "'messages.appointments.test_not_ready'",
    "'Earlier tests must be passed before booking this test.'" => "'messages.appointments.prior_tests_required'",
    "'Test appointment not found.'" => "'messages.tests.appointment_not_found'",
    "'A result can only be recorded for a booked appointment.'" => "'messages.tests.only_booked_result'",
    "'A result has already been recorded for this appointment.'" => "'messages.tests.already_recorded'",
    "'The application is not in a testable status.'" => "'messages.tests.not_testable_status'",
    "'Invalid test result.'" => "'messages.tests.invalid_result'",
    "'License not found.'" => "'messages.licenses.not_found'",
    "'A license has already been issued for this application.'" => "'messages.licenses.already_issued'",
    "'Replacement type must be lost or damaged.'" => "'messages.licenses.replacement_type_invalid'",
    "'Blocked licenses cannot be replaced.'" => "'messages.licenses.blocked_cannot_replace'",
    "'This license cannot be replaced in its current status.'" => "'messages.licenses.cannot_replace_status'",
    "'Only blocked licenses can request unblock.'" => "'messages.licenses.only_blocked_unblock'",
    "'All related fines must be paid before requesting unblock.'" => "'messages.licenses.fines_before_unblock'",
    "'This license cannot be blocked in its current status.'" => "'messages.licenses.cannot_block_status'",
    "'Only blocked licenses can be unblocked.'" => "'messages.licenses.only_blocked_can_unblock'",
    "'Citizen has unpaid fines. Fines must be settled before unblock.'" => "'messages.licenses.unpaid_fines_unblock'",
    "'Application must be approved before a license can be issued.'" => "'messages.licenses.must_be_approved'",
    "'All required tests must be passed before issuing a license.'" => "'messages.licenses.tests_required'",
    "'Application fee payment must be completed before issuing a license.'" => "'messages.licenses.payment_required'",
    "'All required documents must be approved before issuing a license.'" => "'messages.licenses.documents_required'",
    "'Citizen has unpaid fines. Fines must be settled before license issuance.'" => "'messages.licenses.unpaid_fines_issue'",
    "'This license cannot be renewed in its current status.'" => "'messages.licenses.cannot_renew_status'",
    "'License is not yet eligible for renewal.'" => "'messages.licenses.not_eligible_renewal'",
    "'Unpaid fines must be settled before continuing.'" => "'messages.licenses.unpaid_fines_continue'",
    "'Fine amount must be greater than zero.'" => "'messages.fines.amount_invalid'",
    "'Citizen not found.'" => "'messages.fines.citizen_not_found'",
    "'License not found for this citizen.'" => "'messages.fines.license_not_found'",
    "'Fine not found.'" => "'messages.fines.not_found'",
    "'Paid fines cannot be cancelled.'" => "'messages.fines.paid_cannot_cancel'",
    "'Notification not found.'" => "'messages.notifications.not_found'",
    "'AI agent is currently disabled.'" => "'messages.ai_agent.disabled'",
    "'Message cannot be empty.'" => "'messages.ai_agent.message_empty'",
    "'AI agent session not found.'" => "'messages.ai_agent.session_not_found'",
    "'This AI agent session is closed.'" => "'messages.ai_agent.session_closed'",
    "'This action requires an authorized employee.'" => "'messages.ai_agent.employee_required'",
    "'This action cannot be executed yet. Please use the standard API endpoints.'" => "'messages.ai_agent.cannot_execute_yet'",
    "'AI agent action not found.'" => "'messages.ai_agent.action_not_found'",
    "'This action cannot be cancelled.'" => "'messages.ai_agent.cannot_cancel'",
    "'Unsupported AI agent action.'" => "'messages.ai_agent.unsupported_action'",
    "'Application ID is required for this action.'" => "'messages.ai_agent.application_id_required'",
    "'License type is required.'" => "'messages.ai_agent.license_type_required'",
    "'Invalid or inactive license type.'" => "'messages.ai_agent.license_type_invalid'",
    "'Invalid or inactive service type.'" => "'messages.ai_agent.service_type_invalid'",
    "'Registration successful. Verification code sent to email.'" => "'messages.auth.register_success'",
    "'Email verified successfully.'" => "'messages.auth.verify_success'",
    "'Logged in successfully.'" => "'messages.auth.login_success'",
    "'Logged out successfully.'" => "'messages.auth.logout_success'",
    "'If the email exists, a verification code has been sent.'" => "'messages.auth.forgot_sent'",
    "'OTP verified successfully.'" => "'messages.auth.forgot_otp_verified'",
    "'Password reset successfully. Please login again.'" => "'messages.auth.password_reset'",
    "'Profile retrieved successfully.'" => "'messages.auth.profile_retrieved'",
    "'Profile completed successfully.'" => "'messages.auth.profile_completed'",
    "'Profile updated successfully.'" => "'messages.auth.profile_updated'",
    "'Password changed successfully.'" => "'messages.auth.password_changed'",
    "'Applications retrieved successfully.'" => "'messages.applications.list_success'",
    "'Application draft created successfully.'" => "'messages.applications.created'",
    "'Application retrieved successfully.'" => "'messages.applications.retrieved'",
    "'License types retrieved successfully.'" => "'messages.applications.license_types'",
    "'Service types retrieved successfully.'" => "'messages.applications.service_types'",
    "'Required documents retrieved successfully.'" => "'messages.documents.required_list'",
    "'Application documents retrieved successfully.'" => "'messages.documents.list'",
    "'Document uploaded successfully.'" => "'messages.documents.uploaded'",
    "'Application submitted for document review.'" => "'messages.documents.submitted'",
    "'Pending documents retrieved successfully.'" => "'messages.documents.pending_list'",
    "'Document approved successfully.'" => "'messages.documents.approved'",
    "'Document rejected. The citizen must re-upload and resubmit.'" => "'messages.documents.rejected'",
    "'Application fee retrieved successfully.'" => "'messages.payments.fee_retrieved'",
    "'Payments retrieved successfully.'" => "'messages.payments.list'",
    "'Payment status retrieved successfully.'" => "'messages.payments.status'",
    "'Stripe checkout session created successfully.'" => "'messages.payments.stripe_session'",
    "'Payment initiated. Confirm when funds are transferred (mock).'" => "'messages.payments.initiated_mock'",
    "'Payment confirmed successfully. You can book an appointment when slots are available.'" => "'messages.payments.confirmed'",
    "'Available tests retrieved successfully.'" => "'messages.appointments.available_tests'",
    "'Application appointments retrieved successfully.'" => "'messages.appointments.list'",
    "'Test appointment booked successfully.'" => "'messages.appointments.booked'",
    "'Available appointment slots retrieved successfully.'" => "'messages.appointments.slots'",
    "'Appointment rescheduled successfully.'" => "'messages.appointments.rescheduled'",
    "'Appointment cancelled successfully.'" => "'messages.appointments.cancelled'",
    "'Test results retrieved successfully.'" => "'messages.tests.list'",
    "'Test result recorded successfully.'" => "'messages.tests.recorded'",
    "'License issued successfully.'" => "'messages.licenses.issued'",
    "'Licenses retrieved successfully.'" => "'messages.licenses.list'",
    "'License retrieved successfully.'" => "'messages.licenses.retrieved'",
    "'License renewed successfully.'" => "'messages.licenses.renewed'",
    "'License replacement issued successfully.'" => "'messages.licenses.replacement'",
    "'Unblock request submitted successfully.'" => "'messages.licenses.unblock_submitted'",
    "'License blocked successfully.'" => "'messages.licenses.blocked'",
    "'License unblocked successfully.'" => "'messages.licenses.unblocked'",
    "'Fines retrieved successfully.'" => "'messages.fines.list'",
    "'Fine created successfully.'" => "'messages.fines.created'",
    "'Fine updated successfully.'" => "'messages.fines.updated'",
    "'Notifications retrieved successfully.'" => "'messages.notifications.list'",
    "'Notification marked as read.'" => "'messages.notifications.read'",
    "'Report overview retrieved successfully.'" => "'messages.reports.overview'",
    "'Audit logs retrieved successfully.'" => "'messages.audit.list'",
    "'Application status history retrieved successfully.'" => "'messages.audit.status_history'",
    "'AI agent response generated successfully.'" => "'messages.ai_agent.response'",
    "'AI agent sessions retrieved successfully.'" => "'messages.ai_agent.sessions_list'",
    "'AI agent session retrieved successfully.'" => "'messages.ai_agent.session_show'",
    "'AI agent action executed successfully.'" => "'messages.ai_agent.action_executed'",
    "'AI agent action cancelled successfully.'" => "'messages.ai_agent.action_cancelled'",
    "'Only citizens can access this resource.'" => "'messages.middleware.citizen_only'",
    "'You do not have permission to perform this action.'" => "'messages.middleware.permission_denied'",
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root.'/app', RecursiveDirectoryIterator::SKIP_DOTS)
);

$changed = 0;
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, 'Dev'.DIRECTORY_SEPARATOR.'DlmsTestingDashboardController.php')) {
        continue;
    }
    $content = file_get_contents($path);
    $original = $content;
    foreach ($replacements as $from => $to) {
        $content = str_replace($from, $to, $content);
    }
    if ($content !== $original) {
        file_put_contents($path, $content);
        $changed++;
        echo "Updated: {$path}\n";
    }
}

echo "Done. {$changed} files updated.\n";
