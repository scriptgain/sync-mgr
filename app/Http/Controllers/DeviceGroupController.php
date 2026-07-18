<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOwners;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceGroup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceGroupController extends Controller
{
    use ManagesOwners;

    public function index()
    {
        $user = auth()->user();
        $groups = DeviceGroup::visibleTo($user)->with('owner:id,name')->withCount('devices')->latest()->paginate(25)->withQueryString();

        $stats = [
            'total' => DeviceGroup::visibleTo($user)->count(),
            'members' => Device::visibleTo($user)->whereHas('groups')->count(),
            'empty' => DeviceGroup::visibleTo($user)->has('devices', '=', 0)->count(),
        ];

        return view('device-groups.index', compact('groups', 'stats'));
    }

    public function create()
    {
        return view('device-groups.create', [
            'group' => null,
            'owners' => $this->assignableOwners(),
            'endpoints' => Device::visibleTo(auth()->user())->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);
        unset($data['owner_id'], $data['devices']);

        $group = DeviceGroup::create($data);
        $this->syncMembers($group, $request);
        $this->assignFromRequest($group, $request);
        AuditLog::record('created', "Device Group \"{$group->name}\" created", $group);

        return redirect()->route('device-groups.show', $group)->with('status', "Device Group \"{$group->name}\" created.");
    }

    public function show(DeviceGroup $deviceGroup)
    {
        $this->guard($deviceGroup);
        $deviceGroup->load([
            'owner:id,name',
            'devices' => fn ($q) => $q->orderBy('name'),
        ]);

        return view('device-groups.show', ['group' => $deviceGroup]);
    }

    public function edit(DeviceGroup $deviceGroup)
    {
        $this->guard($deviceGroup);
        $deviceGroup->load('devices:id');

        return view('device-groups.edit', [
            'group' => $deviceGroup,
            'owners' => $this->assignableOwners(),
            'endpoints' => Device::visibleTo(auth()->user())->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, DeviceGroup $deviceGroup)
    {
        $this->guard($deviceGroup);
        $data = $this->validated($request);
        if (auth()->user()->isAdmin()) {
            $data['user_id'] = $data['owner_id'] ?? null;
        }
        unset($data['owner_id'], $data['devices']);

        $deviceGroup->update($data);
        $this->syncMembers($deviceGroup, $request);
        $this->assignFromRequest($deviceGroup, $request);
        AuditLog::record('updated', "Device Group \"{$deviceGroup->name}\" updated", $deviceGroup);

        return redirect()->route('device-groups.show', $deviceGroup)->with('status', 'Device Group updated.');
    }

    public function destroy(DeviceGroup $deviceGroup)
    {
        $this->guard($deviceGroup);
        $name = $deviceGroup->name;
        $deviceGroup->delete();
        AuditLog::record('deleted', "Device Group \"{$name}\" deleted");

        return redirect()->route('device-groups.index')->with('status', "Device Group \"{$name}\" deleted.");
    }

    /**
     * Pause or resume a group. A paused group contributes no peers to a fan-out:
     * pairings that target it skip its members until it is resumed.
     */
    public function togglePause(DeviceGroup $deviceGroup)
    {
        $this->guard($deviceGroup);
        $deviceGroup->forceFill(['paused' => ! $deviceGroup->paused])->save();
        $verb = $deviceGroup->paused ? 'paused' : 'resumed';
        AuditLog::record('updated', "Device Group \"{$deviceGroup->name}\" {$verb}", $deviceGroup);

        return back()->with('status', "Device Group \"{$deviceGroup->name}\" {$verb}.");
    }

    /** Bulk pause selected groups (owner-scoped). */
    public function bulkPause(Request $request)
    {
        return $this->bulkSetPaused($request, true);
    }

    /** Bulk resume selected groups (owner-scoped). */
    public function bulkResume(Request $request)
    {
        return $this->bulkSetPaused($request, false);
    }

    /** Shared body for bulk pause/resume: only touch owner-visible submitted ids. */
    private function bulkSetPaused(Request $request, bool $paused)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ids = DeviceGroup::visibleTo(auth()->user())->whereIn('id', $data['ids'])->pluck('id');
        if ($ids->isEmpty()) {
            return back()->with('warning', 'No matching groups were selected.');
        }

        $count = DeviceGroup::whereIn('id', $ids->all())->update(['paused' => $paused]);
        $verb = $paused ? 'paused' : 'resumed';
        AuditLog::record('updated', "Bulk {$verb} {$count} device group".($count === 1 ? '' : 's').'.');

        return back()->with('status', $count.' group'.($count === 1 ? '' : 's').' '.$verb.'.');
    }

    /**
     * Bulk-delete selected groups. Only the submitted ids are touched, and only
     * groups the current user is allowed to see.
     */
    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ids = DeviceGroup::visibleTo(auth()->user())->whereIn('id', $data['ids'])->pluck('id');

        if ($ids->isEmpty()) {
            return back()->with('warning', 'No matching groups were selected.');
        }

        $count = DeviceGroup::whereIn('id', $ids->all())->delete();

        AuditLog::record('deleted', "Bulk deleted {$count} device group".($count === 1 ? '' : 's').'.');

        return back()->with('status', $count.' device group'.($count === 1 ? '' : 's').' deleted.');
    }

    private function guard(DeviceGroup $group): void
    {
        abort_unless($group->isVisibleTo(auth()->user()), 403);
    }

    /** Replace the group's membership with the submitted, owner-visible ids. */
    private function syncMembers(DeviceGroup $group, Request $request): void
    {
        $submitted = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('devices', [])))));
        // Only assign endpoints the current user is actually allowed to see.
        $allowed = Device::visibleTo(auth()->user())->whereIn('id', $submitted)->pluck('id')->all();
        $group->devices()->sync($allowed);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'devices' => ['nullable', 'array'],
            'devices.*' => ['integer'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ]);
    }
}
