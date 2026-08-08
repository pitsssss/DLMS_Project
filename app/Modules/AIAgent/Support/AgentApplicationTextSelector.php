<?php

namespace App\Modules\AIAgent\Support;

use App\Models\LicenseApplication;
use Illuminate\Support\Collection;

/**
 * Deterministic text matching while awaiting application_choice.
 */
class AgentApplicationTextSelector
{
    /**
     * @param  Collection<int, LicenseApplication>  $candidates Ordered as shown to the citizen.
     * @return array{status: string, application_id?: int, matched_ids?: list<int>}
     */
    public static function resolve(string $message, Collection $candidates): array
    {
        $normalized = self::normalize($message);
        if ($normalized === '' || $candidates->isEmpty()) {
            return ['status' => 'ambiguous'];
        }

        $ordered = $candidates->values();

        $ordinal = self::matchOrdinal($normalized, $ordered->count());
        if ($ordinal !== null) {
            $app = $ordered->get($ordinal);
            if ($app instanceof LicenseApplication) {
                return ['status' => 'matched', 'application_id' => (int) $app->id];
            }
        }

        $byId = self::matchApplicationId($normalized, $ordered);
        if ($byId !== null) {
            return ['status' => 'matched', 'application_id' => $byId];
        }

        $byService = self::matchServiceType($normalized, $ordered);
        if ($byService['status'] === 'matched' || $byService['status'] === 'ambiguous') {
            return $byService;
        }

        $byLicense = self::matchLicenseType($normalized, $ordered);
        if ($byLicense['status'] === 'matched' || $byLicense['status'] === 'ambiguous') {
            return $byLicense;
        }

        return ['status' => 'ambiguous'];
    }

    /**
     * Exact cancel only — never treat longer topic-change sentences as cancel.
     */
    public static function isCancelPhrase(string $message): bool
    {
        $normalized = self::normalize($message);
        if ($normalized === '') {
            return false;
        }

        $phrases = [
            'الغاء',
            'إلغاء',
            'الغي',
            'ألغي',
            'خلاص',
            'خلاص ما بدي',
            'ما بدي',
            'اترك الموضوع',
            'وقف العملية',
            'ارجع',
            'cancel',
            'never mind',
            'nevermind',
        ];

        foreach ($phrases as $phrase) {
            if ($normalized === self::normalize($phrase)) {
                return true;
            }
        }

        return false;
    }

    private static function matchOrdinal(string $normalized, int $count): ?int
    {
        $map = [
            'الاول' => 0,
            'الأول' => 0,
            'اول' => 0,
            'اول واحد' => 0,
            'الطلب الاول' => 0,
            'الطلب الأول' => 0,
            'الخيار الاول' => 0,
            'الخيار الأول' => 0,
            'رقم 1' => 0,
            'رقم١' => 0,
            '1' => 0,
            'الثاني' => 1,
            'تاني' => 1,
            'تاني واحد' => 1,
            'الطلب الثاني' => 1,
            'الخيار الثاني' => 1,
            'رقم 2' => 1,
            'رقم٢' => 1,
            '2' => 1,
            'الثالث' => 2,
            'تالت' => 2,
            'الطلب الثالث' => 2,
            'رقم 3' => 2,
            '3' => 2,
            'first' => 0,
            'second' => 1,
            'third' => 2,
            'option 1' => 0,
            'option 2' => 1,
            'option 3' => 2,
        ];

        foreach ($map as $phrase => $index) {
            if ($normalized === self::normalize($phrase) && $index < $count) {
                return $index;
            }
        }

        if (preg_match('/^(?:ال)?(?:طلب|خيار|رقم)?\s*([1-9]|[١٢۳۱۲۳])$/u', $normalized, $m)) {
            $digit = self::normalizeDigits($m[1]);
            $index = ((int) $digit) - 1;
            if ($index >= 0 && $index < $count) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, LicenseApplication>  $ordered
     */
    private static function matchApplicationId(string $normalized, Collection $ordered): ?int
    {
        $normalized = self::normalizeDigits($normalized);
        $ids = $ordered->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (preg_match('/(?:رقم|طلب(?:\s+رقم)?|#)\s*(\d{1,10})/u', $normalized, $m)
            || preg_match('/^(\d{1,10})$/u', $normalized, $m)) {
            $id = (int) $m[1];
            if (in_array($id, $ids, true)) {
                return $id;
            }

            foreach ($ordered as $application) {
                if ((string) $application->id === (string) $id) {
                    return (int) $application->id;
                }

                $number = (string) $application->application_number;
                if ($number === (string) $id) {
                    return (int) $application->id;
                }

                // Allow APP-2026-000025 to match "25" / "٢٥" (zero-padded suffix / segment).
                if (preg_match('/(?:^|[^0-9])0*'.$id.'(?:[^0-9]|$)/', $number) === 1) {
                    return (int) $application->id;
                }
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, LicenseApplication>  $ordered
     * @return array{status: string, application_id?: int, matched_ids?: list<int>}
     */
    private static function matchServiceType(string $normalized, Collection $ordered): array
    {
        $map = [
            'new_license' => ['رخصة جديدة', 'طلب رخصة جديدة', 'اصدار', 'إصدار', 'new license'],
            'renew_license' => ['تجديد', 'طلب التجديد', 'طلب تجديد', 'renew'],
            'lost_replacement' => ['بدل فاقد', 'فاقد', 'lost'],
            'damaged_replacement' => ['بدل تالف', 'تالف', 'damaged'],
        ];

        return self::matchByAttribute($normalized, $ordered, $map, static function (LicenseApplication $app): string {
            return (string) ($app->serviceType?->code ?? '');
        });
    }

    /**
     * @param  Collection<int, LicenseApplication>  $ordered
     * @return array{status: string, application_id?: int, matched_ids?: list<int>}
     */
    private static function matchLicenseType(string $normalized, Collection $ordered): array
    {
        $map = [
            'private' => ['خاصة', 'خاصه', 'رخصة خاصة', 'طلب الرخصة الخاصة', 'private'],
            'public' => ['عامة', 'عامه', 'رخصة عامة', 'public'],
            'truck' => ['شاحنة', 'شاحنه', 'truck'],
            'bus' => ['حافلة', 'حافله', 'باص', 'bus'],
        ];

        return self::matchByAttribute($normalized, $ordered, $map, static function (LicenseApplication $app): string {
            return (string) ($app->licenseType?->code ?? '');
        });
    }

    /**
     * @param  array<string, list<string>>  $map
     * @param  Collection<int, LicenseApplication>  $ordered
     * @param  callable(LicenseApplication): string  $attribute
     * @return array{status: string, application_id?: int, matched_ids?: list<int>}
     */
    private static function matchByAttribute(
        string $normalized,
        Collection $ordered,
        array $map,
        callable $attribute,
    ): array {
        $matchedCodes = [];
        foreach ($map as $code => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($normalized, self::normalize($phrase))) {
                    $matchedCodes[$code] = true;
                    break;
                }
            }
        }

        if ($matchedCodes === []) {
            return ['status' => 'none'];
        }

        $matches = $ordered->filter(function (LicenseApplication $app) use ($matchedCodes, $attribute): bool {
            return isset($matchedCodes[$attribute($app)]);
        })->values();

        if ($matches->count() === 1) {
            return ['status' => 'matched', 'application_id' => (int) $matches->first()->id];
        }

        if ($matches->count() > 1) {
            return [
                'status' => 'ambiguous',
                'matched_ids' => $matches->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ];
        }

        return ['status' => 'none'];
    }

    public static function normalizeDigits(string $text): string
    {
        $map = [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ];

        return strtr($text, $map);
    }

    private static function normalize(string $message): string
    {
        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $message) ?? $message));
        $text = str_replace(['َ', 'ً', 'ُ', 'ٌ', 'ِ', 'ٍ', 'ْ', 'ّ', 'ـ'], '', $text);

        return self::normalizeDigits($text);
    }
}
