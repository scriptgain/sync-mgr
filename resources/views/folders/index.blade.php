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
        <x-table>
            <thead>
                <tr><th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Path</th><th>Type</th><th>Status</th><th>Devices</th><th>Size</th><th class="text-right">Actions</th></tr>
            </thead>
            <tbody>
                @foreach ($folders as $f)
                    <tr>
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
    @endif
</x-layouts.app>
