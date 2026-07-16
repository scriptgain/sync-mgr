<?php

namespace App\Models\Concerns;

use App\Models\User;

/**
 * A model whose ownership is inherited from its parent folder. Visibility
 * follows the folder's owner.
 */
trait OwnedViaFolder
{
    /** Visibility follows the parent folder's owner. */
    public function scopeVisibleTo($query, ?User $user)
    {
        if ($user && ! $user->isAdmin()) {
            $query->whereHas('folder', fn ($f) => $f->where('user_id', $user->id));
        }

        return $query;
    }

    public function isVisibleTo(?User $user): bool
    {
        return $user && ($user->isAdmin() || ($this->folder && $this->folder->user_id === $user->id));
    }
}
