@php
    $f = $folder ?? null;
    $endpoints = $endpoints ?? collect();
    $groups = $groups ?? collect();
    $selectedPeerIds = collect(old('peer_device_ids', $f?->peers?->pluck('id')->all() ?? []))
        ->map(fn ($v) => (int) $v)->all();
    $selectedGroupIds = collect(old('peer_group_ids', []))->map(fn ($v) => (int) $v)->all();
    $groupMembers = $groups->mapWithKeys(fn ($g) => [$g->id => $g->devices->pluck('id')->map(fn ($i) => (int) $i)->values()->all()]);
@endphp
<div class="space-y-5"
     x-data="{
        mainId: '{{ old('main_device_id', $f->main_device_id ?? '') }}',
        mainMode: '{{ old('main_mode', $f->main_mode ?? 'send_only') }}',
        peerDevices: {{ \Illuminate\Support\Js::from($selectedPeerIds) }},
        peerGroups: {{ \Illuminate\Support\Js::from($selectedGroupIds) }},
        groupMembers: {{ \Illuminate\Support\Js::from($groupMembers) }},
        scheduleMode: '{{ old('schedule_mode', $f->schedule_mode ?? 'scheduled') }}',
        toggleDevice(id) { this.peerDevices.includes(id) ? this.peerDevices.splice(this.peerDevices.indexOf(id), 1) : this.peerDevices.push(id); },
        toggleGroup(id) { this.peerGroups.includes(id) ? this.peerGroups.splice(this.peerGroups.indexOf(id), 1) : this.peerGroups.push(id); },
        get resolvedPeers() {
            const s = new Set(this.peerDevices.map(Number));
            this.peerGroups.forEach(g => (this.groupMembers[g] || []).forEach(id => s.add(Number(id))));
            if (this.mainId) s.delete(Number(this.mainId));
            return [...s];
        },
        isPeer(id) { return this.resolvedPeers.includes(Number(id)); },
        viaGroupOnly(id) { return ! this.peerDevices.includes(Number(id)) && this.isPeer(id); },
        flow() {
            const n = this.resolvedPeers.length;
            if (this.mainMode === 'send_only') return n < 1 ? 'invalid' : (n > 1 ? 'fanout' : 'out');
            if (this.mainMode === 'receive_only') return n === 1 ? 'in' : 'invalid';
            if (this.mainMode === 'send_receive') return n === 1 ? 'two' : 'invalid';
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
                    <div class="relative">
                        <select id="main_device_id" name="main_device_id" x-model="mainId" required
                            class="block w-full appearance-none rounded-lg border-0 bg-white pl-3 pr-11 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-500">
                            <option value="">Select an endpoint</option>
                            @foreach ($endpoints as $ep)
                                <option value="{{ $ep->id }}">{{ $ep->name }} ({{ $ep->typeLabel() }})</option>
                            @endforeach
                        </select>
                        <x-icon name="chevron-down" class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                    </div>
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
            <p class="mt-2 text-xs text-slate-500">
                <span x-show="mainMode === 'send_only'">Send Only mirrors the Main out to every peer below (fan-out).</span>
                <span x-show="mainMode === 'receive_only'" x-cloak>Receive Only pulls in from exactly one peer.</span>
                <span x-show="mainMode === 'send_receive'" x-cloak>Send &amp; Receive is two-way with exactly one peer (coming soon).</span>
            </p>
        </div>

        {{-- Resolved data-flow indicator --}}
        <div class="flex items-center justify-center gap-3 text-sm font-medium text-center">
            <span class="text-slate-500">Data flow:</span>
            <span x-show="flow() === 'out'" class="inline-flex items-center gap-2 text-emerald-700"><span>Main</span><x-icon name="arrow-up" class="w-4 h-4 rotate-90" /><span>Peer</span></span>
            <span x-show="flow() === 'fanout'" x-cloak class="inline-flex items-center gap-2 text-emerald-700"><span>Main</span><x-icon name="arrow-up" class="w-4 h-4 rotate-90" /><span><span x-text="resolvedPeers.length"></span> Peers (fan-out)</span></span>
            <span x-show="flow() === 'in'" x-cloak class="inline-flex items-center gap-2 text-emerald-700"><span>Peer</span><x-icon name="arrow-up" class="w-4 h-4 rotate-90" /><span>Main</span></span>
            <span x-show="flow() === 'two'" x-cloak class="inline-flex items-center gap-2 text-amber-700"><span>Main</span><x-icon name="refresh" class="w-4 h-4" /><span>Peer</span> <span class="text-xs">(two-way, coming soon)</span></span>
            <span x-show="flow() === 'invalid'" x-cloak class="text-rose-600"
                x-text="mainMode === 'send_only' ? 'Add at least one peer to fan out to.' : 'Pull and two-way work with exactly one peer. Use Send Only to fan out to many.'"></span>
        </div>

        {{-- Peers: add as many devices and/or whole groups as you want --}}
        <div class="rounded-xl ring-1 ring-slate-200 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <div class="flex items-center gap-2">
                    <x-badge color="neutral" dot>Peers</x-badge>
                    <span class="text-sm text-slate-500">Every endpoint the Main syncs with. <span class="font-medium text-slate-700"><span x-text="resolvedPeers.length"></span> selected</span>.</span>
                </div>
            </div>

            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-2">Endpoints</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                @foreach ($endpoints as $ep)
                    <div class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 ring-1 ring-inset transition"
                         :class="isPeer({{ $ep->id }}) ? 'bg-brand-50 ring-brand-200' : 'bg-white ring-slate-200'"
                         x-show="String(mainId) !== '{{ $ep->id }}'">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-slate-900 truncate">{{ $ep->name }}</span>
                            <span class="block text-xs text-slate-500">{{ $ep->typeLabel() }}{{ $ep->host ? ' · ' . $ep->host : '' }}<span x-show="viaGroupOnly({{ $ep->id }})" x-cloak class="text-brand-600"> · via group</span></span>
                        </span>
                        <button type="button" role="switch" @click="toggleDevice({{ $ep->id }})"
                            :aria-checked="peerDevices.includes({{ $ep->id }}).toString()"
                            :class="peerDevices.includes({{ $ep->id }}) ? 'bg-brand-600' : (viaGroupOnly({{ $ep->id }}) ? 'bg-brand-300' : 'bg-slate-300')"
                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors"
                            aria-label="Toggle peer">
                            <span :class="peerDevices.includes({{ $ep->id }}) ? 'translate-x-6' : 'translate-x-1'"
                                class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                        </button>
                    </div>
                @endforeach
            </div>

            @if ($groups->isNotEmpty())
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mt-4 mb-2">Add Groups <span class="normal-case font-normal text-slate-400">(expands to member endpoints)</span></p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($groups as $grp)
                        <button type="button" @click="toggleGroup({{ $grp->id }})"
                            :class="peerGroups.includes({{ $grp->id }}) ? 'bg-brand-600 text-white ring-brand-600' : 'text-slate-600 ring-slate-200 hover:ring-slate-300'"
                            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-sm font-medium ring-1 ring-inset transition">
                            <x-icon name="users" class="w-4 h-4" />{{ $grp->name }}
                            <span class="text-xs opacity-80">({{ $grp->devices_count ?? $grp->devices->count() }})</span>
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- Hidden inputs mirror the Alpine selections for submission. --}}
            <template x-for="id in peerDevices" :key="'d' + id"><input type="hidden" name="peer_device_ids[]" :value="id"></template>
            <template x-for="id in peerGroups" :key="'g' + id"><input type="hidden" name="peer_group_ids[]" :value="id"></template>

            <p class="mt-3 text-xs text-slate-500">Groups are just saved sets of endpoints; adding one drops its current members in as peers. Mix ad-hoc endpoints and groups freely.</p>
        </div>
    @endif

    {{-- Schedule --}}
    <div class="rounded-xl ring-1 ring-slate-200 p-4 space-y-4">
        <div class="flex items-center gap-2">
            <x-badge color="neutral" dot>Schedule</x-badge>
            <span class="text-sm text-slate-500">How this pairing runs automatically.</span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
            @php
                $modeMeta = [
                    'manual' => ['Manual', 'Sync Now only.'],
                    'scheduled' => ['Scheduled', 'Every N minutes.'],
                    'onchange' => ['On Change', 'Continuous polling.'],
                ];
            @endphp
            @foreach ($modeMeta as $val => [$mLabel, $mDesc])
                <button type="button" @click="scheduleMode = '{{ $val }}'"
                    :class="scheduleMode === '{{ $val }}' ? 'ring-brand-500 bg-brand-50' : 'ring-slate-200 hover:ring-slate-300'"
                    class="text-left rounded-lg px-3 py-2.5 ring-1 ring-inset transition">
                    <span class="flex items-center gap-2 text-sm font-medium text-slate-900">
                        <span class="inline-flex h-4 w-4 items-center justify-center rounded-full ring-1 ring-inset"
                            :class="scheduleMode === '{{ $val }}' ? 'ring-brand-500 bg-brand-500' : 'ring-slate-300'">
                            <span x-show="scheduleMode === '{{ $val }}'" class="h-1.5 w-1.5 rounded-full bg-white"></span>
                        </span>
                        {{ $mLabel }}
                    </span>
                    <span class="block text-xs text-slate-500 mt-0.5 pl-6">{{ $mDesc }}</span>
                </button>
            @endforeach
        </div>
        <input type="hidden" name="schedule_mode" :value="scheduleMode">

        <div x-show="scheduleMode !== 'manual'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <x-field :error="$errors->first('interval_minutes')">
                <x-slot:label>
                    <span x-show="scheduleMode === 'scheduled'">Run Every (Minutes)</span>
                    <span x-show="scheduleMode === 'onchange'" x-cloak>Minimum Poll Gap (Minutes)</span>
                </x-slot:label>
                <x-input name="interval_minutes" type="number" min="0" max="525600" :value="old('interval_minutes', $f->interval_minutes ?? ($f ? 0 : 5))" />
                <x-slot:hint>
                    <span x-show="scheduleMode === 'scheduled'">How often to run. Must be greater than 0.</span>
                    <span x-show="scheduleMode === 'onchange'" x-cloak>Continuously checks for changes; near real-time (0 = every dispatcher tick, ~1 min). Instant push requires the Agent transport.</span>
                </x-slot:hint>
            </x-field>
            <div class="flex items-end pb-1">
                <x-toggle name="enabled" label="Automation Enabled" description="When off, this pairing only runs when you click Sync Now."
                    :checked="(bool) old('enabled', $f->enabled ?? false)" />
            </div>
        </div>
        <div x-show="scheduleMode === 'manual'" x-cloak class="rounded-lg bg-slate-50 px-4 py-2.5 text-sm text-slate-600">
            This pairing will only run when you click <span class="font-medium text-slate-700">Sync Now</span>.
        </div>
    </div>

    <x-field label="Subpath (Optional)" for="subpath" hint="Relative path under each endpoint's base path. Leave blank to sync the whole base." :error="$errors->first('subpath')">
        <x-input id="subpath" name="subpath" :value="old('subpath', $f->subpath ?? '')" placeholder="documents" />
    </x-field>

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
