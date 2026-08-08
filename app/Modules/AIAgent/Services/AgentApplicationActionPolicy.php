<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\AIAgent\DTO\AgentWorkflowContext;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentApplicationStatusMap;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\Applications\Support\ServiceWorkflow;
use Illuminate\Support\Collection;

class AgentApplicationActionPolicy
{
    public function blockReason(LicenseApplication $application, string $actionName): ?string
    {
        $application->loadMissing('serviceType');
        $actionName = AgentApplicationStatusMap::normalizeAction($actionName);

        if (! ServiceWorkflow::requiresTests($application->serviceType?->code)
            && in_array($actionName, [
                'get_available_tests',
                'get_appointment_slots',
                'book_appointment',
                'reschedule_appointment',
                'cancel_appointment',
            ], true)) {
            return 'هذه الخدمة لا تتطلب حجز اختبارات. الخطوة الحالية هي متابعة الوثائق والدفع حتى إصدار الرخصة.';
        }

        $status = $application->status instanceof ApplicationStatus
            ? $application->status
            : ApplicationStatus::tryFrom((string) $application->status) ?? ApplicationStatus::Draft;

        if (AgentApplicationStatusMap::isActionAllowed($status, $actionName)) {
            return null;
        }

        $definition = AgentApplicationStatusMap::definition($status);

        return match ($actionName) {
            'start_payment' => 'لا يمكنك الدفع حالياً لأن الطلب ما زال في مرحلة '
                .$definition['label_ar']
                .'. الخطوة الحالية هي '
                .$definition['next_step_ar']
                .'.',
            'get_available_tests' => $this->availableTestsBlockReason($status, $definition),
            'get_appointment_slots' => $this->appointmentSlotsBlockReason($status, $definition),
            'book_appointment' => 'لا يمكنك حجز موعد قبل إكمال المتطلبات السابقة. الخطوة الحالية هي '
                .$definition['next_step_ar']
                .'.',
            'get_application_fee' => 'لا يمكن عرض رسوم هذا الطلب في مرحلة '
                .$definition['label_ar']
                .'. الخطوة الحالية هي '
                .$definition['next_step_ar']
                .'.',
            default => 'لا يمكن تنفيذ هذه العملية في مرحلة '
                .$definition['label_ar']
                .'. الخطوة الحالية هي '
                .$definition['next_step_ar']
                .'.',
        };
    }

    public function profileBlockReason(User $citizen, bool $mutating): ?string
    {
        if (! $mutating || ! $citizen->isCitizen()) {
            return null;
        }

        $status = (string) ($citizen->profile_status?->value ?? $citizen->profile_status ?? '');

        return match ($status) {
            'pending_review' => 'ملفك الشخصي قيد المراجعة حالياً. لا يمكنك تنفيذ هذه العملية قبل الموافقة على البيانات.',
            'rejected' => 'تم رفض بيانات ملفك الشخصي. يرجى تعديل البيانات وإعادة إرسالها للمراجعة قبل استخدام الخدمات.',
            default => ! $citizen->profile_completed
                ? 'يرجى إكمال بيانات الملف الشخصي قبل استخدام هذه الخدمة.'
                : null,
        };
    }

    public function noApplicationReply(string $intentKey): string
    {
        return AgentTranslator::message('ai_agent.workflow.no_application.'.$intentKey);
    }

    public function multipleApplicationsReply(string $intentKey, Collection $applications, string $language = 'ar'): string
    {
        $summary = $applications
            ->map(function (LicenseApplication $application) use ($language): string {
                $licenseLabel = \App\Modules\AIAgent\Support\LicenseTypeSlotExtractor::labelAr(
                    (string) ($application->licenseType?->code ?? '')
                );
                $statusLabel = \App\Modules\AIAgent\Support\ApplicationStatusLabelMapper::labelAr($application->status);

                return '- '.$application->application_number.' — رخصة قيادة '.$licenseLabel.' — '.$statusLabel;
            })
            ->implode("\n");

        return AgentTranslator::message('ai_agent.workflow.multiple_applications.'.$intentKey, [
            'summary' => $summary,
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function availableTestsBlockReason(ApplicationStatus $status, array $definition): string
    {
        if ($status === ApplicationStatus::PaymentPending) {
            return 'لا يمكنك عرض الاختبارات المتاحة قبل إكمال عملية الدفع. الخطوة الحالية هي '
                .$definition['next_step_ar']
                .'.';
        }

        if ($status === ApplicationStatus::Draft) {
            return 'لا يمكنك عرض الاختبارات المتاحة حالياً لأن الطلب ما زال في مرحلة المسودة. الخطوة الحالية هي '
                .$definition['next_step_ar']
                .'.';
        }

        return 'لا يمكنك عرض الاختبارات المتاحة في مرحلة '
            .$definition['label_ar']
            .'. الخطوة الحالية هي '
            .$definition['next_step_ar']
            .'.';
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function appointmentSlotsBlockReason(ApplicationStatus $status, array $definition): string
    {
        if ($status === ApplicationStatus::PaymentPending) {
            return 'لا يمكنك حجز موعد قبل دفع الرسوم. الخطوة الحالية هي '
                .$definition['next_step_ar']
                .'.';
        }

        if ($status === ApplicationStatus::Draft) {
            return 'لا يمكنك عرض مواعيد الاختبارات حالياً لأن الطلب ما زال في مرحلة المسودة. الخطوة الحالية هي '
                .$definition['next_step_ar']
                .'.';
        }

        return 'لا يمكنك عرض مواعيد الاختبارات في مرحلة '
            .$definition['label_ar']
            .'. الخطوة الحالية هي '
            .$definition['next_step_ar']
            .'.';
    }
}
