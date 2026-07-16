@php
    $f = $folder ?? null;
    $devices = $devices ?? collect();
    $selected = collect(old('devices', $selectedDevices ?? []))->map(fn ($id) => (int) $id)->all();
@endphp
<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
    <x-field label="Folder Name" for="name" required :error="$errors->first('name')">
        <x-input id="name" name="name" required :value="old('name', $f->name ?? '')" placeholder="Documents" />
    </x-field>
    <x-field label="Folder ID" for="folder_id" hint="Stable label shared across devices. Leave blank to generate one." :error="$errors->first('folder_id')">
        <x-input id="folder_id" name="folder_id" :value="old('folder_id', $f->folder_id ?? '')" placeholder="Auto-generated" />
    </x-field>
    <x-field label="Path" for="path" required hint="Absolute path on this node, e.g. /srv/sync/documents." :error="$errors->first('path')" class="sm:col-span-2">
        <x-input id="path" name="path" required :value="old('path', $f->path ?? '')" placeholder="/srv/sync/documents" />
    </x-field>
    <x-field label="Folder Type" for="type" required :error="$errors->first('type')">
        <x-select id="type" name="type" required>
            @foreach (\App\Models\Folder::TYPES as $val => $label)
                <option value="{{ $val }}" @selected(old('type', $f->type ?? 'send_receive') === $val)>{{ $label }}</option>
            @endforeach
        </x-select>
    </x-field>
    <x-field label="Status" for="status" required :error="$errors->first('status')">
        <x-select id="status" name="status" required>
            @foreach (\App\Models\Folder::STATUSES as $val => $label)
                <option value="{{ $val }}" @selected(old('status', $f->status ?? 'idle') === $val)>{{ $label }}</option>
            @endforeach
        </x-select>
    </x-field>
    <x-field label="Rescan Interval (Seconds)" for="rescan_interval" required hint="How often to rescan for changes." :error="$errors->first('rescan_interval')">
        <x-input id="rescan_interval" name="rescan_interval" type="number" min="0" max="31536000" required :value="old('rescan_interval', $f->rescan_interval ?? 3600)" />
    </x-field>
</div>

<x-toggle name="versioning" label="File Versioning" description="Keep previous versions of a file when it's changed or deleted."
    :checked="(bool) old('versioning', $f->versioning ?? false)" />

<x-field label="Notes" for="notes" :error="$errors->first('notes')">
    <textarea id="notes" name="notes" rows="3"
        class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-500"
        placeholder="Optional notes about this folder.">{{ old('notes', $f->notes ?? '') }}</textarea>
</x-field>

<div>
    <span class="block text-sm font-medium text-slate-700 mb-2">Shared With Devices</span>
    @if ($devices->isEmpty())
        <p class="text-sm text-slate-500">No devices yet. <a href="{{ route('devices.create') }}" class="text-brand-700 hover:underline">Add a device</a> to share this folder with it.</p>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            @foreach ($devices as $device)
                <label class="cursor-pointer select-none">
                    <input type="checkbox" name="devices[]" value="{{ $device->id }}"
                        class="peer sr-only" @checked(in_array($device->id, $selected, true))>
                    <span class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-sm font-medium ring-1 ring-slate-200 text-slate-600 bg-white transition
                                 peer-checked:bg-brand-50 peer-checked:text-brand-700 peer-checked:ring-brand-300
                                 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/60">
                        <x-icon name="server" class="w-4 h-4 shrink-0 text-slate-400" />
                        <span class="min-w-0 truncate">{{ $device->name }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        <p class="mt-2 text-sm text-slate-500">Select the devices this folder should sync with.</p>
    @endif
</div>

@isset($owners)
    @if ($owners->isNotEmpty())
        <x-field label="Owner" for="owner_id" hint="User who owns this folder and its events." :error="$errors->first('owner_id')">
            <x-select id="owner_id" name="owner_id">
                <option value="">{{ auth()->user()->name }} (me)</option>
                @foreach ($owners as $owner)
                    <option value="{{ $owner->id }}" @selected(old('owner_id', $f->user_id ?? '') == $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                @endforeach
            </x-select>
        </x-field>
        <x-field label="Also Visible To" hint="Extra users who can see this folder. Leave empty for the owner and admins only.">
            <x-assignee-picker :users="$owners" :selected="$f?->assignees?->pluck('id')->all() ?? []" />
        </x-field>
    @endif
@endisset
