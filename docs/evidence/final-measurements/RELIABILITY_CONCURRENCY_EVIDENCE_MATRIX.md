# Reliability / Concurrency Evidence Matrix (Quantitative)

**System:** SYRTAK / DLMS Backend
**Scope:** tests Feature reliability/idempotency/concurrency evidence
**Audit type:** Read-only quantitative inventory (method bodies reviewed; names insufficient)
**Date:** 2026-08-15
**Source of truth:** _reliability_concurrency_inventory.json

### Suite context (provided by project; not re-run in this inventory)

| Item | Value |
|------|-------|
| Latest full suite | **1043 passed** |
| Assertions | **6557** |
| Duration | **217.86s** |
| Curated unique methods | **107** |

### Counting discipline

| Rule | Application |
|------|-------------|
| Distinct per metric | Each metric_id counts **distinct methods** tagged with it |
| Cross-metric overlap | Allowed (one method may appear under many metrics) |
| **Never sum metrics** | Overlapping tags must not be added into a fake total |
| Conservative inclusion | Body must prove the property; borderline cases excluded |
| Machine-readable companion | reliability_concurrency_evidence.csv |

---

## 1. Architecture mechanisms of reliability

Do **not** call the whole system fault tolerant. Mechanisms below are inventory + evidence status.

| Mechanism | Where implemented | Failure mode addressed | Automated behavioral evidence |
|-----------|-------------------|------------------------|-------------------------------|
| DB::transaction | 68 call sites across domain services (payments, appointments, licenses, access-control, notifications callers, etc.) | Partial multi-row mutation / inconsistent domain state | PARTIALLY — REL-ROLLBACK=1, REL-ATOMICITY=5; call-site count is implementation only |
| lockForUpdate | 56 call sites / 18 files (see CONC-LOCK-CALLS) | Lost updates / overbooking / double-settlement races under concurrent writers | PARTIALLY — 11/13 locked domains have behavioral tests; not every lock site tested |
| Database unique constraints | payments obligation/provider keys; payment_gateway_events; notifications.event_key; push delivery_key/token_hash; license_number/verification_token; fee/slot identity_key | Duplicate business identities / double settlement / duplicate notifications/push | PARTIALLY — 7/12 critical invariants have direct automated evidence |
| Stripe Checkout idempotency_key | StripePaymentGatewayService (dlms-payment-{payment_number}) | Duplicate Stripe Checkout Session creation on retry | IMPLEMENTED BUT UNMEASURED as isolated Stripe API idempotency proof (related webhook/initiation tests exist) |
| event_key / delivery_key | NotificationService event_key unique; PushDelivery delivery_key unique | Duplicate notifications / duplicate push plans for same business event | VERIFIED for covered notify/push planning flows |
| Stripe gateway event uniqueness | payment_gateway_events unique(provider,event_id) | Replayed webhook double-applies provider event | PARTIALLY — duplicate webhook Feature test proves safe outcome; direct QueryException on constraint untested |
| State/status guards | Payments confirm, appointments cancel/reschedule, document review, license issue, AI action confirm | Illegal transitions / double transitions | VERIFIED for covered scenarios (REL-STALE / duplicate prevention inventories) |
| Optimistic version checks | AppointmentSlot.version, Role.version, Fee.version | TOCTOU overwrite of admin edits | VERIFIED — 3 entities / 3 HTTP 409 stale tests |
| Scheduled reconciliation | ReconcilePendingPaymentsCommand, employee-sessions:reconcile/prune, licenses:sync-expired, push:dispatch-pending / recoverStaleProcessing | Stuck pending payments/sessions/push leases; expired license status drift | PARTIALLY — push/session/license sync tested; payment reconcile IMPLEMENTED BUT UNMEASURED |
| Queued retry/backoff | SendPushNotificationJob + PushDeliveryService retryDelaySeconds / Retry-After | Transient FCM failures lost or thundering-herd retries | VERIFIED for covered push retry/terminal paths |
| afterCommit side effects | NotificationService::runAfterCommit (DB::afterCommit); bypassed when runningUnitTests() | Notification emitted for rolled-back business work; or notification failure rolling back business | PARTIALLY — rollback/isolation safety tested (2 methods); positive production afterCommit emit untested |
| AI pending-action expiry / stale guards | AIAgent pending_workflow expiry; interaction tokens; confirm-time state revalidation | Stale confirmation executes outdated intent | VERIFIED for covered AI stale/cancel/retry scenarios |

### Implementation counts (NOT outcome metrics)

| Metric | Exact count | Notes |
|--------|-------------|-------|
| **CONC-LOCK-CALLS** (`lockForUpdate`) | **56** across **18** files | IMPLEMENTATION METRIC — does not prove tested races |
| DB::transaction( | **68** | IMPLEMENTATION METRIC — not reliability proof by itself |
| DB::afterCommit( | **1** | NotificationService::runAfterCommit only |
| CONC-OPTIMISTIC-ENTITIES | **3** | AppointmentSlot, Role, Fee |
| Locked domains with behavioral tests | **11/13** | fines + test-results lack concurrency behavioral tests |
| REL-DB-INVARIANTS tested | **7/12** | Conservative critical unique set |

### lockForUpdate files (CONC-LOCK-CALLS detail)

| File | Occurrences |
|------|-------------|
| app/Console/Commands/BackfillLicenseVerificationTokensCommand.php | 1 |
| app/Modules/AIAgent/Services/AgentDocumentFlowService.php | 3 |
| app/Modules/Admin/Services/DocumentReviewService.php | 2 |
| app/Modules/Appointments/Repositories/AppointmentSlotRepository.php | 1 |
| app/Modules/Appointments/Services/AppointmentService.php | 6 |
| app/Modules/Dashboard/Services/DashboardAccessControlService.php | 6 |
| app/Modules/Dashboard/Services/DashboardAppointmentSlotService.php | 3 |
| app/Modules/Dashboard/Services/DashboardCitizenService.php | 3 |
| app/Modules/Dashboard/Services/DashboardFeeService.php | 3 |
| app/Modules/Dashboard/Services/EmployeeSessions/EmployeeSessionService.php | 3 |
| app/Modules/Devices/Repositories/PushDeviceRepository.php | 3 |
| app/Modules/Fines/Services/FineService.php | 1 |
| app/Modules/Licenses/Services/LicenseLifecycleService.php | 1 |
| app/Modules/Licenses/Services/LicenseService.php | 8 |
| app/Modules/Payments/Services/ApplicationPaymentService.php | 4 |
| app/Modules/Payments/Services/PaymentLifecycleService.php | 5 |
| app/Modules/Push/Repositories/PushDeliveryRepository.php | 1 |
| app/Modules/Tests/Services/TestResultService.php | 2 |

---

## 2. Idempotency methods — REL-IDEMPOTENCY-METHODS

**EXACT distinct methods: 36**

| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/PaymentConcurrencyAndIntegrityTest.php` | `test_duplicate_initiation_reuses_active_attempt` | payments | second create while pending exists | same payment id; one payment row | 200 | Duplicate initiation reuses active attempt. |
| 2 | `tests/Feature/PaymentFlowTest.php` | `test_create_payment_is_idempotent_when_pending_exists` | payments | repeated create with pending | same payment id | 200 | Pending create idempotent by id reuse. |
| 3 | `tests/Feature/PaymentStripeTest.php` | `test_duplicate_webhook_is_idempotent` | payments | identical webhook twice | both OK; one completed payment | 200 | Duplicate Stripe webhook does not re-settle. |
| 4 | `tests/Feature/DashboardPaymentManagementTest.php` | `test_verify_stripe_payment_completes_idempotently` | payments | verify twice after Stripe paid | both OK; payment.completed audit count unchanged | 200 | Dashboard verify idempotent on audits. |
| 5 | `tests/Feature/NotificationIdempotencyTest.php` | `test_same_event_key_does_not_duplicate` | notifications | same event_key twice | one notification row | — | Event-key dedupe proven. |
| 6 | `tests/Feature/NotificationIdempotencyTest.php` | `test_status_history_event_key_dedupes_reprocessing_same_history_row` | notifications | re-notify same history id | one ApplicationPaymentPending notification | — | History-keyed reprocessing deduped. |
| 7 | `tests/Feature/NotificationProductionReadinessTest.php` | `test_already_active_citizen_activation_does_not_spam_notifications` | citizens | activate twice on active citizen | zero AccountActivated notifications | — | No-op activation emits no spam. |
| 8 | `tests/Feature/NotificationProductionReadinessTest.php` | `test_reactivation_after_deactivate_emits_exactly_one_activated_notification` | citizens | second activate after reactivation | exactly one AccountActivated | — | Repeated activate after reactivation does not duplicate. |
| 9 | `tests/Feature/AppointmentNotificationTest.php` | `test_cancel_notifies_once_and_repeat_does_not_duplicate` | appointments/notifications | repeat cancel after success | AppointmentCancelled count stays 1 | — | Cancel notify once; repeat cancel no duplicate notification. |
| 10 | `tests/Feature/PushDeliveryPlanningTest.php` | `test_duplicate_business_event_does_not_create_new_push` | push | duplicate business event | one PushDelivery; one job | — | Deduped notification prevents second push plan. |
| 11 | `tests/Feature/PushDeliveryPlanningTest.php` | `test_pending_recovery_is_idempotent_for_already_sent` | push | dispatch when already Sent | dispatched=0 | — | Recovery skips terminal Sent. |
| 12 | `tests/Feature/PushProductionCertificationTest.php` | `test_terminal_delivery_never_resent` | push | reprocess terminal Sent | skipped; FCM never; stays Sent | — | Terminal Sent never resent. |
| 13 | `tests/Feature/PushProductionCertificationTest.php` | `test_duplicate_job_safe_after_sent` | push | duplicate job after success | Sent; attempts=1 | — | Second job after Sent is no-op. |
| 14 | `tests/Feature/SendPushNotificationJobTest.php` | `test_token_rotation_during_unregistered_does_not_delete_new_token` | push | rotate during FCM call | new token kept; delivery InvalidToken | — | Hash-guarded delete avoids killing rotated token. |
| 15 | `tests/Feature/PushDeviceRegistrationTest.php` | `test_repeated_register_is_idempotent_and_refreshes_last_registered_at` | push-devices | identical register twice | one row; same id; last_registered_at advances | 200 | Repeated register upserts one device. |
| 16 | `tests/Feature/PushDeviceRegistrationTest.php` | `test_owner_can_unregister_one_device_idempotently` | push-devices | unregister same device_id twice | both OK; other device untouched | 200 | Second unregister idempotent success. |
| 17 | `tests/Feature/PushDeviceRegistrationTest.php` | `test_same_token_cannot_exist_in_two_rows` | push-devices | two device_ids same token | one row reconciled to second device | — | Token uniqueness reconciled to single row. |
| 18 | `tests/Feature/PushDeviceTokenRotationTest.php` | `test_same_device_new_token_updates_same_row` | push-devices | new token same device_id | same row id; old hash gone | 200 | Rotation updates in place. |
| 19 | `tests/Feature/PushDeviceTokenRotationTest.php` | `test_duplicate_token_on_two_devices_reconciles_to_one_row` | push-devices | move shared token to device-new | count=1; device-new wins | — | Duplicate token collapses to one registration. |
| 20 | `tests/Feature/AppointmentSlotConcurrencyTest.php` | `test_cancel_releases_capacity_and_is_idempotent_on_status` | appointments | cancel then cancel again | booked_count 1->0; second 422 | 422 | Capacity released; second cancel rejected. |
| 21 | `tests/Feature/EmployeeSessionRevocationTest.php` | `test_reason_required_and_duplicate_revoke_is_409` | employee-sessions | second revoke after success | 409; audit count unchanged | 409 | Duplicate revoke conflict without extra audit. |
| 22 | `tests/Feature/EmployeeSessionLastSeenTest.php` | `test_write_throttling_prevents_update_every_request` | employee-sessions | two /me within interval | last_seen_at unchanged on second | 200 | Throttled last_seen prevents write churn. |
| 23 | `tests/Feature/DashboardCitizenManagementTest.php` | `test_repeated_deactivation_is_idempotent` | citizens | deactivate twice | exactly one citizen.deactivated audit | 200 | Second deactivate does not duplicate audit. |
| 24 | `tests/Feature/DashboardCitizenManagementTest.php` | `test_repeated_activation_is_idempotent` | citizens | activate already-active twice | zero activated audits/notifications | 200 | No-op activation has no side effects. |
| 25 | `tests/Feature/EmployeeManagementTest.php` | `test_repeated_deactivation_is_idempotent` | employees | toggle false when already false | OK; stays inactive | 200 | Repeated employee deactivation is no-op. |
| 26 | `tests/Feature/EmployeeManagementTest.php` | `test_repeated_activation_is_idempotent` | employees | toggle true when already true | OK; stays active | 200 | Repeated employee activation is no-op. |
| 27 | `tests/Feature/LicenseExpirySyncTest.php` | `test_effective_status_detects_expired_without_mutating_row` | licenses | past expiry_date read | effective Expired; stored Active | — | Read path does not mutate status. |
| 28 | `tests/Feature/LicenseExpirySyncTest.php` | `test_sync_command_updates_and_is_idempotent` | licenses | command twice | Expired; history count unchanged | — | Expiry sync idempotent on history. |
| 29 | `tests/Feature/DashboardOverviewTest.php` | `test_duplicate_issue_license_is_prevented_after_success` | licenses | second issue after success | 422; exactly one license | 422 | Duplicate issuance prevented. |
| 30 | `tests/Feature/DemoLicenseServiceSeedersTest.php` | `test_running_seeder_twice_does_not_duplicate_licenses` | licenses | run seeder twice | each demo license_number count=1 | — | Demo seeder does not duplicate licenses. |
| 31 | `tests/Feature/DashboardLicenseTypesTest.php` | `test_activate_deactivate_idempotent_and_audited` | license-types | repeat deactivate/activate | state unchanged on repeat; audits exist | 200 | HTTP/state idempotent activate/deactivate (audit uniqueness not asserted). |
| 32 | `tests/Feature/DashboardFeesManagementTest.php` | `test_seeder_rerun_does_not_overwrite_admin_edited_amount` | fees | admin-edited amount then reseed | amount remains 77.77 | — | Seeder idempotent w.r.t. admin edits. |
| 33 | `tests/Feature/SuperAdminProtectionTest.php` | `test_bootstrap_is_idempotent_and_does_not_overwrite_role_permissions` | rbac | bootstrap after intentional mutation | custom reduced permissions preserved | — | Bootstrap/seed do not overwrite role perms. |
| 34 | `tests/Feature/CommitteeDemoSeederTest.php` | `test_committee_demo_seeder_is_idempotent` | demo-data | seed twice | same APP_A id; 4 apps; waiting=3 | — | Demo seeder rerun does not duplicate entities. |
| 35 | `tests/Feature/DashboardDocumentReviewTest.php` | `test_approve_sets_fields_audit_notification_and_blocks_stale_second_decision` | document-review | second reviewer rejects approved doc | 422; stays Approved; no reject side effects | 422 | Stale second decision blocked after approve. |
| 36 | `tests/Feature/AIAgentActionExecutionTest.php` | `test_executed_action_cannot_be_confirmed_again` | ai-agent | second confirm | 422 already executed | 422 | Re-confirm after execute rejected. |

---

## 3. Duplicate side-effect prevention — REL-DUPLICATE-SIDE-EFFECT-METHODS

**EXACT distinct methods: 31**

| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/PaymentConcurrencyAndIntegrityTest.php` | `test_duplicate_initiation_reuses_active_attempt` | payments | second create while pending exists | same payment id; one payment row | 200 | Duplicate initiation reuses active attempt. |
| 2 | `tests/Feature/PaymentFlowTest.php` | `test_cannot_confirm_payment_twice` | payments | second confirm after completed | 422 | 422 | Double confirm rejected. |
| 3 | `tests/Feature/PaymentStripeTest.php` | `test_cannot_create_payment_when_completed_row_exists_for_fee` | payments | create after completed fee payment | 422 already_completed | 422 | Blocks new attempt when fee settled. |
| 4 | `tests/Feature/PaymentStripeTest.php` | `test_cannot_create_second_stripe_payment_after_successful_flow` | payments | second create after status completed | 422 | 422 | Post-success create blocked. |
| 5 | `tests/Feature/DashboardPaymentManagementTest.php` | `test_verify_stripe_payment_completes_idempotently` | payments | verify twice after Stripe paid | both OK; payment.completed audit count unchanged | 200 | Dashboard verify idempotent on audits. |
| 6 | `tests/Feature/NotificationIdempotencyTest.php` | `test_same_event_key_does_not_duplicate` | notifications | same event_key twice | one notification row | — | Event-key dedupe proven. |
| 7 | `tests/Feature/NotificationProductionReadinessTest.php` | `test_already_active_citizen_activation_does_not_spam_notifications` | citizens | activate twice on active citizen | zero AccountActivated notifications | — | No-op activation emits no spam. |
| 8 | `tests/Feature/NotificationEventCoverageTest.php` | `test_payment_failed_and_under_verification_notify_once_per_code` | payments/notifications | same failure codes applied twice | one PaymentFailed + one UnderVerification notification | — | Per-code lifecycle notify idempotent. |
| 9 | `tests/Feature/AppointmentNotificationTest.php` | `test_cancel_notifies_once_and_repeat_does_not_duplicate` | appointments/notifications | repeat cancel after success | AppointmentCancelled count stays 1 | — | Cancel notify once; repeat cancel no duplicate notification. |
| 10 | `tests/Feature/PushDeliveryPlanningTest.php` | `test_delivery_key_uniqueness_enforced` | push | insert duplicate delivery_key | QueryException | — | DB uniqueness on delivery_key. |
| 11 | `tests/Feature/PushProductionCertificationTest.php` | `test_duplicate_job_safe_after_sent` | push | duplicate job after success | Sent; attempts=1 | — | Second job after Sent is no-op. |
| 12 | `tests/Feature/PushDeviceRegistrationTest.php` | `test_repeated_register_is_idempotent_and_refreshes_last_registered_at` | push-devices | identical register twice | one row; same id; last_registered_at advances | 200 | Repeated register upserts one device. |
| 13 | `tests/Feature/PushDeviceRegistrationTest.php` | `test_same_token_cannot_exist_in_two_rows` | push-devices | two device_ids same token | one row reconciled to second device | — | Token uniqueness reconciled to single row. |
| 14 | `tests/Feature/PushDeviceTokenRotationTest.php` | `test_duplicate_token_on_two_devices_reconciles_to_one_row` | push-devices | move shared token to device-new | count=1; device-new wins | — | Duplicate token collapses to one registration. |
| 15 | `tests/Feature/PushDeviceTokenRotationTest.php` | `test_token_uniqueness_constraint_is_enforced_at_database_level` | push-devices | second row same token_hash | QueryException | — | DB unique on token_hash. |
| 16 | `tests/Feature/DashboardAppointmentSlotsTest.php` | `test_create_rejects_duplicate_and_ignores_client_booked_count` | appointment-slots | duplicate identity + client booked_count=99 | first created booked_count=0; second 422 duplicate_identity | 422 | Duplicate slot identity rejected; client booked_count ignored. |
| 17 | `tests/Feature/EmployeeSessionRevocationTest.php` | `test_reason_required_and_duplicate_revoke_is_409` | employee-sessions | second revoke after success | 409; audit count unchanged | 409 | Duplicate revoke conflict without extra audit. |
| 18 | `tests/Feature/DashboardCitizenManagementTest.php` | `test_repeated_deactivation_is_idempotent` | citizens | deactivate twice | exactly one citizen.deactivated audit | 200 | Second deactivate does not duplicate audit. |
| 19 | `tests/Feature/DashboardCitizenManagementTest.php` | `test_repeated_activation_is_idempotent` | citizens | activate already-active twice | zero activated audits/notifications | 200 | No-op activation has no side effects. |
| 20 | `tests/Feature/EmployeeManagementTest.php` | `test_repeated_deactivation_is_idempotent` | employees | toggle false when already false | OK; stays inactive | 200 | Repeated employee deactivation is no-op. |
| 21 | `tests/Feature/EmployeeManagementTest.php` | `test_repeated_activation_is_idempotent` | employees | toggle true when already true | OK; stays active | 200 | Repeated employee activation is no-op. |
| 22 | `tests/Feature/LicenseExpirySyncTest.php` | `test_sync_command_updates_and_is_idempotent` | licenses | command twice | Expired; history count unchanged | — | Expiry sync idempotent on history. |
| 23 | `tests/Feature/OtherLicenseServicesFlowTest.php` | `test_duplicate_renew_application_is_blocked` | applications | second renew while active exists | 422 duplicate_active_application_license | 422 | Duplicate renew blocked. |
| 24 | `tests/Feature/DemoLicenseServiceSeedersTest.php` | `test_running_seeder_twice_does_not_duplicate_licenses` | licenses | run seeder twice | each demo license_number count=1 | — | Demo seeder does not duplicate licenses. |
| 25 | `tests/Feature/DashboardFeesManagementTest.php` | `test_create_rejects_duplicate_identity` | fees | duplicate fee identity | 422 duplicate_identity | 422 | Fee identity_key duplicate rejected. |
| 26 | `tests/Feature/CommitteeDemoSeederTest.php` | `test_committee_demo_seeder_is_idempotent` | demo-data | seed twice | same APP_A id; 4 apps; waiting=3 | — | Demo seeder rerun does not duplicate entities. |
| 27 | `tests/Feature/DashboardDocumentReviewTest.php` | `test_approve_sets_fields_audit_notification_and_blocks_stale_second_decision` | document-review | second reviewer rejects approved doc | 422; stays Approved; no reject side effects | 422 | Stale second decision blocked after approve. |
| 28 | `tests/Feature/DashboardDocumentReviewTest.php` | `test_reject_validation_structured_storage_notification_and_stale_approve` | document-review | approve after reject | 422; rejected notification stays 1 | 422 | Stale approve after reject blocked. |
| 29 | `tests/Feature/AIAgentPhase1CriticalActionsTest.php` | `test_cancel_submit_documents_for_review_does_not_change_application_or_create_audit_notification_or_queue` | ai-agent | cancel pending submit | Draft unchanged; audits/notifications/queue unchanged | 200 | Cancel has no domain side effects. |
| 30 | `tests/Feature/AIAgentActionExecutionTest.php` | `test_executed_action_cannot_be_confirmed_again` | ai-agent | second confirm | 422 already executed | 422 | Re-confirm after execute rejected. |
| 31 | `tests/Feature/AIAgentActionExecutionTest.php` | `test_confirm_create_application_fails_when_duplicate_active_application_exists` | applications | active duplicate same license/service | 422; Failed; still one application | 422 | Duplicate active application blocked on confirm. |

---

## 4. Rollback / atomicity / afterCommit safety

| Metric | Exact |
|--------|-------|
| REL-ROLLBACK-METHODS | **1** |
| REL-ATOMICITY-METHODS | **5** |
| REL-AFTERCOMMIT-SAFETY-METHODS | **2** |
| NOTIF-TX-SAFETY-METHODS | **3** |

### afterCommit safety (only methods that prove semantics)

| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/NotificationTransactionSafetyTest.php` | `test_rolled_back_business_transaction_creates_no_notification` | notifications | RuntimeException after notify | zero notifications | — | Rolled-back domain TX leaves no notification (afterCommit/tx safety). |
| 2 | `tests/Feature/NotificationTransactionSafetyTest.php` | `test_notification_persist_failure_does_not_roll_back_business_success` | notifications | notification persist throws | fine committed; zero notifications | — | Notification failure isolated from business commit. |

**Gap:** No dedicated positive Feature test that notifications emit only after production DB::afterCommit (PHPUnit path runs callbacks immediately when runningUnitTests()).

---

## 5. Stale-state rejection — REL-STALE-STATE-REJECTION-METHODS

**EXACT distinct methods: 22**

Classes observed: optimistic_version | workflow_guard | expiring_token | revalidation_before_commit

| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/PaymentFlowTest.php` | `test_cannot_confirm_payment_twice` | payments | second confirm after completed | 422 | 422 | Double confirm rejected. |
| 2 | `tests/Feature/PushProductionCertificationTest.php` | `test_stale_processing_recovered_recent_not_stolen` | push | stale Processing 10m vs fresh 30s | reclaimed=1; fresh stays Processing | — | Lease recovery selective. |
| 3 | `tests/Feature/SendPushNotificationJobTest.php` | `test_deleted_device_before_processing_is_safe` | push | device deleted before process | InvalidToken; attempts=0 | — | Missing device handled without FCM. |
| 4 | `tests/Feature/AppointmentSlotConcurrencyTest.php` | `test_cancel_releases_capacity_and_is_idempotent_on_status` | appointments | cancel then cancel again | booked_count 1->0; second 422 | 422 | Capacity released; second cancel rejected. |
| 5 | `tests/Feature/AppointmentSlotConcurrencyTest.php` | `test_concurrent_slot_update_cannot_reduce_capacity_below_booked_count` | appointments | capacity 5 booked 3 -> set 2 | 422; capacity remains 5 | 422 | Unsafe capacity reduction rejected (not 409 optimistic). |
| 6 | `tests/Feature/DashboardAppointmentSlotsTest.php` | `test_update_requires_version_and_stale_returns_409` | appointment-slots | stale version 999 | 409 stale_version | 409 | Optimistic AppointmentSlot.version conflict. |
| 7 | `tests/Feature/DashboardAppointmentSlotsTest.php` | `test_capacity_cannot_drop_below_booked_count` | appointment-slots | capacity 2 with booked 3 | 422 unsafe_capacity_reduction | 422 | Capacity integrity guard. |
| 8 | `tests/Feature/EmployeeSessionRevocationTest.php` | `test_reason_required_and_duplicate_revoke_is_409` | employee-sessions | second revoke after success | 409; audit count unchanged | 409 | Duplicate revoke conflict without extra audit. |
| 9 | `tests/Feature/DashboardLicenseIssuanceQueueTest.php` | `test_stale_condition_causes_issue_license_422_after_ready_get` | licenses | unpaid fine added after ready view | 422; leaves ready queue | 422 | Issuance revalidates readiness. |
| 10 | `tests/Feature/DashboardFeesManagementTest.php` | `test_stale_version_returns_409` | fees | version 999 | 409 stale_version | 409 | Optimistic Fee.version conflict. |
| 11 | `tests/Feature/DashboardRoleManagementTest.php` | `test_update_role_version_conflict_returns_409` | access-control/roles | version 999 | 409 | 409 | Optimistic Role.version conflict. |
| 12 | `tests/Feature/DashboardDocumentReviewTest.php` | `test_approve_sets_fields_audit_notification_and_blocks_stale_second_decision` | document-review | second reviewer rejects approved doc | 422; stays Approved; no reject side effects | 422 | Stale second decision blocked after approve. |
| 13 | `tests/Feature/DashboardDocumentReviewTest.php` | `test_reject_validation_structured_storage_notification_and_stale_approve` | document-review | approve after reject | 422; rejected notification stays 1 | 422 | Stale approve after reject blocked. |
| 14 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_expired_workflow_chat_returns_expired_not_general_help` | ai-agent | chat after pending_workflow expired | application_selection_expired; workflow cleared | 200 | Expired workflow not general help. |
| 15 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_expired_interaction_token_returns_pending_workflow_expired` | ai-agent | token after expiry | 422 PENDING_WORKFLOW_EXPIRED | 422 | Expired interaction token rejected. |
| 16 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_show_choices_after_expiry_returns_expired_response` | ai-agent | after expiry | application_selection_expired | 200 | Post-expiry show-choices expired. |
| 17 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_ma_badi_araaf_al_mokhalafat_switches_to_fines_not_cancel` | ai-agent | old token after workflow cleared | old select 422 | 422 | Stale token invalid after topic switch. |
| 18 | `tests/Feature/AIAgentPhase1CriticalActionsTest.php` | `test_confirm_submit_documents_for_review_fails_when_application_state_changes_before_confirmation` | ai-agent | external status change before confirm | 422; action Failed | 422 | Stale application state blocks confirm. |
| 19 | `tests/Feature/AIAgentPhase1CriticalActionsTest.php` | `test_submit_documents_for_review_fails_when_required_documents_become_rejected_before_confirmation` | ai-agent | docs rejected before confirm | confirm fails stale eligibility | 422 | Stale document eligibility blocks confirm. |
| 20 | `tests/Feature/AIAgentAppointmentMultiSlotFlowTest.php` | `test_stale_slot_and_expired_and_foreign_token` | ai-agent/appointments | slot at capacity; expired workflow; foreign token | 422 NO_LONGER_AVAILABLE; 422 EXPIRED; foreign 404 | 422 | Stale capacity and expired workflow guarded. |
| 21 | `tests/Feature/AIAgentAppointmentMultiSlotFlowTest.php` | `test_reschedule_end_to_end_and_stale_replacement_slot` | ai-agent/appointments | candidate slot filled before select | 422 APPOINTMENT_SLOT_NO_LONGER_AVAILABLE | 422 | Stale replacement slot rejected. |
| 22 | `tests/Feature/AIAgentActionExecutionTest.php` | `test_cancelled_action_cannot_be_confirmed` | ai-agent | confirm after Cancelled | 422 | 422 | Cancelled action cannot be confirmed. |

---

## 6. Optimistic concurrency and locked domains

### CONC-OPTIMISTIC-ENTITIES = **3**

| Entity | Field | Conflict HTTP |
|--------|-------|---------------|
| AppointmentSlot | version | 409 |
| Role | version | 409 |
| Fee | version | 409 |

### CONC-OPTIMISTIC-CONFLICT-METHODS = **3** (409 stale_version only)

| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/DashboardAppointmentSlotsTest.php` | `test_update_requires_version_and_stale_returns_409` | appointment-slots | stale version 999 | 409 stale_version | 409 | Optimistic AppointmentSlot.version conflict. |
| 2 | `tests/Feature/DashboardFeesManagementTest.php` | `test_stale_version_returns_409` | fees | version 999 | 409 stale_version | 409 | Optimistic Fee.version conflict. |
| 3 | `tests/Feature/DashboardRoleManagementTest.php` | `test_update_role_version_conflict_returns_409` | access-control/roles | version 999 | 409 | 409 | Optimistic Role.version conflict. |

Capacity-below-booked asserts **422** and are counted under CONC-APPOINTMENT-METHODS + REL-STALE-STATE-REJECTION-METHODS, **not** optimistic-conflict.

### Locked domains

| Set | Count | Members |
|-----|-------|---------|
| IDENTIFIED | 13 | licenses, payments, appointments, fees, citizens, access-control/roles, employee-sessions, push-devices, push-deliveries, fines, test-results, document-review, ai-agent-sessions |
| WITH-BEHAVIORAL-TESTS | 11 | licenses, payments, appointments, fees, citizens, access-control/roles, employee-sessions, push-devices, push-deliveries, document-review, ai-agent-sessions |
| locks without concurrency behavioral tests | 2 | fines, test-results |
| **Ratio** | **11/13** | |

---

## 7. DB invariants — REL-DB-INVARIANTS = **12**; tested **7/12**

| # | Constraint | Tested? | Evidence |
|---|------------|---------|----------|
| 1 | payments.settled_obligation_key | YES | PaymentConcurrencyAndIntegrityTest::test_settled_obligation_key_unique |
| 2 | payments.active_obligation_key | NO | UNTESTED direct constraint |
| 3 | payments.(provider,provider_reference) | YES | PaymentConcurrencyAndIntegrityTest::test_provider_reference_unique_constraint |
| 4 | payment_gateway_events.(provider,event_id) | NO | Webhook idempotency is behavioral not QueryException |
| 5 | notifications.event_key | YES | NotificationIdempotencyTest::test_same_event_key_does_not_duplicate |
| 6 | push_deliveries.delivery_key | YES | PushDeliveryPlanningTest::test_delivery_key_uniqueness_enforced |
| 7 | push_devices.token_hash | YES | PushDeviceTokenRotationTest::test_token_uniqueness_constraint_is_enforced_at_database_level |
| 8 | push_devices.(user_id,device_id) | NO | UNTESTED direct |
| 9 | licenses.license_number | NO | UNTESTED direct |
| 10 | licenses.verification_token | NO | UNTESTED direct |
| 11 | fees.identity_key | YES | DashboardFeesManagementTest::test_create_rejects_duplicate_identity |
| 12 | appointment_slots.identity_key | YES | DashboardAppointmentSlotsTest::test_create_rejects_duplicate_and_ignores_client_booked_count |

---

## 8. Appointment concurrency deep dive — CONC-APPOINTMENT-METHODS

**EXACT distinct methods: 9**

### Concurrent booking (canonical numbers)

| Assertion | Value |
|-----------|-------|
| Slot capacity | **1** |
| Concurrent actors | 2 citizens (A, B) |
| Success count | **1** |
| Failure count | **1** |
| Final booked_count | **1** |
| Overbook | **0** |
| Parallelism model | Sequential HTTP loop in PHPUnit (foreach two requests) — **not** OS threads |
| Test | AppointmentSlotConcurrencyTest::test_concurrent_booking_cannot_overbook_single_capacity_slot |

| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/AppointmentSlotConcurrencyTest.php` | `test_concurrent_booking_cannot_overbook_single_capacity_slot` | appointments | two citizens book capacity=1 (sequential HTTP loop) | success=1; failure=1; booked_count=1; overbook=0 | — | capacity=1; success=1; failure=1; booked_count=1. Sequential requests not OS threads. |
| 2 | `tests/Feature/AppointmentSlotConcurrencyTest.php` | `test_cancel_releases_capacity_and_is_idempotent_on_status` | appointments | cancel then cancel again | booked_count 1->0; second 422 | 422 | Capacity released; second cancel rejected. |
| 3 | `tests/Feature/AppointmentSlotConcurrencyTest.php` | `test_reschedule_releases_and_consumes_capacity_with_audit` | appointments | move slotA->slotB | slotA=0; slotB=1; audit | 200 | Reschedule transfers capacity with audit. |
| 4 | `tests/Feature/AppointmentSlotConcurrencyTest.php` | `test_reschedule_lock_order_preserves_booked_counts` | appointments | A low->high then B high->low | each booked_count=1 within capacity | 200 | Cross-reschedule preserves counts under lock order. |
| 5 | `tests/Feature/AppointmentSlotConcurrencyTest.php` | `test_concurrent_slot_update_cannot_reduce_capacity_below_booked_count` | appointments | capacity 5 booked 3 -> set 2 | 422; capacity remains 5 | 422 | Unsafe capacity reduction rejected (not 409 optimistic). |
| 6 | `tests/Feature/DashboardAppointmentSlotsTest.php` | `test_capacity_cannot_drop_below_booked_count` | appointment-slots | capacity 2 with booked 3 | 422 unsafe_capacity_reduction | 422 | Capacity integrity guard. |
| 7 | `tests/Feature/DashboardAppointmentSlotsTest.php` | `test_create_rejects_duplicate_and_ignores_client_booked_count` | appointment-slots | duplicate identity + client booked_count=99 | first created booked_count=0; second 422 duplicate_identity | 422 | Duplicate slot identity rejected; client booked_count ignored. |
| 8 | `tests/Feature/AIAgentAppointmentMultiSlotFlowTest.php` | `test_stale_slot_and_expired_and_foreign_token` | ai-agent/appointments | slot at capacity; expired workflow; foreign token | 422 NO_LONGER_AVAILABLE; 422 EXPIRED; foreign 404 | 422 | Stale capacity and expired workflow guarded. |
| 9 | `tests/Feature/AIAgentAppointmentMultiSlotFlowTest.php` | `test_reschedule_end_to_end_and_stale_replacement_slot` | ai-agent/appointments | candidate slot filled before select | 422 APPOINTMENT_SLOT_NO_LONGER_AVAILABLE | 422 | Stale replacement slot rejected. |

---

## 9. Payment reliability metrics

| Metric | Exact |
|--------|-------|
| PAY-IDEMPOTENCY-METHODS | **9** |
| PAY-UNIQUENESS-METHODS | **2** |
| PAY-CONCURRENCY-INTEGRITY-METHODS | **2** |
| PAY-WEBHOOK-IDEMPOTENCY-METHODS | **1** |
| PAY-MONEY-PRECISION-METHODS | **6** |

### Payment reconciliation status

| Component | Status |
|-----------|--------|
| PaymentReconciliationService | **EXISTS** |
| ReconcilePendingPaymentsCommand | **EXISTS** |
| Dedicated Feature reconcile test | **NONE** |
| Committee claim | **IMPLEMENTED BUT UNMEASURED** — do not invent REL-RECOVERY-METHODS for payment reconcile |

### PAY-IDEMPOTENCY
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/PaymentConcurrencyAndIntegrityTest.php` | `test_duplicate_initiation_reuses_active_attempt` | payments | second create while pending exists | same payment id; one payment row | 200 | Duplicate initiation reuses active attempt. |
| 2 | `tests/Feature/PaymentConcurrencyAndIntegrityTest.php` | `test_settled_obligation_key_unique` | payments | second completed same settled_obligation_key | QueryException | — | Prevents double-settlement of same obligation. |
| 3 | `tests/Feature/PaymentFlowTest.php` | `test_create_payment_is_idempotent_when_pending_exists` | payments | repeated create with pending | same payment id | 200 | Pending create idempotent by id reuse. |
| 4 | `tests/Feature/PaymentFlowTest.php` | `test_cannot_confirm_payment_twice` | payments | second confirm after completed | 422 | 422 | Double confirm rejected. |
| 5 | `tests/Feature/PaymentStripeTest.php` | `test_duplicate_webhook_is_idempotent` | payments | identical webhook twice | both OK; one completed payment | 200 | Duplicate Stripe webhook does not re-settle. |
| 6 | `tests/Feature/PaymentStripeTest.php` | `test_cannot_create_payment_when_completed_row_exists_for_fee` | payments | create after completed fee payment | 422 already_completed | 422 | Blocks new attempt when fee settled. |
| 7 | `tests/Feature/PaymentStripeTest.php` | `test_cannot_create_second_stripe_payment_after_successful_flow` | payments | second create after status completed | 422 | 422 | Post-success create blocked. |
| 8 | `tests/Feature/DashboardPaymentManagementTest.php` | `test_verify_stripe_payment_completes_idempotently` | payments | verify twice after Stripe paid | both OK; payment.completed audit count unchanged | 200 | Dashboard verify idempotent on audits. |
| 9 | `tests/Feature/NotificationEventCoverageTest.php` | `test_payment_failed_and_under_verification_notify_once_per_code` | payments/notifications | same failure codes applied twice | one PaymentFailed + one UnderVerification notification | — | Per-code lifecycle notify idempotent. |

### PAY-UNIQUENESS
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/PaymentConcurrencyAndIntegrityTest.php` | `test_provider_reference_unique_constraint` | payments | second row same provider_reference | QueryException | — | DB uniqueness on provider_reference. |
| 2 | `tests/Feature/PaymentConcurrencyAndIntegrityTest.php` | `test_settled_obligation_key_unique` | payments | second completed same settled_obligation_key | QueryException | — | Prevents double-settlement of same obligation. |

### PAY-CONCURRENCY-INTEGRITY (includes manual Stripe confirm disabled + duplicate initiation)
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/PaymentConcurrencyAndIntegrityTest.php` | `test_duplicate_initiation_reuses_active_attempt` | payments | second create while pending exists | same payment id; one payment row | 200 | Duplicate initiation reuses active attempt. |
| 2 | `tests/Feature/PaymentStripeTest.php` | `test_stripe_manual_confirm_is_disabled` | payments | manual confirm when provider=stripe | 400 manual_confirm_disabled | 400 | Stripe path disables manual confirm (payment integrity). |

### PAY-WEBHOOK-IDEMPOTENCY
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/PaymentStripeTest.php` | `test_duplicate_webhook_is_idempotent` | payments | identical webhook twice | both OK; one completed payment | 200 | Duplicate Stripe webhook does not re-settle. |

### PAY-MONEY-PRECISION (includes currency/amount mismatch under-verification integrity)
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/PaymentConcurrencyAndIntegrityTest.php` | `test_money_to_minor_units_exact_without_float` | payments | decimal strings USD/SYP/JPY | exact minor units; equals ignores trailing zeros | — | Exact minor-unit math without float drift. |
| 2 | `tests/Feature/PaymentConcurrencyAndIntegrityTest.php` | `test_money_rejects_excess_precision` | payments | USD with 3 fractional digits | InvalidArgumentException | — | Rejects excess fractional precision. |
| 3 | `tests/Feature/ApplicationFeeUsdCatalogTest.php` | `test_usd_minor_unit_conversion_is_exact` | payments | catalog/sample amounts | exact minor units | — | USD minor-unit conversion exact. |
| 4 | `tests/Feature/ApplicationFeeUsdCatalogTest.php` | `test_client_cannot_override_amount_or_currency` | payments | client sends amount=1.00 currency=EUR | server snapshots catalog USD amount | 200 | Client cannot override fee amount/currency. |
| 5 | `tests/Feature/ApplicationFeeUsdCatalogTest.php` | `test_currency_mismatch_moves_payment_to_under_verification` | payments | webhook currency eur vs USD payment | UnderVerification; app stays PaymentPending | 200 | Currency mismatch financial integrity (underpayment verification). |
| 6 | `tests/Feature/ApplicationFeeUsdCatalogTest.php` | `test_amount_mismatch_moves_payment_to_under_verification` | payments | webhook amount_total 999 vs expected | UnderVerification | 200 | Amount mismatch financial integrity. |

---

## 10. License issuance integrity

| Metric | Exact |
|--------|-------|
| LIC-ISSUANCE-INTEGRITY-METHODS | **9** |
| LIC-DUPLICATE-PREVENTION-METHODS | **6** |
| LIC-STALE-REVALIDATION-METHODS | **1** |

### LIC-ISSUANCE-INTEGRITY
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/DashboardLicenseIssuanceQueueTest.php` | `test_stale_condition_causes_issue_license_422_after_ready_get` | licenses | unpaid fine added after ready view | 422; leaves ready queue | 422 | Issuance revalidates readiness. |
| 2 | `tests/Feature/DashboardLicenseIssuanceQueueTest.php` | `test_application_with_unpaid_fine_is_excluded` | licenses | issuable app + unpaid fine vs ready | total=1 only ready app | 200 | Unpaid fine excludes from issuance queue. |
| 3 | `tests/Feature/DashboardLicenseIssuanceQueueTest.php` | `test_already_issued_application_is_excluded` | licenses | already has license row | total=1 only other ready | 200 | Already-issued excluded from queue. |
| 4 | `tests/Feature/DashboardLicenseIssuanceQueueTest.php` | `test_existing_issue_license_still_succeeds_for_ready_application` | licenses | ready application issue | OK active license; queue empties | 200 | Happy-path issue succeeds and removes from queue. |
| 5 | `tests/Feature/LicenseExpirySyncTest.php` | `test_effective_status_detects_expired_without_mutating_row` | licenses | past expiry_date read | effective Expired; stored Active | — | Read path does not mutate status. |
| 6 | `tests/Feature/LicenseExpirySyncTest.php` | `test_sync_command_updates_and_is_idempotent` | licenses | command twice | Expired; history count unchanged | — | Expiry sync idempotent on history. |
| 7 | `tests/Feature/DashboardOverviewTest.php` | `test_license_unblock_is_excluded_from_ready_and_issue_license` | licenses | disallowed service | 422; license count unchanged | 422 | Failed issue creates no license. |
| 8 | `tests/Feature/DashboardOverviewTest.php` | `test_unknown_custom_service_code_is_not_issuable` | licenses | unsupported service type | 422; licenses/audits unchanged | 422 | Failed issue no license/audit. |
| 9 | `tests/Feature/DashboardOverviewTest.php` | `test_duplicate_issue_license_is_prevented_after_success` | licenses | second issue after success | 422; exactly one license | 422 | Duplicate issuance prevented. |

### LIC-DUPLICATE-PREVENTION
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/DashboardLicenseIssuanceQueueTest.php` | `test_already_issued_application_is_excluded` | licenses | already has license row | total=1 only other ready | 200 | Already-issued excluded from queue. |
| 2 | `tests/Feature/DashboardOverviewTest.php` | `test_license_unblock_is_excluded_from_ready_and_issue_license` | licenses | disallowed service | 422; license count unchanged | 422 | Failed issue creates no license. |
| 3 | `tests/Feature/DashboardOverviewTest.php` | `test_duplicate_issue_license_is_prevented_after_success` | licenses | second issue after success | 422; exactly one license | 422 | Duplicate issuance prevented. |
| 4 | `tests/Feature/OtherLicenseServicesFlowTest.php` | `test_duplicate_renew_application_is_blocked` | applications | second renew while active exists | 422 duplicate_active_application_license | 422 | Duplicate renew blocked. |
| 5 | `tests/Feature/DemoLicenseServiceSeedersTest.php` | `test_running_seeder_twice_does_not_duplicate_licenses` | licenses | run seeder twice | each demo license_number count=1 | — | Demo seeder does not duplicate licenses. |
| 6 | `tests/Feature/AIAgentActionExecutionTest.php` | `test_confirm_create_application_fails_when_duplicate_active_application_exists` | applications | active duplicate same license/service | 422; Failed; still one application | 422 | Duplicate active application blocked on confirm. |

### LIC-STALE-REVALIDATION
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/DashboardLicenseIssuanceQueueTest.php` | `test_stale_condition_causes_issue_license_422_after_ready_get` | licenses | unpaid fine added after ready view | 422; leaves ready queue | 422 | Issuance revalidates readiness. |

---

## 11. Notifications and push

| Metric | Exact |
|--------|-------|
| NOTIF-IDEMPOTENCY-METHODS | **8** |
| NOTIF-TX-SAFETY-METHODS | **3** |
| PUSH-RETRY-METHODS | **9** |
| PUSH-TERMINAL-NO-RESEND-METHODS | **9** |
| PUSH-RECOVERY-METHODS | **9** |

### NOTIF-IDEMPOTENCY
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/NotificationIdempotencyTest.php` | `test_same_event_key_does_not_duplicate` | notifications | same event_key twice | one notification row | — | Event-key dedupe proven. |
| 2 | `tests/Feature/NotificationIdempotencyTest.php` | `test_status_history_event_key_dedupes_reprocessing_same_history_row` | notifications | re-notify same history id | one ApplicationPaymentPending notification | — | History-keyed reprocessing deduped. |
| 3 | `tests/Feature/NotificationProductionReadinessTest.php` | `test_already_active_citizen_activation_does_not_spam_notifications` | citizens | activate twice on active citizen | zero AccountActivated notifications | — | No-op activation emits no spam. |
| 4 | `tests/Feature/NotificationProductionReadinessTest.php` | `test_reactivation_after_deactivate_emits_exactly_one_activated_notification` | citizens | second activate after reactivation | exactly one AccountActivated | — | Repeated activate after reactivation does not duplicate. |
| 5 | `tests/Feature/NotificationEventCoverageTest.php` | `test_payment_failed_and_under_verification_notify_once_per_code` | payments/notifications | same failure codes applied twice | one PaymentFailed + one UnderVerification notification | — | Per-code lifecycle notify idempotent. |
| 6 | `tests/Feature/AppointmentNotificationTest.php` | `test_cancel_notifies_once_and_repeat_does_not_duplicate` | appointments/notifications | repeat cancel after success | AppointmentCancelled count stays 1 | — | Cancel notify once; repeat cancel no duplicate notification. |
| 7 | `tests/Feature/PushDeliveryPlanningTest.php` | `test_duplicate_business_event_does_not_create_new_push` | push | duplicate business event | one PushDelivery; one job | — | Deduped notification prevents second push plan. |
| 8 | `tests/Feature/DashboardCitizenManagementTest.php` | `test_repeated_activation_is_idempotent` | citizens | activate already-active twice | zero activated audits/notifications | 200 | No-op activation has no side effects. |

### NOTIF-TX-SAFETY
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/NotificationTransactionSafetyTest.php` | `test_rolled_back_business_transaction_creates_no_notification` | notifications | RuntimeException after notify | zero notifications | — | Rolled-back domain TX leaves no notification (afterCommit/tx safety). |
| 2 | `tests/Feature/NotificationTransactionSafetyTest.php` | `test_notification_persist_failure_does_not_roll_back_business_success` | notifications | notification persist throws | fine committed; zero notifications | — | Notification failure isolated from business commit. |
| 3 | `tests/Feature/PushProductionCertificationTest.php` | `test_db_notification_isolation_when_push_planning_errors` | notifications/push | push planning no-ops | notification persisted; zero deliveries | — | In-app notification isolated from empty push plan. |

### PUSH-RETRY
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/PushDeliveryRetryTest.php` | `test_retry_delay_uses_backoff_and_honors_retry_after_minimum` | push | attempt index + Retry-After floors | 60/120/300; floor 60; honor larger | — | Backoff and Retry-After minimum. |
| 2 | `tests/Feature/PushDeliveryRetryTest.php` | `test_retries_exhausted_marks_failed` | push | 503 with attempts=2 tries=3 | Failed; attempts=3; failed_at set | — | Exhausted retries become terminal Failed. |
| 3 | `tests/Feature/PushProductionCertificationTest.php` | `test_fcm_attempts_count_only_real_sends` | push | device missing | FCM never; attempts=0 | — | Attempts only on real FCM sends. |
| 4 | `tests/Feature/PushProductionCertificationTest.php` | `test_quota_429_minimum_delay_is_60_seconds` | push | Retry-After 10 vs 90 | floor 60; honor 90 | — | Quota delay floor. |
| 5 | `tests/Feature/PushProductionCertificationTest.php` | `test_503_retry_after_honored` | push | Retry-After 180 | releasedFor=180; attempts=1 | — | Retry-After drives release delay. |
| 6 | `tests/Feature/PushProductionCertificationTest.php` | `test_malformed_retry_after_falls_back_to_backoff` | push | invalid Retry-After | null parse; delay 60 | — | Malformed Retry-After falls back. |
| 7 | `tests/Feature/PushProductionCertificationTest.php` | `test_invalid_token_never_retries` | push | invalid token then process again | InvalidToken; FCM never on second | — | InvalidToken terminal non-retry. |
| 8 | `tests/Feature/SendPushNotificationJobTest.php` | `test_permanent_failure_does_not_delete_device_or_retry` | push | permanent FCM failure | Failed; device kept | — | Permanent failure terminal without device delete. |
| 9 | `tests/Feature/SendPushNotificationJobTest.php` | `test_retryable_failure_releases_job_and_keeps_pending` | push | Retry-After 120 | releasedFor=120; Pending; attempts=1 | — | Retryable failure keeps Pending. |

### PUSH-TERMINAL-NO-RESEND
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/PushDeliveryPlanningTest.php` | `test_duplicate_business_event_does_not_create_new_push` | push | duplicate business event | one PushDelivery; one job | — | Deduped notification prevents second push plan. |
| 2 | `tests/Feature/PushDeliveryPlanningTest.php` | `test_pending_recovery_is_idempotent_for_already_sent` | push | dispatch when already Sent | dispatched=0 | — | Recovery skips terminal Sent. |
| 3 | `tests/Feature/PushDeliveryRetryTest.php` | `test_retries_exhausted_marks_failed` | push | 503 with attempts=2 tries=3 | Failed; attempts=3; failed_at set | — | Exhausted retries become terminal Failed. |
| 4 | `tests/Feature/PushDeliveryRetryTest.php` | `test_dispatch_pending_command_dispatches_only_pending` | push | pending + Sent | job only for pending | — | Recovery command skips Sent. |
| 5 | `tests/Feature/PushProductionCertificationTest.php` | `test_terminal_delivery_never_resent` | push | reprocess terminal Sent | skipped; FCM never; stays Sent | — | Terminal Sent never resent. |
| 6 | `tests/Feature/PushProductionCertificationTest.php` | `test_duplicate_job_safe_after_sent` | push | duplicate job after success | Sent; attempts=1 | — | Second job after Sent is no-op. |
| 7 | `tests/Feature/PushProductionCertificationTest.php` | `test_invalid_token_never_retries` | push | invalid token then process again | InvalidToken; FCM never on second | — | InvalidToken terminal non-retry. |
| 8 | `tests/Feature/PushProductionCertificationTest.php` | `test_dispatch_pending_never_redispatches_terminal` | push | pending+Sent+Failed+InvalidToken | dispatched=1 only pending | — | Terminal statuses never redispatched. |
| 9 | `tests/Feature/SendPushNotificationJobTest.php` | `test_permanent_failure_does_not_delete_device_or_retry` | push | permanent FCM failure | Failed; device kept | — | Permanent failure terminal without device delete. |

### PUSH-RECOVERY
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/PushDeliveryPlanningTest.php` | `test_pending_recovery_is_idempotent_for_already_sent` | push | dispatch when already Sent | dispatched=0 | — | Recovery skips terminal Sent. |
| 2 | `tests/Feature/PushDeliveryRetryTest.php` | `test_dispatch_pending_command_dispatches_only_pending` | push | pending + Sent | job only for pending | — | Recovery command skips Sent. |
| 3 | `tests/Feature/PushProductionCertificationTest.php` | `test_stale_processing_recovered_recent_not_stolen` | push | stale Processing 10m vs fresh 30s | reclaimed=1; fresh stays Processing | — | Lease recovery selective. |
| 4 | `tests/Feature/PushProductionCertificationTest.php` | `test_dispatch_pending_never_redispatches_terminal` | push | pending+Sent+Failed+InvalidToken | dispatched=1 only pending | — | Terminal statuses never redispatched. |
| 5 | `tests/Feature/PushProductionCertificationTest.php` | `test_failed_dispatch_leaves_pending_recoverable` | push | Pending with no prior job | dispatched=1 | — | Orphan pending recoverable. |
| 6 | `tests/Feature/PushProductionCertificationTest.php` | `test_worker_crash_after_claim_leaves_recoverable_processing` | push | Processing + stale last_attempt_at | reclaimed to Pending | — | Crash-after-claim recoverable. |
| 7 | `tests/Feature/SendPushNotificationJobTest.php` | `test_deleted_device_before_processing_is_safe` | push | device deleted before process | InvalidToken; attempts=0 | — | Missing device handled without FCM. |
| 8 | `tests/Feature/SendPushNotificationJobTest.php` | `test_token_rotation_during_unregistered_does_not_delete_new_token` | push | rotate during FCM call | new token kept; delivery InvalidToken | — | Hash-guarded delete avoids killing rotated token. |
| 9 | `tests/Feature/SendPushNotificationJobTest.php` | `test_token_rotation_race_does_not_delete_new_token` | push | delete with stale token_hash | deleted=0; device exists | — | Stale-hash delete no-op after rotation. |

---

## 12. Session lifecycle — REL-SESSION-LIFECYCLE-METHODS

**EXACT distinct methods: 6**

| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/EmployeeSessionRevocationTest.php` | `test_reason_required_and_duplicate_revoke_is_409` | employee-sessions | second revoke after success | 409; audit count unchanged | 409 | Duplicate revoke conflict without extra audit. |
| 2 | `tests/Feature/EmployeeSessionRevocationTest.php` | `test_revoke_all_preserves_current_by_default` | employee-sessions | revoke-all default | revoked=1 preserved_current=1; current token still OK | 200 | Revoke-all preserves current session by default. |
| 3 | `tests/Feature/EmployeeSessionRevocationTest.php` | `test_revoke_all_can_include_current_and_rejects_citizens` | employee-sessions | include current; then citizen id | current revoked; citizen 404 | 200 | Revoke-all can include current; citizens rejected. |
| 4 | `tests/Feature/EmployeeSessionLastSeenTest.php` | `test_write_throttling_prevents_update_every_request` | employee-sessions | two /me within interval | last_seen_at unchanged on second | 200 | Throttled last_seen prevents write churn. |
| 5 | `tests/Feature/EmployeeSessionLifecycleTest.php` | `test_reconcile_and_prune_commands` | employee-sessions | open missing credential; old ended | CredentialMissing; prune removes aged | — | Reconcile recovers orphans; prune removes aged ended. |
| 6 | `tests/Feature/EmployeeSessionLifecycleTest.php` | `test_repeated_logout_is_idempotent` | employee-sessions | ended session force-filled revoked | status Revoked; not relabeled logout | 200 | Does NOT prove double-logout HTTP; proves ended-reason precedence only. |

Note: EmployeeSessionLifecycleTest::test_repeated_logout_is_idempotent proves ended-reason precedence only — **not** double-logout HTTP idempotency.

---

## 13. AI agent reliability

| Metric | Exact |
|--------|-------|
| AI-RELIABILITY-METHODS | **9** |
| AI-STALE-GUARD-METHODS | **9** |
| AI-CANCEL-NO-MUTATION-METHODS | **4** |

### AI-RELIABILITY
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_expired_workflow_chat_returns_expired_not_general_help` | ai-agent | chat after pending_workflow expired | application_selection_expired; workflow cleared | 200 | Expired workflow not general help. |
| 2 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_retryable_resume_failure_keeps_pending_workflow` | ai-agent | first resume throws then retry OK | 422 RETRY_REQUIRED keeps workflow; retry clears | 422 | Transient failure preserves workflow for retry. |
| 3 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_book_appointment_selection_does_not_create_incomplete_pending_action` | ai-agent | app selected before slot | no incomplete book_appointment pending action | 200 | No incomplete confirmable action created. |
| 4 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_exact_ma_badi_cancels_pending_workflow` | ai-agent | exact cancel awaiting selection | cancelled; pending_workflow removed | 200 | Cancel clears pending workflow. |
| 5 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_ma_badi_araaf_al_mokhalafat_switches_to_fines_not_cancel` | ai-agent | old token after workflow cleared | old select 422 | 422 | Stale token invalid after topic switch. |
| 6 | `tests/Feature/AIAgentAppointmentMultiSlotFlowTest.php` | `test_reschedule_end_to_end_and_stale_replacement_slot` | ai-agent/appointments | candidate slot filled before select | 422 APPOINTMENT_SLOT_NO_LONGER_AVAILABLE | 422 | Stale replacement slot rejected. |
| 7 | `tests/Feature/AIAgentAppointmentMultiSlotFlowTest.php` | `test_topic_change_and_cancel_phrase_clear_slot_workflow` | ai-agent | status question then cancel | pending_workflow cleared | 200 | Topic change and cancel clear slot workflow. |
| 8 | `tests/Feature/AIAgentActionExecutionTest.php` | `test_executed_action_cannot_be_confirmed_again` | ai-agent | second confirm | 422 already executed | 422 | Re-confirm after execute rejected. |
| 9 | `tests/Feature/AIAgentActionExecutionTest.php` | `test_confirm_create_application_fails_when_duplicate_active_application_exists` | applications | active duplicate same license/service | 422; Failed; still one application | 422 | Duplicate active application blocked on confirm. |

### AI-STALE-GUARD
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_expired_workflow_chat_returns_expired_not_general_help` | ai-agent | chat after pending_workflow expired | application_selection_expired; workflow cleared | 200 | Expired workflow not general help. |
| 2 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_expired_interaction_token_returns_pending_workflow_expired` | ai-agent | token after expiry | 422 PENDING_WORKFLOW_EXPIRED | 422 | Expired interaction token rejected. |
| 3 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_show_choices_after_expiry_returns_expired_response` | ai-agent | after expiry | application_selection_expired | 200 | Post-expiry show-choices expired. |
| 4 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_ma_badi_araaf_al_mokhalafat_switches_to_fines_not_cancel` | ai-agent | old token after workflow cleared | old select 422 | 422 | Stale token invalid after topic switch. |
| 5 | `tests/Feature/AIAgentPhase1CriticalActionsTest.php` | `test_confirm_submit_documents_for_review_fails_when_application_state_changes_before_confirmation` | ai-agent | external status change before confirm | 422; action Failed | 422 | Stale application state blocks confirm. |
| 6 | `tests/Feature/AIAgentPhase1CriticalActionsTest.php` | `test_submit_documents_for_review_fails_when_required_documents_become_rejected_before_confirmation` | ai-agent | docs rejected before confirm | confirm fails stale eligibility | 422 | Stale document eligibility blocks confirm. |
| 7 | `tests/Feature/AIAgentAppointmentMultiSlotFlowTest.php` | `test_stale_slot_and_expired_and_foreign_token` | ai-agent/appointments | slot at capacity; expired workflow; foreign token | 422 NO_LONGER_AVAILABLE; 422 EXPIRED; foreign 404 | 422 | Stale capacity and expired workflow guarded. |
| 8 | `tests/Feature/AIAgentAppointmentMultiSlotFlowTest.php` | `test_reschedule_end_to_end_and_stale_replacement_slot` | ai-agent/appointments | candidate slot filled before select | 422 APPOINTMENT_SLOT_NO_LONGER_AVAILABLE | 422 | Stale replacement slot rejected. |
| 9 | `tests/Feature/AIAgentActionExecutionTest.php` | `test_cancelled_action_cannot_be_confirmed` | ai-agent | confirm after Cancelled | 422 | 422 | Cancelled action cannot be confirmed. |

### AI-CANCEL-NO-MUTATION
| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_book_appointment_selection_does_not_create_incomplete_pending_action` | ai-agent | app selected before slot | no incomplete book_appointment pending action | 200 | No incomplete confirmable action created. |
| 2 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_exact_ma_badi_cancels_pending_workflow` | ai-agent | exact cancel awaiting selection | cancelled; pending_workflow removed | 200 | Cancel clears pending workflow. |
| 3 | `tests/Feature/AIAgentPhase1CriticalActionsTest.php` | `test_cancel_submit_documents_for_review_does_not_change_application_or_create_audit_notification_or_queue` | ai-agent | cancel pending submit | Draft unchanged; audits/notifications/queue unchanged | 200 | Cancel has no domain side effects. |
| 4 | `tests/Feature/AIAgentAppointmentMultiSlotFlowTest.php` | `test_topic_change_and_cancel_phrase_clear_slot_workflow` | ai-agent | status question then cancel | pending_workflow cleared | 200 | Topic change and cancel clear slot workflow. |

---

## 14. Recovery methods — REL-RECOVERY-METHODS

**EXACT distinct methods: 6**

Scope (allowed): push recovery + employee session reconcile/prune + license expiry sync + AI retryable resume. **Excludes** unmeasured payment reconcile command.

| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/PushProductionCertificationTest.php` | `test_stale_processing_recovered_recent_not_stolen` | push | stale Processing 10m vs fresh 30s | reclaimed=1; fresh stays Processing | — | Lease recovery selective. |
| 2 | `tests/Feature/PushProductionCertificationTest.php` | `test_failed_dispatch_leaves_pending_recoverable` | push | Pending with no prior job | dispatched=1 | — | Orphan pending recoverable. |
| 3 | `tests/Feature/PushProductionCertificationTest.php` | `test_worker_crash_after_claim_leaves_recoverable_processing` | push | Processing + stale last_attempt_at | reclaimed to Pending | — | Crash-after-claim recoverable. |
| 4 | `tests/Feature/EmployeeSessionLifecycleTest.php` | `test_reconcile_and_prune_commands` | employee-sessions | open missing credential; old ended | CredentialMissing; prune removes aged | — | Reconcile recovers orphans; prune removes aged ended. |
| 5 | `tests/Feature/LicenseExpirySyncTest.php` | `test_sync_command_updates_and_is_idempotent` | licenses | command twice | Expired; history count unchanged | — | Expiry sync idempotent on history. |
| 6 | `tests/Feature/AIAgentPendingWorkflowReliabilityTest.php` | `test_retryable_resume_failure_keeps_pending_workflow` | ai-agent | first resume throws then retry OK | 422 RETRY_REQUIRED keeps workflow; retry clears | 422 | Transient failure preserves workflow for retry. |

---

## 15. Rollback methods detail — REL-ROLLBACK-METHODS

**EXACT: 1**

| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/NotificationTransactionSafetyTest.php` | `test_rolled_back_business_transaction_creates_no_notification` | notifications | RuntimeException after notify | zero notifications | — | Rolled-back domain TX leaves no notification (afterCommit/tx safety). |

---

## 16. Atomicity methods detail — REL-ATOMICITY-METHODS

**EXACT: 5**

| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |
|---|------|--------|--------|----------|---------|------|-------|
| 1 | `tests/Feature/NotificationTransactionSafetyTest.php` | `test_rolled_back_business_transaction_creates_no_notification` | notifications | RuntimeException after notify | zero notifications | — | Rolled-back domain TX leaves no notification (afterCommit/tx safety). |
| 2 | `tests/Feature/NotificationTransactionSafetyTest.php` | `test_notification_persist_failure_does_not_roll_back_business_success` | notifications | notification persist throws | fine committed; zero notifications | — | Notification failure isolated from business commit. |
| 3 | `tests/Feature/PushProductionCertificationTest.php` | `test_db_notification_isolation_when_push_planning_errors` | notifications/push | push planning no-ops | notification persisted; zero deliveries | — | In-app notification isolated from empty push plan. |
| 4 | `tests/Feature/AppointmentSlotConcurrencyTest.php` | `test_concurrent_booking_cannot_overbook_single_capacity_slot` | appointments | two citizens book capacity=1 (sequential HTTP loop) | success=1; failure=1; booked_count=1; overbook=0 | — | capacity=1; success=1; failure=1; booked_count=1. Sequential requests not OS threads. |
| 5 | `tests/Feature/AppointmentSlotConcurrencyTest.php` | `test_reschedule_lock_order_preserves_booked_counts` | appointments | A low->high then B high->low | each booked_count=1 within capacity | 200 | Cross-reschedule preserves counts under lock order. |

---

## 17. Final numeric summary (all metrics)

| Metric ID | Exact value | Denominator / method | Interpretation | Limitation |
|-----------|-------------|----------------------|----------------|------------|
| **CONC-LOCK-CALLS** | **56** | 18 files (app/ scan) | IMPLEMENTATION METRIC only | Not outcome proof |
| IMPL-DB-TRANSACTION | **68** | app/ scan DB::transaction( | Atomic units | Excludes transactionLevel |
| IMPL-DB-AFTERCOMMIT | **1** | app/ scan | Notify after commit | Unit tests bypass |
| CONC-OPTIMISTIC-ENTITIES | **3** | schema | version fields | — |
| CONC-OPTIMISTIC-CONFLICT-METHODS | **3** | curated | 409 stale_version | capacity 422 excluded |
| CONC-APPOINTMENT-METHODS | **9** | curated | booking/capacity | sequential loop |
| CONC-LOCKED-DOMAINS | **11/13** | architecture | behavioral lock coverage | fines/test-results gap |
| REL-DB-INVARIANTS | **12** | curated set | critical uniques | conservative |
| REL-DB-INVARIANTS-TESTED | **7/12** | curated | direct proof | 5 untested |
| REL-IDEMPOTENCY-METHODS | **36** | curated | idempotent ops | overlap OK |
| REL-DUPLICATE-SIDE-EFFECT-METHODS | **31** | curated | no duplicate effects | — |
| REL-ROLLBACK-METHODS | **1** | curated | rollback safety | — |
| REL-ATOMICITY-METHODS | **5** | curated | atomic multi-write | — |
| REL-AFTERCOMMIT-SAFETY-METHODS | **2** | curated | afterCommit semantics | no positive emit test |
| REL-STALE-STATE-REJECTION-METHODS | **22** | curated | stale rejection | — |
| REL-SESSION-LIFECYCLE-METHODS | **6** | curated | session lifecycle | — |
| REL-RECOVERY-METHODS | **6** | curated | recovery paths | payment reconcile excluded |
| PAY-IDEMPOTENCY-METHODS | **9** | curated | payment idempotency | — |
| PAY-UNIQUENESS-METHODS | **2** | curated | payment uniques | — |
| PAY-CONCURRENCY-INTEGRITY-METHODS | **2** | curated | payment integrity | includes manual confirm disabled |
| PAY-WEBHOOK-IDEMPOTENCY-METHODS | **1** | curated | webhook dedupe | — |
| PAY-MONEY-PRECISION-METHODS | **6** | curated | money correctness | includes mismatch UV |
| LIC-ISSUANCE-INTEGRITY-METHODS | **9** | curated | issuance integrity | — |
| LIC-DUPLICATE-PREVENTION-METHODS | **6** | curated | license/app dupes | — |
| LIC-STALE-REVALIDATION-METHODS | **1** | curated | stale ready queue | — |
| NOTIF-IDEMPOTENCY-METHODS | **8** | curated | notify dedupe | — |
| NOTIF-TX-SAFETY-METHODS | **3** | curated | notify TX isolation | — |
| PUSH-RETRY-METHODS | **9** | curated | push retry | — |
| PUSH-TERMINAL-NO-RESEND-METHODS | **9** | curated | no terminal resend | — |
| PUSH-RECOVERY-METHODS | **9** | curated | push recovery | — |
| AI-RELIABILITY-METHODS | **9** | curated | AI reliability | — |
| AI-STALE-GUARD-METHODS | **9** | curated | AI stale guards | — |
| AI-CANCEL-NO-MUTATION-METHODS | **4** | curated | cancel no mutation | — |
| REL-UNIQUE-METHODS (inventory) | **107** | inventory | unique methods | do not sum metrics |

---

## 18. Committee-safe claims

| Claim | Status | Notes |
|-------|--------|-------|
| Duplicate payment initiation reuses the active attempt (idempotent create) | **VERIFIED** |  |
| Duplicate Stripe webhook processing is idempotent in the tested checkout.session.completed flow | **VERIFIED** |  |
| Concurrent booking tests resulted in zero overbooking for the covered capacity=1 scenario (success=1, failure=1, booked_count=1) | **VERIFIED** | Sequential HTTP loop in PHPUnit — not OS-thread parallelism |
| License issuance re-validates readiness at mutation time and rejects stale eligibility (GET readiness is advisory) | **VERIFIED** | DashboardLicenseIssuanceQueueTest::test_stale_condition_causes_issue_license_422_after_ready_get |
| Optimistic version conflicts return HTTP 409 for AppointmentSlot, Fee, and Role | **VERIFIED** |  |
| Notification event_key and push delivery_key prevent duplicate side effects in tested flows | **VERIFIED** |  |
| afterCommit / notification isolation: rolled-back business TX creates no notification; notification persist failure does not roll back business success | **PARTIALLY VERIFIED** | Rollback/isolation proven; positive production afterCommit emit not Feature-proven |
| Application-level recoverability exists for push leases, employee sessions, and license expiry sync | **PARTIALLY VERIFIED** | Payment reconcile implemented but unmeasured; not disaster recovery |
| Payment reconcile is fully measured by Feature tests | **DO NOT CLAIM** | IMPLEMENTED BUT UNMEASURED |
| The entire platform is race-condition free / exactly-once processing is guaranteed system-wide / the system is fault tolerant | **DO NOT CLAIM** |  |
| RPO / RTO / disaster-recovery backup restoration | **DO NOT CLAIM** | Not measured |
| OS-thread true parallel booking stress tested | **DO NOT CLAIM** |  |

---

## 19. Gaps

| # | Gap | Committee value | Risk | Effort |
|---|-----|-----------------|------|--------|
| 1 | Feature test for payment reconcile (ReconcilePendingPaymentsCommand / PaymentReconciliationService) proving recover stuck/paid sessions without double-settle | High | High (money) | Med |
| 2 | Issuance race: two employees issue same ready application concurrently | High | High (duplicate license) | Med |
| 3 | True parallel/OS-thread booking stress beyond sequential HTTP loop | High | Med–High (overbook) | Med–High |
| 4 | Additional forced-rollback atomicity tests outside notifications (payment settle, license issue, appointment book) | High | Med | Med |
| 5 | Positive production-mode afterCommit emit test (notify appears only after commit) | Med–High | Med | Med |
| 6 | Direct QueryException tests for active_obligation_key, gateway event unique, license_number/verification_token, push (user_id,device_id) | Med | Med | Low |
| 7 | Concurrent behavioral tests for FineService and TestResultService lockForUpdate sites | Med | Med | Med |

Do **not** implement these in this audit.

---

## 20. Reproducibility

### Artifacts
- docs/evidence/final-measurements/_reliability_concurrency_inventory.json (source of truth)
- docs/evidence/final-measurements/_export_reliability_concurrency_evidence.php (this exporter)
- docs/evidence/final-measurements/RELIABILITY_CONCURRENCY_EVIDENCE_MATRIX.md
- docs/evidence/final-measurements/reliability_concurrency_evidence.csv

### Command
```text
php docs/evidence/final-measurements/_export_reliability_concurrency_evidence.php
```

### Rules
1. Do not invent methods — inventory is curated from method-body review.
2. Metric totals are **distinct method counts** per metric_id.
3. Implementation counts are recomputed on each export from app/.
4. Suite totals (1043 / 6557 / 217.86s) are project-provided context, not re-run here.

**Exporter recomputed:** lockForUpdate=56; DB::transaction=68; DB::afterCommit=1; unique_methods=107
