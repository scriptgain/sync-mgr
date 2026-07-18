@php
    $g = $group ?? null;
    $endpoints = $endpoints ?? collect();
    $selectedDeviceIds = collect(old('devices', $g?->devices?->pluck('id')->all() ?? []))
        ->map(fn ($v) => (int) $v)->all();
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

    {{-- Membership: assign a set of endpoints to this group. --}}
    <div class="rounded-xl ring-1 ring-slate-200 p-4"
         x-data="{
            members: {{ \Illuminate\Support\Js::from($selectedDeviceIds) }},
            allIds: [{{ $endpoints->pluck('id')->implode(',') }}],
            toggle(id) { this.members.includes(id) ? this.members.splice(this.members.indexOf(id), 1) : this.members.push(id); },
            get allSelected() { return this.allIds.length > 0 && this.members.length === this.allIds.length; },
            toggleAll() { this.members = this.allSelected ? [] : [...this.allIds]; }
         }">
        <div class="flex items-center justify-between gap-3 mb-3">
            <div class="flex items-center gap-2">
                <x-badge color="info" dot>Members</x-badge>
                <span class="text-sm text-slate-500">Endpoints this group fans a sync out to. <span x-text="members.length"></span> selected.</span>
            </div>
            @if ($endpoints->isNotEmpty())
                <button type="button" @click="toggleAll()" class="text-sm font-medium text-brand-700 hover:text-brand-800" x-text="allSelected ? 'Clear All' : 'Select All'"></button>
            @endif
        </div>

        @if ($endpoints->isEmpty())
            <div class="rounded-lg bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-200">
                <p class="text-sm text-amber-800">You have no endpoints yet. <a href="{{ route('devices.create') }}" class="font-medium underline">Add an endpoint</a> first, then add it to this group.</p>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                @foreach ($endpoints as $ep)
                    <label class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 ring-1 ring-inset transition cursor-pointer"
                           :class="members.includes({{ $ep->id }}) ? 'bg-brand-50 ring-brand-200' : 'bg-white ring-slate-200 hover:ring-slate-300'">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-slate-900 truncate">{{ $ep->name }}</span>
                            <span class="block text-xs text-slate-500">{{ $ep->typeLabel() }}{{ $ep->host ? ' · ' . $ep->host : '' }}</span>
                        </span>
                        <button type="button" role="switch" @click.prevent="toggle({{ $ep->id }})"
                            :aria-checked="members.includes({{ $ep->id }}).toString()"
                            :class="members.includes({{ $ep->id }}) ? 'bg-brand-600' : 'bg-slate-300'"
                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors"
                            aria-label="Toggle membership">
                            <span :class="members.includes({{ $ep->id }}) ? 'translate-x-6' : 'translate-x-1'"
                                class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                        </button>
                    </label>
                @endforeach
            </div>
            {{-- Hidden inputs mirror the Alpine `members` array for submission. --}}
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
