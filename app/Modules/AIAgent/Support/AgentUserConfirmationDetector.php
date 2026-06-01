<?php

namespace App\Modules\AIAgent\Support;

class AgentUserConfirmationDetector
{
    public static function isAffirmative(string $message): bool
    {
        $normalized = self::normalize($message);

        if ($normalized === '') {
            return false;
        }

        $exact = [
            'نعم',
            'ايوه',
            'أيوه',
            'موافق',
            'تمام',
            'اوك',
            'أوك',
            'ok',
            'okay',
            'yes',
            'y',
            'confirm',
            'confirmed',
            'تابع',
            'متابعة',
            'اكيد',
            'أكيد',
        ];

        if (in_array($normalized, $exact, true)) {
            return true;
        }

        $phrases = [
            'نعم اؤكد',
            'نعم أؤكد',
            'نعم اوافق',
            'نعم أوافق',
            'اوافق',
            'أوافق',
            'اقبل',
            'أقبل',
            'yes confirm',
            'i confirm',
            'go ahead',
            'اكمل',
            'أكمل',
        ];

        foreach ($phrases as $phrase) {
            if ($normalized === $phrase || str_starts_with($normalized, $phrase.' ')) {
                return true;
            }
        }

        if (str_starts_with($normalized, 'نعم') && mb_strlen($normalized) <= 48) {
            return true;
        }

        return false;
    }

    public static function isNegative(string $message): bool
    {
        $normalized = self::normalize($message);

        if ($normalized === '') {
            return false;
        }

        $exact = [
            'لا',
            'لأ',
            'كلا',
            'الغاء',
            'إلغاء',
            'الغي',
            'ألغي',
            'cancel',
            'no',
            'n',
            'stop',
        ];

        if (in_array($normalized, $exact, true)) {
            return true;
        }

        $phrases = [
            'لا اريد',
            'لا أريد',
            'لا اوافق',
            'لا أوافق',
            'dont confirm',
            "don't confirm",
        ];

        foreach ($phrases as $phrase) {
            if ($normalized === $phrase || str_starts_with($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $message): string
    {
        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $message) ?? $message));
        $text = str_replace(['َ', 'ً', 'ُ', 'ٌ', 'ِ', 'ٍ', 'ْ', 'ّ'], '', $text);

        return $text;
    }
}
