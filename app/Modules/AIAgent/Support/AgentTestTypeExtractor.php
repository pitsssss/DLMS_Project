<?php

namespace App\Modules\AIAgent\Support;

class AgentTestTypeExtractor
{
    /**
     * @return array<string, list<string>>
     */
    private static function phraseMap(): array
    {
        return [
            'vision' => [
                'فحص النظر',
                'اختبار النظر',
                'فحص نظر',
                'اختبار نظر',
                'النظر',
                'vision',
            ],
            'theory' => [
                'الفحص النظري',
                'الاختبار النظري',
                'فحص نظري',
                'اختبار نظري',
                'النظري',
                'theory',
            ],
            'practical' => [
                'الفحص العملي',
                'الاختبار العملي',
                'فحص عملي',
                'اختبار عملي',
                'العملي',
                'practical',
                'street',
            ],
        ];
    }

    public static function extractFromMessage(string $message): ?string
    {
        $normalized = self::normalize($message);
        $bestCode = null;
        $bestLength = 0;

        foreach (self::phraseMap() as $code => $phrases) {
            foreach ($phrases as $phrase) {
                $phraseNormalized = self::normalize($phrase);
                if ($phraseNormalized === '' || ! str_contains($normalized, $phraseNormalized)) {
                    continue;
                }

                if (mb_strlen($phraseNormalized) >= $bestLength) {
                    $bestLength = mb_strlen($phraseNormalized);
                    $bestCode = $code;
                }
            }
        }

        return $bestCode;
    }

    private static function normalize(string $message): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $message) ?? $message));
    }
}
