@php
    $f = $folder ?? null;
    $endpoints = $endpoints ?? collect();
@endphp
<div class="space-y-5"
     x-data="{
        mainMode: '{{ old('main_mode', $f->main_mode ?? 'send_only') }}',
        peerMode: '{{ old('peer_mode', $f->peer_mode ?? 'receive_only') }}',
        flow() {
            if (this.mainMode === 'send_only' && this.peerMode === 'receive_only') return 'out';
            if (this.mainMode === 'receive_only' && this.peerMode === 'send_only') return 'in';
            if (this.mainMode === 'send_receive' && this.peerMode === 'send_receive') return 'two';
            return 'invalid';
        }
     }">

    <x-field label="Pairing Name" for="name" required :error="$errors->first('name')">
        <x-input id="name" name="name" required :value="old('name', $f->name ?? '')" placeholder="Documents Mirror" />
    </x-field>

    @if ($endpoints->isEmpty())
        <div class="rounded-lg bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-200">
            <p class="text-sm text-amber-800">You need at least two endpoints before you can pair them. <a href="{{ route('devices.create') }}" class="font-medium underline">Add an endpoint</a> first.</p>
        </div>
    @else
        {{-- Main endpoint (source of truth) --}}
        <div class="rounded-xl ring-1 ring-slate-200 p-4">
            <div class="flex items-center gap-2 mb-3">
                <x-badge color="info" dot>Main</x-badge>
                <span class="text-sm text-slate-500">The authoritative endpoint (source of truth).</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <x-field label="Main Endpoint" for="main_device_id" required :error="$errors->first('main_device_id')">
                    <x-select id="main_device_id" name="main_device_id" required>
                        <option value="">Select an endpoint</option>
                        @foreach ($endpoints as $ep)
                            <option value="{{ $ep->id }}" @selected(old('main_device_id', $f->main_device_id ?? '') == $ep->id)>{{ $ep->name }} ({{ $ep->typeLabel() }})</option>
                        @endforeach
                    </x-select>
                </x-field>
                <x-field label="Main Sync Mode" for="main_mode" required :error="$errors->first('main_mode')">
                    <div class="relative">
                        <select id="main_mode" name="main_mode" x-model="mainMode"
                            class="block w-full appearance-none rounded-lg border-0 bg-white pl-3 pr-11 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-500">
                            @foreach (\App\Models\Folder::MODES as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <x-icon name="chevron-down" class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                    </div>
                </x-field>
            </div>
        </div>

        {{-- Resolved data-flow indicator --}}
        <div class="flex items-center justify-center gap-3 text-sm font-medium">
            <span class="text-slate-500">Data flow:</span>
            <span x-show="flow() === 'out'" class="inline-flex items-center gap-2 text-emerald-700"><span>Main</span><x-icon name="arrow-up" class="w-4 h-4 rotate-90" /><span>Peer</span></span>
            <span x-show="flow() === 'in'" class="inline-flex items-center gap-2 text-emerald-700"><span>Peer</span><x-icon name="arrow-up" class="w-4 h-4 rotate-90" /><span>Main</span></span>
            <span x-show="flow() === 'two'" class="inline-flex items-center gap-2 text-amber-700"><span>Main</span><x-icon name="refresh" class="w-4 h-4" /><span>Peer</span> <span class="text-xs">(two-way, coming soon)</span></span>
            <span x-show="flow() === 'invalid'" x-cloak class="text-rose-600">Invalid: pair Send Only with Receive Only, or Send &amp; Receive on both.</span>
        </div>

        {{-- Peer endpoint --}}
        <div class="rounded-xl ring-1 ring-slate-200 p-4">
            <div class="flex items-center gap-2 mb-3">
                <x-badge color="neutral" dot>Peer</x-badge>
                <span class="text-sm text-slate-500">The other side of the pairing.</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <x-field label="Peer Endpoint" for="peer_device_id" required :error="$errors->first('peer_device_id')">
                    <x-select id="peer_device_id" name="peer_device_id" required>
                        <option value="">Select an endpoint</option>
                        @foreach ($endpoints as $ep)
                            <option value="{{ $ep->id }}" @selected(old('peer_device_id', $f->peer_device_id ?? '') == $ep->id)>{{ $ep->name }} ({{ $ep->typeLabel() }})</option>
                        @endforeach
                    </x-select>
                </x-field>
                <x-field label="Peer Sync Mode" for="peer_mode" required :error="$errors->first('peer_mode')">
                    <div class="relative">
                        <select id="peer_mode" name="peer_mode" x-model="peerMode"
                            class="block w-full appearance-none rounded-lg border-0 bg-white pl-3 pr-11 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-500">
                            @foreach (\App\Models\Folder::MODES as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <x-icon name="chevron-down" class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                    </div>
                </x-field>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <x-field label="Subpath (Optional)" for="subpath" hint="Relative path under each endpoint's base path. Leave blank to sync the whole base." :error="$errors->first('subpath')">
            <x-input id="subpath" name="subpath" :value="old('subpath', $f->subpath ?? '')" placeholder="documents" />
        </x-field>
        <x-field label="Run Every (Minutes)" for="interval_minutes" hint="0 = manual only (run with Sync Now)." :error="$errors->first('interval_minutes')">
            <x-input id="interval_minutes" name="interval_minutes" type="number" min="0" max="525600" :value="old('interval_minutes', $f->interval_minutes ?? 0)" />
        </x-field>
    </div>

    <x-toggle name="enabled" label="Enabled" description="Run this pairing on its schedule. Disabled pairings only run when you click Sync Now."
        :checked="(bool) old('enabled', $f->enabled ?? false)" />

    <x-field label="Notes" for="notes" :error="$errors->first('notes')">
        <textarea id="notes" name="notes" rows="3"
            class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-500"
            placeholder="Optional notes about this pairing.">{{ old('notes', $f->notes ?? '') }}</textarea>
    </x-field>

    @isset($owners)
        @if ($owners->isNotEmpty())
            <x-field label="Owner" for="owner_id" hint="User who owns this pairing and its events." :error="$errors->first('owner_id')">
                <x-select id="owner_id" name="owner_id">
                    <option value="">{{ auth()->user()->name }} (me)</option>
                    @foreach ($owners as $owner)
                        <option value="{{ $owner->id }}" @selected(old('owner_id', $f->user_id ?? '') == $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                    @endforeach
                </x-select>
            </x-field>
            <x-field label="Also Visible To" hint="Extra users who can see this pairing. Leave empty for the owner and admins only.">
                <x-assignee-picker :users="$owners" :selected="$f?->assignees?->pluck('id')->all() ?? []" />
            </x-field>
        @endif
    @endisset
</div>
