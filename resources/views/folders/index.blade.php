@php
    $statusColors = ['idle' => 'neutral', 'syncing' => 'info', 'scanning' => 'info', 'paused' => 'warn', 'error' => 'danger'];
@endphp
<x-layouts.app title="Folders">
    <x-page-header title="Folders" icon="folder" subtitle="Synced folders and the devices they share with.">
        <x-slot:actions>
            <x-button icon="plus" href="{{ route('folders.create') }}">New Folder</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat label="Total Folders" :value="number_format($stats['total'])" icon="folder" />
        <x-stat label="Syncing" :value="number_format($stats['syncing'])" icon="sync" />
        <x-stat label="Errored" :value="number_format($stats['errors'])" icon="warning" />
    </div>

    @if ($folders->isEmpty())
        <x-card>
            <x-empty-state icon="folder" title="No Folders Yet" description="Create a folder to start syncing files across your devices.">
                <x-slot:action><x-button icon="plus" href="{{ route('folders.create') }}">New Folder</x-button></x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        <div
            x-data="{
                selected: [],
                confirming: false,
                allIds: [{{ $folders->pluck('id')->implode(',') }}],
                submitBulk() {
                    const f = this.$refs.bulkForm;
                    f.querySelectorAll('input.js-dyn').forEach(n => n.remove());
                    this.selected.forEach(id => {
                        const i = document.createElement('input');
                        i.type = 'hidden'; i.name = 'ids[]'; i.value = id; i.className = 'js-dyn';
                        f.appendChild(i);
                    });
                    f.submit();
                }
            }">
            {{-- Hidden form the bulk delete posts through. --}}
            <form method="POST" action="{{ route('folders.bulk-destroy') }}" x-ref="bulkForm" class="hidden">@csrf @method('DELETE')</form>

            {{-- Bulk actions bar: appears once at least one folder is selected. --}}
            <div x-show="selected.length" x-cloak class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-50 px-4 py-2.5 ring-1 ring-inset ring-brand-200">
                <span class="text-sm font-medium text-brand-800"><span x-text="selected.length"></span> selected</span>
                <div class="flex items-center gap-2">
                    <template x-if="! confirming">
                        <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="confirming = true">Delete Selected</x-button>
                    </template>
                    <template x-if="confirming">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-brand-800">Delete <span x-text="selected.length"></span> folder(s)?</span>
                            <x-button type="button" variant="secondary" size="sm" x-on:click="confirming = false">Cancel</x-button>
                            <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="submitBulk()">Confirm Delete</x-button>
                        </div>
                    </template>
                </div>
            </div>

            <x-table>
                <thead>
                    <tr>
                        <th class="w-10">
                            <button type="button" role="switch"
                                :aria-checked="(allIds.length > 0 && selected.length === allIds.length).toString()"
                                @click="selected = (allIds.length > 0 && selected.length === allIds.length) ? [] : [...allIds]"
                                :class="(allIds.length > 0 && selected.length === allIds.length) ? 'bg-brand-600' : 'bg-slate-300'"
                                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle disabled:opacity-40"
                                :disabled="allIds.length === 0" aria-label="Select all folders">
                                <span :class="(allIds.length > 0 && selected.length === allIds.length) ? 'translate-x-6' : 'translate-x-1'"
                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                            </button>
                        </th>
                        <th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Path</th><th>Type</th><th>Status</th><th>Devices</th><th>Size</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($folders as $f)
                        <tr>
                            <td>
                                <button type="button" role="switch"
                                    :aria-checked="selected.includes({{ $f->id }}).toString()"
                                    @click="selected.includes({{ $f->id }}) ? selected.splice(selected.indexOf({{ $f->id }}), 1) : selected.push({{ $f->id }}); confirming = false"
                                    :class="selected.includes({{ $f->id }}) ? 'bg-brand-600' : 'bg-slate-300'"
                                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle"
                                    aria-label="Select folder">
                                    <span :class="selected.includes({{ $f->id }}) ? 'translate-x-6' : 'translate-x-1'"
                                        class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                                </button>
                            </td>
                            <td class="font-medium text-slate-900"><a href="{{ route('folders.show', $f) }}" class="hover:text-brand-700">{{ $f->name }}</a></td>
                            @if (auth()->user()->isAdmin())<td class="text-slate-500">{{ $f->owner?->name ?? 'Unassigned' }}</td>@endif
                            <td class="font-mono text-xs text-slate-500">{{ $f->path }}</td>
                            <td class="text-slate-500">{{ $f->typeLabel() }}</td>
                            <td><x-badge :color="$statusColors[$f->status] ?? 'neutral'" dot>{{ $f->statusLabel() }}</x-badge></td>
                            <td class="tabular text-slate-500">{{ number_format($f->devices_count) }}</td>
                            <td class="tabular text-slate-500">{{ \App\Support\Bytes::human($f->size_bytes) }}</td>
                            <td class="text-right">
                                <div class="inline-flex items-center gap-2">
                                    <x-icon-button :href="route('folders.show', $f)" icon="eye" title="Open" />
                                    <x-icon-button :href="route('folders.edit', $f)" icon="edit" title="Edit" />
                                    <x-delete-button :name="'del-folder-' . $f->id" :action="route('folders.destroy', $f)"
                                        title="Delete Folder?" message="This removes the folder and its sync history. This cannot be undone." />
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
            <div class="mt-4">{{ $folders->links() }}</div>
        </div>
    @endif
</x-layouts.app>
