<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOwners;
use App\Models\AuditLog;
use App\Models\Device;
use App\Services\RcloneEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        // Never write an empty secret over nothing; drop blanks on create.
        foreach (['secret', 'private_key'] as $s) {
            if (($data[$s] ?? '') === '') {
                unset($data[$s]);
            }
        }

        if (empty($data['device_id'])) {
            $data['device_id'] = Device::generateDeviceId();
        }

        $device = Device::create($data);
        $this->assignFromRequest($device, $request);
        AuditLog::record('created', "Endpoint \"{$device->name}\" created", $device);

        return redirect()->route('devices.show', $device)->with('status', "Endpoint \"{$device->name}\" created.");
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

        // Blank secret fields on edit mean "keep the stored value".
        foreach (['secret', 'private_key'] as $s) {
            if (($data[$s] ?? '') === '') {
                unset($data[$s]);
            }
        }

        if (empty($data['device_id'])) {
            $data['device_id'] = $device->device_id ?: Device::generateDeviceId();
        }

        $device->update($data);
        $this->assignFromRequest($device, $request);
        AuditLog::record('updated', "Endpoint \"{$device->name}\" updated", $device);

        return redirect()->route('devices.show', $device)->with('status', 'Endpoint updated.');
    }

    public function destroy(Device $device)
    {
        $this->guard($device);
        $name = $device->name;
        $device->delete();
        AuditLog::record('deleted', "Endpoint \"{$name}\" deleted");

        return redirect()->route('devices.index')->with('status', "Endpoint \"{$name}\" deleted.");
    }

    /** Run a fail-soft rclone reachability probe against this endpoint. */
    public function test(Device $device, RcloneEngine $engine)
    {
        $this->guard($device);
        $result = $engine->testConnection($device);

        AuditLog::record('sync', "Tested endpoint \"{$device->name}\": " . ($result['ok'] ? 'reachable' : 'unreachable'), $device);

        if ($result['ok']) {
            return back()->with('status', "Endpoint \"{$device->name}\" is reachable.");
        }

        return back()->with('warning', "Endpoint \"{$device->name}\" is unreachable. " . Str::limit($result['output'], 400));
    }

    /**
     * Bulk-delete selected endpoints. Only the submitted ids are touched, and
     * only endpoints the current user is allowed to see.
     */
    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ids = Device::visibleTo(auth()->user())->whereIn('id', $data['ids'])->pluck('id');

        if ($ids->isEmpty()) {
            return back()->with('warning', 'No matching endpoints were selected.');
        }

        $count = Device::whereIn('id', $ids->all())->delete();

        AuditLog::record('deleted', "Bulk deleted {$count} endpoint".($count === 1 ? '' : 's').'.');

        return back()->with('status', $count.' endpoint'.($count === 1 ? '' : 's').' deleted.');
    }

    private function guard(Device $device): void
    {
        abort_unless($device->isVisibleTo(auth()->user()), 403);
    }

    private function validated(Request $request, ?Device $device = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'endpoint_type' => ['required', Rule::in(array_keys(Device::ENDPOINT_TYPES))],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:4096'],
            'private_key' => ['nullable', 'string', 'max:16384'],
            'base_path' => ['nullable', 'string', 'max:1024'],
            'bucket' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:120'],
            'device_id' => ['nullable', 'string', 'max:120', Rule::unique('devices', 'device_id')->ignore($device?->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(array_keys(Device::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ]);
        $data['is_local'] = $request->boolean('is_local') || ($data['endpoint_type'] ?? null) === 'local';
        $data['ftp_tls'] = $request->boolean('ftp_tls');
        $data['s3_path_style'] = $request->boolean('s3_path_style');

        return $data;
    }
}
