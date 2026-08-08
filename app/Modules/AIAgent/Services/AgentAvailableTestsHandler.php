<?php

namespace App\Modules\AIAgent\Services;

use App\Models\LicenseApplication;
use App\Modules\AIAgent\DTO\AgentWorkflowContext;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Support\AgentCatalogLocalizer;
use App\Modules\AIAgent\Support\AgentTranslator;

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
            'reply' => AgentTranslator::message('ai_agent.tests.loading'),
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
            return AgentTranslator::message('ai_agent.tests.unavailable');
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
                return AgentTranslator::message('ai_agent.tests.none').' '.$firstReason;
            }

            return AgentTranslator::message('ai_agent.tests.none');
        }

        $availableNames = collect($available)
            ->map(fn (array $test): string => AgentCatalogLocalizer::testTypeFromPayload($test))
            ->filter(fn (string $name): bool => $name !== '')
            ->values()
            ->all();

        if (count($availableNames) === 1) {
            $reply = AgentTranslator::message('ai_agent.tests.single_available', [
                'name' => $availableNames[0],
            ]);
        } else {
            $reply = AgentTranslator::message('ai_agent.tests.multiple_available', [
                'names' => implode(AgentTranslator::getLocale() === 'en' ? ', ' : '، ', $availableNames),
            ]);
        }

        if ($unavailable !== []) {
            $unavailableSummary = collect($unavailable)
                ->map(function (array $test): string {
                    $name = AgentCatalogLocalizer::testTypeFromPayload($test);
                    $reason = trim((string) ($test['reason'] ?? ''));

                    return $reason !== ''
                        ? "{$name}: {$reason}"
                        : $name;
                })
                ->implode(' ');

            $reply .= ' '.$unavailableSummary;
        }

        return trim($reply);
    }
}
