<?php

namespace App\Modules\Dashboard\Support;

use InvalidArgumentException;

final class PermissionRegistry
{
    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return config('dashboard_permissions', []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function permissions(): array
    {
        return array_values(self::config()['permissions'] ?? []);
    }

    /**
     * @return array<string, array{label: string}>
     */
    public static function modules(): array
    {
        return self::config()['modules'] ?? [];
    }

    /**
     * @return array<string, array{label: string}>
     */
    public static function riskLevels(): array
    {
        return self::config()['risk_levels'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function systemRoles(): array
    {
        return array_values(self::config()['system_roles'] ?? []);
    }

    /**
     * @return list<string>
     */
    public static function permissionNames(): array
    {
        return array_values(array_map(
            static fn (array $p): string => (string) $p['name'],
            self::permissions()
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $name): ?array
    {
        foreach (self::permissions() as $permission) {
            if (($permission['name'] ?? null) === $name) {
                return $permission;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function require(string $name): array
    {
        $permission = self::find($name);

        if ($permission === null) {
            throw new InvalidArgumentException("Unknown permission [{$name}] is not registered.");
        }

        return $permission;
    }

    public static function isKnown(string $name): bool
    {
        return self::find($name) !== null;
    }

    public static function isAssignable(string $name): bool
    {
        $permission = self::find($name);

        return $permission !== null && ($permission['assignable'] ?? false) === true;
    }

    public static function isProtected(string $name): bool
    {
        $permission = self::find($name);

        return $permission !== null && ($permission['protected'] ?? false) === true;
    }

    public static function riskLevel(string $name): string
    {
        return (string) (self::find($name)['risk_level'] ?? 'normal');
    }

    public static function moduleLabel(string $module): string
    {
        return (string) (self::modules()[$module]['label'] ?? $module);
    }

    public static function riskLabel(string $risk): string
    {
        return (string) (self::riskLevels()[$risk]['label'] ?? $risk);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function systemRole(string $name): ?array
    {
        foreach (self::systemRoles() as $role) {
            if (($role['name'] ?? null) === $name) {
                return $role;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function reservedRoleNames(): array
    {
        return array_values(array_map(
            static fn (array $role): string => (string) $role['name'],
            self::systemRoles()
        ));
    }

    public static function isProtectedRoleName(string $name): bool
    {
        $role = self::systemRole($name);

        return $role !== null && ($role['is_protected'] ?? false) === true;
    }

    /**
     * Document reviewer corrected baseline (no general application management).
     *
     * @return list<string>
     */
    public static function documentReviewerBaselinePermissions(): array
    {
        $role = self::systemRole('profile_document_reviewer');

        return array_values(array_filter(
            $role['baseline_permissions'] ?? [],
            static fn (mixed $name): bool => is_string($name) && $name !== '*'
        ));
    }

    /**
     * @param  list<string>  $permissionNames
     * @return array{has_sensitive: bool, has_critical: bool, sensitive: list<string>, critical: list<string>}
     */
    public static function classifyPermissionNames(array $permissionNames): array
    {
        $sensitive = [];
        $critical = [];

        foreach ($permissionNames as $name) {
            $level = self::riskLevel($name);
            if ($level === 'critical') {
                $critical[] = $name;
            } elseif ($level === 'sensitive') {
                $sensitive[] = $name;
            }
        }

        sort($sensitive);
        sort($critical);

        return [
            'has_sensitive' => $sensitive !== [],
            'has_critical' => $critical !== [],
            'sensitive' => $sensitive,
            'critical' => $critical,
        ];
    }
}
