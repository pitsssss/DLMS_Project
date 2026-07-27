<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Business calendar helpers for DLMS / SYRTAK.
 *
 * Storage timestamps follow config('app.timezone') (UTC).
 * Calendar periods and Overview meta use config('dlms.business_timezone').
 */
class BusinessClock
{
    public function timezone(): string
    {
        return (string) config('dlms.business_timezone', 'Asia/Damascus');
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    public function toUtc(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->utc();
    }

    /**
     * @return array{
     *     period: string,
     *     timezone: string,
     *     granularity: string,
     *     current_from: CarbonImmutable,
     *     current_to: CarbonImmutable,
     *     previous_from: CarbonImmutable,
     *     previous_to: CarbonImmutable,
     *     query_current_from: CarbonImmutable,
     *     query_current_to: CarbonImmutable,
     *     query_previous_from: CarbonImmutable,
     *     query_previous_to: CarbonImmutable
     * }
     */
    public function resolvePeriod(string $period): array
    {
        $tz = $this->timezone();
        $now = $this->now();
        $granularity = $period === '12m' ? 'month' : 'day';

        if ($period === '12m') {
            $currentFrom = $now->startOfMonth()->subMonths(11);
            $currentTo = $now->endOfDay();
            $previousTo = $currentFrom->subSecond();
            $previousFrom = $previousTo->startOfMonth()->subMonths(11);
        } else {
            $days = match ($period) {
                '7d' => 7,
                '90d' => 90,
                default => 30,
            };
            $currentTo = $now->endOfDay();
            $currentFrom = $now->startOfDay()->subDays($days - 1);
            $previousTo = $currentFrom->subSecond();
            $previousFrom = $previousTo->startOfDay()->subDays($days - 1);
        }

        return [
            'period' => $period,
            'timezone' => $tz,
            'granularity' => $granularity,
            'current_from' => $currentFrom,
            'current_to' => $currentTo,
            'previous_from' => $previousFrom,
            'previous_to' => $previousTo,
            'query_current_from' => $this->toUtc($currentFrom),
            'query_current_to' => $this->toUtc($currentTo),
            'query_previous_from' => $this->toUtc($previousFrom),
            'query_previous_to' => $this->toUtc($previousTo),
        ];
    }

    /**
     * SQL expression that maps a UTC-stored DATETIME column to a business local day (Y-m-d).
     *
     * Asia/Damascus is UTC+3 year-round (DST abolished). Project data is post-2022.
     */
    public function sqlBusinessDateExpression(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        return $driver === 'sqlite'
            ? "strftime('%Y-%m-%d', datetime({$column}, '+3 hours'))"
            : "DATE(CONVERT_TZ({$column}, '+00:00', '+03:00'))";
    }

    /**
     * SQL expression that maps a UTC-stored DATETIME column to a business local month (Y-m).
     */
    public function sqlBusinessMonthExpression(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        return $driver === 'sqlite'
            ? "strftime('%Y-%m', datetime({$column}, '+3 hours'))"
            : "DATE_FORMAT(CONVERT_TZ({$column}, '+00:00', '+03:00'), '%Y-%m')";
    }

    /**
     * SQL expression that maps a UTC-stored DATETIME column to a business local ISO week (Y-Www).
     */
    public function sqlBusinessWeekExpression(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        return $driver === 'sqlite'
            ? "strftime('%Y-W%W', datetime({$column}, '+3 hours'))"
            : "CONCAT(DATE_FORMAT(CONVERT_TZ({$column}, '+00:00', '+03:00'), '%x'), '-W', DATE_FORMAT(CONVERT_TZ({$column}, '+00:00', '+03:00'), '%v'))";
    }

    public function applyUtcRange($query, string $column, CarbonImmutable $from, CarbonImmutable $toExclusive): void
    {
        $query->where($column, '>=', $from)->where($column, '<', $toExclusive);
    }
}
