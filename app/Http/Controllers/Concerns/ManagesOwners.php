<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

/** Shared owner-assignment helpers for admin-managed, per-user-owned resources. */
trait ManagesOwners
{
    /** Owner to set on store: admins may pick anyone; others own what they create. */
    protected function resolveOwner(Request $request): int
    {
        $user = $request->user();

        return $user->isAdmin() ? (int) ($request->input('owner_id') ?: $user->id) : $user->id;
    }

    /** Users an admin may assign as owner; non-admins get an empty list. */
    protected function assignableOwners()
    {
        return auth()->user()->isAdmin()
            ? User::orderBy('name')->get(['id', 'name', 'email'])
            : collect();
    }

    /**
     * Sync the extra assignees on a resource from the request. Only admins may
     * change assignees; others leave the set untouched.
     */
    protected function assignFromRequest($model, Request $request): void
    {
        if (! auth()->user()->isAdmin() || ! method_exists($model, 'syncAssignees')) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('assignees', [])))));
        $model->syncAssignees($ids);
    }
}
