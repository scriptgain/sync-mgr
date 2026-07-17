@php
    $eventColors = ['scan' => 'neutral', 'index' => 'info', 'conflict' => 'warn', 'completed' => 'success', 'error' => 'danger'];
@endphp
<x-layouts.app title="Events">
    <x-page-header title="Events" icon="clock" subtitle="Scans, index updates, conflicts, and completions across your folders." />

    @if ($folders->isNotEmpty())
        <x-card class="mb-6">
            <form method="GET" action="{{ route('events.index') }}" class="flex flex-wrap items-end gap-3">
                <div class="min-w-[16rem]">
                    <x-field label="Filter By Folder" for="folder_id">
                        <x-select id="folder_id" name="folder_id" onchange="this.form.submit()">
                            <option value="">All Folders</option>
                            @foreach ($folders as $f)
                                <option value="{{ $f->id }}" @selected($folderId === $f->id)>{{ $f->name }}</option>
                            @endforeach
                        </x-select>
                    </x-field>
                </div>
                <div class="flex items-center gap-2">
                    <x-button type="submit" variant="secondary" size="sm">Apply</x-button>
                    @if ($folderId)<x-button href="{{ route('events.index') }}" variant="ghost" size="sm">Clear</x-button>@endif
                </div>
            </form>
        </x-card>
    @endif

    @if ($events->isEmpty())
        <x-card>
            <x-empty-state icon="clock" title="No Events Yet" description="Sync activity will appear here as your folders scan and sync." />
        </x-card>
    @else
        <div
            x-data="{
                selected: [],
                confirming: false,
                allIds: [{{ $events->pluck('id')->implode(',') }}],
                toggleAll(e) { this.selected = e.target.checked ? [...this.allIds] : []; this.confirming = false; },
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
            <form method="POST" action="{{ route('events.bulk-destroy') }}" x-ref="bulkForm" class="hidden">@csrf @method('DELETE')</form>

            {{-- Bulk actions bar: appears once at least one event is selected. --}}
            <div x-show="selected.length" x-cloak class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-50 px-4 py-2.5 ring-1 ring-inset ring-brand-200">
                <span class="text-sm font-medium text-brand-800"><span x-text="selected.length"></span> selected</span>
                <div class="flex items-center gap-2">
                    <template x-if="! confirming">
                        <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="confirming = true">Delete Selected</x-button>
                    </template>
                    <template x-if="confirming">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-brand-800">Delete <span x-text="selected.length"></span> event(s)?</span>
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
                            <input type="checkbox" x-on:change="toggleAll($event)"
                                :checked="selected.length > 0 && selected.length === allIds.length"
                                :disabled="allIds.length === 0"
                                class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 align-middle" aria-label="Select all events">
                        </th>
                        <th>Type</th><th>Folder</th><th>Device</th><th>Message</th><th>When</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($events as $e)
                        <tr>
                            <td>
                                <input type="checkbox" x-model.number="selected" value="{{ $e->id }}" x-on:change="confirming = false"
                                    class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 align-middle" aria-label="Select event">
                            </td>
                            <td><x-badge :color="$eventColors[$e->type] ?? 'neutral'">{{ $e->typeLabel() }}</x-badge></td>
                            <td class="font-medium text-slate-900">
                                @if ($e->folder)<a href="{{ route('folders.show', $e->folder) }}" class="hover:text-brand-700">{{ $e->folder->name }}</a>@else<span class="text-slate-400">—</span>@endif
                            </td>
                            <td class="text-slate-500">{{ $e->device?->name ?? '—' }}</td>
                            <td class="text-slate-600">{{ \Illuminate\Support\Str::limit($e->message, 80) ?: '—' }}</td>
                            <td class="text-slate-500">
                                <a href="{{ route('events.show', $e) }}" class="hover:text-brand-700">{{ optional($e->occurred_at ?? $e->created_at)->diffForHumans() ?? '—' }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
        </div>
        <div class="mt-4">{{ $events->links() }}</div>
    @endif
</x-layouts.app>
