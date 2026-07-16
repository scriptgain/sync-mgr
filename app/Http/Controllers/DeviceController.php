<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOwners;
use App\Models\AuditLog;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    use ManagesOwners;

    public function index()
    {
        $user = auth()->user();
        $devices = Device::visibleTo($user)->with('owner:id,name')->withCount('folders')->latest()->paginate(25)->withQueryString();

        $stats = [
            'total' => Device::visibleTo($user)->count(),
            'connected' => Device::visibleTo($user)->where('status', 'connected')->count(),
            'local' => Device::visibleTo($user)->where('is_local', true)->count(),
        ];

        return view('devices.index', compact('devices', 'stats'));
    }

    public function create()
    {
        return view('devices.create', ['owners' => $this->assignableOwners()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);
        unset($data['owner_id']);

        if (empty($data['device_id'])) {
            $data['device_id'] = Device::generateDeviceId();
        }

        $device = Device::create($data);
        $this->assignFromRequest($device, $request);
        AuditLog::record('created', "Device \"{$device->name}\" created", $device);

        return redirect()->route('devices.show', $device)->with('status', "Device \"{$device->name}\" created.");
    }

    public function show(Device $device)
    {
        $this->guard($device);
        $device->load(['owner:id,name', 'folders']);

        return view('devices.show', compact('device'));
    }

    public function edit(Device $device)
    {
        $this->guard($device);

        return view('devices.edit', ['device' => $device, 'owners' => $this->assignableOwners()]);
    }

    public function update(Request $request, Device $device)
    {
        $this->guard($device);
        $data = $this->validated($request, $device);
        if (auth()->user()->isAdmin()) {
            $data['user_id'] = $data['owner_id'] ?? null;
        }
        unset($data['owner_id']);

        if (empty($data['device_id'])) {
            $data['device_id'] = $device->device_id ?: Device::generateDeviceId();
        }

        $device->update($data);
        $this->assignFromRequest($device, $request);
        AuditLog::record('updated', "Device \"{$device->name}\" updated", $device);

        return redirect()->route('devices.show', $device)->with('status', 'Device updated.');
    }

    public function destroy(Device $device)
    {
        $this->guard($device);
        $name = $device->name;
        $device->delete();
        AuditLog::record('deleted', "Device \"{$name}\" deleted");

        return redirect()->route('devices.index')->with('status', "Device \"{$name}\" deleted.");
    }

    private function guard(Device $device): void
    {
        abort_unless($device->isVisibleTo(auth()->user()), 403);
    }

    private function validated(Request $request, ?Device $device = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'device_id' => ['nullable', 'string', 'max:120', Rule::unique('devices', 'device_id')->ignore($device?->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(array_keys(Device::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ]);
        $data['is_local'] = $request->boolean('is_local');

        return $data;
    }
}
