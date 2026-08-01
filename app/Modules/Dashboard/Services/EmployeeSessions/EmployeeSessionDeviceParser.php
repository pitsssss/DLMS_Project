<?php

namespace App\Modules\Dashboard\Services\EmployeeSessions;

/**
 * Conservative approximate User-Agent parsing for operational visibility.
 * Values are approximate — not exact device models or fingerprints.
 */
class EmployeeSessionDeviceParser
{
    /**
     * @return array{
     *     device_type: string,
     *     operating_system: string,
     *     browser: string,
     *     browser_version: string|null
     * }
     */
    public function parse(?string $userAgent): array
    {
        $ua = trim((string) $userAgent);

        if ($ua === '') {
            return [
                'device_type' => 'unknown',
                'operating_system' => 'Unknown',
                'browser' => 'Unknown',
                'browser_version' => null,
            ];
        }

        return [
            'device_type' => $this->detectDeviceType($ua),
            'operating_system' => $this->detectOperatingSystem($ua),
            'browser' => $this->detectBrowser($ua),
            'browser_version' => $this->detectBrowserVersion($ua),
        ];
    }

    public function deviceTypeLabel(string $deviceType): string
    {
        $key = 'messages.employee_sessions.device_types.'.$deviceType;

        return __($key) !== $key ? __($key) : __('messages.employee_sessions.device_types.unknown');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function deviceTypeOptions(): array
    {
        return $this->optionList(['desktop', 'mobile', 'tablet', 'unknown'], 'device_types');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function operatingSystemOptions(): array
    {
        return $this->optionList(
            ['Windows', 'Android', 'iOS', 'macOS', 'Linux', 'Unknown'],
            'operating_systems'
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function browserOptions(): array
    {
        return $this->optionList(
            ['Chrome', 'Edge', 'Firefox', 'Safari', 'Other', 'Unknown'],
            'browsers'
        );
    }

    private function detectDeviceType(string $ua): string
    {
        if (preg_match('/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $ua)) {
            return 'tablet';
        }

        if (preg_match('/Mobile|Android|iPhone|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i', $ua)) {
            return 'mobile';
        }

        if (preg_match('/Windows|Macintosh|Linux|X11|CrOS/i', $ua)) {
            return 'desktop';
        }

        return 'unknown';
    }

    private function detectOperatingSystem(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/Windows NT|Windows/i', $ua) => 'Windows',
            (bool) preg_match('/Android/i', $ua) => 'Android',
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'iOS',
            (bool) preg_match('/Mac OS X|Macintosh/i', $ua) => 'macOS',
            (bool) preg_match('/Linux|X11|CrOS/i', $ua) => 'Linux',
            default => 'Unknown',
        };
    }

    private function detectBrowser(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/Edg\//i', $ua) => 'Edge',
            (bool) preg_match('/Chrome\//i', $ua) && ! preg_match('/Edg\//i', $ua) => 'Chrome',
            (bool) preg_match('/Firefox\//i', $ua) => 'Firefox',
            (bool) preg_match('/Safari\//i', $ua) && ! preg_match('/Chrome\//i', $ua) => 'Safari',
            default => 'Other',
        };
    }

    private function detectBrowserVersion(string $ua): ?string
    {
        $patterns = [
            'Edge' => '/Edg\/([0-9.]+)/i',
            'Chrome' => '/Chrome\/([0-9.]+)/i',
            'Firefox' => '/Firefox\/([0-9.]+)/i',
            'Safari' => '/Version\/([0-9.]+).*Safari/i',
        ];

        $browser = $this->detectBrowser($ua);
        $pattern = $patterns[$browser] ?? null;
        if ($pattern === null) {
            return null;
        }

        if (preg_match($pattern, $ua, $matches)) {
            return $matches[1] ?? null;
        }

        return null;
    }

    /**
     * @param  list<string>  $values
     * @return list<array{value: string, label: string}>
     */
    private function optionList(array $values, string $group): array
    {
        $items = [];
        foreach ($values as $value) {
            $key = 'messages.employee_sessions.'.$group.'.'.$value;
            $label = __($key);
            $items[] = [
                'value' => $value,
                'label' => $label !== $key ? $label : $value,
            ];
        }

        return $items;
    }
}
