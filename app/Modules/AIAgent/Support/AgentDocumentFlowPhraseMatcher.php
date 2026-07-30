<?php

namespace App\Modules\AIAgent\Support;

/**
 * State-aware phrase matching for the conversational document upload offer.
 * Affirmative/negative phrases here must only be applied when the session is in document_upload_offer.
 */
class AgentDocumentFlowPhraseMatcher
{
    public static function isAgentUploadConsent(string $message): bool
    {
        $normalized = self::normalize($message);

        if ($normalized === '') {
            return false;
        }

        $exact = [
            'نعم',
            'اي',
            'إي',
            'ايوه',
            'أيوه',
            'موافق',
            'اوك',
            'أوك',
            'yes',
            'ok',
            'okay',
            'y',
        ];

        if (in_array($normalized, $exact, true)) {
            return true;
        }

        $phrases = [
            'ارفعهن',
            'ارفعهم',
            'ارفعها',
            'بدي ارفعهن معك',
            'بدي ارفعهم معك',
            'خلينا نرفعهن',
            'خلينا نرفعهم',
            'رفعها عبر المساعد',
            'رفعها وارسالها عبر المساعد',
            'رفعها وإرسالها عبر المساعد',
            'نعم رفعها',
            'نعم ارفع',
            'upload them',
            'upload with agent',
            'yes upload',
        ];

        foreach ($phrases as $phrase) {
            if ($normalized === $phrase || str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    public static function isManualUploadChoice(string $message): bool
    {
        $normalized = self::normalize($message);

        if ($normalized === '') {
            return false;
        }

        $exact = [
            'لا',
            'لأ',
            'كلا',
            'no',
            'n',
            'manual',
        ];

        if (in_array($normalized, $exact, true)) {
            return true;
        }

        $phrases = [
            'مو هلق',
            'برفعهن لحالي',
            'برفعهم لحالي',
            'بدي ارفعهن يدوي',
            'بدي ارفعهم يدوي',
            'سأرفعها يدويًا',
            'سارفعها يدويا',
            'ارفعهن يدوي',
            'ارفعهم يدوي',
            'رفع يدوي',
            'i will upload them myself',
            'upload myself',
            'manual upload',
        ];

        foreach ($phrases as $phrase) {
            if ($normalized === $phrase || str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $message): string
    {
        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $message) ?? $message));

        return str_replace(['َ', 'ً', 'ُ', 'ٌ', 'ِ', 'ٍ', 'ْ', 'ّ', 'ـ'], '', $text);
    }
}
