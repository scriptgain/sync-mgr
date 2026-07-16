<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        return Device::visibleTo($request->user())
            ->withCount('folders')
            ->latest()
            ->paginate(50);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);

        if (empty($data['device_id'])) {
            $data['device_id'] = Device::generateDeviceId();
        }

        return response()->json(Device::create($data), 201);
    }

    public function show(Device $device)
    {
        abort_unless($device->isVisibleTo(auth()->user()), 403);

        return $device->load('folders');
    }

    public function update(Request $request, Device $device)
    {
        abort_unless($device->isVisibleTo($request->user()), 403);

        $data = $this->validated($request, $device, updating: true);

        if ($request->user()->isAdmin() && $request->filled('user_id')) {
            $data['user_id'] = $request->validate([
                'user_id' => ['integer', 'exists:users,id'],
            ])['user_id'];
        } else {
            unset($data['user_id']);
        }

        $device->update($data);

        return $device;
    }

    public function destroy(Device $device)
    {
        abort_unless($device->isVisibleTo(auth()->user()), 403);

        $device->delete();

        return response()->noContent();
    }

    /** Admins may assign an explicit owner; everyone else owns what they create. */
    private function resolveOwner(Request $request): int
    {
        if ($request->user()->isAdmin() && $request->filled('user_id')) {
            return (int) $request->validate([
                'user_id' => ['integer', 'exists:users,id'],
            ])['user_id'];
        }

        return $request->user()->id;
    }

    private function validated(Request $request, ?Device $device = null, bool $updating = false): array
    {
        $req = $updating ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:120', Rule::unique('devices', 'device_id')->ignore($device?->id)],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(array_keys(Device::STATUSES))],
            'is_local' => ['sometimes', 'boolean'],
            'last_seen_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if ($request->has('is_local')) {
            $data['is_local'] = $request->boolean('is_local');
        }

        return $data;
    }
}
