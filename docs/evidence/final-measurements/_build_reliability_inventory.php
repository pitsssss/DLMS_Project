<?php

declare(strict_types=1);

/**
 * One-shot builder for _reliability_concurrency_inventory.json (source of truth).
 * Not part of the public exporter surface; safe to keep for regeneration.
 */

$m = static function (
    string $file,
    string $method,
    array $metrics,
    string $domain,
    string $operation,
    string $stimulus,
    string $outcome,
    ?string $http,
    string $notes,
    ?string $idempotencyClass = null,
    ?string $staleClass = null,
    ?string $sideEffect = null,
    ?string $expectedMax = null,
    ?string $classification = null,
): array {
    $row = [
        'file' => $file,
        'method' => $method,
        'metrics' => array_values($metrics),
        'domain' => $domain,
        'operation' => $operation,
        'stimulus' => $stimulus,
        'outcome' => $outcome,
        'http_status' => $http,
        'notes' => $notes,
    ];
    if ($classification !== null) {
        $row['classification'] = $classification;
    }
    if ($idempotencyClass !== null) {
        $row['idempotency_class'] = $idempotencyClass;
    }
    if ($staleClass !== null) {
        $row['stale_class'] = $staleClass;
    }
    if ($sideEffect !== null) {
        $row['side_effect'] = $sideEffect;
    }
    if ($expectedMax !== null) {
        $row['expected_max'] = $expectedMax;
    }

    return $row;
};

$methods = [
    // --- Payments ---
    $m('tests/Feature/PaymentConcurrencyAndIntegrityTest.php', 'test_money_to_minor_units_exact_without_float', ['PAY-MONEY-PRECISION-METHODS'], 'payments', 'Money::toMinorUnits/equals', 'decimal strings USD/SYP/JPY', 'exact minor units; equals ignores trailing zeros', null, 'Exact minor-unit math without float drift.', null, null, 'none', null, 'money_precision'),
    $m('tests/Feature/PaymentConcurrencyAndIntegrityTest.php', 'test_money_rejects_excess_precision', ['PAY-MONEY-PRECISION-METHODS'], 'payments', 'Money::toMinorUnits', 'USD with 3 fractional digits', 'InvalidArgumentException', null, 'Rejects excess fractional precision.', null, null, 'none', null, 'money_precision'),
    $m('tests/Feature/PaymentConcurrencyAndIntegrityTest.php', 'test_duplicate_initiation_reuses_active_attempt', ['PAY-IDEMPOTENCY-METHODS', 'REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS', 'PAY-CONCURRENCY-INTEGRITY-METHODS'], 'payments', 'POST /api/applications/{id}/payments', 'second create while pending exists', 'same payment id; one payment row', '200', 'Duplicate initiation reuses active attempt.', 'http', null, 'payment_row', '1', 'idempotent_reuse'),
    $m('tests/Feature/PaymentConcurrencyAndIntegrityTest.php', 'test_provider_reference_unique_constraint', ['PAY-UNIQUENESS-METHODS'], 'payments', 'payments.provider_reference unique', 'second row same provider_reference', 'QueryException', null, 'DB uniqueness on provider_reference.', null, null, 'insert_rejected', '1', 'db_invariant'),
    $m('tests/Feature/PaymentConcurrencyAndIntegrityTest.php', 'test_settled_obligation_key_unique', ['PAY-UNIQUENESS-METHODS', 'PAY-IDEMPOTENCY-METHODS'], 'payments', 'payments.settled_obligation_key unique', 'second completed same settled_obligation_key', 'QueryException', null, 'Prevents double-settlement of same obligation.', null, null, 'insert_rejected', '1', 'db_invariant'),

    $m('tests/Feature/PaymentFlowTest.php', 'test_create_payment_is_idempotent_when_pending_exists', ['PAY-IDEMPOTENCY-METHODS', 'REL-IDEMPOTENCY-METHODS'], 'payments', 'POST /api/applications/{id}/payments', 'repeated create with pending', 'same payment id', '200', 'Pending create idempotent by id reuse.', 'http', null, 'payment_row', '1', 'idempotent_reuse'),
    $m('tests/Feature/PaymentFlowTest.php', 'test_cannot_confirm_payment_twice', ['PAY-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS', 'REL-STALE-STATE-REJECTION-METHODS'], 'payments', 'POST .../payments/{id}/confirm', 'second confirm after completed', '422', '422', 'Double confirm rejected.', 'http', 'revalidation_before_commit', 'confirm', '1', 'duplicate_reject'),

    $m('tests/Feature/PaymentStripeTest.php', 'test_duplicate_webhook_is_idempotent', ['PAY-WEBHOOK-IDEMPOTENCY-METHODS', 'PAY-IDEMPOTENCY-METHODS', 'REL-IDEMPOTENCY-METHODS'], 'payments', 'POST /api/webhooks/stripe completed', 'identical webhook twice', 'both OK; one completed payment', '200', 'Duplicate Stripe webhook does not re-settle.', 'integration', null, 'payment_status', '1', 'webhook_idempotency'),
    $m('tests/Feature/PaymentStripeTest.php', 'test_cannot_create_payment_when_completed_row_exists_for_fee', ['PAY-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS', 'PAY-UNIQUENESS-METHODS'], 'payments', 'POST /api/applications/{id}/payments', 'create after completed fee payment', '422 already_completed', '422', 'Blocks new attempt when fee settled.', 'http', 'revalidation_before_commit', 'payment_create', '0', 'duplicate_reject'),
    $m('tests/Feature/PaymentStripeTest.php', 'test_cannot_create_second_stripe_payment_after_successful_flow', ['PAY-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'payments', 'POST /api/applications/{id}/payments', 'second create after status completed', '422', '422', 'Post-success create blocked.', 'http', 'revalidation_before_commit', 'payment_create', '0', 'duplicate_reject'),
    $m('tests/Feature/PaymentStripeTest.php', 'test_stripe_manual_confirm_is_disabled', ['PAY-CONCURRENCY-INTEGRITY-METHODS'], 'payments', 'POST .../payments/{id}/confirm under stripe', 'manual confirm when provider=stripe', '400 manual_confirm_disabled', '400', 'Stripe path disables manual confirm (payment integrity).', 'http', null, 'confirm_blocked', '0', 'provider_integrity'),

    $m('tests/Feature/ApplicationFeeUsdCatalogTest.php', 'test_usd_minor_unit_conversion_is_exact', ['PAY-MONEY-PRECISION-METHODS'], 'payments', 'Money::toMinorUnits USD', 'catalog/sample amounts', 'exact minor units', null, 'USD minor-unit conversion exact.', null, null, 'none', null, 'money_precision'),
    $m('tests/Feature/ApplicationFeeUsdCatalogTest.php', 'test_client_cannot_override_amount_or_currency', ['PAY-MONEY-PRECISION-METHODS'], 'payments', 'POST payments with client amount/currency', 'client sends amount=1.00 currency=EUR', 'server snapshots catalog USD amount', '200', 'Client cannot override fee amount/currency.', 'http', null, 'amount_snapshot', '1', 'money_precision'),
    $m('tests/Feature/ApplicationFeeUsdCatalogTest.php', 'test_currency_mismatch_moves_payment_to_under_verification', ['PAY-MONEY-PRECISION-METHODS'], 'payments', 'Stripe webhook currency mismatch', 'webhook currency eur vs USD payment', 'UnderVerification; app stays PaymentPending', '200', 'Currency mismatch financial integrity (underpayment verification).', 'integration', null, 'under_verification', '1', 'money_precision'),
    $m('tests/Feature/ApplicationFeeUsdCatalogTest.php', 'test_amount_mismatch_moves_payment_to_under_verification', ['PAY-MONEY-PRECISION-METHODS'], 'payments', 'Stripe webhook amount mismatch', 'webhook amount_total 999 vs expected', 'UnderVerification', '200', 'Amount mismatch financial integrity.', 'integration', null, 'under_verification', '1', 'money_precision'),

    $m('tests/Feature/DashboardPaymentManagementTest.php', 'test_verify_stripe_payment_completes_idempotently', ['PAY-IDEMPOTENCY-METHODS', 'REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'payments', 'POST /api/dashboard/payments/{id}/verify', 'verify twice after Stripe paid', 'both OK; payment.completed audit count unchanged', '200', 'Dashboard verify idempotent on audits.', 'http', null, 'payment.completed_audit', '1', 'idempotent_reuse'),

    // --- Notifications ---
    $m('tests/Feature/NotificationIdempotencyTest.php', 'test_same_event_key_does_not_duplicate', ['NOTIF-IDEMPOTENCY-METHODS', 'REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'notifications', 'NotificationService::notify', 'same event_key twice', 'one notification row', null, 'Event-key dedupe proven.', 'service', null, 'notification', '1', 'idempotent_dedupe'),
    $m('tests/Feature/NotificationIdempotencyTest.php', 'test_status_history_event_key_dedupes_reprocessing_same_history_row', ['NOTIF-IDEMPOTENCY-METHODS', 'REL-IDEMPOTENCY-METHODS'], 'notifications', 'notifyApplicationStatusChange reprocess', 're-notify same history id', 'one ApplicationPaymentPending notification', null, 'History-keyed reprocessing deduped.', 'service', null, 'notification', '1', 'idempotent_dedupe'),

    $m('tests/Feature/NotificationTransactionSafetyTest.php', 'test_rolled_back_business_transaction_creates_no_notification', ['REL-AFTERCOMMIT-SAFETY-METHODS', 'REL-ROLLBACK-METHODS', 'REL-ATOMICITY-METHODS', 'NOTIF-TX-SAFETY-METHODS'], 'notifications', 'notify inside rolled-back DB::transaction', 'RuntimeException after notify', 'zero notifications', null, 'Rolled-back domain TX leaves no notification (afterCommit/tx safety).', 'service', null, 'notification', '0', 'tx_safety'),
    $m('tests/Feature/NotificationTransactionSafetyTest.php', 'test_notification_persist_failure_does_not_roll_back_business_success', ['REL-AFTERCOMMIT-SAFETY-METHODS', 'REL-ATOMICITY-METHODS', 'NOTIF-TX-SAFETY-METHODS'], 'notifications', 'FineService::create with failing notify persist', 'notification persist throws', 'fine committed; zero notifications', null, 'Notification failure isolated from business commit.', 'service', null, 'fine_kept', '1', 'tx_safety'),

    $m('tests/Feature/NotificationProductionReadinessTest.php', 'test_already_active_citizen_activation_does_not_spam_notifications', ['NOTIF-IDEMPOTENCY-METHODS', 'REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'citizens', 'DashboardCitizenService::activate already active', 'activate twice on active citizen', 'zero AccountActivated notifications', null, 'No-op activation emits no spam.', 'service', null, 'notification', '0', 'idempotent_noop'),
    $m('tests/Feature/NotificationProductionReadinessTest.php', 'test_reactivation_after_deactivate_emits_exactly_one_activated_notification', ['NOTIF-IDEMPOTENCY-METHODS', 'REL-IDEMPOTENCY-METHODS'], 'citizens', 'deactivate then activate twice', 'second activate after reactivation', 'exactly one AccountActivated', null, 'Repeated activate after reactivation does not duplicate.', 'service', null, 'notification', '1', 'idempotent_dedupe'),

    $m('tests/Feature/NotificationEventCoverageTest.php', 'test_payment_failed_and_under_verification_notify_once_per_code', ['NOTIF-IDEMPOTENCY-METHODS', 'PAY-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'payments/notifications', 'markFailed/markUnderVerification twice', 'same failure codes applied twice', 'one PaymentFailed + one UnderVerification notification', null, 'Per-code lifecycle notify idempotent.', 'service', null, 'notification', '1', 'idempotent_dedupe'),

    $m('tests/Feature/AppointmentNotificationTest.php', 'test_cancel_notifies_once_and_repeat_does_not_duplicate', ['NOTIF-IDEMPOTENCY-METHODS', 'REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'appointments/notifications', 'AppointmentService::cancel twice', 'repeat cancel after success', 'AppointmentCancelled count stays 1', null, 'Cancel notify once; repeat cancel no duplicate notification.', 'service', 'workflow_guard', 'notification', '1', 'idempotent_dedupe'),

    // --- Push ---
    $m('tests/Feature/PushDeliveryPlanningTest.php', 'test_duplicate_business_event_does_not_create_new_push', ['NOTIF-IDEMPOTENCY-METHODS', 'PUSH-TERMINAL-NO-RESEND-METHODS', 'REL-IDEMPOTENCY-METHODS'], 'push', 'notify same event_key', 'duplicate business event', 'one PushDelivery; one job', null, 'Deduped notification prevents second push plan.', 'service', null, 'push_delivery', '1', 'idempotent_dedupe'),
    $m('tests/Feature/PushDeliveryPlanningTest.php', 'test_delivery_key_uniqueness_enforced', ['REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'push', 'push_deliveries.delivery_key unique', 'insert duplicate delivery_key', 'QueryException', null, 'DB uniqueness on delivery_key.', null, null, 'insert_rejected', '1', 'db_invariant'),
    $m('tests/Feature/PushDeliveryPlanningTest.php', 'test_pending_recovery_is_idempotent_for_already_sent', ['PUSH-TERMINAL-NO-RESEND-METHODS', 'PUSH-RECOVERY-METHODS', 'REL-IDEMPOTENCY-METHODS'], 'push', 'dispatchPending', 'dispatch when already Sent', 'dispatched=0', null, 'Recovery skips terminal Sent.', 'command', null, 'job_dispatch', '0', 'recovery'),

    $m('tests/Feature/PushDeliveryRetryTest.php', 'test_retry_delay_uses_backoff_and_honors_retry_after_minimum', ['PUSH-RETRY-METHODS'], 'push', 'retryDelaySeconds', 'attempt index + Retry-After floors', '60/120/300; floor 60; honor larger', null, 'Backoff and Retry-After minimum.', null, null, 'none', null, 'retry'),
    $m('tests/Feature/PushDeliveryRetryTest.php', 'test_retries_exhausted_marks_failed', ['PUSH-RETRY-METHODS', 'PUSH-TERMINAL-NO-RESEND-METHODS'], 'push', 'processDelivery near budget', '503 with attempts=2 tries=3', 'Failed; attempts=3; failed_at set', null, 'Exhausted retries become terminal Failed.', 'service', null, 'delivery_failed', '1', 'retry'),
    $m('tests/Feature/PushDeliveryRetryTest.php', 'test_dispatch_pending_command_dispatches_only_pending', ['PUSH-TERMINAL-NO-RESEND-METHODS', 'PUSH-RECOVERY-METHODS'], 'push', 'artisan push:dispatch-pending', 'pending + Sent', 'job only for pending', null, 'Recovery command skips Sent.', 'command', null, 'job_dispatch', '1', 'recovery'),

    $m('tests/Feature/PushProductionCertificationTest.php', 'test_fcm_attempts_count_only_real_sends', ['PUSH-RETRY-METHODS'], 'push', 'processDelivery deleted device', 'device missing', 'FCM never; attempts=0', null, 'Attempts only on real FCM sends.', 'service', null, 'attempts', '0', 'retry'),
    $m('tests/Feature/PushProductionCertificationTest.php', 'test_quota_429_minimum_delay_is_60_seconds', ['PUSH-RETRY-METHODS'], 'push', 'retryDelaySeconds', 'Retry-After 10 vs 90', 'floor 60; honor 90', null, 'Quota delay floor.', null, null, 'none', null, 'retry'),
    $m('tests/Feature/PushProductionCertificationTest.php', 'test_503_retry_after_honored', ['PUSH-RETRY-METHODS'], 'push', 'job handle 503', 'Retry-After 180', 'releasedFor=180; attempts=1', null, 'Retry-After drives release delay.', 'service', null, 'job_release', '1', 'retry'),
    $m('tests/Feature/PushProductionCertificationTest.php', 'test_malformed_retry_after_falls_back_to_backoff', ['PUSH-RETRY-METHODS'], 'push', 'parseRetryAfterSeconds', 'invalid Retry-After', 'null parse; delay 60', null, 'Malformed Retry-After falls back.', null, null, 'none', null, 'retry'),
    $m('tests/Feature/PushProductionCertificationTest.php', 'test_terminal_delivery_never_resent', ['PUSH-TERMINAL-NO-RESEND-METHODS', 'REL-IDEMPOTENCY-METHODS'], 'push', 'processDelivery on Sent', 'reprocess terminal Sent', 'skipped; FCM never; stays Sent', null, 'Terminal Sent never resent.', 'service', null, 'fcm_send', '0', 'terminal'),
    $m('tests/Feature/PushProductionCertificationTest.php', 'test_duplicate_job_safe_after_sent', ['PUSH-TERMINAL-NO-RESEND-METHODS', 'REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'push', 'SendPushNotificationJob twice', 'duplicate job after success', 'Sent; attempts=1', null, 'Second job after Sent is no-op.', 'service', null, 'fcm_send', '1', 'idempotent_reuse'),
    $m('tests/Feature/PushProductionCertificationTest.php', 'test_invalid_token_never_retries', ['PUSH-TERMINAL-NO-RESEND-METHODS', 'PUSH-RETRY-METHODS'], 'push', 'UNREGISTERED then reprocess', 'invalid token then process again', 'InvalidToken; FCM never on second', null, 'InvalidToken terminal non-retry.', 'service', null, 'fcm_send', '0', 'terminal'),
    $m('tests/Feature/PushProductionCertificationTest.php', 'test_stale_processing_recovered_recent_not_stolen', ['PUSH-RECOVERY-METHODS', 'REL-RECOVERY-METHODS', 'REL-STALE-STATE-REJECTION-METHODS'], 'push', 'recoverStaleProcessing', 'stale Processing 10m vs fresh 30s', 'reclaimed=1; fresh stays Processing', null, 'Lease recovery selective.', 'service', 'workflow_guard', 'status_reset', '1', 'recovery'),
    $m('tests/Feature/PushProductionCertificationTest.php', 'test_dispatch_pending_never_redispatches_terminal', ['PUSH-TERMINAL-NO-RESEND-METHODS', 'PUSH-RECOVERY-METHODS'], 'push', 'dispatchPending', 'pending+Sent+Failed+InvalidToken', 'dispatched=1 only pending', null, 'Terminal statuses never redispatched.', 'service', null, 'job_dispatch', '1', 'recovery'),
    $m('tests/Feature/PushProductionCertificationTest.php', 'test_failed_dispatch_leaves_pending_recoverable', ['PUSH-RECOVERY-METHODS', 'REL-RECOVERY-METHODS'], 'push', 'dispatchPending orphan pending', 'Pending with no prior job', 'dispatched=1', null, 'Orphan pending recoverable.', 'service', null, 'job_dispatch', '1', 'recovery'),
    $m('tests/Feature/PushProductionCertificationTest.php', 'test_worker_crash_after_claim_leaves_recoverable_processing', ['PUSH-RECOVERY-METHODS', 'REL-RECOVERY-METHODS'], 'push', 'recoverStaleProcessing after crash claim', 'Processing + stale last_attempt_at', 'reclaimed to Pending', null, 'Crash-after-claim recoverable.', 'service', null, 'status_reset', '1', 'recovery'),
    $m('tests/Feature/PushProductionCertificationTest.php', 'test_db_notification_isolation_when_push_planning_errors', ['NOTIF-TX-SAFETY-METHODS', 'REL-ATOMICITY-METHODS'], 'notifications/push', 'sendToUser no devices', 'push planning no-ops', 'notification persisted; zero deliveries', null, 'In-app notification isolated from empty push plan.', 'service', null, 'notification', '1', 'tx_safety'),

    $m('tests/Feature/SendPushNotificationJobTest.php', 'test_permanent_failure_does_not_delete_device_or_retry', ['PUSH-TERMINAL-NO-RESEND-METHODS', 'PUSH-RETRY-METHODS'], 'push', 'processDelivery INVALID_ARGUMENT', 'permanent FCM failure', 'Failed; device kept', null, 'Permanent failure terminal without device delete.', 'service', null, 'delivery_failed', '1', 'terminal'),
    $m('tests/Feature/SendPushNotificationJobTest.php', 'test_retryable_failure_releases_job_and_keeps_pending', ['PUSH-RETRY-METHODS'], 'push', 'job handle 503', 'Retry-After 120', 'releasedFor=120; Pending; attempts=1', null, 'Retryable failure keeps Pending.', 'service', null, 'job_release', '1', 'retry'),
    $m('tests/Feature/SendPushNotificationJobTest.php', 'test_deleted_device_before_processing_is_safe', ['PUSH-RECOVERY-METHODS', 'REL-STALE-STATE-REJECTION-METHODS'], 'push', 'job after device delete', 'device deleted before process', 'InvalidToken; attempts=0', null, 'Missing device handled without FCM.', 'service', 'workflow_guard', 'fcm_send', '0', 'recovery'),
    $m('tests/Feature/SendPushNotificationJobTest.php', 'test_token_rotation_during_unregistered_does_not_delete_new_token', ['PUSH-RECOVERY-METHODS', 'REL-IDEMPOTENCY-METHODS'], 'push', 'UNREGISTERED while rotate in-flight', 'rotate during FCM call', 'new token kept; delivery InvalidToken', null, 'Hash-guarded delete avoids killing rotated token.', 'service', null, 'device_row', '1', 'recovery'),
    $m('tests/Feature/SendPushNotificationJobTest.php', 'test_token_rotation_race_does_not_delete_new_token', ['PUSH-RECOVERY-METHODS'], 'push', 'deleteByIdAndTokenHash after rotation', 'delete with stale token_hash', 'deleted=0; device exists', null, 'Stale-hash delete no-op after rotation.', 'service', null, 'device_row', '1', 'recovery'),

    $m('tests/Feature/PushDeviceRegistrationTest.php', 'test_repeated_register_is_idempotent_and_refreshes_last_registered_at', ['REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'push-devices', 'POST /api/devices/push-token', 'identical register twice', 'one row; same id; last_registered_at advances', '200', 'Repeated register upserts one device.', 'http', null, 'device_row', '1', 'idempotent_reuse'),
    $m('tests/Feature/PushDeviceRegistrationTest.php', 'test_owner_can_unregister_one_device_idempotently', ['REL-IDEMPOTENCY-METHODS'], 'push-devices', 'DELETE /api/devices/push-token', 'unregister same device_id twice', 'both OK; other device untouched', '200', 'Second unregister idempotent success.', 'http', null, 'device_delete', '1', 'idempotent_noop'),
    $m('tests/Feature/PushDeviceRegistrationTest.php', 'test_same_token_cannot_exist_in_two_rows', ['REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'push-devices', 'PushDeviceService::register shared token', 'two device_ids same token', 'one row reconciled to second device', null, 'Token uniqueness reconciled to single row.', 'service', null, 'device_row', '1', 'idempotent_reconcile'),

    $m('tests/Feature/PushDeviceTokenRotationTest.php', 'test_same_device_new_token_updates_same_row', ['REL-IDEMPOTENCY-METHODS'], 'push-devices', 'register rotate token', 'new token same device_id', 'same row id; old hash gone', '200', 'Rotation updates in place.', 'http', null, 'device_row', '1', 'idempotent_reuse'),
    $m('tests/Feature/PushDeviceTokenRotationTest.php', 'test_duplicate_token_on_two_devices_reconciles_to_one_row', ['REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'push-devices', 'register token owned by other device', 'move shared token to device-new', 'count=1; device-new wins', null, 'Duplicate token collapses to one registration.', 'service', null, 'device_row', '1', 'idempotent_reconcile'),
    $m('tests/Feature/PushDeviceTokenRotationTest.php', 'test_token_uniqueness_constraint_is_enforced_at_database_level', ['REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'push-devices', 'push_devices.token_hash unique', 'second row same token_hash', 'QueryException', null, 'DB unique on token_hash.', null, null, 'insert_rejected', '1', 'db_invariant'),

    // --- Appointments concurrency ---
    $m('tests/Feature/AppointmentSlotConcurrencyTest.php', 'test_concurrent_booking_cannot_overbook_single_capacity_slot', ['CONC-APPOINTMENT-METHODS', 'REL-ATOMICITY-METHODS'], 'appointments', 'POST /api/applications/{id}/appointments', 'two citizens book capacity=1 (sequential HTTP loop)', 'success=1; failure=1; booked_count=1; overbook=0', null, 'capacity=1; success=1; failure=1; booked_count=1. Sequential requests not OS threads.', 'http', null, 'booking', '1', 'concurrency'),
    $m('tests/Feature/AppointmentSlotConcurrencyTest.php', 'test_cancel_releases_capacity_and_is_idempotent_on_status', ['CONC-APPOINTMENT-METHODS', 'REL-IDEMPOTENCY-METHODS', 'REL-STALE-STATE-REJECTION-METHODS'], 'appointments', 'DELETE /api/appointments/{id}/cancel', 'cancel then cancel again', 'booked_count 1->0; second 422', '422', 'Capacity released; second cancel rejected.', 'http', 'workflow_guard', 'cancel', '1', 'idempotent_reject'),
    $m('tests/Feature/AppointmentSlotConcurrencyTest.php', 'test_reschedule_releases_and_consumes_capacity_with_audit', ['CONC-APPOINTMENT-METHODS'], 'appointments', 'PUT /api/appointments/{id}/reschedule', 'move slotA->slotB', 'slotA=0; slotB=1; audit', '200', 'Reschedule transfers capacity with audit.', 'http', null, 'booked_count', '1', 'concurrency'),
    $m('tests/Feature/AppointmentSlotConcurrencyTest.php', 'test_reschedule_lock_order_preserves_booked_counts', ['CONC-APPOINTMENT-METHODS', 'REL-ATOMICITY-METHODS'], 'appointments', 'cross reschedule low<->high', 'A low->high then B high->low', 'each booked_count=1 within capacity', '200', 'Cross-reschedule preserves counts under lock order.', 'http', null, 'booked_count', '1', 'concurrency'),
    $m('tests/Feature/AppointmentSlotConcurrencyTest.php', 'test_concurrent_slot_update_cannot_reduce_capacity_below_booked_count', ['CONC-APPOINTMENT-METHODS', 'REL-STALE-STATE-REJECTION-METHODS'], 'appointments', 'PATCH appointment-slots capacity', 'capacity 5 booked 3 -> set 2', '422; capacity remains 5', '422', 'Unsafe capacity reduction rejected (not 409 optimistic).', 'http', 'revalidation_before_commit', 'capacity', '5', 'stale_reject'),

    $m('tests/Feature/DashboardAppointmentSlotsTest.php', 'test_update_requires_version_and_stale_returns_409', ['CONC-OPTIMISTIC-CONFLICT-METHODS', 'REL-STALE-STATE-REJECTION-METHODS'], 'appointment-slots', 'PATCH /api/dashboard/appointment-slots/{id}', 'stale version 999', '409 stale_version', '409', 'Optimistic AppointmentSlot.version conflict.', 'http', 'optimistic_version', 'slot_update', '0', 'optimistic_conflict'),
    $m('tests/Feature/DashboardAppointmentSlotsTest.php', 'test_capacity_cannot_drop_below_booked_count', ['CONC-APPOINTMENT-METHODS', 'REL-STALE-STATE-REJECTION-METHODS'], 'appointment-slots', 'PATCH capacity below booked', 'capacity 2 with booked 3', '422 unsafe_capacity_reduction', '422', 'Capacity integrity guard.', 'http', 'revalidation_before_commit', 'capacity', null, 'stale_reject'),
    $m('tests/Feature/DashboardAppointmentSlotsTest.php', 'test_create_rejects_duplicate_and_ignores_client_booked_count', ['REL-DUPLICATE-SIDE-EFFECT-METHODS', 'CONC-APPOINTMENT-METHODS'], 'appointment-slots', 'POST /api/dashboard/appointment-slots', 'duplicate identity + client booked_count=99', 'first created booked_count=0; second 422 duplicate_identity', '422', 'Duplicate slot identity rejected; client booked_count ignored.', 'http', null, 'slot_row', '1', 'duplicate_reject'),

    // --- Sessions ---
    $m('tests/Feature/EmployeeSessionRevocationTest.php', 'test_reason_required_and_duplicate_revoke_is_409', ['REL-SESSION-LIFECYCLE-METHODS', 'REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS', 'REL-STALE-STATE-REJECTION-METHODS'], 'employee-sessions', 'POST .../employee-sessions/{uuid}/revoke', 'second revoke after success', '409; audit count unchanged', '409', 'Duplicate revoke conflict without extra audit.', 'http', 'workflow_guard', 'revoke_audit', '1', 'session'),
    $m('tests/Feature/EmployeeSessionRevocationTest.php', 'test_revoke_all_preserves_current_by_default', ['REL-SESSION-LIFECYCLE-METHODS'], 'employee-sessions', 'POST .../sessions/revoke-all', 'revoke-all default', 'revoked=1 preserved_current=1; current token still OK', '200', 'Revoke-all preserves current session by default.', 'http', null, 'session_revoke', null, 'session'),
    $m('tests/Feature/EmployeeSessionRevocationTest.php', 'test_revoke_all_can_include_current_and_rejects_citizens', ['REL-SESSION-LIFECYCLE-METHODS'], 'employee-sessions', 'revoke-all include_current + citizen target', 'include current; then citizen id', 'current revoked; citizen 404', '200', 'Revoke-all can include current; citizens rejected.', 'http', null, 'session_revoke', null, 'session'),

    $m('tests/Feature/EmployeeSessionLastSeenTest.php', 'test_write_throttling_prevents_update_every_request', ['REL-SESSION-LIFECYCLE-METHODS', 'REL-IDEMPOTENCY-METHODS'], 'employee-sessions', 'last_seen write throttle', 'two /me within interval', 'last_seen_at unchanged on second', '200', 'Throttled last_seen prevents write churn.', 'http', null, 'last_seen_write', '1', 'session'),

    $m('tests/Feature/EmployeeSessionLifecycleTest.php', 'test_reconcile_and_prune_commands', ['REL-SESSION-LIFECYCLE-METHODS', 'REL-RECOVERY-METHODS'], 'employee-sessions', 'employee-sessions:reconcile/prune', 'open missing credential; old ended', 'CredentialMissing; prune removes aged', null, 'Reconcile recovers orphans; prune removes aged ended.', 'command', null, 'session_end', null, 'recovery'),
    $m('tests/Feature/EmployeeSessionLifecycleTest.php', 'test_repeated_logout_is_idempotent', ['REL-SESSION-LIFECYCLE-METHODS'], 'employee-sessions', 'logout then force revoked precedence', 'ended session force-filled revoked', 'status Revoked; not relabeled logout', '200', 'Does NOT prove double-logout HTTP; proves ended-reason precedence only.', 'service', 'workflow_guard', 'ended_reason', '1', 'session'),

    // --- Citizens / employees ---
    $m('tests/Feature/DashboardCitizenManagementTest.php', 'test_repeated_deactivation_is_idempotent', ['REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'citizens', 'POST .../citizens/{id}/deactivate', 'deactivate twice', 'exactly one citizen.deactivated audit', '200', 'Second deactivate does not duplicate audit.', 'http', null, 'audit', '1', 'idempotent_noop'),
    $m('tests/Feature/DashboardCitizenManagementTest.php', 'test_repeated_activation_is_idempotent', ['REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS', 'NOTIF-IDEMPOTENCY-METHODS'], 'citizens', 'POST .../citizens/{id}/activate', 'activate already-active twice', 'zero activated audits/notifications', '200', 'No-op activation has no side effects.', 'http', null, 'audit+notification', '0', 'idempotent_noop'),

    $m('tests/Feature/EmployeeManagementTest.php', 'test_repeated_deactivation_is_idempotent', ['REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'employees', 'PATCH .../toggle-active is_active=false', 'toggle false when already false', 'OK; stays inactive', '200', 'Repeated employee deactivation is no-op.', 'http', null, 'is_active', 'false', 'idempotent_noop'),
    $m('tests/Feature/EmployeeManagementTest.php', 'test_repeated_activation_is_idempotent', ['REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'employees', 'PATCH .../toggle-active is_active=true', 'toggle true when already true', 'OK; stays active', '200', 'Repeated employee activation is no-op.', 'http', null, 'is_active', 'true', 'idempotent_noop'),

    // --- Licenses ---
    $m('tests/Feature/DashboardLicenseIssuanceQueueTest.php', 'test_stale_condition_causes_issue_license_422_after_ready_get', ['LIC-STALE-REVALIDATION-METHODS', 'LIC-ISSUANCE-INTEGRITY-METHODS', 'REL-STALE-STATE-REJECTION-METHODS'], 'licenses', 'POST issue-license after ready GET', 'unpaid fine added after ready view', '422; leaves ready queue', '422', 'Issuance revalidates readiness.', 'http', 'revalidation_before_commit', 'license_issue', '0', 'stale_revalidation'),
    $m('tests/Feature/DashboardLicenseIssuanceQueueTest.php', 'test_application_with_unpaid_fine_is_excluded', ['LIC-ISSUANCE-INTEGRITY-METHODS'], 'licenses', 'GET license-issuance queue', 'issuable app + unpaid fine vs ready', 'total=1 only ready app', '200', 'Unpaid fine excludes from issuance queue.', 'http', null, 'queue_membership', '1', 'issuance_integrity'),
    $m('tests/Feature/DashboardLicenseIssuanceQueueTest.php', 'test_already_issued_application_is_excluded', ['LIC-ISSUANCE-INTEGRITY-METHODS', 'LIC-DUPLICATE-PREVENTION-METHODS'], 'licenses', 'GET license-issuance queue', 'already has license row', 'total=1 only other ready', '200', 'Already-issued excluded from queue.', 'http', null, 'queue_membership', '1', 'duplicate_prevention'),
    $m('tests/Feature/DashboardLicenseIssuanceQueueTest.php', 'test_existing_issue_license_still_succeeds_for_ready_application', ['LIC-ISSUANCE-INTEGRITY-METHODS'], 'licenses', 'POST issue-license', 'ready application issue', 'OK active license; queue empties', '200', 'Happy-path issue succeeds and removes from queue.', 'http', null, 'license_row', '1', 'issuance_integrity'),

    $m('tests/Feature/LicenseExpirySyncTest.php', 'test_effective_status_detects_expired_without_mutating_row', ['LIC-ISSUANCE-INTEGRITY-METHODS', 'REL-IDEMPOTENCY-METHODS'], 'licenses', 'LicenseEffectiveStatus::resolve', 'past expiry_date read', 'effective Expired; stored Active', null, 'Read path does not mutate status.', 'service', null, 'status_column', 'Active', 'idempotent_read'),
    $m('tests/Feature/LicenseExpirySyncTest.php', 'test_sync_command_updates_and_is_idempotent', ['LIC-ISSUANCE-INTEGRITY-METHODS', 'REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS', 'REL-RECOVERY-METHODS'], 'licenses', 'licenses:sync-expired', 'command twice', 'Expired; history count unchanged', null, 'Expiry sync idempotent on history.', 'command', null, 'expired_history', '1', 'recovery'),

    $m('tests/Feature/DashboardOverviewTest.php', 'test_license_unblock_is_excluded_from_ready_and_issue_license', ['LIC-ISSUANCE-INTEGRITY-METHODS', 'LIC-DUPLICATE-PREVENTION-METHODS'], 'licenses', 'issue-license license_unblock', 'disallowed service', '422; license count unchanged', '422', 'Failed issue creates no license.', 'http', 'revalidation_before_commit', 'license_row', '0', 'issuance_integrity'),
    $m('tests/Feature/DashboardOverviewTest.php', 'test_unknown_custom_service_code_is_not_issuable', ['LIC-ISSUANCE-INTEGRITY-METHODS'], 'licenses', 'issue-license unknown service', 'unsupported service type', '422; licenses/audits unchanged', '422', 'Failed issue no license/audit.', 'http', 'revalidation_before_commit', 'license_row', '0', 'issuance_integrity'),
    $m('tests/Feature/DashboardOverviewTest.php', 'test_duplicate_issue_license_is_prevented_after_success', ['LIC-DUPLICATE-PREVENTION-METHODS', 'LIC-ISSUANCE-INTEGRITY-METHODS', 'REL-IDEMPOTENCY-METHODS'], 'licenses', 'issue-license twice', 'second issue after success', '422; exactly one license', '422', 'Duplicate issuance prevented.', 'http', 'revalidation_before_commit', 'license_row', '1', 'duplicate_prevention'),

    $m('tests/Feature/OtherLicenseServicesFlowTest.php', 'test_duplicate_renew_application_is_blocked', ['LIC-DUPLICATE-PREVENTION-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'applications', 'POST /api/applications renew', 'second renew while active exists', '422 duplicate_active_application_license', '422', 'Duplicate renew blocked.', 'http', null, 'application_row', '1', 'duplicate_prevention'),

    $m('tests/Feature/DemoLicenseServiceSeedersTest.php', 'test_running_seeder_twice_does_not_duplicate_licenses', ['REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS', 'LIC-DUPLICATE-PREVENTION-METHODS'], 'licenses', 'demo license seeder twice', 'run seeder twice', 'each demo license_number count=1', null, 'Demo seeder does not duplicate licenses.', 'command', null, 'license_row', '1', 'idempotent_seed'),

    $m('tests/Feature/DashboardLicenseTypesTest.php', 'test_activate_deactivate_idempotent_and_audited', ['REL-IDEMPOTENCY-METHODS'], 'license-types', 'activate/deactivate twice', 'repeat deactivate/activate', 'state unchanged on repeat; audits exist', '200', 'HTTP/state idempotent activate/deactivate (audit uniqueness not asserted).', 'http', null, 'is_active', null, 'idempotent_noop'),

    // --- Fees / roles / seeders ---
    $m('tests/Feature/DashboardFeesManagementTest.php', 'test_stale_version_returns_409', ['CONC-OPTIMISTIC-CONFLICT-METHODS', 'REL-STALE-STATE-REJECTION-METHODS'], 'fees', 'PATCH /api/dashboard/fees/{id}', 'version 999', '409 stale_version', '409', 'Optimistic Fee.version conflict.', 'http', 'optimistic_version', 'fee_update', '0', 'optimistic_conflict'),
    $m('tests/Feature/DashboardFeesManagementTest.php', 'test_seeder_rerun_does_not_overwrite_admin_edited_amount', ['REL-IDEMPOTENCY-METHODS'], 'fees', 'FeesSeeder rerun', 'admin-edited amount then reseed', 'amount remains 77.77', null, 'Seeder idempotent w.r.t. admin edits.', 'command', null, 'fee_amount', '77.77', 'idempotent_seed'),
    $m('tests/Feature/DashboardFeesManagementTest.php', 'test_create_rejects_duplicate_identity', ['REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'fees', 'POST /api/dashboard/fees', 'duplicate fee identity', '422 duplicate_identity', '422', 'Fee identity_key duplicate rejected.', 'http', null, 'fee_row', '1', 'duplicate_reject'),

    $m('tests/Feature/DashboardRoleManagementTest.php', 'test_update_role_version_conflict_returns_409', ['CONC-OPTIMISTIC-CONFLICT-METHODS', 'REL-STALE-STATE-REJECTION-METHODS'], 'access-control/roles', 'PATCH /api/dashboard/access-control/roles/{id}', 'version 999', '409', '409', 'Optimistic Role.version conflict.', 'http', 'optimistic_version', 'role_update', '0', 'optimistic_conflict'),

    $m('tests/Feature/SuperAdminProtectionTest.php', 'test_bootstrap_is_idempotent_and_does_not_overwrite_role_permissions', ['REL-IDEMPOTENCY-METHODS'], 'rbac', 'rbac:bootstrap + PermissionsSeeder', 'bootstrap after intentional mutation', 'custom reduced permissions preserved', null, 'Bootstrap/seed do not overwrite role perms.', 'command', null, 'role_permissions', null, 'idempotent_seed'),

    $m('tests/Feature/CommitteeDemoSeederTest.php', 'test_committee_demo_seeder_is_idempotent', ['REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'demo-data', 'CommitteeDemoSeeder twice', 'seed twice', 'same APP_A id; 4 apps; waiting=3', null, 'Demo seeder rerun does not duplicate entities.', 'command', null, 'demo_rows', '4', 'idempotent_seed'),

    // --- Document review ---
    $m('tests/Feature/DashboardDocumentReviewTest.php', 'test_approve_sets_fields_audit_notification_and_blocks_stale_second_decision', ['REL-STALE-STATE-REJECTION-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS', 'REL-IDEMPOTENCY-METHODS'], 'document-review', 'approve then stale reject', 'second reviewer rejects approved doc', '422; stays Approved; no reject side effects', '422', 'Stale second decision blocked after approve.', 'http', 'workflow_guard', 'document_status', '1', 'stale_reject'),
    $m('tests/Feature/DashboardDocumentReviewTest.php', 'test_reject_validation_structured_storage_notification_and_stale_approve', ['REL-STALE-STATE-REJECTION-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'document-review', 'reject then stale approve', 'approve after reject', '422; rejected notification stays 1', '422', 'Stale approve after reject blocked.', 'http', 'workflow_guard', 'document_status', '1', 'stale_reject'),

    // --- AI ---
    $m('tests/Feature/AIAgentPendingWorkflowReliabilityTest.php', 'test_expired_workflow_chat_returns_expired_not_general_help', ['AI-STALE-GUARD-METHODS', 'AI-RELIABILITY-METHODS'], 'ai-agent', 'POST /api/ai-agent/message expired workflow', 'chat after pending_workflow expired', 'application_selection_expired; workflow cleared', '200', 'Expired workflow not general help.', 'http', 'expiring_token', 'pending_workflow', '0', 'ai_stale'),
    $m('tests/Feature/AIAgentPendingWorkflowReliabilityTest.php', 'test_expired_interaction_token_returns_pending_workflow_expired', ['AI-STALE-GUARD-METHODS'], 'ai-agent', 'select_application', 'token after expiry', '422 PENDING_WORKFLOW_EXPIRED', '422', 'Expired interaction token rejected.', 'http', 'expiring_token', 'selection', '0', 'ai_stale'),
    $m('tests/Feature/AIAgentPendingWorkflowReliabilityTest.php', 'test_show_choices_after_expiry_returns_expired_response', ['AI-STALE-GUARD-METHODS'], 'ai-agent', 'show_application_choices_again', 'after expiry', 'application_selection_expired', '200', 'Post-expiry show-choices expired.', 'http', 'expiring_token', 'pending_workflow', null, 'ai_stale'),
    $m('tests/Feature/AIAgentPendingWorkflowReliabilityTest.php', 'test_retryable_resume_failure_keeps_pending_workflow', ['AI-RELIABILITY-METHODS', 'REL-RECOVERY-METHODS'], 'ai-agent', 'select_application transient failure', 'first resume throws then retry OK', '422 RETRY_REQUIRED keeps workflow; retry clears', '422', 'Transient failure preserves workflow for retry.', 'http', 'workflow_guard', 'pending_workflow', '1', 'ai_recovery'),
    $m('tests/Feature/AIAgentPendingWorkflowReliabilityTest.php', 'test_book_appointment_selection_does_not_create_incomplete_pending_action', ['AI-RELIABILITY-METHODS', 'AI-CANCEL-NO-MUTATION-METHODS'], 'ai-agent', 'select_application in book flow', 'app selected before slot', 'no incomplete book_appointment pending action', '200', 'No incomplete confirmable action created.', 'http', null, 'ai_action', '0', 'ai_reliability'),
    $m('tests/Feature/AIAgentPendingWorkflowReliabilityTest.php', 'test_exact_ma_badi_cancels_pending_workflow', ['AI-CANCEL-NO-MUTATION-METHODS', 'AI-RELIABILITY-METHODS'], 'ai-agent', 'cancel phrase ما بدي', 'exact cancel awaiting selection', 'cancelled; pending_workflow removed', '200', 'Cancel clears pending workflow.', 'http', 'workflow_guard', 'pending_workflow', '0', 'ai_cancel'),
    $m('tests/Feature/AIAgentPendingWorkflowReliabilityTest.php', 'test_ma_badi_araaf_al_mokhalafat_switches_to_fines_not_cancel', ['AI-STALE-GUARD-METHODS', 'AI-RELIABILITY-METHODS'], 'ai-agent', 'topic switch then old token', 'old token after workflow cleared', 'old select 422', '422', 'Stale token invalid after topic switch.', 'http', 'workflow_guard', 'selection', '0', 'ai_stale'),

    $m('tests/Feature/AIAgentPhase1CriticalActionsTest.php', 'test_cancel_submit_documents_for_review_does_not_change_application_or_create_audit_notification_or_queue', ['AI-CANCEL-NO-MUTATION-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'ai-agent', 'POST /api/ai-agent/actions/{id}/cancel', 'cancel pending submit', 'Draft unchanged; audits/notifications/queue unchanged', '200', 'Cancel has no domain side effects.', 'http', null, 'application_status', 'Draft', 'ai_cancel'),
    $m('tests/Feature/AIAgentPhase1CriticalActionsTest.php', 'test_confirm_submit_documents_for_review_fails_when_application_state_changes_before_confirmation', ['AI-STALE-GUARD-METHODS', 'REL-STALE-STATE-REJECTION-METHODS'], 'ai-agent', 'confirm submit_documents_for_review', 'external status change before confirm', '422; action Failed', '422', 'Stale application state blocks confirm.', 'http', 'revalidation_before_commit', 'confirm', '0', 'ai_stale'),
    $m('tests/Feature/AIAgentPhase1CriticalActionsTest.php', 'test_submit_documents_for_review_fails_when_required_documents_become_rejected_before_confirmation', ['AI-STALE-GUARD-METHODS', 'REL-STALE-STATE-REJECTION-METHODS'], 'ai-agent', 'confirm submit_documents_for_review', 'docs rejected before confirm', 'confirm fails stale eligibility', '422', 'Stale document eligibility blocks confirm.', 'http', 'revalidation_before_commit', 'confirm', '0', 'ai_stale'),

    $m('tests/Feature/AIAgentAppointmentMultiSlotFlowTest.php', 'test_stale_slot_and_expired_and_foreign_token', ['AI-STALE-GUARD-METHODS', 'CONC-APPOINTMENT-METHODS'], 'ai-agent/appointments', 'select_appointment_slot', 'slot at capacity; expired workflow; foreign token', '422 NO_LONGER_AVAILABLE; 422 EXPIRED; foreign 404', '422', 'Stale capacity and expired workflow guarded.', 'http', 'expiring_token', 'slot_select', '0', 'ai_stale'),
    $m('tests/Feature/AIAgentAppointmentMultiSlotFlowTest.php', 'test_reschedule_end_to_end_and_stale_replacement_slot', ['AI-STALE-GUARD-METHODS', 'CONC-APPOINTMENT-METHODS', 'AI-RELIABILITY-METHODS'], 'ai-agent/appointments', 'reschedule then select filled slot', 'candidate slot filled before select', '422 APPOINTMENT_SLOT_NO_LONGER_AVAILABLE', '422', 'Stale replacement slot rejected.', 'http', 'workflow_guard', 'slot_select', '0', 'ai_stale'),
    $m('tests/Feature/AIAgentAppointmentMultiSlotFlowTest.php', 'test_topic_change_and_cancel_phrase_clear_slot_workflow', ['AI-CANCEL-NO-MUTATION-METHODS', 'AI-RELIABILITY-METHODS'], 'ai-agent', 'topic change / ما بدي during slot workflow', 'status question then cancel', 'pending_workflow cleared', '200', 'Topic change and cancel clear slot workflow.', 'http', 'workflow_guard', 'pending_workflow', '0', 'ai_cancel'),

    $m('tests/Feature/AIAgentActionExecutionTest.php', 'test_cancelled_action_cannot_be_confirmed', ['AI-STALE-GUARD-METHODS', 'REL-STALE-STATE-REJECTION-METHODS'], 'ai-agent', 'confirm cancelled action', 'confirm after Cancelled', '422', '422', 'Cancelled action cannot be confirmed.', 'http', 'workflow_guard', 'confirm', '0', 'ai_stale'),
    $m('tests/Feature/AIAgentActionExecutionTest.php', 'test_executed_action_cannot_be_confirmed_again', ['AI-RELIABILITY-METHODS', 'REL-IDEMPOTENCY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'ai-agent', 'confirm executed action again', 'second confirm', '422 already executed', '422', 'Re-confirm after execute rejected.', 'http', 'workflow_guard', 'confirm', '1', 'idempotent_reject'),
    $m('tests/Feature/AIAgentActionExecutionTest.php', 'test_confirm_create_application_fails_when_duplicate_active_application_exists', ['LIC-DUPLICATE-PREVENTION-METHODS', 'AI-RELIABILITY-METHODS', 'REL-DUPLICATE-SIDE-EFFECT-METHODS'], 'applications', 'confirm create_application', 'active duplicate same license/service', '422; Failed; still one application', '422', 'Duplicate active application blocked on confirm.', 'http', 'revalidation_before_commit', 'application_row', '1', 'duplicate_prevention'),
];

$inventory = [
    'meta' => [
        'system' => 'SYRTAK / DLMS Backend',
        'scope' => 'tests Feature reliability/idempotency/concurrency evidence',
        'suite_tests' => 1043,
        'suite_assertions' => 6557,
        'suite_duration_seconds' => 217.86,
        'date' => '2026-08-15',
        'counting_rule' => 'Distinct method counts per metric_id; methods may appear under multiple metrics; never sum overlapping metrics',
        'impl_verified' => [
            'lockForUpdate' => 56,
            'lockForUpdate_files' => 18,
            'DB_transaction' => 68,
            'DB_afterCommit' => 1,
        ],
    ],
    'methods' => $methods,
    'optimistic_entities' => [
        ['entity' => 'AppointmentSlot', 'field' => 'version', 'conflict_http' => 409],
        ['entity' => 'Role', 'field' => 'version', 'conflict_http' => 409],
        ['entity' => 'Fee', 'field' => 'version', 'conflict_http' => 409],
    ],
    'locked_domains' => [
        'identified' => [
            'licenses', 'payments', 'appointments', 'fees', 'citizens', 'access-control/roles',
            'employee-sessions', 'push-devices', 'push-deliveries', 'fines', 'test-results',
            'document-review', 'ai-agent-sessions',
        ],
        'with_behavioral_tests' => [
            'licenses', 'payments', 'appointments', 'fees', 'citizens', 'access-control/roles',
            'employee-sessions', 'push-devices', 'push-deliveries', 'document-review', 'ai-agent-sessions',
        ],
        'implemented_locks_without_concurrency_behavioral_tests' => ['fines', 'test-results'],
    ],
    'db_invariants' => [
        ['id' => 1, 'constraint' => 'payments.settled_obligation_key', 'tested' => true, 'evidence' => 'PaymentConcurrencyAndIntegrityTest::test_settled_obligation_key_unique'],
        ['id' => 2, 'constraint' => 'payments.active_obligation_key', 'tested' => false, 'evidence' => 'UNTESTED direct constraint'],
        ['id' => 3, 'constraint' => 'payments.(provider,provider_reference)', 'tested' => true, 'evidence' => 'PaymentConcurrencyAndIntegrityTest::test_provider_reference_unique_constraint'],
        ['id' => 4, 'constraint' => 'payment_gateway_events.(provider,event_id)', 'tested' => false, 'evidence' => 'Webhook idempotency is behavioral not QueryException'],
        ['id' => 5, 'constraint' => 'notifications.event_key', 'tested' => true, 'evidence' => 'NotificationIdempotencyTest::test_same_event_key_does_not_duplicate'],
        ['id' => 6, 'constraint' => 'push_deliveries.delivery_key', 'tested' => true, 'evidence' => 'PushDeliveryPlanningTest::test_delivery_key_uniqueness_enforced'],
        ['id' => 7, 'constraint' => 'push_devices.token_hash', 'tested' => true, 'evidence' => 'PushDeviceTokenRotationTest::test_token_uniqueness_constraint_is_enforced_at_database_level'],
        ['id' => 8, 'constraint' => 'push_devices.(user_id,device_id)', 'tested' => false, 'evidence' => 'UNTESTED direct'],
        ['id' => 9, 'constraint' => 'licenses.license_number', 'tested' => false, 'evidence' => 'UNTESTED direct'],
        ['id' => 10, 'constraint' => 'licenses.verification_token', 'tested' => false, 'evidence' => 'UNTESTED direct'],
        ['id' => 11, 'constraint' => 'fees.identity_key', 'tested' => true, 'evidence' => 'DashboardFeesManagementTest::test_create_rejects_duplicate_identity'],
        ['id' => 12, 'constraint' => 'appointment_slots.identity_key', 'tested' => true, 'evidence' => 'DashboardAppointmentSlotsTest::test_create_rejects_duplicate_and_ignores_client_booked_count'],
    ],
    'gaps' => [
        'No dedicated positive test that notifications emit only after DB::afterCommit in production mode (unit tests bypass afterCommit).',
        'PaymentReconciliationService + ReconcilePendingPaymentsCommand EXIST but NO dedicated Feature reconcile test — IMPLEMENTED BUT UNMEASURED.',
        'payments.active_obligation_key, payment_gateway_events unique, push_devices(user_id,device_id), licenses.license_number/verification_token UNTESTED as direct QueryException invariants.',
        'fines and test-results have lockForUpdate without dedicated concurrency behavioral tests in this inventory.',
        'AppointmentSlotConcurrencyTest concurrent booking uses sequential HTTP loop, not OS-level parallel threads.',
    ],
    'claims' => [
        ['claim' => 'Duplicate payment initiation reuses active attempt (idempotent)', 'status' => 'VERIFIED'],
        ['claim' => 'Stripe webhook completion is idempotent under duplicate delivery', 'status' => 'VERIFIED'],
        ['claim' => 'Appointment capacity=1 cannot overbook (success=1,failure=1,booked_count=1)', 'status' => 'VERIFIED', 'limitation' => 'Sequential HTTP loop'],
        ['claim' => 'Optimistic version conflicts return 409 for slots/fees/roles (3 entities)', 'status' => 'VERIFIED'],
        ['claim' => 'Notification event_key and push delivery_key prevent duplicate side effects', 'status' => 'VERIFIED'],
        ['claim' => 'Locked domains behavioral coverage 11/13', 'status' => 'VERIFIED'],
        ['claim' => 'Critical DB invariants tested 7/12', 'status' => 'VERIFIED'],
        ['claim' => 'Payment reconcile command fully measured by Feature tests', 'status' => 'DO NOT CLAIM', 'note' => 'IMPLEMENTED BUT UNMEASURED'],
        ['claim' => 'afterCommit production path has positive emit-after-commit Feature proof', 'status' => 'DO NOT CLAIM', 'note' => 'Gap: only rollback/isolation safety tested'],
        ['claim' => 'OS-thread true parallel booking stress tested', 'status' => 'DO NOT CLAIM'],
    ],
];

$path = __DIR__.'/_reliability_concurrency_inventory.json';
file_put_contents($path, json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
echo 'methods='.count($methods)."\n";
echo 'wrote='.$path."\n";
