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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user, AIAgentAction $action): array
    {
        if (AgentSafetyRules::isAdminOnlyAction($action->action_name)) {
            throw new ApiException('messages.ai_agent.employee_required', 403);
        }

        if (! AgentSafetyRules::isPhase9bExecutable($action->action_name)) {
            throw new ApiException('messages.ai_agent.cannot_execute_yet', 422);
        }

        $arguments = is_array($action->arguments) ? $action->arguments : [];

        return match ($action->action_name) {
            'create_application' => $this->executeCreateApplication($user, $arguments),
            'get_application_status' => $this->executeGetApplicationStatus($user, $arguments),
            'get_required_documents' => $this->executeGetRequiredDocuments($user, $arguments),
            'get_fines' => $this->executeGetFines($user),
            'get_licenses' => $this->executeGetLicenses($user),
            default => throw new ApiException('messages.ai_agent.unsupported_action', 422),
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

        return (new ApplicationResource($application))->resolve();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeGetRequiredDocuments(User $user, array $arguments): array
    {
        $applicationId = $this->requireApplicationId($arguments);
        $checklist = $this->documents->requiredChecklist($user, $applicationId);

        return [
            'application_id' => $applicationId,
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
            throw new ApiException('messages.ai_agent.application_id_required', 422, [
                'application_id' => [__('messages.ai_agent.application_id_arg_required')],
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
            throw new ApiException('messages.ai_agent.license_type_required', 422, [
                'license_type_code' => [__('messages.ai_agent.license_type_arg_required')],
            ]);
        }

        $licenseType = LicenseType::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($licenseType === null) {
            throw new ApiException('messages.ai_agent.license_type_invalid', 422, [
                'license_type_code' => [__('messages.ai_agent.license_type_arg_invalid')],
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
            throw new ApiException('messages.ai_agent.service_type_invalid', 422, [
                'service_type_code' => [__('messages.ai_agent.service_type_arg_invalid')],
            ]);
        }

        return $serviceType;
    }
}
