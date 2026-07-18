@php
    $statusColors = ['idle' => 'neutral', 'syncing' => 'info', 'scanning' => 'info', 'paused' => 'warn', 'error' => 'danger'];
    $peers = $folder->peers;
    $peerCount = $peers->count();
    $isFanOut = $folder->main_mode === 'send_only' && $peerCount > 1;
    $op = match ($folder->main_mode) {
        'send_only' => 'push',
        'receive_only' => 'pull',
        'send_receive' => 'bisync',
        default => 'invalid',
    };
@endphp
<x-layouts.app :title="$folder->name">
    <x-page-header :title="$folder->name" icon="folder"
        :subtitle="$folder->flowLabel() . ' · ' . $folder->folder_id"
        :back="['href' => route('folders.index'), 'label' => 'Pairings']">
        <x-slot:actions>
            <form method="POST" action="{{ route('folders.sync', $folder) }}">
                @csrf
                <x-button type="submit" icon="play">Sync Now</x-button>
            </form>
            <x-button variant="secondary" icon="edit" href="{{ route('folders.edit', $folder) }}">Edit</x-button>
            <x-delete-button :name="'del-folder'" :action="route('folders.destroy', $folder)"
                title="Delete Pairing?" message="This removes the pairing and its sync history. This cannot be undone." />
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            {{-- Pairing map --}}
            <x-card title="Sync Pairing">
                <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto_1fr] items-stretch gap-4">
                    {{-- Main --}}
                    <div class="rounded-xl ring-1 ring-brand-200 bg-brand-50/50 p-4">
                        <div class="flex items-center gap-2 mb-2"><x-badge color="info" dot>Main</x-badge><span class="text-xs text-slate-500">Source of truth</span></div>
                        @if ($folder->mainDevice)
                            <a href="{{ route('devices.show', $folder->mainDevice) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $folder->mainDevice->name }}</a>
                            <p class="text-xs text-slate-500 mt-0.5">{{ $folder->mainDevice->typeLabel() }}</p>
                        @else
                            <span class="text-slate-400">Not set</span>
                        @endif
                        <div class="mt-2"><x-badge :color="$folder->main_mode === 'send_receive' ? 'warn' : 'neutral'">{{ $folder->mainModeLabel() }}</x-badge></div>
                    </div>
                    {{-- Arrow --}}
                    <div class="flex sm:flex-col items-center justify-center text-slate-400">
                        @if ($op === 'push')
                            <x-icon name="chevron-right" class="w-6 h-6 hidden sm:block" /><span class="sm:hidden">↓</span>
                        @elseif ($op === 'pull')
                            <x-icon name="chevron-left" class="w-6 h-6 hidden sm:block" /><span class="sm:hidden">↑</span>
                        @else
                            <x-icon name="refresh" class="w-6 h-6" />
                        @endif
                    </div>
                    {{-- Peers --}}
                    <div class="rounded-xl ring-1 ring-slate-200 p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <x-badge :color="$isFanOut ? 'info' : 'neutral'" dot>{{ $peerCount > 1 ? 'Peers' : 'Peer' }}</x-badge>
                            @if ($isFanOut)<span class="text-xs text-slate-500">Fan-out to {{ $peerCount }}</span>@endif
                        </div>
                        @if ($peerCount === 0)
                            <span class="text-slate-400">No peers</span>
                        @elseif ($peerCount === 1)
                            @php $only = $peers->first(); @endphp
                            <a href="{{ route('devices.show', $only) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $only->name }}</a>
                            <p class="text-xs text-slate-500 mt-0.5">{{ $only->typeLabel() }}</p>
                        @else
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($peers as $m)
                                    <a href="{{ route('devices.show', $m) }}"><x-badge color="neutral">{{ $m->name }}</x-badge></a>
                                @endforeach
                            </div>
                        @endif
                        <div class="mt-2"><x-badge :color="$folder->peer_mode === 'send_receive' ? 'warn' : 'neutral'">{{ $folder->peerModeLabel() }}{{ $peerCount > 1 ? ' (each peer)' : '' }}</x-badge></div>
                    </div>
                </div>
                <div class="mt-4 rounded-lg ring-1 ring-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">{{ $folder->flowLabel() }}</span>
                    @if ($op === 'bisync')<span class="text-amber-700"> — two-way bisync is coming soon; one-way pairings run today.</span>@endif
                    @if ($isFanOut)<span class="text-slate-500"> — one SyncEvent is recorded per peer on each run.</span>@endif
                </div>
            </x-card>

            {{-- Recent runs --}}
            <x-card title="Recent Runs" flush>
                @if ($events->isEmpty())
                    <div class="p-6"><x-empty-state icon="clock" title="No Runs Yet" description="Click Sync Now to run this pairing. Results appear here." /></div>
                @else
                    <div
                        x-data="{
                            selected: [],
                            allIds: [{{ $events->pluck('id')->implode(',') }}],
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
                        <form method="POST" action="{{ route('events.bulk-destroy') }}" x-ref="bulkForm" class="hidden">@csrf @method('DELETE')</form>

                        {{-- Bulk action bar --}}
                        <div x-show="selected.length" x-cloak class="mx-4 mt-4 mb-1 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-50 px-4 py-2.5 ring-1 ring-inset ring-brand-200">
                            <span class="text-sm font-medium text-brand-800"><span x-text="selected.length"></span> Selected</span>
                            <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="$dispatch('open-modal', 'bulk-delete-runs')">Delete Selected</x-button>
                        </div>

                        {{-- Fleet-modal confirmation for the bulk delete --}}
                        <x-modal name="bulk-delete-runs" title="Delete Selected Runs?" icon="trash" tone="danger" maxWidth="max-w-md">
                            This permanently removes <span x-text="selected.length"></span> selected run record(s) from this pairing's history. This cannot be undone.
                            <x-slot:footer>
                                <x-button variant="secondary" size="sm" x-on:click="$dispatch('close-modal', 'bulk-delete-runs')">Cancel</x-button>
                                <x-button variant="danger" size="sm" icon="trash" x-on:click="$dispatch('close-modal', 'bulk-delete-runs'); submitBulk()">Delete Runs</x-button>
                            </x-slot:footer>
                        </x-modal>

                        <x-table flush>
                            <thead>
                                <tr>
                                    <th class="w-10">
                                        <button type="button" role="switch"
                                            :aria-checked="(allIds.length > 0 && selected.length === allIds.length).toString()"
                                            @click="selected = (allIds.length > 0 && selected.length === allIds.length) ? [] : [...allIds]"
                                            :class="(allIds.length > 0 && selected.length === allIds.length) ? 'bg-brand-600' : 'bg-slate-300'"
                                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle disabled:opacity-40"
                                            :disabled="allIds.length === 0" aria-label="Select all runs" data-tip="Select All">
                                            <span :class="(allIds.length > 0 && selected.length === allIds.length) ? 'translate-x-6' : 'translate-x-1'"
                                                class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                                        </button>
                                    </th>
                                    <th>Status</th>@if ($isFanOut)<th>Peer</th>@endif<th>Files</th><th>Size</th><th>Duration</th><th>Message</th><th>When</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($events as $e)
                                    <tr>
                                        <td>
                                            <button type="button" role="switch"
                                                :aria-checked="selected.includes({{ $e->id }}).toString()"
                                                @click="selected.includes({{ $e->id }}) ? selected.splice(selected.indexOf({{ $e->id }}), 1) : selected.push({{ $e->id }})"
                                                :class="selected.includes({{ $e->id }}) ? 'bg-brand-600' : 'bg-slate-300'"
                                                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle"
                                                aria-label="Select run">
                                                <span :class="selected.includes({{ $e->id }}) ? 'translate-x-6' : 'translate-x-1'"
                                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                                            </button>
                                        </td>
                                        <td><x-badge :color="$e->statusColor()" dot>{{ $e->statusLabel() }}</x-badge></td>
                                        @if ($isFanOut)<td class="text-slate-600">{{ $e->device?->name ?? '—' }}</td>@endif
                                        <td class="tabular text-slate-600">{{ number_format($e->files_transferred) }}</td>
                                        <td class="tabular text-slate-600">{{ \App\Support\Bytes::human($e->bytes_transferred) }}</td>
                                        <td class="tabular text-slate-600">{{ $e->durationLabel() }}</td>
                                        <td class="text-slate-600"><a href="{{ route('events.show', $e) }}" class="hover:text-brand-700">{{ \Illuminate\Support\Str::limit($e->message, 60) ?: '—' }}</a></td>
                                        <td class="text-slate-500">{{ optional($e->occurred_at ?? $e->created_at)->diffForHumans() ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-table>
                    </div>
                @endif
            </x-card>
        </div>

        {{-- Details --}}
        <div class="space-y-6">
            <x-card title="Schedule">
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-slate-500">Mode</dt><dd><x-badge :color="$folder->schedule_mode === 'manual' ? 'neutral' : 'info'">{{ $folder->scheduleModeLabel() }}</x-badge></dd></div>
                    <div><dt class="text-slate-500">Automation</dt><dd>@if ($folder->schedule_mode !== 'manual' && $folder->enabled)<x-badge color="success" dot>Enabled</x-badge>@else<x-badge color="neutral" dot>Off</x-badge>@endif</dd></div>
                    <div><dt class="text-slate-500">Cadence</dt><dd class="text-slate-900">{{ $folder->scheduleLabel() }}</dd></div>
                    <div><dt class="text-slate-500">Last Run</dt><dd class="text-slate-900">{{ optional($folder->last_run_at)->diffForHumans() ?? 'Never' }}</dd></div>
                    @if ($folder->last_status)<div><dt class="text-slate-500">Last Status</dt><dd class="text-slate-900">{{ ucfirst($folder->last_status) }}</dd></div>@endif
                    <div><dt class="text-slate-500">Next Run</dt><dd class="text-slate-900">{{ $folder->schedule_mode !== 'manual' && $folder->enabled && $folder->next_run_at ? $folder->next_run_at->diffForHumans() : '—' }}</dd></div>
                </dl>
            </x-card>
            <x-card title="Details">
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-slate-500">Pairing ID</dt><dd class="text-slate-900 font-mono text-xs break-all">{{ $folder->folder_id }}</dd></div>
                    <div><dt class="text-slate-500">Subpath</dt><dd class="text-slate-900 font-mono text-xs break-all">{{ $folder->subpath ?: '(base path)' }}</dd></div>
                    <div><dt class="text-slate-500">Status</dt><dd><x-badge :color="$statusColors[$folder->status] ?? 'neutral'" dot>{{ $folder->statusLabel() }}</x-badge></dd></div>
                    @if (auth()->user()->isAdmin())<div><dt class="text-slate-500">Owner</dt><dd class="text-slate-900">{{ $folder->owner?->name ?? 'Unassigned' }}</dd></div>@endif
                    <div><dt class="text-slate-500">Created</dt><dd class="text-slate-900">{{ $folder->created_at->format('M j, Y') }}</dd></div>
                </dl>
            </x-card>
            @if ($folder->notes)
                <x-card title="Notes">
                    <p class="text-sm text-slate-600 whitespace-pre-line">{{ $folder->notes }}</p>
                </x-card>
            @endif
        </div>
    </div>
</x-layouts.app>
