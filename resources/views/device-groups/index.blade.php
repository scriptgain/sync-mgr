<x-layouts.app title="Device Groups">
    <x-page-header title="Device Groups" icon="users" subtitle="Named sets of endpoints a pairing can fan a one-way sync out to.">
        <x-slot:actions>
            <x-button icon="plus" href="{{ route('device-groups.create') }}">New Group</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat label="Total Groups" :value="number_format($stats['total'])" icon="users" />
        <x-stat label="Grouped Endpoints" :value="number_format($stats['members'])" icon="server" />
        <x-stat label="Empty Groups" :value="number_format($stats['empty'])" icon="warning" />
    </div>

    @if ($groups->isEmpty())
        <x-card>
            <x-empty-state icon="users" title="No Device Groups Yet" description="Create a group of endpoints, then pair a Main endpoint to it to fan a one-way sync out to every member.">
                <x-slot:action><x-button icon="plus" href="{{ route('device-groups.create') }}">New Group</x-button></x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        <div
            x-data="{
                selected: [],
                confirming: false,
                allIds: [{{ $groups->pluck('id')->implode(',') }}],
                submitBulk(ref) {
                    const f = this.$refs[ref];
                    f.querySelectorAll('input.js-dyn').forEach(n => n.remove());
                    this.selected.forEach(id => {
                        const i = document.createElement('input');
                        i.type = 'hidden'; i.name = 'ids[]'; i.value = id; i.className = 'js-dyn';
                        f.appendChild(i);
                    });
                    f.submit();
                }
            }">
            <form method="POST" action="{{ route('device-groups.bulk-destroy') }}" x-ref="bulkForm" class="hidden">@csrf @method('DELETE')</form>
            <form method="POST" action="{{ route('device-groups.bulk-pause') }}" x-ref="bulkPauseForm" class="hidden">@csrf</form>
            <form method="POST" action="{{ route('device-groups.bulk-resume') }}" x-ref="bulkResumeForm" class="hidden">@csrf</form>

            <div x-show="selected.length" x-cloak class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-50 px-4 py-2.5 ring-1 ring-inset ring-brand-200">
                <span class="text-sm font-medium text-brand-800"><span x-text="selected.length"></span> selected</span>
                <div class="flex items-center gap-2">
                    <x-button type="button" variant="secondary" size="sm" icon="pause" x-on:click="$dispatch('open-modal', 'bulk-pause-groups')">Pause Selected</x-button>
                    <x-button type="button" variant="secondary" size="sm" icon="play" x-on:click="$dispatch('open-modal', 'bulk-resume-groups')">Resume Selected</x-button>
                    <template x-if="! confirming">
                        <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="confirming = true">Delete Selected</x-button>
                    </template>
                    <template x-if="confirming">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-brand-800">Delete <span x-text="selected.length"></span> group(s)?</span>
                            <x-button type="button" variant="secondary" size="sm" x-on:click="confirming = false">Cancel</x-button>
                            <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="submitBulk('bulkForm')">Confirm Delete</x-button>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Fleet-modal confirmations for the bulk pause/resume actions --}}
            <x-modal name="bulk-pause-groups" title="Pause Selected Groups?" icon="pause" maxWidth="max-w-md">
                Paused groups contribute no peers. Pairings that fan out to a paused group skip its members until it is resumed. This does not delete anything.
                <x-slot:footer>
                    <x-button variant="secondary" size="sm" x-on:click="$dispatch('close-modal', 'bulk-pause-groups')">Cancel</x-button>
                    <x-button variant="primary" size="sm" icon="pause" x-on:click="$dispatch('close-modal', 'bulk-pause-groups'); submitBulk('bulkPauseForm')">Pause Groups</x-button>
                </x-slot:footer>
            </x-modal>
            <x-modal name="bulk-resume-groups" title="Resume Selected Groups?" icon="play" maxWidth="max-w-md">
                Resumed groups contribute their members as peers again. Pairings that fan out to them will include those members on the next run.
                <x-slot:footer>
                    <x-button variant="secondary" size="sm" x-on:click="$dispatch('close-modal', 'bulk-resume-groups')">Cancel</x-button>
                    <x-button variant="primary" size="sm" icon="play" x-on:click="$dispatch('close-modal', 'bulk-resume-groups'); submitBulk('bulkResumeForm')">Resume Groups</x-button>
                </x-slot:footer>
            </x-modal>

            <x-table>
                <thead>
                    <tr>
                        <th class="w-10">
                            <button type="button" role="switch"
                                :aria-checked="(allIds.length > 0 && selected.length === allIds.length).toString()"
                                @click="selected = (allIds.length > 0 && selected.length === allIds.length) ? [] : [...allIds]"
                                :class="(allIds.length > 0 && selected.length === allIds.length) ? 'bg-brand-600' : 'bg-slate-300'"
                                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle disabled:opacity-40"
                                :disabled="allIds.length === 0" aria-label="Select all groups">
                                <span :class="(allIds.length > 0 && selected.length === allIds.length) ? 'translate-x-6' : 'translate-x-1'"
                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                            </button>
                        </th>
                        <th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Members</th><th>Status</th><th>Created</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groups as $g)
                        <tr>
                            <td>
                                <button type="button" role="switch"
                                    :aria-checked="selected.includes({{ $g->id }}).toString()"
                                    @click="selected.includes({{ $g->id }}) ? selected.splice(selected.indexOf({{ $g->id }}), 1) : selected.push({{ $g->id }}); confirming = false"
                                    :class="selected.includes({{ $g->id }}) ? 'bg-brand-600' : 'bg-slate-300'"
                                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle"
                                    aria-label="Select group">
                                    <span :class="selected.includes({{ $g->id }}) ? 'translate-x-6' : 'translate-x-1'"
                                        class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                                </button>
                            </td>
                            <td class="font-medium text-slate-900">
                                <a href="{{ route('device-groups.show', $g) }}" class="inline-flex items-center gap-2.5 hover:text-brand-700">
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-brand-100 text-brand-700 text-xs font-semibold ring-1 ring-inset ring-brand-200">{{ $g->initial() }}</span>
                                    <span>{{ $g->name }}</span>
                                </a>
                            </td>
                            @if (auth()->user()->isAdmin())<td class="text-slate-500">{{ $g->owner?->name ?? 'Unassigned' }}</td>@endif
                            <td>
                                <x-badge :color="$g->devices_count > 0 ? 'info' : 'neutral'">{{ number_format($g->devices_count) }} {{ \Illuminate\Support\Str::plural('device', $g->devices_count) }}</x-badge>
                            </td>
                            <td>
                                <div class="flex items-center gap-2.5">
                                    <form method="POST" action="{{ route('device-groups.toggle-pause', $g) }}" class="inline-flex">
                                        @csrf
                                        <button type="submit" role="switch" aria-checked="{{ $g->paused ? 'false' : 'true' }}"
                                            data-tip="{{ $g->paused ? 'Resume Group' : 'Pause Group' }}"
                                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle {{ $g->paused ? 'bg-slate-300' : 'bg-brand-600' }}"
                                            aria-label="{{ $g->paused ? 'Resume group' : 'Pause group' }}">
                                            <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform {{ $g->paused ? 'translate-x-1' : 'translate-x-6' }}"></span>
                                        </button>
                                    </form>
                                    <x-badge :color="$g->paused ? 'warn' : 'success'" dot>{{ $g->paused ? 'Paused' : 'Active' }}</x-badge>
                                </div>
                            </td>
                            <td class="text-slate-500">{{ $g->created_at->format('M j, Y') }}</td>
                            <td class="text-right">
                                <div class="inline-flex items-center gap-2">
                                    <x-icon-button :href="route('device-groups.show', $g)" icon="eye" title="Open" />
                                    <x-icon-button :href="route('device-groups.edit', $g)" icon="edit" title="Edit" />
                                    <x-delete-button :name="'del-group-' . $g->id" :action="route('device-groups.destroy', $g)"
                                        title="Delete Device Group?" message="This removes the group. Endpoints stay, but pairings that fan out to this group will stop running. This cannot be undone." />
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
            <div class="mt-4">{{ $groups->links() }}</div>
        </div>
    @endif
</x-layouts.app>
