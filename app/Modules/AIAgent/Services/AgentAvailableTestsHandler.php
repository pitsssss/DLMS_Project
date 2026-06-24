<?php

namespace App\Modules\AIAgent\Services;

use App\Models\LicenseApplication;
use App\Modules\AIAgent\DTO\AgentWorkflowContext;
use App\Modules\AIAgent\Enums\AgentIntent;

class AgentAvailableTestsHandler
{
    public function __construct(
        private readonly AgentWorkflowResponseBuilder $responseBuilder,
        private readonly AgentApplicationActionPolicy $policy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(AgentWorkflowContext $context): array
    {
        if ($context->hasNoApplications()) {
            return $this->responseBuilder->basePayload(AgentIntent::GetAvailableTests, $context->language, [
                'reply' => $this->policy->noApplicationReply('appointment'),
                'proposed_action' => null,
                'execute_immediately' => false,
            ]);
        }

        if ($context->hasMultipleApplications()) {
            return $this->responseBuilder->basePayload(AgentIntent::GetAvailableTests, $context->language, [
                'reply' => $this->policy->multipleApplicationsReply('appointment', $context->applicationChoices, $context->language),
                'proposed_action' => null,
                'missing_slots' => ['application_choice'],
                'execute_immediately' => false,
            ]);
        }

        $application = $context->targetApplication;
        if ($application === null) {
            return $this->responseBuilder->basePayload(AgentIntent::GetAvailableTests, $context->language, [
                'reply' => $this->policy->noApplicationReply('appointment'),
                'proposed_action' => null,
                'execute_immediately' => false,
            ]);
        }

        $blockReason = $this->policy->blockReason($application, 'get_available_tests');
        if ($blockReason !== null) {
            return $this->responseBuilder->blockedPayload(AgentIntent::GetAvailableTests, $context->language, $blockReason);
        }

        return $this->responseBuilder->basePayload(AgentIntent::GetAvailableTests, $context->language, [
            'reply' => 'سأعرض لك الاختبارات المتاحة لطلبك مع حالة كل اختبار.',
            'proposed_action' => [
                'name' => 'get_available_tests',
                'arguments' => [
                    'application_id' => $application->id,
                ],
            ],
            'requires_confirmation' => false,
            'execute_immediately' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function replyFromActionResult(array $result): string
    {
        $tests = $result['tests'] ?? [];
        if (! is_array($tests) || $tests === []) {
            return 'لم أتمكن من جلب الاختبارات المتاحة لهذا الطلب حالياً.';
        }

        $available = [];
        $unavailable = [];

        foreach ($tests as $test) {
            if (! is_array($test)) {
                continue;
            }

            if (! empty($test['is_available'])) {
                $available[] = $test;
            } else {
                $unavailable[] = $test;
            }
        }

        if ($available === []) {
            $firstReason = trim((string) ($unavailable[0]['reason'] ?? ''));
            if ($firstReason !== '') {
                return 'لا يوجد اختبار متاح للحجز حالياً. '.$firstReason;
            }

            return 'لا يوجد اختبار متاح للحجز حالياً. يرجى متابعة الخطوة الحالية لطلبك.';
        }

        $availableNames = collect($available)
            ->pluck('name')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->values()
            ->all();

        if (count($availableNames) === 1) {
            $reply = 'الفحص المتاح حالياً هو '.$availableNames[0].'.';
        } else {
            $reply = 'الاختبارات المتاحة حالياً هي: '.implode('، ', $availableNames).'.';
        }

        if ($unavailable !== []) {
            $unavailableSummary = collect($unavailable)
                ->map(function (array $test): string {
                    $name = trim((string) ($test['name'] ?? 'اختبار'));
                    $reason = trim((string) ($test['reason'] ?? ''));

                    return $reason !== ''
                        ? "{$name} غير متاح حالياً. {$reason}"
                        : "{$name} غير متاح حالياً";
                })
                ->implode(' ');

            $reply .= ' '.$unavailableSummary;
        }

        return trim($reply);
    }
}
