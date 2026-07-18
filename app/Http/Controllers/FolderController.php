<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOwners;
use App\Jobs\RunSyncJob;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FolderController extends Controller
{
    use ManagesOwners;

    public function index()
    {
        $user = auth()->user();
        $folders = Folder::visibleTo($user)->with(['owner:id,name', 'mainDevice:id,name', 'peerDevice:id,name'])->latest()->paginate(25)->withQueryString();

        $stats = [
            'total' => Folder::visibleTo($user)->count(),
            'enabled' => Folder::visibleTo($user)->where('enabled', true)->count(),
            'errors' => Folder::visibleTo($user)->where('last_status', 'failed')->count(),
        ];

        return view('folders.index', compact('folders', 'stats'));
    }

    public function create()
    {
        return view('folders.create', [
            'owners' => $this->assignableOwners(),
            'endpoints' => Device::visibleTo(auth()->user())->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);
        unset($data['owner_id']);

        $this->applyLegacyDefaults($data);

        if (empty($data['folder_id'])) {
            $data['folder_id'] = Folder::generateFolderId();
        }

        $folder = Folder::create($data);
        $this->assignFromRequest($folder, $request);
        AuditLog::record('created', "Pairing \"{$folder->name}\" created", $folder);

        return redirect()->route('folders.show', $folder)->with('status', "Pairing \"{$folder->name}\" created.");
    }

    public function show(Folder $folder)
    {
        $this->guard($folder);
        $folder->load(['owner:id,name', 'mainDevice', 'peerDevice']);
        $events = $folder->syncEvents()->with('device:id,name')->latest('occurred_at')->latest('id')->limit(20)->get();

        return view('folders.show', compact('folder', 'events'));
    }

    public function edit(Folder $folder)
    {
        $this->guard($folder);

        return view('folders.edit', [
            'folder' => $folder,
            'owners' => $this->assignableOwners(),
            'endpoints' => Device::visibleTo(auth()->user())->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Folder $folder)
    {
        $this->guard($folder);
        $data = $this->validated($request, $folder);
        if (auth()->user()->isAdmin()) {
            $data['user_id'] = $data['owner_id'] ?? null;
        }
        unset($data['owner_id']);

        $this->applyLegacyDefaults($data);

        if (empty($data['folder_id'])) {
            $data['folder_id'] = $folder->folder_id ?: Folder::generateFolderId();
        }

        $folder->update($data);
        $this->assignFromRequest($folder, $request);
        AuditLog::record('updated', "Pairing \"{$folder->name}\" updated", $folder);

        return redirect()->route('folders.show', $folder)->with('status', 'Pairing updated.');
    }

    public function destroy(Folder $folder)
    {
        $this->guard($folder);
        $name = $folder->name;
        $folder->delete();
        AuditLog::record('deleted', "Pairing \"{$name}\" deleted");

        return redirect()->route('folders.index')->with('status', "Pairing \"{$name}\" deleted.");
    }

    /** Queue an immediate run of this pairing. */
    public function syncNow(Folder $folder)
    {
        $this->guard($folder);

        if (! $folder->main_device_id || ! $folder->peer_device_id) {
            return back()->with('warning', 'Set both a Main and a Peer endpoint before running this pairing.');
        }

        RunSyncJob::dispatch($folder->id);
        $folder->forceFill(['status' => 'syncing'])->save();
        AuditLog::record('sync', "Sync \"{$folder->name}\" queued", $folder);

        return back()->with('status', "Sync queued for \"{$folder->name}\". Results appear here within a minute.");
    }

    /**
     * Bulk-delete selected pairings. Only the submitted ids are touched, and
     * only pairings the current user is allowed to see.
     */
    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ids = Folder::visibleTo(auth()->user())->whereIn('id', $data['ids'])->pluck('id');

        if ($ids->isEmpty()) {
            return back()->with('warning', 'No matching pairings were selected.');
        }

        $count = Folder::whereIn('id', $ids->all())->delete();

        AuditLog::record('deleted', "Bulk deleted {$count} pairing".($count === 1 ? '' : 's').'.');

        return back()->with('status', $count.' pairing'.($count === 1 ? '' : 's').' deleted.');
    }

    /** Fill the legacy Syncthing columns from the pairing's roles. */
    private function applyLegacyDefaults(array &$data): void
    {
        $data['type'] = $data['main_mode'] ?? 'send_only';
        $data['path'] = $data['subpath'] ?? '';
    }

    private function guard(Folder $folder): void
    {
        abort_unless($folder->isVisibleTo(auth()->user()), 403);
    }

    private function validated(Request $request, ?Folder $folder = null): array
    {
        $visibleIds = Device::visibleTo(auth()->user())->pluck('id')->all();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'folder_id' => ['nullable', 'string', 'max:120', Rule::unique('folders', 'folder_id')->ignore($folder?->id)],
            'main_device_id' => ['required', Rule::in($visibleIds)],
            'peer_device_id' => ['required', Rule::in($visibleIds)],
            'main_mode' => ['required', Rule::in(array_keys(Folder::MODES))],
            'peer_mode' => ['required', Rule::in(array_keys(Folder::MODES))],
            'subpath' => ['nullable', 'string', 'max:1024'],
            'interval_minutes' => ['nullable', 'integer', 'min:0', 'max:525600'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ], [
            'main_device_id.in' => 'Choose a valid Main endpoint.',
            'peer_device_id.in' => 'Choose a valid Peer endpoint.',
        ]);

        // Main and Peer must be different endpoints.
        if ($data['main_device_id'] == $data['peer_device_id']) {
            throw ValidationException::withMessages([
                'peer_device_id' => 'The Main and Peer must be two different endpoints.',
            ]);
        }

        // Roles must resolve to a real data flow. Exactly one direction of travel:
        // Send Only + Receive Only (one-way), or Send & Receive on both (two-way).
        $combo = [$data['main_mode'], $data['peer_mode']];
        $allowed = [['send_only', 'receive_only'], ['receive_only', 'send_only'], ['send_receive', 'send_receive']];
        if (! in_array($combo, $allowed, true)) {
            throw ValidationException::withMessages([
                'peer_mode' => 'These roles will not move any files. Use Send Only + Receive Only for a one-way mirror, or Send & Receive on both endpoints for two-way sync.',
            ]);
        }

        $data['enabled'] = $request->boolean('enabled');
        $data['interval_minutes'] = (int) ($data['interval_minutes'] ?? 0);

        return $data;
    }
}
