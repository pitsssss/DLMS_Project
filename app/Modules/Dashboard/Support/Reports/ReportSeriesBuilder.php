<?php

namespace App\Modules\Dashboard\Support\Reports;

use Carbon\CarbonImmutable;

final class ReportSeriesBuilder
{
    /**
     * @param  array<string, int>  $rows
     * @return list<array{bucket: string, value: int}>
     */
    public static function fill(
        array $context,
        array $rows,
        string $valueKey = 'value',
    ): array {
        $groupBy = $context['group_by'];
        $from = $context['date_from'];
        $to = $context['date_to'];
        $items = [];

        if ($groupBy === 'month') {
            $cursor = $from->startOfMonth();
            $end = $to->startOfMonth();
            while ($cursor->lte($end)) {
                $bucket = $cursor->format('Y-m');
                $items[] = ['bucket' => $bucket, $valueKey => (int) ($rows[$bucket] ?? 0)];
                $cursor = $cursor->addMonth();
            }

            return $items;
        }

        if ($groupBy === 'week') {
            $cursor = $from->startOfWeek();
            $end = $to->startOfWeek();
            while ($cursor->lte($end)) {
                $bucket = $cursor->format('o').'-W'.str_pad((string) $cursor->isoWeek(), 2, '0', STR_PAD_LEFT);
                $items[] = ['bucket' => $bucket, $valueKey => (int) ($rows[$bucket] ?? 0)];
                $cursor = $cursor->addWeek();
            }

            return $items;
        }

        $cursor = $from->startOfDay();
        $end = $to->startOfDay();
        while ($cursor->lte($end)) {
            $bucket = $cursor->format('Y-m-d');
            $items[] = ['bucket' => $bucket, $valueKey => (int) ($rows[$bucket] ?? 0)];
            $cursor = $cursor->addDay();
        }

        return $items;
    }

    /**
     * @param  array<string, array<string, int>>  $multiRows
     * @param  list<string>  $seriesKeys
     * @return list<array{bucket: string, values: array<string, int>}>
     */
    public static function fillMulti(array $context, array $multiRows, array $seriesKeys): array
    {
        $single = [];
        foreach ($seriesKeys as $key) {
            $single[$key] = self::fill($context, $multiRows[$key] ?? [], 'count');
        }

        $buckets = array_unique(array_merge(...array_map(
            fn (array $items) => array_column($items, 'bucket'),
            $single
        )));

        sort($buckets);
        $out = [];
        foreach ($buckets as $bucket) {
            $values = [];
            foreach ($seriesKeys as $key) {
                $match = collect($single[$key])->firstWhere('bucket', $bucket);
                $values[$key] = (int) ($match['count'] ?? 0);
            }
            $out[] = ['bucket' => $bucket, 'values' => $values];
        }

        return $out;
    }
}
