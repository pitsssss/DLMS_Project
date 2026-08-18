<?php

namespace App\Modules\Dashboard\Support\Reports;

use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class ReportPeriodResolver
{
    public const MAX_CUSTOM_DAYS = 366;

    public function __construct(
        private readonly BusinessClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     period: string,
     *     timezone: string,
     *     group_by: string,
     *     date_from: CarbonImmutable,
     *     date_to: CarbonImmutable,
     *     query_from: CarbonImmutable,
     *     query_to_exclusive: CarbonImmutable,
     *     filters: array<string, mixed>
     * }
     */
    public function resolve(array $filters): array
    {
        $tz = $this->clock->timezone();
        $period = (string) ($filters['period'] ?? '30d');
        $groupBy = (string) ($filters['group_by'] ?? 'auto');

        if ($period === 'custom') {
            $dateFrom = (string) ($filters['date_from'] ?? '');
            $dateTo = (string) ($filters['date_to'] ?? '');
            if ($dateFrom === '' || $dateTo === '') {
                throw new InvalidArgumentException('Custom period requires date_from and date_to.');
            }
            $from = CarbonImmutable::parse($dateFrom, $tz)->startOfDay();
            $to = CarbonImmutable::parse($dateTo, $tz)->endOfDay();
        } else {
            [$from, $to] = $this->resolvePresetRange($period, $tz);
        }

        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;
        if ($days > self::MAX_CUSTOM_DAYS) {
            throw new InvalidArgumentException('Date range exceeds maximum allowed span.');
        }

        if ($groupBy === 'auto') {
            $groupBy = $days <= 31 ? 'day' : ($days <= 90 ? 'week' : 'month');
        }

        $queryFrom = $this->clock->toUtc($from);
        $queryToExclusive = $this->clock->toUtc($to->startOfDay()->addDay());

        return [
            'period' => $period,
            'timezone' => $tz,
            'group_by' => $groupBy,
            'date_from' => $from,
            'date_to' => $to,
            'query_from' => $queryFrom,
            'query_to_exclusive' => $queryToExclusive,
            'filters' => $filters,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolvePresetRange(string $period, string $tz): array
    {
        $now = CarbonImmutable::now($tz);

        if ($period === '12m') {
            return [$now->startOfMonth()->subMonths(11)->startOfDay(), $now->endOfDay()];
        }

        $days = match ($period) {
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };

        return [$now->startOfDay()->subDays($days - 1), $now->endOfDay()];
    }

    public function bucketExpression(string $column, string $groupBy): string
    {
        return match ($groupBy) {
            'week' => $this->clock->sqlBusinessWeekExpression($column),
            'month' => $this->clock->sqlBusinessMonthExpression($column),
            default => $this->clock->sqlBusinessDateExpression($column),
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function meta(array $context): array
    {
        return [
            'period' => $context['period'],
            'date_from' => $context['date_from']->toIso8601String(),
            'date_to' => $context['date_to']->toIso8601String(),
            'timezone' => $context['timezone'],
            'group_by' => $context['group_by'],
            'generated_at' => $this->clock->now()->utc()->toIso8601String(),
            'filters' => $this->publicFilters($context['filters'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function publicFilters(array $filters): array
    {
        $keys = [
            'period', 'date_from', 'date_to', 'group_by',
            'application_status', 'service_type_code', 'license_type_code',
            'test_type_code', 'test_result', 'appointment_status',
            'payment_status', 'currency', 'employee_id', 'document_status', 'fine_status',
            'violation_type', 'status', 'role',
            'page', 'per_page',
        ];

        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $filters) && $filters[$key] !== null && $filters[$key] !== '') {
                $out[$key] = $filters[$key];
            }
        }

        return $out;
    }
}
