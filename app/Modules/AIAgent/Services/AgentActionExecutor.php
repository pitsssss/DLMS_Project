<?php

namespace App\Modules\AIAgent\Services;

use App\Exceptions\ApiException;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Support\AgentSafetyRules;
use App\Modules\Applications\Resources\ApplicationResource;
use App\Modules\Applications\Services\ApplicationDocumentService;
use App\Modules\Applications\Services\ApplicationService;
use App\Modules\Auth\Services\ProfileService;
use App\Modules\Fines\Resources\FineResource;
use App\Modules\Fines\Services\FineService;
use App\Modules\Licenses\Resources\LicenseResource;
use App\Modules\Licenses\Services\LicenseService;

class AgentActionExecutor
{
    public function __construct(
        private readonly ApplicationService $applications,
        private readonly ApplicationDocumentService $documents,
        private readonly FineService $fines,
        private readonly LicenseService $licenses,
        private readonly ProfileService $profiles,
        private readonly AgentApplicationNextStepService $nextStepService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user, AIAgentAction $action): array
    {
        if (AgentSafetyRules::isAdminOnlyAction($action->action_name)) {
            throw new ApiException('This action requires an authorized employee.', 403);
        }

        if (! AgentSafetyRules::isPhase9bExecutable($action->action_name)) {
            throw new ApiException('This action cannot be executed yet. Please use the standard API endpoints.', 422);
        }

        if ($this->requiresApprovedProfile($action->action_name)) {
            $this->profiles->assertCanUseCitizenServices($user);
        }

        $arguments = is_array($action->arguments) ? $action->arguments : [];

        return match ($action->action_name) {
            'create_application' => $this->executeCreateApplication($user, $arguments),
            'get_application_status' => $this->executeGetApplicationStatus($user, $arguments),
            'get_application_next_step' => $this->executeGetApplicationNextStep($user, $arguments),
            'get_required_documents' => $this->executeGetRequiredDocuments($user, $arguments),
            'get_fines' => $this->executeGetFines($user),
            'get_licenses' => $this->executeGetLicenses($user),
            default => throw new ApiException('Unsupported AI agent action.', 422),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeCreateApplication(User $user, array $arguments): array
    {
        $licenseType = $this->resolveLicenseType($arguments);
        $serviceType = $this->resolveServiceType($arguments);

        $application = $this->applications->createDraft(
            $user,
            $licenseType->id,
            $serviceType->id
        );

        return [
            'application_id' => $application->id,
            'application_number' => $application->application_number,
            'status' => $application->status->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeGetApplicationStatus(User $user, array $arguments): array
    {
        $applicationId = $this->requireApplicationId($arguments);
        $application = $this->applications->getForCitizen($user, $applicationId);
        $application->loadMissing(['licenseType', 'serviceType']);

        $step = $this->nextStepService->nextStepForApplication($application);

        return array_merge(
            (new ApplicationResource($application))->resolve(),
            [
                'status_label_ar' => $step['status_label_ar'],
                'next_step_key' => $step['next_step_key'],
                'next_step_message' => $step['next_step_message'],
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeGetApplicationNextStep(User $user, array $arguments): array
    {
        $applicationId = $this->requireApplicationId($arguments);
        $application = $this->applications->getForCitizen($user, $applicationId);
        $application->loadMissing(['licenseType', 'serviceType']);

        $step = $this->nextStepService->nextStepForApplication($application);

        return [
            'application_id' => $application->id,
            'application_number' => $application->application_number,
            'status' => $step['status'],
            'status_label_ar' => $step['status_label_ar'],
            'next_step_key' => $step['next_step_key'],
            'next_step_message' => $step['next_step_message'],
            'suggested_action' => $step['suggested_action'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeGetRequiredDocuments(User $user, array $arguments): array
    {
        $applicationId = $this->requireApplicationId($arguments);
        $application = $this->applications->getForCitizen($user, $applicationId);
        $checklist = $this->documents->requiredChecklist($user, $applicationId);

        return [
            'application_id' => $applicationId,
            'application_number' => $application->application_number,
            'status' => $application->status->value,
            'required_documents' => $checklist,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeGetFines(User $user): array
    {
        $fines = $this->fines->listForCitizen($user);

        return [
            'items' => FineResource::collection($fines)->resolve(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeGetLicenses(User $user): array
    {
        $licenses = $this->licenses->listForCitizen($user);

        return [
            'items' => LicenseResource::collection($licenses)->resolve(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function requireApplicationId(array $arguments): int
    {
        $applicationId = $arguments['application_id'] ?? null;

        if (! is_numeric($applicationId) || (int) $applicationId < 1) {
            throw new ApiException('Application ID is required for this action.', 422, [
                'application_id' => ['The application_id argument is required.'],
            ]);
        }

        return (int) $applicationId;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveLicenseType(array $arguments): LicenseType
    {
        $code = trim((string) ($arguments['license_type_code'] ?? ''));

        if ($code === '') {
            throw new ApiException('License type is required.', 422, [
                'license_type_code' => ['The license_type_code argument is required.'],
            ]);
        }

        $licenseType = LicenseType::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($licenseType === null) {
            throw new ApiException('Invalid or inactive license type.', 422, [
                'license_type_code' => ['The selected license type is invalid.'],
            ]);
        }

        return $licenseType;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveServiceType(array $arguments): ServiceType
    {
        $code = trim((string) ($arguments['service_type_code'] ?? 'new_license'));

        $serviceType = ServiceType::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($serviceType === null) {
            throw new ApiException('Invalid or inactive service type.', 422, [
                'service_type_code' => ['The selected service type is invalid.'],
            ]);
        }

        return $serviceType;
    }

    private function requiresApprovedProfile(string $actionName): bool
    {
        return $actionName === 'create_application';
    }
}
