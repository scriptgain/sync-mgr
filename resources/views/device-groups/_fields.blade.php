@php
    $g = $group ?? null;
    $endpoints = $endpoints ?? collect();
    $selectedDeviceIds = collect(old('devices', $g?->devices?->pluck('id')->all() ?? []))
        ->map(fn ($v) => (int) $v)->all();
    $deviceData = $endpoints->map(fn ($ep) => [
        'id' => $ep->id,
        'name' => $ep->name,
        'type' => $ep->typeLabel(),
        'host' => $ep->endpoint_type === 'local' ? 'localhost' : ($ep->host ?: ''),
    ])->values();
@endphp
<div class="space-y-5">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <x-field label="Group Name" for="name" required :error="$errors->first('name')">
            <x-input id="name" name="name" required :value="old('name', $g->name ?? '')" placeholder="Edge Nodes" />
        </x-field>
    </div>

    <x-field label="Description" for="description" :error="$errors->first('description')">
        <textarea id="description" name="description" rows="2"
            class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-500"
            placeholder="Optional description of what this group is for.">{{ old('description', $g->description ?? '') }}</textarea>
    </x-field>

    {{-- Membership: search + select multiple endpoints (no full list dump). --}}
    <div class="rounded-xl ring-1 ring-slate-200 p-4"
         x-data="{
            members: {{ \Illuminate\Support\Js::from($selectedDeviceIds) }},
            all: {{ \Illuminate\Support\Js::from($deviceData) }},
            search: '',
            get selected() { return this.all.filter(d => this.members.includes(d.id)); },
            get results() {
                const q = this.search.trim().toLowerCase();
                return this.all.filter(d => ! this.members.includes(d.id)
                    && (q === '' || (d.name + ' ' + d.type + ' ' + d.host).toLowerCase().includes(q)));
            },
            add(id) { if (! this.members.includes(id)) this.members.push(id); this.search = ''; },
            remove(id) { this.members = this.members.filter(x => x !== id); }
         }">
        <div class="flex items-center gap-2 mb-3">
            <x-badge color="info" dot>Members</x-badge>
            <span class="text-sm text-slate-500">Endpoints this group fans a sync out to. <span x-text="members.length"></span> selected.</span>
        </div>

        @if ($endpoints->isEmpty())
            <div class="rounded-lg bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-200">
                <p class="text-sm text-amber-800">You have no endpoints yet. <a href="{{ route('devices.create') }}" class="font-medium underline">Add an endpoint</a> first, then add it to this group.</p>
            </div>
        @else
            {{-- Selected chips --}}
            <div class="flex flex-wrap gap-2 mb-3" x-show="selected.length" x-cloak>
                <template x-for="d in selected" :key="d.id">
                    <span class="inline-flex max-w-[14rem] items-center gap-1.5 rounded-lg bg-brand-50 ring-1 ring-inset ring-brand-200 pl-2.5 pr-1.5 py-1 text-sm text-brand-800" :data-tip="d.name">
                        <span class="truncate font-medium" x-text="d.name"></span>
                        <button type="button" @click="remove(d.id)" class="rounded p-0.5 text-brand-500 hover:bg-brand-100 hover:text-brand-700" aria-label="Remove">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 6.28a.75.75 0 011.06 0L10 8.94l2.66-2.66a.75.75 0 111.06 1.06L11.06 10l2.66 2.66a.75.75 0 11-1.06 1.06L10 11.06l-2.66 2.66a.75.75 0 01-1.06-1.06L8.94 10 6.28 7.34a.75.75 0 010-1.06z"/></svg>
                        </button>
                    </span>
                </template>
            </div>

            {{-- Search box --}}
            <div class="relative">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                    <svg class="w-4 h-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="9" r="6"/><path d="M15 15l3 3" stroke-linecap="round"/></svg>
                </div>
                <input type="text" x-model="search"
                    placeholder="Search endpoints by name, type, or host..."
                    class="block w-full rounded-lg border-0 bg-white pl-9 pr-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-500" />
            </div>

            {{-- Results (only unselected, filtered by search) --}}
            <div class="mt-2 max-h-64 overflow-y-auto rounded-lg ring-1 ring-inset ring-slate-200 divide-y divide-slate-100">
                <template x-for="d in results" :key="d.id">
                    <button type="button" @click="add(d.id)" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left transition hover:bg-brand-50 hover:ring-1 hover:ring-inset hover:ring-brand-200">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-slate-900 truncate" x-text="d.name"></span>
                            <span class="block text-xs text-slate-500 truncate"><span x-text="d.type"></span><span x-show="d.host" x-text="' · ' + d.host"></span></span>
                        </span>
                        <span class="shrink-0 text-sm font-medium text-brand-700">Add</span>
                    </button>
                </template>
                <div x-show="results.length === 0" class="px-3 py-4 text-center text-sm text-slate-400">
                    <span x-show="search.trim() !== ''">No endpoints match &ldquo;<span x-text="search"></span>&rdquo;.</span>
                    <span x-show="search.trim() === ''" x-cloak>All endpoints are selected.</span>
                </div>
            </div>

            {{-- Hidden inputs mirror the Alpine members array for submission. --}}
            <template x-for="id in members" :key="id">
                <input type="hidden" name="devices[]" :value="id">
            </template>
        @endif
    </div>

    @isset($owners)
        @if ($owners->isNotEmpty())
            <x-field label="Owner" for="owner_id" hint="User who owns this group." :error="$errors->first('owner_id')">
                <x-select id="owner_id" name="owner_id">
                    <option value="">{{ auth()->user()->name }} (me)</option>
                    @foreach ($owners as $owner)
                        <option value="{{ $owner->id }}" @selected(old('owner_id', $g->user_id ?? '') == $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                    @endforeach
                </x-select>
            </x-field>
            <x-field label="Also Visible To" hint="Extra users who can see this group. Leave empty for the owner and admins only.">
                <x-assignee-picker :users="$owners" :selected="$g?->assignees?->pluck('id')->all() ?? []" />
            </x-field>
        @endif
    @endisset
</div>
