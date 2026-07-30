<?php

namespace App\Modules\Dashboard\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Modules\Dashboard\Support\PermissionRegistry;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent, non-destructive RBAC bootstrap.
 * Creates missing permissions/roles/metadata. Does not overwrite existing role permission pivots
 * unless explicitly repairing a known incorrect baseline (separate repair command).
 */
class RbacBootstrapService
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function bootstrap(bool $apply = true): array
    {
        $createdPermissions = [];
        $createdRoles = [];
        $updatedRoleMeta = [];
        $seededEmptyRolePermissions = [];

        $permissionNames = PermissionRegistry::permissionNames();

        foreach (PermissionRegistry::permissions() as $meta) {
            $name = (string) $meta['name'];
            $existing = Permission::query()->where('name', $name)->first();
            if ($existing === null) {
                if ($apply) {
                    Permission::query()->create(['name' => $name]);
                }
                $createdPermissions[] = $name;
            }
        }

        foreach (PermissionRegistry::systemRoles() as $def) {
            $name = (string) $def['name'];
            $role = Role::query()->where('name', $name)->first();

            if ($role === null) {
                if ($apply) {
                    $role = Role::query()->create([
                        'name' => $name,
                        'display_name' => $def['label'] ?? $name,
                        'description' => $def['description'] ?? null,
                        'is_system' => (bool) ($def['is_system'] ?? true),
                        'is_protected' => (bool) ($def['is_protected'] ?? false),
                        'is_assignable' => (bool) ($def['is_assignable'] ?? true),
                        'is_archived' => false,
                        'version' => 1,
                    ]);
                    $this->seedBaselineIfEmpty($role, $def['baseline_permissions'] ?? []);
                }
                $createdRoles[] = $name;
                continue;
            }

            $metaChanges = [];
            foreach ([
                'display_name' => $def['label'] ?? $role->display_name,
                'description' => $def['description'] ?? $role->description,
                'is_system' => (bool) ($def['is_system'] ?? $role->is_system),
                'is_protected' => (bool) ($def['is_protected'] ?? $role->is_protected),
                'is_assignable' => (bool) ($def['is_assignable'] ?? $role->is_assignable),
            ] as $field => $value) {
                if ($role->{$field} != $value) {
                    // Only fill empty metadata fields non-destructively for display_name/description.
                    if (in_array($field, ['display_name', 'description'], true) && filled($role->{$field})) {
                        continue;
                    }
                    $metaChanges[$field] = $value;
                }
            }

            if ($metaChanges !== []) {
                if ($apply) {
                    $role->fill($metaChanges)->save();
                }
                $updatedRoleMeta[$name] = array_keys($metaChanges);
            }

            if ($role->permissions()->count() === 0) {
                if ($apply) {
                    $this->seedBaselineIfEmpty($role, $def['baseline_permissions'] ?? []);
                }
                $seededEmptyRolePermissions[] = $name;
            }
        }

        $obsolete = Permission::query()
            ->whereNotIn('name', $permissionNames)
            ->pluck('name')
            ->all();

        return [
            'apply' => $apply,
            'created_permissions' => $createdPermissions,
            'created_roles' => $createdRoles,
            'updated_role_metadata_fields' => $updatedRoleMeta,
            'seeded_empty_role_permissions' => $seededEmptyRolePermissions,
            'obsolete_unregistered_permissions' => $obsolete,
            'note' => 'Existing non-empty role permission pivots were not overwritten.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        $missingPermissions = [];
        foreach (PermissionRegistry::permissionNames() as $name) {
            if (! Permission::query()->where('name', $name)->exists()) {
                $missingPermissions[] = $name;
            }
        }

        $obsolete = Permission::query()
            ->whereNotIn('name', PermissionRegistry::permissionNames())
            ->pluck('name')
            ->all();

        $reviewer = Role::query()->where('name', 'profile_document_reviewer')->with('permissions')->first();
        $current = $reviewer?->permissions->pluck('name')->sort()->values()->all() ?? [];
        $expected = PermissionRegistry::documentReviewerBaselinePermissions();
        sort($expected);

        return [
            'missing_permissions' => $missingPermissions,
            'obsolete_permissions' => $obsolete,
            'document_reviewer' => [
                'exists' => $reviewer !== null,
                'current_permissions' => $current,
                'expected_baseline' => $expected,
                'extra' => array_values(array_diff($current, $expected)),
                'missing' => array_values(array_diff($expected, $current)),
                'needs_repair' => $current !== $expected,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function repairDocumentReviewer(bool $apply = false): array
    {
        $role = Role::query()->where('name', 'profile_document_reviewer')->with('permissions')->first();
        if ($role === null) {
            return [
                'apply' => $apply,
                'changed' => false,
                'message' => 'profile_document_reviewer role not found',
            ];
        }

        $old = $role->permissions->pluck('name')->sort()->values()->all();
        $expected = PermissionRegistry::documentReviewerBaselinePermissions();
        sort($expected);

        $changed = $old !== $expected;

        if ($apply && $changed) {
            DB::transaction(function () use ($role, $expected, $old): void {
                $ids = Permission::query()->whereIn('name', $expected)->pluck('id');
                $role->permissions()->sync($ids);
                $role->version = ((int) $role->version) + 1;
                $role->save();

                $this->auditLogs->log(
                    null,
                    'document_reviewer.permissions_repaired',
                    'role',
                    $role->id,
                    ['permission_names' => $old],
                    [
                        'permission_names' => $expected,
                        'added' => array_values(array_diff($expected, $old)),
                        'removed' => array_values(array_diff($old, $expected)),
                        'source' => 'rbac:repair-document-reviewer',
                    ]
                );
            });
        }

        return [
            'apply' => $apply,
            'changed' => $changed,
            'old_permissions' => $old,
            'new_permissions' => $expected,
            'added' => array_values(array_diff($expected, $old)),
            'removed' => array_values(array_diff($old, $expected)),
            'super_admin_untouched' => true,
        ];
    }

    /**
     * @param  list<string>  $baseline
     */
    private function seedBaselineIfEmpty(Role $role, array $baseline): void
    {
        if ($role->permissions()->exists()) {
            return;
        }

        if (in_array('*', $baseline, true)) {
            $role->permissions()->sync(Permission::query()->pluck('id'));

            return;
        }

        $ids = Permission::query()->whereIn('name', $baseline)->pluck('id');
        $role->permissions()->sync($ids);
    }
}
