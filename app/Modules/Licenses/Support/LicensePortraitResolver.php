<?php

namespace App\Modules\Licenses\Support;

use App\Enums\DocumentStatus;
use App\Enums\ServiceCode;
use App\Models\ApplicationDocument;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Modules\Applications\Support\ServiceWorkflow;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only resolver for an approved personal-photo document attached to a license.
 *
 * Does not mutate licenses, documents, or storage. Public verification must never
 * receive this file.
 */
final class LicensePortraitResolver
{
    public const PHOTO_CODES = [
        'personal_photo',
        'recent_personal_photo',
    ];

    private const IMAGE_MIMES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
    ];

    private const MAX_LINEAGE_HOPS = 12;

    /**
     * @return array{path: string, mime: string, filename: string}|null
     */
    public function resolve(License $license): ?array
    {
        $visited = [];

        $current = $license;
        for ($hop = 0; $hop < self::MAX_LINEAGE_HOPS; $hop++) {
            if (isset($visited[$current->id])) {
                break;
            }
            $visited[$current->id] = true;

            $hit = $this->resolveFromLicense($current);
            if ($hit !== null) {
                return $hit;
            }

            $current->loadMissing('previousLicense');
            if ($current->previousLicense === null) {
                break;
            }
            $current = $current->previousLicense;
        }

        return null;
    }

    public function hasPortrait(License $license): bool
    {
        return $this->resolve($license) !== null;
    }

    /**
     * @return array{path: string, mime: string, filename: string}|null
     */
    private function resolveFromLicense(License $license): ?array
    {
        $license->loadMissing(['application.serviceType']);
        $application = $license->application;
        if (! $application instanceof LicenseApplication) {
            return null;
        }

        foreach ($this->preferredCodes($application) as $code) {
            $hit = $this->latestApprovedImage($application, $code);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function preferredCodes(LicenseApplication $application): array
    {
        $service = ServiceWorkflow::fromServiceType($application->serviceType);

        if ($service === ServiceCode::NewLicense) {
            return ['personal_photo', 'recent_personal_photo'];
        }

        return ['recent_personal_photo', 'personal_photo'];
    }

    /**
     * @return array{path: string, mime: string, filename: string}|null
     */
    private function latestApprovedImage(LicenseApplication $application, string $code): ?array
    {
        $documents = ApplicationDocument::query()
            ->where('application_id', $application->id)
            ->where('status', DocumentStatus::Approved)
            ->whereHas('requiredDocument', function ($query) use ($code): void {
                $query->where('code', $code);
            })
            ->orderByDesc('id')
            ->get();

        foreach ($documents as $document) {
            $resolved = $this->resolveImageFile($document);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @return array{path: string, mime: string, filename: string}|null
     */
    private function resolveImageFile(ApplicationDocument $document): ?array
    {
        $path = $this->safeLocalPath((string) $document->file_path);
        if ($path === null) {
            return null;
        }

        $mime = $this->detectImageMime($document, $path);
        if ($mime === null) {
            return null;
        }

        $filename = basename((string) $document->original_name);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = 'portrait.'.$this->extensionForMime($mime);
        }

        return [
            'path' => $path,
            'mime' => $mime,
            'filename' => $filename,
        ];
    }

    private function detectImageMime(ApplicationDocument $document, string $path): ?string
    {
        $declared = strtolower(trim((string) $document->mime_type));
        if (in_array($declared, self::IMAGE_MIMES, true)) {
            $normalized = $declared === 'image/jpg' ? 'image/jpeg' : $declared;
            if ($this->finfoConfirmsImage($path, $normalized)) {
                return $normalized;
            }
        }

        if (! function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $detected = finfo_file($finfo, $path);
        finfo_close($finfo);

        if (! is_string($detected)) {
            return null;
        }

        $detected = strtolower($detected);
        if (! in_array($detected, self::IMAGE_MIMES, true)) {
            return null;
        }

        return $detected === 'image/jpg' ? 'image/jpeg' : $detected;
    }

    private function finfoConfirmsImage(string $path, string $expected): bool
    {
        if (! function_exists('finfo_open')) {
            return true;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return true;
        }

        $detected = finfo_file($finfo, $path);
        finfo_close($finfo);

        if (! is_string($detected)) {
            return false;
        }

        $detected = strtolower($detected) === 'image/jpg' ? 'image/jpeg' : strtolower($detected);

        return in_array($detected, self::IMAGE_MIMES, true) && $detected === $expected;
    }

    private function safeLocalPath(string $filePath): ?string
    {
        $path = trim($filePath, " \t\n\r\0\x0B\"'");

        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $diskRoot = realpath(Storage::disk('local')->path(''));
        if ($diskRoot === false) {
            return null;
        }

        if ($this->isAbsolutePath($path)) {
            $real = realpath($path);
        } else {
            if (str_contains($path, '..')) {
                return null;
            }
            $real = realpath(Storage::disk('local')->path($path));
        }

        if ($real === false || ! is_file($real)) {
            return null;
        }

        if (! $this->isPathInsideRoot($real, $diskRoot)) {
            return null;
        }

        return $real;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private function isPathInsideRoot(string $realPath, string $rootPath): bool
    {
        $real = str_replace('\\', '/', $realPath);
        $root = rtrim(str_replace('\\', '/', $rootPath), '/');

        if (PHP_OS_FAMILY === 'Windows') {
            $real = strtolower($real);
            $root = strtolower($root);
        }

        return $real === $root || str_starts_with($real, $root.'/');
    }

    private function extensionForMime(string $mime): string
    {
        return $mime === 'image/png' ? 'png' : 'jpg';
    }
}
