<?php

namespace App\Modules\Applications\Support;

/**
 * Central mapping between allowed document extensions and trusted MIME types.
 * Validation must use Fileinfo-backed MIME ({@see UploadedFile::getMimeType()}),
 * never client-declared MIME alone.
 */
class AllowedDocumentMime
{
    /**
     * @var array<string, list<string>>
     */
    public const EXTENSION_MIME_MAP = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'pdf' => ['application/pdf'],
    ];

    /**
     * @param  list<string>|null  $extensions
     * @return list<string>
     */
    public static function normalizeExtensions(?array $extensions): array
    {
        if ($extensions === null || $extensions === []) {
            return ['jpg', 'jpeg', 'png', 'pdf'];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $ext): string => strtolower((string) $ext),
            $extensions
        )));
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public static function allowedMimesForExtensions(array $extensions): array
    {
        $mimes = [];

        foreach (self::normalizeExtensions($extensions) as $extension) {
            foreach (self::EXTENSION_MIME_MAP[$extension] ?? [] as $mime) {
                $mimes[] = $mime;
            }
        }

        return array_values(array_unique($mimes));
    }

    public static function isMimeAllowedForExtension(string $extension, string $mime): bool
    {
        $extension = strtolower($extension);
        $mime = strtolower(trim($mime));

        $allowed = self::EXTENSION_MIME_MAP[$extension] ?? null;

        if ($allowed === null || $mime === '') {
            return false;
        }

        return in_array($mime, $allowed, true);
    }
}
