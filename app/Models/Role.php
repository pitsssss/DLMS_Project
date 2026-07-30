<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'is_system',
        'is_protected',
        'is_assignable',
        'is_archived',
        'version',
        'created_by',
        'updated_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_protected' => 'boolean',
            'is_assignable' => 'boolean',
            'is_archived' => 'boolean',
            'version' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isArchived(): bool
    {
        return (bool) $this->is_archived;
    }

    public function canBeAssigned(): bool
    {
        return (bool) $this->is_assignable && ! $this->isArchived() && $this->name !== 'citizen';
    }
}
