<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class DlmsTestingDashboardController extends Controller
{
    /** @var list<string> */
    private const SESSION_KEYS = [
        'citizen_token',
        'employee_token',
        'admin_token',
        'citizen_email',
        'citizen_password',
        'employee_login',
        'employee_password',
        'admin_login',
        'admin_password',
        'citizen_user_id',
        'employee_user_id',
        'admin_user_id',
        'application_id',
        'application_number',
        'application_status',
        'license_type_id',
        'service_type_id',
        'required_document_id',
        'required_document_ids',
        'document_id',
        'pending_document_id',
        'payment_id',
        'checkout_url',
        'appointment_slot_id',
        'appointment_id',
        'test_type_id',
        'license_id',
        'fine_id',
        'notification_id',
        'ai_agent_session_id',
        'ai_agent_action_id',
        'ai_agent_message',
    ];

    public function index(): View
    {
        return view('dev-dashboard.index', [
            'apiBaseUrl' => '/api',
            'devRoutes' => $this->devDashboardRoutes(),
            'environment' => app()->environment(),
            'status' => $this->buildStatusPanel(),
            'lastResponse' => session('dev_last_response'),
            'defaults' => $this->credentialDefaults(),
            'paymentProvider' => config('payment.provider', 'mock'),
        ]);
    }

    public function runAction(Request $request): RedirectResponse
    {
        $action = (string) $request->input('action', '');

        if ($action === '') {
            return $this->redirectWithError('No action specified.');
        }

        if (str_starts_with($action, 'scenario_')) {
            return $this->runScenario(substr($action, 9), $request);
        }

        $result = match ($action) {
            'ping' => $this->ping(),
            'refresh_application' => $this->refreshApplication(),
            'register_citizen' => $this->registerCitizen($request),
            'verify_citizen_otp' => $this->verifyCitizenOtp($request),
            'login_citizen' => $this->loginCitizen($request),
            'complete_citizen_profile' => $this->completeCitizenProfile($request),
            'citizen_profile_status' => $this->citizenProfileStatus(),
            'list_pending_profile_reviews' => $this->listPendingProfileReviews(),
            'approve_citizen_profile' => $this->approveCitizenProfile(),
            'reject_citizen_profile' => $this->rejectCitizenProfile($request),
            'citizen_me' => $this->citizenMe(),
            'login_employee' => $this->loginEmployee($request),
            'employee_me' => $this->employeeMe(),
            'login_admin' => $this->loginAdmin($request),
            'admin_me' => $this->adminMe(),
            'license_types' => $this->licenseTypes(),
            'service_types' => $this->serviceTypes(),
            'create_application' => $this->createApplication($request),
            'list_applications' => $this->listApplications(),
            'show_application' => $this->showApplication(),
            'admin_application_status_history' => $this->adminApplicationStatusHistory(),
            'required_documents' => $this->requiredDocuments(),
            'list_documents' => $this->listDocuments(),
            'upload_document' => $this->uploadDocument($request),
            'upload_sample_document' => $this->uploadSampleDocument($request),
            'submit_documents' => $this->submitDocuments(),
            'pending_document_reviews' => $this->pendingDocumentReviews(),
            'approve_pending_document' => $this->approvePendingDocument(),
            'reject_pending_document' => $this->rejectPendingDocument($request),
            'approve_all_documents' => $this->approveAllDocuments(),
            'application_fee' => $this->applicationFee(),
            'list_payments' => $this->listPayments(),
            'create_payment' => $this->createPayment(),
            'confirm_mock_payment' => $this->confirmMockPayment(),
            'payment_status' => $this->paymentStatus(),
            'available_tests' => $this->availableTests(),
            'appointment_slots' => $this->appointmentSlots($request),
            'book_appointment' => $this->bookAppointment($request),
            'list_appointments' => $this->listAppointments(),
            'reschedule_appointment' => $this->rescheduleAppointment($request),
            'cancel_appointment' => $this->cancelAppointment(),
            'list_test_results' => $this->listTestResults(),
            'record_test_result' => $this->recordTestResult($request),
            'quick_pass_test' => $this->quickPassTest($request),
            'list_licenses' => $this->listLicenses(),
            'show_license' => $this->showLicense(),
            'renew_license' => $this->renewLicense(),
            'replacement_license' => $this->replacementLicense($request),
            'unblock_license_request' => $this->unblockLicenseRequest(),
            'issue_license' => $this->issueLicense($request),
            'list_fines' => $this->listFines(),
            'admin_list_fines' => $this->adminListFines(),
            'create_fine' => $this->createFine($request),
            'update_fine' => $this->updateFine($request),
            'block_license' => $this->blockLicense($request),
            'unblock_license' => $this->unblockLicense($request),
            'list_notifications' => $this->listNotifications($request),
            'mark_notification_read' => $this->markNotificationRead(),
            'reports_overview' => $this->reportsOverview(),
            'audit_logs' => $this->auditLogs(),
            'ai_agent_message' => $this->aiAgentMessage($request),
            'ai_agent_confirm' => $this->aiAgentConfirm(),
            'ai_agent_cancel' => $this->aiAgentCancel(),
            'ai_agent_sessions' => $this->aiAgentSessions(),
            'ai_agent_show_session' => $this->aiAgentShowSession(),
            default => $this->recordResult('unknown', 'POST', '/dev-dashboard', 400, [
                'success' => false,
                'message' => "Unknown action: {$action}",
            ]),
        };

        return $this->redirectWithResult($result);
    }

    public function resetSession(): RedirectResponse
    {
        foreach (self::SESSION_KEYS as $key) {
            Session::forget($key);
        }

        Session::forget('dev_last_response');

        return $this->redirectToDashboard('Dashboard session cleared.');
    }

    private function runScenario(string $name, Request $request): RedirectResponse
    {
        $steps = match ($name) {
            'prepare_citizen' => [
                ['register_citizen', fn () => $this->registerCitizen($request)],
                ['verify_citizen_otp', fn () => $this->verifyCitizenOtp($request)],
                ['login_citizen', fn () => $this->loginCitizen($request)],
                ['complete_citizen_profile', fn () => $this->completeCitizenProfile($request)],
            ],
            'prepare_approved_citizen' => [
                ['register_citizen', fn () => $this->registerCitizen($request)],
                ['verify_citizen_otp', fn () => $this->verifyCitizenOtp($request)],
                ['login_citizen', fn () => $this->loginCitizen($request)],
                ['complete_citizen_profile', fn () => $this->completeCitizenProfile($request)],
                ['login_employee', fn () => $this->loginEmployee($request)],
                ['approve_citizen_profile', fn () => $this->approveCitizenProfile()],
                ['login_citizen', fn () => $this->loginCitizen($request)],
            ],
            'prepare_new_application' => [
                ['license_types', fn () => $this->licenseTypes()],
                ['service_types', fn () => $this->serviceTypes()],
                ['create_application', fn () => $this->createApplication($request)],
            ],
            'approve_all_documents' => [
                ['login_employee', fn () => $this->loginEmployee($request)],
                ['approve_all_documents', fn () => $this->approveAllDocuments()],
            ],
            'complete_mock_payment' => [
                ['application_fee', fn () => $this->applicationFee()],
                ['create_payment', fn () => $this->createPayment()],
                ['confirm_mock_payment', fn () => $this->confirmMockPayment()],
            ],
            'pass_current_test' => [
                ['available_tests', fn () => $this->availableTests()],
                ['appointment_slots', fn () => $this->appointmentSlots($request)],
                ['book_appointment', fn () => $this->bookAppointment($request)],
                ['login_employee', fn () => $this->loginEmployee($request)],
                ['quick_pass_test', fn () => $this->quickPassTest($request)],
            ],
            'issue_license' => [
                ['login_employee', fn () => $this->loginEmployee($request)],
                ['issue_license', fn () => $this->issueLicense($request)],
                ['list_licenses', fn () => $this->listLicenses()],
            ],
            'ai_create_application' => [
                ['ai_agent_message', fn () => $this->aiAgentMessage($request->merge(['message' => 'بدي رخصة جديدة']))],
                ['ai_agent_message', fn () => $this->aiAgentMessage($request->merge(['message' => 'رخصة خاصة']))],
                ['ai_agent_confirm', fn () => $this->aiAgentConfirm()],
            ],
            default => null,
        };

        if ($steps === null) {
            return $this->redirectWithError("Unknown scenario: {$name}");
        }

        $executed = [];

        foreach ($steps as [$label, $callback]) {
            $result = $callback();
            $executed[] = $result;

            if (! ($result['success'] ?? false)) {
                $result['scenario'] = $name;
                $result['scenario_steps'] = $executed;

                return $this->redirectWithResult($result);
            }
        }

        $last = end($executed) ?: [];
        $last['scenario'] = $name;
        $last['scenario_steps'] = $executed;
        $last['action'] = "scenario_{$name}";

        return $this->redirectWithResult($last);
    }

    private function ping(): array
    {
        return $this->apiGet('/ping');
    }

    private function refreshApplication(): array
    {
        $id = session('application_id');
        if (! $id) {
            return $this->recordResult('refresh_application', 'GET', '/api/applications/{id}', 422, [
                'success' => false,
                'message' => 'application_id is missing in session.',
            ]);
        }

        $result = $this->apiGet("/applications/{$id}", 'citizen');
        $this->hydrateApplicationFromResponse($result);

        return $result;
    }

    private function registerCitizen(Request $request): array
    {
        $email = $request->input('citizen_email') ?: 'testcitizen+'.time().'@example.com';

        $result = $this->apiPost('/auth/register', [
            'name' => 'Test Citizen',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        Session::put('citizen_email', $email);
        Session::put('citizen_password', 'password123');

        return $this->withSaved($result, ['citizen_email' => $email]);
    }

    private function verifyCitizenOtp(Request $request): array
    {
        $email = $request->input('citizen_email') ?: session('citizen_email');
        $code = $request->input('otp_code') ?: config('otp.fixed_code') ?: '123456';

        if (! $email) {
            return $this->recordResult('verify_citizen_otp', 'POST', '/api/auth/verify-otp', 422, [
                'success' => false,
                'message' => 'citizen_email is missing. Register a citizen first.',
            ]);
        }

        $result = $this->apiPost('/auth/verify-otp', [
            'email' => $email,
            'code' => $code,
            'purpose' => 'register',
        ]);

        $this->storeAuthFromResponse($result, 'citizen');

        return $this->withSaved($result, ['citizen_email' => $email, 'otp_code' => $code]);
    }

    private function loginCitizen(Request $request): array
    {
        $email = $request->input('citizen_email') ?: session('citizen_email');
        $password = $request->input('citizen_password') ?: session('citizen_password') ?: 'password123';

        if (! $email) {
            return $this->recordResult('login_citizen', 'POST', '/api/auth/login', 422, [
                'success' => false,
                'message' => 'citizen_email is missing.',
            ]);
        }

        $result = $this->apiPost('/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        Session::put('citizen_email', $email);
        Session::put('citizen_password', $password);
        $this->storeAuthFromResponse($result, 'citizen');

        return $result;
    }

    private function completeCitizenProfile(Request $request): array
    {
        $result = $this->apiPut('/profile/complete', [
            'name' => $request->input('profile_name', 'Test Citizen'),
            'national_id' => $request->input('national_id', 'NID'.time().rand(100, 999)),
            'birth_date' => $request->input('birth_date', '1990-01-01'),
            'governorate' => $request->input('governorate', 'Damascus'),
            'address' => $request->input('address', 'Test Address'),
        ], 'citizen');

        $data = $this->jsonData($result);
        if (is_array($data) && ! empty($data['profile_status'])) {
            Session::put('citizen_profile_status', $data['profile_status']);
        }

        return $result;
    }

    private function citizenProfileStatus(): array
    {
        return $this->apiGet('/profile/status', 'citizen');
    }

    private function listPendingProfileReviews(): array
    {
        return $this->apiGet('/admin/profile-reviews?status=pending_review', 'employee');
    }

    private function approveCitizenProfile(): array
    {
        $citizenId = session('citizen_user_id');
        if (! $citizenId) {
            return $this->recordResult('approve_citizen_profile', 'POST', '/admin/profile-reviews/{user}/approve', 400, [
                'success' => false,
                'message' => 'citizen_user_id is required. Login or complete citizen profile first.',
            ], false);
        }

        $result = $this->apiPost("/admin/profile-reviews/{$citizenId}/approve", [], 'employee');
        $data = $this->jsonData($result);
        if (is_array($data) && ($data['profile_status'] ?? null) === 'approved') {
            Session::put('citizen_profile_status', 'approved');
        }

        return $result;
    }

    private function rejectCitizenProfile(Request $request): array
    {
        $citizenId = session('citizen_user_id');
        if (! $citizenId) {
            return $this->recordResult('reject_citizen_profile', 'POST', '/admin/profile-reviews/{user}/reject', 400, [
                'success' => false,
                'message' => 'citizen_user_id is required. Login or complete citizen profile first.',
            ], false);
        }

        return $this->apiPost("/admin/profile-reviews/{$citizenId}/reject", [
            'rejection_reason' => $request->input('profile_rejection_reason', 'بيانات غير مكتملة للاختبار'),
        ], 'employee');
    }

    private function citizenMe(): array
    {
        return $this->apiGet('/auth/me', 'citizen');
    }

    private function loginEmployee(Request $request): array
    {
        return $this->loginStaff($request, 'employee');
    }

    private function employeeMe(): array
    {
        return $this->apiGet('/auth/me', 'employee');
    }

    private function loginAdmin(Request $request): array
    {
        return $this->loginStaff($request, 'admin');
    }

    private function adminMe(): array
    {
        return $this->apiGet('/auth/me', 'admin');
    }

    private function loginStaff(Request $request, string $type): array
    {
        $login = $request->input("{$type}_login") ?: session("{$type}_login") ?: $this->credentialDefaults()["{$type}_login"];
        $password = $request->input("{$type}_password") ?: session("{$type}_password") ?: 'password';

        Session::put("{$type}_login", $login);
        Session::put("{$type}_password", $password);

        $payload = ['password' => $password];
        if (str_contains($login, '@')) {
            $payload['email'] = $login;
        } else {
            $payload['identifier'] = $login;
        }

        $result = $this->apiPost('/auth/login', $payload);
        $this->storeAuthFromResponse($result, $type);

        return $result;
    }

    private function licenseTypes(): array
    {
        $result = $this->apiGet('/license-types');
        $types = $this->jsonData($result);
        if (is_array($types) && isset($types[0]['id'])) {
            Session::put('license_type_id', $types[0]['id']);
        }

        return $this->withSaved($result, ['license_type_id' => session('license_type_id')]);
    }

    private function serviceTypes(): array
    {
        $result = $this->apiGet('/service-types');
        $types = $this->jsonData($result);
        if (is_array($types)) {
            foreach ($types as $type) {
                if (($type['code'] ?? null) === 'new_license') {
                    Session::put('service_type_id', $type['id']);
                    break;
                }
            }
            if (! session('service_type_id') && isset($types[0]['id'])) {
                Session::put('service_type_id', $types[0]['id']);
            }
        }

        return $this->withSaved($result, ['service_type_id' => session('service_type_id')]);
    }

    private function createApplication(Request $request): array
    {
        $licenseTypeId = $request->input('license_type_id') ?: session('license_type_id');
        $serviceTypeId = $request->input('service_type_id') ?: session('service_type_id');

        if (! $licenseTypeId || ! $serviceTypeId) {
            return $this->recordResult('create_application', 'POST', '/api/applications', 422, [
                'success' => false,
                'message' => 'license_type_id and service_type_id are required.',
            ]);
        }

        $result = $this->apiPost('/applications', [
            'license_type_id' => (int) $licenseTypeId,
            'service_type_id' => (int) $serviceTypeId,
        ], 'citizen');

        $this->hydrateApplicationFromResponse($result);

        return $result;
    }

    private function listApplications(): array
    {
        return $this->apiGet('/applications', 'citizen');
    }

    private function showApplication(): array
    {
        $id = session('application_id');
        if (! $id) {
            return $this->missingId('show_application', '/api/applications/{id}');
        }

        $result = $this->apiGet("/applications/{$id}", 'citizen');
        $this->hydrateApplicationFromResponse($result);

        return $result;
    }

    private function adminApplicationStatusHistory(): array
    {
        $id = session('application_id');
        if (! $id) {
            return $this->missingId('admin_application_status_history', '/api/admin/application-status-histories/{id}');
        }

        return $this->apiGet("/admin/application-status-histories/{$id}", 'admin');
    }

    private function requiredDocuments(): array
    {
        $id = session('application_id');
        if (! $id) {
            return $this->missingId('required_documents', '/api/applications/{id}/required-documents');
        }

        $result = $this->apiGet("/applications/{$id}/required-documents", 'citizen');
        $docs = $this->jsonData($result);
        $ids = [];
        if (is_array($docs)) {
            foreach ($docs as $doc) {
                if (isset($doc['id'])) {
                    $ids[] = $doc['id'];
                }
            }
            if ($ids !== []) {
                Session::put('required_document_id', $ids[0]);
                Session::put('required_document_ids', $ids);
            }
        }

        return $this->withSaved($result, [
            'required_document_id' => session('required_document_id'),
            'required_document_ids' => session('required_document_ids'),
        ]);
    }

    private function listDocuments(): array
    {
        $id = session('application_id');
        if (! $id) {
            return $this->missingId('list_documents', '/api/applications/{id}/documents');
        }

        return $this->apiGet("/applications/{$id}/documents", 'citizen');
    }

    private function uploadDocument(Request $request): array
    {
        $applicationId = session('application_id');
        $requiredDocumentId = $request->input('required_document_id') ?: session('required_document_id');
        $file = $request->file('document_file');

        if (! $applicationId || ! $requiredDocumentId || ! $file instanceof UploadedFile) {
            return $this->recordResult('upload_document', 'POST', '/api/applications/{id}/documents', 422, [
                'success' => false,
                'message' => 'application_id, required_document_id, and document_file are required.',
            ]);
        }

        $result = $this->apiPostMultipart(
            "/applications/{$applicationId}/documents",
            ['required_document_id' => (int) $requiredDocumentId],
            $file,
            'citizen'
        );

        $documentId = data_get($this->jsonData($result), 'id');
        if ($documentId) {
            Session::put('document_id', $documentId);
        }

        return $this->withSaved($result, ['document_id' => session('document_id')]);
    }

    private function uploadSampleDocument(Request $request): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dlms_sample_');
        $path = $tmp.'.pdf';
        rename($tmp, $path);
        file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

        $uploaded = new UploadedFile(
            $path,
            'sample-document.pdf',
            'application/pdf',
            null,
            true
        );

        $request->files->set('document_file', $uploaded);

        return $this->uploadDocument($request);
    }

    private function submitDocuments(): array
    {
        $id = session('application_id');
        if (! $id) {
            return $this->missingId('submit_documents', '/api/applications/{id}/submit-documents');
        }

        $result = $this->apiPost("/applications/{$id}/submit-documents", [], 'citizen');
        $this->hydrateApplicationFromResponse($result);

        return $result;
    }

    private function pendingDocumentReviews(): array
    {
        $result = $this->apiGet('/admin/documents/pending-review', 'employee');
        $items = data_get($this->jsonData($result), 'items', $this->jsonData($result));
        if (is_array($items) && isset($items[0]['id'])) {
            Session::put('pending_document_id', $items[0]['id']);
        }

        return $this->withSaved($result, ['pending_document_id' => session('pending_document_id')]);
    }

    private function approvePendingDocument(): array
    {
        $docId = session('pending_document_id');
        if (! $docId) {
            return $this->missingId('approve_pending_document', '/api/admin/documents/{id}/approve');
        }

        return $this->apiPost("/admin/documents/{$docId}/approve", [], 'employee');
    }

    private function rejectPendingDocument(Request $request): array
    {
        $docId = session('pending_document_id');
        if (! $docId) {
            return $this->missingId('reject_pending_document', '/api/admin/documents/{id}/reject');
        }

        return $this->apiPost("/admin/documents/{$docId}/reject", [
            'rejection_reason' => $request->input('rejection_reason', 'Rejected from dev dashboard for testing.'),
        ], 'employee');
    }

    private function approveAllDocuments(): array
    {
        $applicationId = session('application_id');
        if (! $applicationId) {
            return $this->missingId('approve_all_documents', '/api/admin/documents/pending-review');
        }

        if (! session('employee_token')) {
            $login = $this->loginEmployee(request());
            if (! ($login['success'] ?? false)) {
                return $login;
            }
        }

        $pending = $this->apiGet('/admin/documents/pending-review', 'employee');
        $items = data_get($this->jsonData($pending), 'items', $this->jsonData($pending));
        $approved = [];

        if (! is_array($items)) {
            return $this->withSaved($pending, ['approved_count' => 0]);
        }

        foreach ($items as $item) {
            $appId = data_get($item, 'application.id', data_get($item, 'application_id'));
            if ((int) $appId !== (int) $applicationId) {
                continue;
            }

            $docId = $item['id'] ?? null;
            if (! $docId) {
                continue;
            }

            $approved[] = $this->apiPost("/admin/documents/{$docId}/approve", [], 'employee');
        }

        return $this->recordResult(
            'approve_all_documents',
            'POST',
            '/api/admin/documents/{id}/approve (batch)',
            200,
            [
                'success' => true,
                'message' => 'Batch approve completed.',
                'data' => ['approved' => $approved, 'count' => count($approved)],
            ]
        );
    }

    private function applicationFee(): array
    {
        $id = session('application_id');
        if (! $id) {
            return $this->missingId('application_fee', '/api/applications/{id}/fee');
        }

        return $this->apiGet("/applications/{$id}/fee", 'citizen');
    }

    private function listPayments(): array
    {
        $id = session('application_id');
        if (! $id) {
            return $this->missingId('list_payments', '/api/applications/{id}/payments');
        }

        $result = $this->apiGet("/applications/{$id}/payments", 'citizen');
        $items = data_get($this->jsonData($result), 'items', $this->jsonData($result));
        if (is_array($items) && isset($items[0]['id'])) {
            Session::put('payment_id', $items[0]['id']);
            if (! empty($items[0]['checkout_url'])) {
                Session::put('checkout_url', $items[0]['checkout_url']);
            }
        }

        return $this->withSaved($result, [
            'payment_id' => session('payment_id'),
            'checkout_url' => session('checkout_url'),
        ]);
    }

    private function createPayment(): array
    {
        $applicationId = session('application_id');
        if (! $applicationId) {
            return $this->missingId('create_payment', '/api/applications/{id}/payments');
        }

        $result = $this->apiPost("/applications/{$applicationId}/payments", [], 'citizen');
        $data = $this->jsonData($result);
        if (is_array($data)) {
            if (! empty($data['id'])) {
                Session::put('payment_id', $data['id']);
            }
            if (! empty($data['checkout_url'])) {
                Session::put('checkout_url', $data['checkout_url']);
            }
        }

        return $this->withSaved($result, [
            'payment_id' => session('payment_id'),
            'checkout_url' => session('checkout_url'),
        ]);
    }

    private function confirmMockPayment(): array
    {
        $applicationId = session('application_id');
        $paymentId = session('payment_id');
        if (! $applicationId || ! $paymentId) {
            return $this->recordResult('confirm_mock_payment', 'POST', '/api/applications/{id}/payments/{payment}/confirm', 422, [
                'success' => false,
                'message' => 'application_id and payment_id are required.',
            ]);
        }

        return $this->apiPost("/applications/{$applicationId}/payments/{$paymentId}/confirm", [], 'citizen');
    }

    private function paymentStatus(): array
    {
        $applicationId = session('application_id');
        $paymentId = session('payment_id');
        if (! $applicationId || ! $paymentId) {
            return $this->recordResult('payment_status', 'GET', '/api/applications/{id}/payments/{payment}/status', 422, [
                'success' => false,
                'message' => 'application_id and payment_id are required.',
            ]);
        }

        return $this->apiGet("/applications/{$applicationId}/payments/{$paymentId}/status", 'citizen');
    }

    private function availableTests(): array
    {
        $id = session('application_id');
        if (! $id) {
            return $this->missingId('available_tests', '/api/applications/{id}/available-tests');
        }

        $result = $this->apiGet("/applications/{$id}/available-tests", 'citizen');
        $tests = $this->jsonData($result);
        if (is_array($tests) && isset($tests[0]['test_type_id'])) {
            Session::put('test_type_id', $tests[0]['test_type_id']);
        }

        return $this->withSaved($result, ['test_type_id' => session('test_type_id')]);
    }

    private function appointmentSlots(Request $request): array
    {
        $testTypeId = $request->input('test_type_id') ?: session('test_type_id') ?: 1;
        Session::put('test_type_id', $testTypeId);

        $result = $this->apiGet('/appointment-slots?test_type_id='.$testTypeId, 'citizen');
        $slots = data_get($this->jsonData($result), 'items', $this->jsonData($result));
        if (is_array($slots) && isset($slots[0]['id'])) {
            Session::put('appointment_slot_id', $slots[0]['id']);
        }

        return $this->withSaved($result, [
            'test_type_id' => $testTypeId,
            'appointment_slot_id' => session('appointment_slot_id'),
        ]);
    }

    private function bookAppointment(Request $request): array
    {
        $applicationId = session('application_id');
        $slotId = $request->input('appointment_slot_id') ?: session('appointment_slot_id');
        if (! $applicationId || ! $slotId) {
            return $this->recordResult('book_appointment', 'POST', '/api/applications/{id}/appointments', 422, [
                'success' => false,
                'message' => 'application_id and appointment_slot_id are required.',
            ]);
        }

        $result = $this->apiPost("/applications/{$applicationId}/appointments", [
            'appointment_slot_id' => (int) $slotId,
        ], 'citizen');

        $appointmentId = data_get($this->jsonData($result), 'id');
        if ($appointmentId) {
            Session::put('appointment_id', $appointmentId);
        }

        return $this->withSaved($result, ['appointment_id' => session('appointment_id')]);
    }

    private function listAppointments(): array
    {
        $id = session('application_id');
        if (! $id) {
            return $this->missingId('list_appointments', '/api/applications/{id}/appointments');
        }

        $result = $this->apiGet("/applications/{$id}/appointments", 'citizen');
        $items = data_get($this->jsonData($result), 'items', $this->jsonData($result));
        if (is_array($items) && isset($items[0]['id'])) {
            Session::put('appointment_id', $items[0]['id']);
        }

        return $this->withSaved($result, ['appointment_id' => session('appointment_id')]);
    }

    private function rescheduleAppointment(Request $request): array
    {
        $appointmentId = session('appointment_id');
        $slotId = $request->input('appointment_slot_id') ?: session('appointment_slot_id');
        if (! $appointmentId || ! $slotId) {
            return $this->recordResult('reschedule_appointment', 'PUT', '/api/appointments/{id}/reschedule', 422, [
                'success' => false,
                'message' => 'appointment_id and appointment_slot_id are required.',
            ]);
        }

        return $this->apiPut("/appointments/{$appointmentId}/reschedule", [
            'appointment_slot_id' => (int) $slotId,
        ], 'citizen');
    }

    private function cancelAppointment(): array
    {
        $appointmentId = session('appointment_id');
        if (! $appointmentId) {
            return $this->missingId('cancel_appointment', '/api/appointments/{id}/cancel');
        }

        return $this->apiDelete("/appointments/{$appointmentId}/cancel", [], 'citizen');
    }

    private function listTestResults(): array
    {
        $id = session('application_id');
        if (! $id) {
            return $this->missingId('list_test_results', '/api/applications/{id}/test-results');
        }

        return $this->apiGet("/applications/{$id}/test-results", 'citizen');
    }

    private function recordTestResult(Request $request): array
    {
        $appointmentId = session('appointment_id');
        if (! $appointmentId) {
            return $this->missingId('record_test_result', '/api/admin/test-appointments/{id}/record-result');
        }

        if (! session('employee_token')) {
            $login = $this->loginEmployee($request);
            if (! ($login['success'] ?? false)) {
                return $login;
            }
        }

        return $this->apiPost("/admin/test-appointments/{$appointmentId}/record-result", [
            'result' => $request->input('test_result', 'passed'),
            'notes' => $request->input('test_notes', 'Recorded from dev dashboard.'),
        ], 'employee');
    }

    private function quickPassTest(Request $request): array
    {
        return $this->recordTestResult($request->merge(['test_result' => 'passed']));
    }

    private function listLicenses(): array
    {
        $result = $this->apiGet('/licenses', 'citizen');
        $items = data_get($this->jsonData($result), 'items', $this->jsonData($result));
        if (is_array($items) && isset($items[0]['id'])) {
            Session::put('license_id', $items[0]['id']);
        }

        return $this->withSaved($result, ['license_id' => session('license_id')]);
    }

    private function showLicense(): array
    {
        $licenseId = session('license_id');
        if (! $licenseId) {
            return $this->missingId('show_license', '/api/licenses/{id}');
        }

        return $this->apiGet("/licenses/{$licenseId}", 'citizen');
    }

    private function renewLicense(): array
    {
        $licenseId = session('license_id');
        if (! $licenseId) {
            return $this->missingId('renew_license', '/api/licenses/{id}/renew');
        }

        return $this->apiPost("/licenses/{$licenseId}/renew", [], 'citizen');
    }

    private function replacementLicense(Request $request): array
    {
        $licenseId = session('license_id');
        if (! $licenseId) {
            return $this->missingId('replacement_license', '/api/licenses/{id}/replacement');
        }

        return $this->apiPost("/licenses/{$licenseId}/replacement", [
            'type' => $request->input('replacement_type', 'lost'),
        ], 'citizen');
    }

    private function unblockLicenseRequest(): array
    {
        $licenseId = session('license_id');
        if (! $licenseId) {
            return $this->missingId('unblock_license_request', '/api/licenses/{id}/unblock-request');
        }

        return $this->apiPost("/licenses/{$licenseId}/unblock-request", [], 'citizen');
    }

    private function issueLicense(Request $request): array
    {
        $applicationId = session('application_id');
        if (! $applicationId) {
            return $this->missingId('issue_license', '/api/admin/applications/{id}/issue-license');
        }

        $tokenType = $request->input('issue_token_type', 'employee');

        $result = $this->apiPost("/admin/applications/{$applicationId}/issue-license", [], $tokenType);
        $licenseId = data_get($this->jsonData($result), 'id');
        if ($licenseId) {
            Session::put('license_id', $licenseId);
        }

        return $this->withSaved($result, ['license_id' => session('license_id')]);
    }

    private function listFines(): array
    {
        $result = $this->apiGet('/fines', 'citizen');
        $items = data_get($this->jsonData($result), 'items', $this->jsonData($result));
        if (is_array($items) && isset($items[0]['id'])) {
            Session::put('fine_id', $items[0]['id']);
        }

        return $this->withSaved($result, ['fine_id' => session('fine_id')]);
    }

    private function adminListFines(): array
    {
        return $this->apiGet('/admin/fines', 'admin');
    }

    private function createFine(Request $request): array
    {
        $citizenId = $request->input('fine_citizen_id') ?: session('citizen_user_id');
        if (! $citizenId) {
            return $this->recordResult('create_fine', 'POST', '/api/admin/fines', 422, [
                'success' => false,
                'message' => 'citizen_user_id is required (login citizen or set fine_citizen_id).',
            ]);
        }

        $result = $this->apiPost('/admin/fines', [
            'citizen_id' => (int) $citizenId,
            'license_id' => $request->input('fine_license_id') ?: session('license_id'),
            'amount' => (float) ($request->input('fine_amount', 100)),
            'reason' => $request->input('fine_reason', 'Dev dashboard test fine'),
        ], 'admin');

        $fineId = data_get($this->jsonData($result), 'id');
        if ($fineId) {
            Session::put('fine_id', $fineId);
        }

        return $this->withSaved($result, ['fine_id' => session('fine_id')]);
    }

    private function updateFine(Request $request): array
    {
        $fineId = session('fine_id');
        if (! $fineId) {
            return $this->missingId('update_fine', '/api/admin/fines/{id}');
        }

        return $this->apiPut("/admin/fines/{$fineId}", array_filter([
            'amount' => $request->input('fine_amount'),
            'reason' => $request->input('fine_reason'),
            'status' => $request->input('fine_status'),
        ], fn ($v) => $v !== null && $v !== ''), 'admin');
    }

    private function blockLicense(Request $request): array
    {
        $licenseId = session('license_id');
        if (! $licenseId) {
            return $this->missingId('block_license', '/api/admin/licenses/{id}/block');
        }

        return $this->apiPost("/admin/licenses/{$licenseId}/block", [
            'reason' => $request->input('block_reason', 'Blocked from dev dashboard.'),
        ], 'admin');
    }

    private function unblockLicense(Request $request): array
    {
        $licenseId = session('license_id');
        if (! $licenseId) {
            return $this->missingId('unblock_license', '/api/admin/licenses/{id}/unblock');
        }

        return $this->apiPost("/admin/licenses/{$licenseId}/unblock", [], 'admin');
    }

    private function listNotifications(Request $request): array
    {
        $query = $request->boolean('unread_only') ? '?unread_only=true' : '';
        $result = $this->apiGet('/notifications'.$query, 'citizen');
        $items = data_get($this->jsonData($result), 'items', $this->jsonData($result));
        if (is_array($items) && isset($items[0]['id'])) {
            Session::put('notification_id', $items[0]['id']);
        }

        return $this->withSaved($result, ['notification_id' => session('notification_id')]);
    }

    private function markNotificationRead(): array
    {
        $notificationId = session('notification_id');
        if (! $notificationId) {
            return $this->missingId('mark_notification_read', '/api/notifications/{id}/read');
        }

        return $this->apiPut("/notifications/{$notificationId}/read", [], 'citizen');
    }

    private function reportsOverview(): array
    {
        return $this->apiGet('/admin/reports/overview', 'admin');
    }

    private function auditLogs(): array
    {
        return $this->apiGet('/admin/audit-logs', 'admin');
    }

    private function aiAgentMessage(Request $request): array
    {
        $message = $request->input('message') ?: session('ai_agent_message') ?: 'مرحبا';
        Session::put('ai_agent_message', $message);

        $payload = ['message' => $message];
        if ($sessionId = session('ai_agent_session_id')) {
            $payload['session_id'] = (int) $sessionId;
        }

        $result = $this->apiPost('/ai-agent/message', $payload, 'citizen');
        $data = $this->jsonData($result);
        if (is_array($data)) {
            if (! empty($data['session_id'])) {
                Session::put('ai_agent_session_id', $data['session_id']);
            }
            $actionId = data_get($data, 'pending_action.id');
            if ($actionId) {
                Session::put('ai_agent_action_id', $actionId);
            }
            if (! empty($data['result']['application_id'])) {
                Session::put('application_id', $data['result']['application_id']);
            }
        }

        return $this->withSaved($result, [
            'ai_agent_session_id' => session('ai_agent_session_id'),
            'ai_agent_action_id' => session('ai_agent_action_id'),
        ]);
    }

    private function aiAgentConfirm(): array
    {
        $actionId = session('ai_agent_action_id');
        if (! $actionId) {
            return $this->missingId('ai_agent_confirm', '/api/ai-agent/actions/{id}/confirm');
        }

        $result = $this->apiPost("/ai-agent/actions/{$actionId}/confirm", [], 'citizen');
        $applicationId = data_get($this->jsonData($result), 'result.application_id');
        if ($applicationId) {
            Session::put('application_id', $applicationId);
        }

        return $result;
    }

    private function aiAgentCancel(): array
    {
        $actionId = session('ai_agent_action_id');
        if (! $actionId) {
            return $this->missingId('ai_agent_cancel', '/api/ai-agent/actions/{id}/cancel');
        }

        return $this->apiPost("/ai-agent/actions/{$actionId}/cancel", [], 'citizen');
    }

    private function aiAgentSessions(): array
    {
        return $this->apiGet('/ai-agent/sessions', 'citizen');
    }

    private function aiAgentShowSession(): array
    {
        $sessionId = session('ai_agent_session_id');
        if (! $sessionId) {
            return $this->missingId('ai_agent_show_session', '/api/ai-agent/sessions/{id}');
        }

        return $this->apiGet("/ai-agent/sessions/{$sessionId}", 'citizen');
    }

    private function apiGet(string $path, ?string $tokenType = null): array
    {
        return $this->sendRequest('GET', $path, [], $tokenType);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function apiPost(string $path, array $data = [], ?string $tokenType = null): array
    {
        return $this->sendRequest('POST', $path, $data, $tokenType);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function apiPut(string $path, array $data = [], ?string $tokenType = null): array
    {
        return $this->sendRequest('PUT', $path, $data, $tokenType);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function apiDelete(string $path, array $data = [], ?string $tokenType = null): array
    {
        return $this->sendRequest('DELETE', $path, $data, $tokenType);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function apiPostMultipart(string $path, array $fields, UploadedFile $file, ?string $tokenType = null): array
    {
        $uri = $this->normalizeApiPath($path);
        $server = $this->apiServerHeaders($tokenType);

        $request = Request::create($uri, 'POST', $fields, [], ['file' => $file], $server);
        $response = app()->handle($request);

        return $this->formatHttpResponse('upload_document', 'POST', $path, $response);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sendRequest(string $method, string $path, array $data, ?string $tokenType): array
    {
        $method = strtoupper($method);
        $uri = $this->normalizeApiPath($path);
        $server = $this->apiServerHeaders($tokenType);
        $content = null;

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $data !== []) {
            $content = json_encode($data);
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $parameters = $method === 'GET' ? $data : [];
        $request = Request::create($uri, $method, $parameters, [], [], $server, $content);
        $response = app()->handle($request);

        $action = str_replace(['/', '{', '}'], ['_', '', ''], trim(parse_url($path, PHP_URL_PATH) ?: $path, '/'));
        $action = str_replace('?', '_', $action);

        return $this->formatHttpResponse($action, $method, $path, $response);
    }

    /**
     * @return array<string, string>
     */
    private function apiServerHeaders(?string $tokenType): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        $token = $this->tokenFor($tokenType);
        if ($token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $server;
    }

    private function normalizeApiPath(string $path): string
    {
        $path = str_starts_with($path, '/') ? $path : '/'.$path;
        if (! str_starts_with($path, '/api')) {
            $path = '/api'.$path;
        }

        $query = parse_url($path, PHP_URL_QUERY);
        if ($query) {
            $base = strtok($path, '?');

            return $base.'?'.$query;
        }

        return $path;
    }

    private function tokenFor(?string $tokenType): ?string
    {
        if ($tokenType === null) {
            return null;
        }

        $token = session("{$tokenType}_token");

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function apiUrl(string $path): string
    {
        return url($this->normalizeApiPath($path));
    }

    private function formatHttpResponse(string $action, string $method, string $path, HttpResponse $response): array
    {
        $raw = $response->getContent();
        $body = json_decode($raw, true);
        if (! is_array($body)) {
            $body = ['raw' => $raw];
        }

        $success = $response->isSuccessful() && ($body['success'] ?? true);

        return $this->recordResult($action, $method, $path, $response->getStatusCode(), $body, $success);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function recordResult(
        string $action,
        string $method,
        string $path,
        int $status,
        array $body,
        ?bool $success = null,
    ): array {
        if ($success === null) {
            $success = $status >= 200 && $status < 300 && ($body['success'] ?? true);
        }

        return [
            'action' => $action,
            'method' => $method,
            'url' => $this->apiUrl($path),
            'path' => $path,
            'status' => $status,
            'success' => $success,
            'body' => $body,
            'saved' => $this->snapshotSavedVariables(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function withSaved(array $result, array $extra = []): array
    {
        $result['saved'] = array_merge($this->snapshotSavedVariables(), $extra);

        return $result;
    }

    private function missingId(string $action, string $path): array
    {
        return $this->recordResult($action, 'GET', $path, 422, [
            'success' => false,
            'message' => 'Required session ID is missing. Run prerequisite steps first.',
        ], false);
    }

    private function storeAuthFromResponse(array $result, string $type): void
    {
        $data = $this->jsonData($result);
        if (! is_array($data)) {
            return;
        }

        if (! empty($data['token'])) {
            Session::put("{$type}_token", $data['token']);
        }

        $userId = data_get($data, 'user.id');
        if ($userId) {
            Session::put("{$type}_user_id", $userId);
        }
    }

    private function hydrateApplicationFromResponse(array $result): void
    {
        $data = $this->jsonData($result);
        if (! is_array($data)) {
            return;
        }

        if (! empty($data['id'])) {
            Session::put('application_id', $data['id']);
        }
        if (! empty($data['application_number'])) {
            Session::put('application_number', $data['application_number']);
        }
        if (! empty($data['status'])) {
            Session::put('application_status', is_string($data['status']) ? $data['status'] : ($data['status']['value'] ?? $data['status']));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonData(array $result): ?array
    {
        $body = $result['body'] ?? [];
        $data = $body['data'] ?? null;

        if (is_array($data)) {
            return $data;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotSavedVariables(): array
    {
        $out = [];
        foreach (self::SESSION_KEYS as $key) {
            if (str_ends_with($key, '_token')) {
                $out[$key] = $this->tokenPreview(session($key));
            } else {
                $out[$key] = session($key);
            }
        }

        return $out;
    }

    private function tokenPreview(mixed $token): ?string
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        if (strlen($token) <= 12) {
            return substr($token, 0, 4).'…';
        }

        return substr($token, 0, 8).'…'.substr($token, -4);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStatusPanel(): array
    {
        return [
            'citizen_token' => session('citizen_token') ? 'exists' : 'missing',
            'employee_token' => session('employee_token') ? 'exists' : 'missing',
            'admin_token' => session('admin_token') ? 'exists' : 'missing',
            'citizen_token_preview' => $this->tokenPreview(session('citizen_token')),
            'employee_token_preview' => $this->tokenPreview(session('employee_token')),
            'admin_token_preview' => $this->tokenPreview(session('admin_token')),
            'application_id' => session('application_id'),
            'application_status' => session('application_status'),
            'application_number' => session('application_number'),
            'payment_id' => session('payment_id'),
            'appointment_id' => session('appointment_id'),
            'license_id' => session('license_id'),
            'ai_agent_session_id' => session('ai_agent_session_id'),
            'ai_agent_action_id' => session('ai_agent_action_id'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function credentialDefaults(): array
    {
        return [
            'employee_login' => session('employee_login', '0988888888'),
            'employee_password' => session('employee_password', 'password'),
            'admin_login' => session('admin_login', '0999999999'),
            'admin_password' => session('admin_password', 'password'),
            'citizen_email' => session('citizen_email', ''),
            'citizen_password' => session('citizen_password', 'password123'),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function redirectWithResult(array $result): RedirectResponse
    {
        Session::flash('dev_last_response', $result);

        return $this->redirectToDashboard();
    }

    /**
     * @return array{index: string, action: string, reset: string}
     */
    private function devDashboardRoutes(): array
    {
        return [
            'index' => route('dev-dashboard.index', absolute: false),
            'action' => route('dev-dashboard.action', absolute: false),
            'reset' => route('dev-dashboard.reset', absolute: false),
        ];
    }

    private function redirectToDashboard(?string $flashMessage = null): RedirectResponse
    {
        $redirect = redirect()->route('dev-dashboard.index');

        if ($flashMessage !== null) {
            $redirect->with('dev_flash', $flashMessage);
        }

        return $redirect;
    }

    private function redirectWithError(string $message): RedirectResponse
    {
        return $this->redirectWithResult($this->recordResult('error', 'N/A', '/dev-dashboard', 400, [
            'success' => false,
            'message' => $message,
        ], false));
    }
}
