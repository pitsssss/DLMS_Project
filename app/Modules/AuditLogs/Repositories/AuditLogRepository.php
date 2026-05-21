<?php

namespace App\Modules\AuditLogs\Repositories;

use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AuditLogRepository
{
    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(
        int $perPage,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $action = null,
        ?int $userId = null
    ): LengthAwarePaginator {
        $query = AuditLog::query()
            ->with('user')
            ->orderByDesc('id');

        if ($entityType !== null && $entityType !== '') {
            $query->where('entity_type', $entityType);
        }

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        if ($action !== null && $action !== '') {
            $query->where('action', $action);
        }

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $query->paginate($perPage);
    }
}
