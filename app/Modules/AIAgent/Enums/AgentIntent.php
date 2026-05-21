<?php

namespace App\Modules\AIAgent\Enums;

enum AgentIntent: string
{
    case CreateNewLicenseApplication = 'create_new_license_application';
    case GetApplicationStatus = 'get_application_status';
    case GetRequiredDocuments = 'get_required_documents';
    case SubmitDocumentsForReview = 'submit_documents_for_review';
    case StartPayment = 'start_payment';
    case GetAvailableTests = 'get_available_tests';
    case GetAppointmentSlots = 'get_appointment_slots';
    case BookAppointment = 'book_appointment';
    case RescheduleAppointment = 'reschedule_appointment';
    case CancelAppointment = 'cancel_appointment';
    case GetTestResults = 'get_test_results';
    case GetLicenses = 'get_licenses';
    case GetFines = 'get_fines';
    case GeneralHelp = 'general_help';
    case OutOfScope = 'out_of_scope';
    case AdminActionDenied = 'admin_action_denied';
    case Unknown = 'unknown';
}
