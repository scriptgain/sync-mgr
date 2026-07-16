@php
    $statusColors = ['idle' => 'neutral', 'syncing' => 'info', 'scanning' => 'info', 'paused' => 'warn', 'error' => 'danger'];
    $eventColors = ['scan' => 'neutral', 'index' => 'info', 'conflict' => 'warn', 'completed' => 'success', 'error' => 'danger'];
@endphp
<x-layouts.app :title="$folder->name">
    <x-page-header :title="$folder->name" icon="folder"
        :subtitle="$folder->typeLabel() . ' · ' . $folder->folder_id"
        :back="['href' => route('folders.index'), 'label' => 'Folders']">
        <x-slot:actions>
            <x-button variant="secondary" icon="edit" href="{{ route('folders.edit', $folder) }}">Edit</x-button>
            <x-delete-button :name="'del-folder'" :action="route('folders.destroy', $folder)"
                title="Delete Folder?" message="This removes the folder and its sync history. This cannot be undone." />
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            {{-- Shared devices --}}
            <x-card title="Shared With ({{ $folder->devices->count() }})">
                @if ($folder->devices->isEmpty())
                    <x-empty-state icon="server" title="Not Shared Yet" description="Edit this folder to share it with one or more devices." />
                @else
                    <x-table>
                        <thead><tr><th>Device</th><th>Device ID</th><th>Status</th><th>Introducer</th></tr></thead>
                        <tbody>
                            @foreach ($folder->devices as $d)
                                <tr>
                                    <td class="font-medium text-slate-900"><a href="{{ route('devices.show', $d) }}" class="hover:text-brand-700">{{ $d->name }}</a></td>
                                    <td class="font-mono text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($d->device_id, 20) }}</td>
                                    <td><x-badge :color="$d->status === 'connected' ? 'success' : ($d->status === 'paused' ? 'warn' : 'neutral')" dot>{{ $d->statusLabel() }}</x-badge></td>
                                    <td>@if ($d->pivot->introducer)<x-badge color="info">Yes</x-badge>@else<span class="text-slate-400">—</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-table>
                @endif
            </x-card>

            {{-- Recent events --}}
            <x-card title="Recent Events">
                @if ($events->isEmpty())
                    <x-empty-state icon="clock" title="No Events Yet" description="Sync activity for this folder will appear here." />
                @else
                    <x-table>
                        <thead><tr><th>Type</th><th>Device</th><th>Message</th><th>When</th></tr></thead>
                        <tbody>
                            @foreach ($events as $e)
                                <tr>
                                    <td><x-badge :color="$eventColors[$e->type] ?? 'neutral'">{{ $e->typeLabel() }}</x-badge></td>
                                    <td class="text-slate-500">{{ $e->device?->name ?? '—' }}</td>
                                    <td class="text-slate-600">{{ $e->message ?? '—' }}</td>
                                    <td class="text-slate-500">{{ optional($e->occurred_at ?? $e->created_at)->diffForHumans() ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-table>
                @endif
            </x-card>
        </div>

        {{-- Details --}}
        <div class="space-y-6">
            <x-card title="Details">
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-slate-500">Folder ID</dt><dd class="text-slate-900 font-mono text-xs break-all">{{ $folder->folder_id }}</dd></div>
                    <div><dt class="text-slate-500">Path</dt><dd class="text-slate-900 font-mono text-xs break-all">{{ $folder->path }}</dd></div>
                    <div><dt class="text-slate-500">Type</dt><dd class="text-slate-900">{{ $folder->typeLabel() }}</dd></div>
                    <div><dt class="text-slate-500">Status</dt><dd><x-badge :color="$statusColors[$folder->status] ?? 'neutral'" dot>{{ $folder->statusLabel() }}</x-badge></dd></div>
                    <div><dt class="text-slate-500">Rescan Interval</dt><dd class="text-slate-900 tabular">{{ number_format($folder->rescan_interval) }}s</dd></div>
                    <div><dt class="text-slate-500">Versioning</dt><dd>@if ($folder->versioning)<x-badge color="success">On</x-badge>@else<x-badge color="neutral">Off</x-badge>@endif</dd></div>
                    @if (auth()->user()->isAdmin())<div><dt class="text-slate-500">Owner</dt><dd class="text-slate-900">{{ $folder->owner?->name ?? 'Unassigned' }}</dd></div>@endif
                    <div><dt class="text-slate-500">Created</dt><dd class="text-slate-900">{{ $folder->created_at->format('M j, Y') }}</dd></div>
                </dl>
            </x-card>
            <x-card title="Contents">
                <div class="flex items-baseline justify-between">
                    <span class="text-2xl font-semibold tabular text-slate-900">{{ \App\Support\Bytes::human($folder->size_bytes) }}</span>
                    <span class="text-sm text-slate-500">{{ number_format($folder->file_count) }} files</span>
                </div>
            </x-card>
            @if ($folder->notes)
                <x-card title="Notes">
                    <p class="text-sm text-slate-600 whitespace-pre-line">{{ $folder->notes }}</p>
                </x-card>
            @endif
        </div>
    </div>
</x-layouts.app>
