<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOwners;
use App\Jobs\RunSyncJob;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceGroup;
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
        $folders = Folder::visibleTo($user)->with(['owner:id,name', 'mainDevice:id,name', 'peers:id,name'])->withCount('peers')->latest()->paginate(25)->withQueryString();

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
            'groups' => DeviceGroup::visibleTo(auth()->user())->with('devices:id')->withCount('devices')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);
        $peerIds = $data['_peer_ids'];
        $peerMode = $data['peer_mode'];
        unset($data['owner_id'], $data['_peer_ids']);

        $this->applyLegacyDefaults($data);

        if (empty($data['folder_id'])) {
            $data['folder_id'] = Folder::generateFolderId();
        }

        $folder = Folder::create($data);
        $this->syncPeers($folder, $peerIds, $peerMode);
        $this->assignFromRequest($folder, $request);
        AuditLog::record('created', "Pairing \"{$folder->name}\" created", $folder);

        return redirect()->route('folders.show', $folder)->with('status', "Pairing \"{$folder->name}\" created.");
    }

    public function show(Folder $folder)
    {
        $this->guard($folder);
        $folder->load(['owner:id,name', 'mainDevice', 'peers' => fn ($q) => $q->orderBy('name')]);
        $events = $folder->syncEvents()->with('device:id,name')->latest('occurred_at')->latest('id')->limit(20)->get();

        return view('folders.show', compact('folder', 'events'));
    }

    public function edit(Folder $folder)
    {
        $this->guard($folder);
        $folder->load('peers:id');

        return view('folders.edit', [
            'folder' => $folder,
            'owners' => $this->assignableOwners(),
            'endpoints' => Device::visibleTo(auth()->user())->orderBy('name')->get(),
            'groups' => DeviceGroup::visibleTo(auth()->user())->with('devices:id')->withCount('devices')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Folder $folder)
    {
        $this->guard($folder);
        $data = $this->validated($request, $folder);
        if (auth()->user()->isAdmin()) {
            $data['user_id'] = $data['owner_id'] ?? null;
        }
        $peerIds = $data['_peer_ids'];
        $peerMode = $data['peer_mode'];
        unset($data['owner_id'], $data['_peer_ids']);

        $this->applyLegacyDefaults($data);

        if (empty($data['folder_id'])) {
            $data['folder_id'] = $folder->folder_id ?: Folder::generateFolderId();
        }

        $folder->update($data);
        $this->syncPeers($folder, $peerIds, $peerMode);
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

        if (! $folder->main_device_id || $folder->peers()->count() === 0) {
            return back()->with('warning', 'Set a Main endpoint and at least one peer before running this pairing.');
        }

        // Agent-managed pairing: the master cannot run rclone against an agent, so
        // raise the pending flag the agent claims on its next poll instead of
        // dispatching a server-side RunSyncJob.
        if ($folder->isAgentManaged()) {
            $folder->forceFill(['pending_sync_now' => true, 'status' => 'syncing'])->save();
            AuditLog::record('sync', "Sync \"{$folder->name}\" requested (agent will run on next poll)", $folder);

            return back()->with('status', "Sync requested for \"{$folder->name}\". The agent runs it on its next check-in.");
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

    /** Replace the pairing's peer set, stamping each peer with the resolved role. */
    private function syncPeers(Folder $folder, array $peerIds, string $mode): void
    {
        $sync = [];
        foreach ($peerIds as $id) {
            $sync[(int) $id] = ['mode' => $mode];
        }
        $folder->peers()->sync($sync);
    }

    private function validated(Request $request, ?Folder $folder = null): array
    {
        $user = auth()->user();
        $visibleIds = Device::visibleTo($user)->pluck('id')->all();
        $visibleGroupIds = DeviceGroup::visibleTo($user)->pluck('id')->all();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'folder_id' => ['nullable', 'string', 'max:120', Rule::unique('folders', 'folder_id')->ignore($folder?->id)],
            'main_device_id' => ['required', Rule::in($visibleIds)],
            'main_mode' => ['required', Rule::in(array_keys(Folder::MODES))],
            'peer_device_ids' => ['nullable', 'array'],
            'peer_device_ids.*' => ['integer', Rule::in($visibleIds)],
            'peer_group_ids' => ['nullable', 'array'],
            'peer_group_ids.*' => ['integer', Rule::in($visibleGroupIds)],
            'subpath' => ['nullable', 'string', 'max:1024'],
            'schedule_mode' => ['required', Rule::in(array_keys(Folder::SCHEDULE_MODES))],
            'interval_minutes' => ['nullable', 'integer', 'min:0', 'max:525600'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ], [
            'main_device_id.in' => 'Choose a valid Main endpoint.',
        ]);

        // Resolve the peer SET: ad-hoc devices unioned with the (expanded) members
        // of any selected groups. A group is just a saved set of devices.
        $adhoc = array_map('intval', (array) ($data['peer_device_ids'] ?? []));
        $groupIds = array_map('intval', (array) ($data['peer_group_ids'] ?? []));
        $fromGroups = [];
        if ($groupIds) {
            $fromGroups = Device::visibleTo($user)
                ->whereHas('groups', fn ($q) => $q->whereIn('device_groups.id', $groupIds))
                ->pluck('id')->all();
        }
        $peerIds = array_values(array_unique(array_filter(array_merge($adhoc, $fromGroups))));
        // A peer can never be the Main endpoint.
        $peerIds = array_values(array_diff($peerIds, [(int) $data['main_device_id']]));

        // Resolve per-peer role from the Main mode and enforce a real data flow.
        if ($data['main_mode'] === 'send_only') {
            if (count($peerIds) < 1) {
                throw ValidationException::withMessages([
                    'peer_device_ids' => 'Add at least one peer endpoint or a device group to fan out to.',
                ]);
            }
            $data['peer_mode'] = 'receive_only';
        } elseif ($data['main_mode'] === 'receive_only') {
            if (count($peerIds) !== 1) {
                throw ValidationException::withMessages([
                    'peer_device_ids' => 'Pull (Main Receive Only) works with exactly one peer. Use Send Only on the Main to fan out to many peers.',
                ]);
            }
            $data['peer_mode'] = 'send_only';
        } else { // send_receive
            if (count($peerIds) !== 1) {
                throw ValidationException::withMessages([
                    'peer_device_ids' => 'Two-Way (Send & Receive) works with exactly one peer.',
                ]);
            }
            $data['peer_mode'] = 'send_receive';
        }

        $data['enabled'] = $request->boolean('enabled');
        $data['interval_minutes'] = (int) ($data['interval_minutes'] ?? 0);

        // A Scheduled + enabled pairing needs a positive interval or it never runs.
        if ($data['schedule_mode'] === 'scheduled' && $data['enabled'] && $data['interval_minutes'] < 1) {
            throw ValidationException::withMessages([
                'interval_minutes' => 'Set how many minutes between runs (at least 1) for a Scheduled pairing.',
            ]);
        }

        // Keep the legacy single-peer column meaningful (set only when there is one).
        $data['peer_device_id'] = count($peerIds) === 1 ? $peerIds[0] : null;
        // Stash the resolved peer set for the pivot sync (not a folders column).
        $data['_peer_ids'] = $peerIds;

        unset($data['peer_device_ids'], $data['peer_group_ids']);

        return $data;
    }
}
