<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ServiceCode;
use App\Models\License;
use App\Models\User;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentApplicationTextSelector;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;
use App\Modules\Applications\Services\LicenseServiceEligibilityService;
use Illuminate\Support\Collection;

/**
 * License options for renew / lost / damaged pending-workflow selection.
 */
class AgentLicenseOptionService
{
    public function __construct(
        private readonly LicenseServiceEligibilityService $eligibility,
        private readonly AgentSelectionTokenService $selectionTokens,
    ) {}

    /**
     * @return Collection<int, License>
     */
    public function eligibleLicenses(User $citizen, ServiceCode $service): Collection
    {
        return License::query()
            ->where('citizen_id', $citizen->id)
            ->with('licenseType')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (License $license): bool => $this->eligibility->check($citizen, $license, $service)['allowed'])
            ->values();
    }

    /**
     * @param  Collection<int, License>  $licenses
     * @return list<array<string, mixed>>
     */
    public function buildLicenseButtons(
        User $citizen,
        AIAgentSession $session,
        Collection $licenses,
        string $workflowId,
        string $intent,
    ): array {
        $ttl = (int) config('ai.agent.selection_token_ttl_seconds', 1800);
        $isEn = AgentTranslator::getLocale() === 'en';

        return $licenses->map(function (License $license) use ($citizen, $session, $workflowId, $intent, $ttl, $isEn): array {
            $code = (string) ($license->licenseType?->code ?? '');
            $typeLabel = $isEn
                ? LicenseTypeSlotExtractor::labelEn($code)
                : LicenseTypeSlotExtractor::labelAr($code);
            $number = (string) $license->license_number;
            $status = $license->status?->value ?? (string) $license->status;
            $expiry = $license->expiry_date?->format('Y-m-d') ?? '';

            return [
                'label' => trim($typeLabel.' — '.$number),
                'subtitle' => trim($status.($expiry !== '' ? ' · '.$expiry : '')),
                'license_number' => $number,
                'license_type' => $code,
                'license_type_label' => $typeLabel,
                'status' => $status,
                'expiry_date' => $expiry,
                'selection_token' => $this->selectionTokens->issue(
                    $citizen,
                    $session,
                    AgentSelectionTokenService::PURPOSE_LICENSE,
                    0,
                    null,
                    $ttl,
                    $workflowId,
                    $intent,
                    null,
                    null,
                    (int) $license->id
                ),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, License>  $licenses
     * @return array{status: string, license_id?: int, matched_ids?: list<int>}
     */
    public function resolveFromText(string $message, Collection $licenses): array
    {
        $normalized = AgentApplicationTextSelector::normalizeDigits(
            mb_strtolower(trim(preg_replace('/\s+/u', ' ', $message) ?? $message))
        );
        if ($normalized === '' || $licenses->isEmpty()) {
            return ['status' => 'ambiguous'];
        }

        $ordered = $licenses->values();
        $map = [
            'الاول' => 0, 'الأول' => 0, 'اول' => 0, 'first' => 0, '1' => 0,
            'الثاني' => 1, 'تاني' => 1, 'second' => 1, '2' => 1,
            'الثالث' => 2, 'تالت' => 2, 'third' => 2, '3' => 2,
        ];
        foreach ($map as $phrase => $index) {
            if ($normalized === mb_strtolower($phrase) && $index < $ordered->count()) {
                return ['status' => 'matched', 'license_id' => (int) $ordered->get($index)->id];
            }
        }

        $contained = [
            0 => ['the first', 'first one', 'first license'],
            1 => ['the second', 'second one', 'second license'],
            2 => ['the third', 'third one', 'third license'],
        ];
        foreach ($contained as $index => $phrases) {
            if ($index >= $ordered->count()) {
                continue;
            }
            foreach ($phrases as $phrase) {
                if (str_contains($normalized, mb_strtolower($phrase))) {
                    return ['status' => 'matched', 'license_id' => (int) $ordered->get($index)->id];
                }
            }
        }

        if (preg_match('/(?:رقم|license|#)\s*([a-z0-9\-]+)/ui', $normalized, $m)
            || preg_match('/^([a-z0-9\-]{4,})$/ui', $normalized, $m)) {
            $needle = mb_strtolower($m[1]);
            $hits = $ordered->filter(function (License $license) use ($needle): bool {
                return mb_strtolower((string) $license->license_number) === $needle
                    || (string) $license->id === $needle;
            })->values();
            if ($hits->count() === 1) {
                return ['status' => 'matched', 'license_id' => (int) $hits->first()->id];
            }
            if ($hits->count() > 1) {
                return [
                    'status' => 'ambiguous',
                    'matched_ids' => $hits->pluck('id')->map(fn ($id) => (int) $id)->all(),
                ];
            }
        }

        return ['status' => 'ambiguous'];
    }
}
