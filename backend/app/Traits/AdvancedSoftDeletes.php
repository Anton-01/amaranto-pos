<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\SoftDeletes;

trait AdvancedSoftDeletes
{
    use SoftDeletes;

    public function initializeAdvancedSoftDeletes(): void
    {
        $this->fillable = array_merge($this->fillable, ['deleted_by', 'deletion_reason']);
    }

    public function advancedDelete(?string $deletedBy = null, ?string $reason = null): bool
    {
        $this->deleted_by = $deletedBy;
        $this->deletion_reason = $reason;
        $this->save();

        return $this->delete();
    }

    public function advancedRestore(): bool
    {
        $this->deleted_by = null;
        $this->deletion_reason = null;

        return $this->restore();
    }

    public function deletedByUser()
    {
        return $this->belongsTo(\App\Models\User::class, 'deleted_by');
    }
}
