<?php

namespace App\Modules\Dashboard\Support\Reports;

/**
 * Shared JSON contract helpers for Dashboard report endpoints.
 *
 * Frontend parsers accept:
 * - series as a named map of {bucket,label,count,value} points
 * - breakdowns under both canonical keys (status) and by_* aliases
 * - breakdown items with label + count
 */
final class ReportContract
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $named
     * @return array<string, list<array{bucket: string, label: string, date: string, count: int, value: int}>>
     */
    public static function namedSeries(array $named): array
    {
        $out = [];
        foreach ($named as $key => $items) {
            $out[$key] = self::points(is_array($items) ? $items : []);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{bucket: string, label: string, date: string, count: int, value: int}>
     */
    public static function points(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $bucket = (string) ($item['bucket'] ?? $item['date'] ?? $item['label'] ?? '');
            $count = (int) ($item['count'] ?? $item['value'] ?? $item['total_actions'] ?? 0);
            $label = (string) ($item['label'] ?? $bucket);

            $out[] = [
                'bucket' => $bucket,
                'label' => $label,
                'date' => $bucket,
                'count' => $count,
                'value' => $count,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function breakdownItems(array $rows, string $keyField, ?string $labelField = null): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : (array) $row;
            $key = (string) ($row[$keyField] ?? $row['code'] ?? $row['key'] ?? $row['value'] ?? $row['status'] ?? '');
            $labelSource = $labelField !== null ? ($row[$labelField] ?? null) : ($row['label'] ?? $row['name'] ?? null);
            $label = (string) ($labelSource ?? $key);
            $count = (int) ($row['count'] ?? $row['aggregate_count'] ?? $row['total_actions'] ?? 0);

            $out[] = array_merge($row, [
                'key' => $key,
                'value' => $key,
                'code' => $row['code'] ?? $key,
                'label' => $label !== '' ? $label : ($key !== '' ? $key : '—'),
                'count' => $count,
            ]);
        }

        return $out;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $canonical  keys like status, service_type
     * @return array<string, list<array<string, mixed>>>
     */
    public static function aliasBreakdowns(array $canonical): array
    {
        $out = $canonical;
        foreach ($canonical as $key => $items) {
            $out['by_'.$key] = $items;
        }
        if (isset($canonical['status'])) {
            $out['application_status'] = $canonical['status'];
        }
        if (isset($canonical['result'])) {
            $out['test_result'] = $canonical['result'];
        }
        if (isset($canonical['violation_type'])) {
            $out['reason'] = $canonical['violation_type'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $totals  currency => amount string, or single amount
     * @return list<array{amount: string, currency: string|null}>
     */
    public static function moneyTotals(?array $byCurrency, ?string $amount, ?string $currency): array
    {
        if (is_array($byCurrency) && $byCurrency !== []) {
            $out = [];
            foreach ($byCurrency as $code => $value) {
                $out[] = ['amount' => (string) $value, 'currency' => (string) $code];
            }

            return $out;
        }

        if ($amount === null || $amount === '') {
            return [];
        }

        return [['amount' => (string) $amount, 'currency' => $currency]];
    }
}
