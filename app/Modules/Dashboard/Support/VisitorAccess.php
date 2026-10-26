<?php

namespace App\Modules\Dashboard\Support;

/**
 * Fixed allowlist for the public demo visitor role.
 * Role pivots and direct grants cannot expand this set.
 */
final class VisitorAccess
{
    public const ROLE = 'visitor';

    public static function enabled(): bool
    {
        return (bool) config('demo.visitor.enabled', false);
    }

    /**
     * @return list<string>
     */
    public static function permissionNames(): array
    {
        $role = PermissionRegistry::systemRole(self::ROLE);
        $names = is_array($role) ? ($role['baseline_permissions'] ?? []) : [];

        $allowed = [];
        foreach ($names as $name) {
            if (! is_string($name) || $name === '*' || ! PermissionRegistry::isKnown($name)) {
                continue;
            }
            $allowed[] = $name;
        }

        return array_values(array_unique($allowed));
    }
}
