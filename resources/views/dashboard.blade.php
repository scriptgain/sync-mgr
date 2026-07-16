@php
    $statusColors = ['idle' => 'neutral', 'syncing' => 'info', 'scanning' => 'info', 'paused' => 'warn', 'error' => 'danger'];
    $eventColors = ['scan' => 'neutral', 'index' => 'info', 'conflict' => 'warn', 'completed' => 'success', 'error' => 'danger'];
@endphp
<x-layouts.app title="Dashboard">
    <x-page-header title="Dashboard" subtitle="File sync at a glance.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" icon="server" href="{{ route('devices.index') }}">Devices</x-button>
            <x-button size="sm" icon="plus" href="{{ route('folders.create') }}">New Folder</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <x-stat label="Folders" :value="number_format($stats['folders'])" icon="folder" />
        <x-stat label="Connected Devices" :value="number_format($stats['connected']) . ' / ' . number_format($stats['devices'])" icon="server" />
        <x-stat label="Events (24h)" :value="number_format($events24h)" icon="clock" />
        <x-stat label="Data Synced" :value="$stats['storage']" icon="database" />
    </div>

    @if ($attention > 0)
        <div class="mt-6">
            <x-alert type="warn" title="{{ $attention }} Folder(s) Need Attention">
                Some folders are syncing or reporting errors. <a href="{{ route('folders.index') }}" class="font-medium underline">Review folders</a>.
            </x-alert>
        </div>
    @endif

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
        <x-card title="Recent Folders">
            @if ($recentFolders->isEmpty())
                <x-empty-state icon="folder" title="No Folders Yet" description="Create your first folder to start syncing.">
                    <x-slot:action><x-button icon="plus" href="{{ route('folders.create') }}">New Folder</x-button></x-slot:action>
                </x-empty-state>
            @else
                <x-table>
                    <thead><tr><th>Name</th><th>Type</th><th>Status</th><th>Size</th></tr></thead>
                    <tbody>
                        @foreach ($recentFolders as $f)
                            <tr>
                                <td class="font-medium text-slate-900"><a href="{{ route('folders.show', $f) }}" class="hover:text-brand-700">{{ $f->name }}</a></td>
                                <td class="text-slate-500">{{ $f->typeLabel() }}</td>
                                <td><x-badge :color="$statusColors[$f->status] ?? 'neutral'" dot>{{ $f->statusLabel() }}</x-badge></td>
                                <td class="tabular text-slate-500">{{ \App\Support\Bytes::human($f->size_bytes) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-table>
            @endif
        </x-card>

        <x-card title="Recent Events">
            @if ($recentEvents->isEmpty())
                <x-empty-state icon="clock" title="No Events Yet" description="Sync activity will appear here." />
            @else
                <x-table>
                    <thead><tr><th>Type</th><th>Folder</th><th>When</th></tr></thead>
                    <tbody>
                        @foreach ($recentEvents as $e)
                            <tr>
                                <td><x-badge :color="$eventColors[$e->type] ?? 'neutral'">{{ $e->typeLabel() }}</x-badge></td>
                                <td class="text-slate-600">
                                    @if ($e->folder)<a href="{{ route('folders.show', $e->folder) }}" class="hover:text-brand-700">{{ $e->folder->name }}</a>@else — @endif
                                </td>
                                <td class="text-slate-500">
                                    <a href="{{ route('events.show', $e) }}" class="hover:text-brand-700">{{ optional($e->occurred_at ?? $e->created_at)->diffForHumans() ?? '—' }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-table>
            @endif
        </x-card>
    </div>
</x-layouts.app>
