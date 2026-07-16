@php $d = $device ?? null; @endphp
<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
    <x-field label="Device Name" for="name" required :error="$errors->first('name')">
        <x-input id="name" name="name" required :value="old('name', $d->name ?? '')" placeholder="Office Laptop" />
    </x-field>
    <x-field label="Device ID" for="device_id" hint="Unique device key. Leave blank to generate one." :error="$errors->first('device_id')">
        <x-input id="device_id" name="device_id" :value="old('device_id', $d->device_id ?? '')" placeholder="Auto-generated" />
    </x-field>
    <x-field label="Address" for="address" hint="dynamic, or tcp://host:port." :error="$errors->first('address')">
        <x-input id="address" name="address" :value="old('address', $d->address ?? '')" placeholder="dynamic" />
    </x-field>
    <x-field label="Status" for="status" required :error="$errors->first('status')">
        <x-select id="status" name="status" required>
            @foreach (\App\Models\Device::STATUSES as $val => $label)
                <option value="{{ $val }}" @selected(old('status', $d->status ?? 'disconnected') === $val)>{{ $label }}</option>
            @endforeach
        </x-select>
    </x-field>
</div>

<x-toggle name="is_local" label="Local Device" description="This is the panel's own node, not a remote peer."
    :checked="(bool) old('is_local', $d->is_local ?? false)" />

<x-field label="Notes" for="notes" :error="$errors->first('notes')">
    <textarea id="notes" name="notes" rows="3"
        class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-500"
        placeholder="Optional notes about this device.">{{ old('notes', $d->notes ?? '') }}</textarea>
</x-field>

@isset($owners)
    @if ($owners->isNotEmpty())
        <x-field label="Owner" for="owner_id" hint="User who owns this device." :error="$errors->first('owner_id')">
            <x-select id="owner_id" name="owner_id">
                <option value="">{{ auth()->user()->name }} (me)</option>
                @foreach ($owners as $owner)
                    <option value="{{ $owner->id }}" @selected(old('owner_id', $d->user_id ?? '') == $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                @endforeach
            </x-select>
        </x-field>
        <x-field label="Also Visible To" hint="Extra users who can see this device. Leave empty for the owner and admins only.">
            <x-assignee-picker :users="$owners" :selected="$d?->assignees?->pluck('id')->all() ?? []" />
        </x-field>
    @endif
@endisset
