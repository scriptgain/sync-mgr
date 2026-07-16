<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\SyncEvent;
use Illuminate\Http\Request;

class SyncEventController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        $events = SyncEvent::visibleTo($user)
            ->with(['folder:id,name', 'device:id,name'])
            ->when($request->integer('folder_id'), fn ($q, $id) => $q->where('folder_id', $id))
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        // Folder filter options, scoped to what the user may see.
        $folders = Folder::visibleTo($user)->orderBy('name')->get(['id', 'name']);

        return view('events.index', [
            'events' => $events,
            'folders' => $folders,
            'folderId' => $request->integer('folder_id') ?: null,
        ]);
    }

    public function show(SyncEvent $syncEvent)
    {
        abort_unless($syncEvent->isVisibleTo(auth()->user()), 403);
        $syncEvent->load(['folder:id,name', 'device:id,name']);

        return view('events.show', ['event' => $syncEvent]);
    }
}
