@php
    $statusColors = ['connected' => 'success', 'disconnected' => 'neutral', 'paused' => 'warn'];
    $folderStatusColors = ['idle' => 'neutral', 'syncing' => 'info', 'scanning' => 'info', 'paused' => 'warn', 'error' => 'danger'];
@endphp
<x-layouts.app :title="$device->name">
    <x-page-header :title="$device->name" icon="server"
        :subtitle="$device->statusLabel() . ($device->is_local ? ' · Local' : '')"
        :back="['href' => route('devices.index'), 'label' => 'Devices']">
        <x-slot:actions>
            <x-button variant="secondary" icon="edit" href="{{ route('devices.edit', $device) }}">Edit</x-button>
            <x-delete-button :name="'del-device'" :action="route('devices.destroy', $device)"
                title="Delete Device?" message="This removes the device and unshares it from every folder. This cannot be undone." />
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <x-card title="Shared Folders ({{ $device->folders->count() }})">
                @if ($device->folders->isEmpty())
                    <x-empty-state icon="folder" title="No Shared Folders" description="Share a folder with this device from the folder's edit screen." />
                @else
                    <x-table>
                        <thead><tr><th>Folder</th><th>Type</th><th>Status</th><th>Size</th></tr></thead>
                        <tbody>
                            @foreach ($device->folders as $f)
                                <tr>
                                    <td class="font-medium text-slate-900"><a href="{{ route('folders.show', $f) }}" class="hover:text-brand-700">{{ $f->name }}</a></td>
                                    <td class="text-slate-500">{{ $f->typeLabel() }}</td>
                                    <td><x-badge :color="$folderStatusColors[$f->status] ?? 'neutral'" dot>{{ $f->statusLabel() }}</x-badge></td>
                                    <td class="tabular text-slate-500">{{ \App\Support\Bytes::human($f->size_bytes) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-table>
                @endif
            </x-card>

            @if ($device->notes)
                <x-card title="Notes">
                    <p class="text-sm text-slate-600 whitespace-pre-line">{{ $device->notes }}</p>
                </x-card>
            @endif
        </div>

        <div class="space-y-6">
            <x-card title="Details">
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-slate-500">Device ID</dt><dd class="text-slate-900 font-mono text-xs break-all">{{ $device->device_id }}</dd></div>
                    <div><dt class="text-slate-500">Address</dt><dd class="text-slate-900">{{ $device->address ?: 'dynamic' }}</dd></div>
                    <div><dt class="text-slate-500">Status</dt><dd><x-badge :color="$statusColors[$device->status] ?? 'neutral'" dot>{{ $device->statusLabel() }}</x-badge></dd></div>
                    <div><dt class="text-slate-500">Local Device</dt><dd>@if ($device->is_local)<x-badge color="info">Yes</x-badge>@else<x-badge color="neutral">No</x-badge>@endif</dd></div>
                    <div><dt class="text-slate-500">Last Seen</dt><dd class="text-slate-900">{{ optional($device->last_seen_at)->diffForHumans() ?? 'Never' }}</dd></div>
                    @if (auth()->user()->isAdmin())<div><dt class="text-slate-500">Owner</dt><dd class="text-slate-900">{{ $device->owner?->name ?? 'Unassigned' }}</dd></div>@endif
                    <div><dt class="text-slate-500">Created</dt><dd class="text-slate-900">{{ $device->created_at->format('M j, Y') }}</dd></div>
                </dl>
            </x-card>
        </div>
    </div>
</x-layouts.app>
