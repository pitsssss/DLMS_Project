<?php

namespace App\Modules\Dashboard\Support\Reports;

final class ReportResponse
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function build(array $context, array $payload): array
    {
        return array_merge([
            'summary' => [],
            'series' => [],
            'breakdowns' => [],
            'rows' => [],
            'pagination' => null,
            'meta' => app(ReportPeriodResolver::class)->meta($context),
        ], $payload);
    }

    public static function rate(?int $numerator, ?int $denominator): ?float
    {
        if ($denominator === null || $denominator === 0) {
            return null;
        }

        return round(((float) $numerator / (float) $denominator) * 100, 2);
    }
}
