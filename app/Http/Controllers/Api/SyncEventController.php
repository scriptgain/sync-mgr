<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncEvent;
use Illuminate\Http\Request;

class SyncEventController extends Controller
{
    public function index(Request $request)
    {
        return SyncEvent::visibleTo($request->user())
            ->with(['folder:id,name', 'device:id,name'])
            ->when($request->integer('folder_id'), fn ($q, $id) => $q->where('folder_id', $id))
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(50);
    }

    public function show(SyncEvent $syncEvent)
    {
        abort_unless($syncEvent->isVisibleTo(auth()->user()), 403);

        return $syncEvent->load(['folder:id,name', 'device:id,name']);
    }
}
