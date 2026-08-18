<?php

namespace App\Modules\AIAgent\Support;

use App\Modules\AIAgent\Enums\AgentIntent;

class AgentWorkflowIntentCatalog
{
    /**
     * @return array<string, array{
     *   intent: string,
     *   requires_application: bool,
     *   read_only: bool,
     *   action_name: string|null,
     *   suggested_followups: list<string>
     * }>
     */
    public static function definitions(): array
    {
        return [
            AgentIntent::GetApplicationStatus->value => [
                'intent' => AgentIntent::GetApplicationStatus->value,
                'requires_application' => true,
                'read_only' => true,
                'action_name' => 'get_application_status',
                'suggested_followups' => ['get_application_next_step', 'get_required_documents'],
            ],
            AgentIntent::GetApplicationNextStep->value => [
                'intent' => AgentIntent::GetApplicationNextStep->value,
                'requires_application' => true,
                'read_only' => true,
                'action_name' => 'get_application_next_step',
                'suggested_followups' => ['get_required_documents', 'get_application_status'],
            ],
            AgentIntent::GetRequiredDocuments->value => [
                'intent' => AgentIntent::GetRequiredDocuments->value,
                'requires_application' => true,
                'read_only' => true,
                'action_name' => 'get_required_documents',
                'suggested_followups' => ['get_application_status'],
            ],
            AgentIntent::GetApplicationFee->value => [
                'intent' => AgentIntent::GetApplicationFee->value,
                'requires_application' => true,
                'read_only' => true,
                'action_name' => 'get_application_fee',
                'suggested_followups' => ['start_payment'],
            ],
            AgentIntent::GetPaymentStatus->value => [
                'intent' => AgentIntent::GetPaymentStatus->value,
                'requires_application' => true,
                'read_only' => true,
                'action_name' => 'get_payment_status',
                'suggested_followups' => ['start_payment', 'get_application_fee'],
            ],
            AgentIntent::StartPayment->value => [
                'intent' => AgentIntent::StartPayment->value,
                'requires_application' => true,
                'read_only' => false,
                'action_name' => 'start_payment',
                'suggested_followups' => ['get_payment_status', 'get_application_fee'],
            ],
            AgentIntent::GetFines->value => [
                'intent' => AgentIntent::GetFines->value,
                'requires_application' => false,
                'read_only' => true,
                'action_name' => 'get_fines',
                'suggested_followups' => [],
            ],
            AgentIntent::GetLicenses->value => [
                'intent' => AgentIntent::GetLicenses->value,
                'requires_application' => false,
                'read_only' => true,
                'action_name' => 'get_licenses',
                'suggested_followups' => [],
            ],
            AgentIntent::GetProfileStatus->value => [
                'intent' => AgentIntent::GetProfileStatus->value,
                'requires_application' => false,
                'read_only' => true,
                'action_name' => 'get_profile_status',
                'suggested_followups' => [],
            ],
            AgentIntent::GetAvailableTests->value => [
                'intent' => AgentIntent::GetAvailableTests->value,
                'requires_application' => true,
                'read_only' => true,
                'action_name' => 'get_available_tests',
                'suggested_followups' => ['get_appointment_slots'],
            ],
            AgentIntent::GetAppointmentSlots->value => [
                'intent' => AgentIntent::GetAppointmentSlots->value,
                'requires_application' => true,
                'read_only' => true,
                'action_name' => 'get_appointment_slots',
                'suggested_followups' => ['book_appointment'],
            ],
            AgentIntent::GetCurrentAppointments->value => [
                'intent' => AgentIntent::GetCurrentAppointments->value,
                'requires_application' => true,
                'read_only' => true,
                'action_name' => 'get_current_appointments',
                'suggested_followups' => ['get_available_tests', 'get_appointment_slots'],
            ],
            AgentIntent::BookAppointment->value => [
                'intent' => AgentIntent::BookAppointment->value,
                'requires_application' => true,
                'read_only' => false,
                'action_name' => 'book_appointment',
                'suggested_followups' => ['get_appointment_slots', 'get_current_appointments'],
            ],
            AgentIntent::RescheduleAppointment->value => [
                'intent' => AgentIntent::RescheduleAppointment->value,
                'requires_application' => true,
                'read_only' => false,
                'action_name' => 'reschedule_appointment',
                'suggested_followups' => ['get_current_appointments'],
            ],
            AgentIntent::CancelAppointment->value => [
                'intent' => AgentIntent::CancelAppointment->value,
                'requires_application' => true,
                'read_only' => false,
                'action_name' => 'cancel_appointment',
                'suggested_followups' => ['get_current_appointments'],
            ],
            AgentIntent::GetTestResults->value => [
                'intent' => AgentIntent::GetTestResults->value,
                'requires_application' => true,
                'read_only' => true,
                'action_name' => 'get_test_results',
                'suggested_followups' => ['get_application_status'],
            ],
            AgentIntent::SubmitDocumentsForReview->value => [
                'intent' => AgentIntent::SubmitDocumentsForReview->value,
                'requires_application' => true,
                'read_only' => false,
                'action_name' => 'submit_documents_for_review',
                'suggested_followups' => ['get_application_next_step', 'get_required_documents'],
            ],
            AgentIntent::CreateNewLicenseApplication->value => [
                'intent' => AgentIntent::CreateNewLicenseApplication->value,
                'requires_application' => false,
                'read_only' => false,
                'action_name' => 'create_application',
                'suggested_followups' => ['get_required_documents'],
            ],
            AgentIntent::CreateRenewLicenseApplication->value => [
                'intent' => AgentIntent::CreateRenewLicenseApplication->value,
                'requires_application' => false,
                'read_only' => false,
                'action_name' => 'create_application',
                'suggested_followups' => ['get_required_documents'],
            ],
            AgentIntent::CreateLostReplacementApplication->value => [
                'intent' => AgentIntent::CreateLostReplacementApplication->value,
                'requires_application' => false,
                'read_only' => false,
                'action_name' => 'create_application',
                'suggested_followups' => ['get_required_documents'],
            ],
            AgentIntent::CreateDamagedReplacementApplication->value => [
                'intent' => AgentIntent::CreateDamagedReplacementApplication->value,
                'requires_application' => false,
                'read_only' => false,
                'action_name' => 'create_application',
                'suggested_followups' => ['get_required_documents'],
            ],
            AgentIntent::CreateLicenseUnblockApplication->value => [
                'intent' => AgentIntent::CreateLicenseUnblockApplication->value,
                'requires_application' => false,
                'read_only' => false,
                'action_name' => 'create_application',
                'suggested_followups' => ['get_required_documents'],
            ],
            AgentIntent::GeneralHelp->value => [
                'intent' => AgentIntent::GeneralHelp->value,
                'requires_application' => false,
                'read_only' => true,
                'action_name' => null,
                'suggested_followups' => [],
            ],
            AgentIntent::OutOfScope->value => [
                'intent' => AgentIntent::OutOfScope->value,
                'requires_application' => false,
                'read_only' => true,
                'action_name' => null,
                'suggested_followups' => [],
            ],
            AgentIntent::AdminActionDenied->value => [
                'intent' => AgentIntent::AdminActionDenied->value,
                'requires_application' => false,
                'read_only' => true,
                'action_name' => null,
                'suggested_followups' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $intent): ?array
    {
        return self::definitions()[$intent] ?? null;
    }

    public static function requiresApplication(string $intent): bool
    {
        return (bool) (self::get($intent)['requires_application'] ?? false);
    }

    public static function isReadOnly(string $intent): bool
    {
        return (bool) (self::get($intent)['read_only'] ?? true);
    }

    public static function actionName(string $intent): ?string
    {
        $action = self::get($intent)['action_name'] ?? null;

        return is_string($action) && $action !== '' ? $action : null;
    }

    /**
     * @return list<string>
     */
    public static function applicationDependentIntents(): array
    {
        $intents = [];
        foreach (self::definitions() as $intent => $definition) {
            if (($definition['requires_application'] ?? false) === true) {
                $intents[] = $intent;
            }
        }

        return $intents;
    }
}
