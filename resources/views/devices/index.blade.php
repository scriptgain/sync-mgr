@php
    $statusColors = ['connected' => 'success', 'disconnected' => 'neutral', 'paused' => 'warn'];
@endphp
<x-layouts.app title="Devices">
    <x-page-header title="Devices" icon="server" subtitle="Peer devices in your sync cluster.">
        <x-slot:actions>
            <x-button icon="plus" href="{{ route('devices.create') }}">New Device</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat label="Total Devices" :value="number_format($stats['total'])" icon="server" />
        <x-stat label="Connected" :value="number_format($stats['connected'])" icon="check-circle" />
        <x-stat label="Local" :value="number_format($stats['local'])" icon="home" />
    </div>

    @if ($devices->isEmpty())
        <x-card>
            <x-empty-state icon="server" title="No Devices Yet" description="Register a device to start sharing folders with it.">
                <x-slot:action><x-button icon="plus" href="{{ route('devices.create') }}">New Device</x-button></x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        <x-table>
            <thead>
                <tr><th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Device ID</th><th>Address</th><th>Status</th><th>Folders</th><th>Last Seen</th><th class="text-right">Actions</th></tr>
            </thead>
            <tbody>
                @foreach ($devices as $d)
                    <tr>
                        <td class="font-medium text-slate-900">
                            <a href="{{ route('devices.show', $d) }}" class="hover:text-brand-700">{{ $d->name }}</a>
                            @if ($d->is_local)<x-badge color="info" class="ml-1.5">Local</x-badge>@endif
                        </td>
                        @if (auth()->user()->isAdmin())<td class="text-slate-500">{{ $d->owner?->name ?? 'Unassigned' }}</td>@endif
                        <td class="font-mono text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($d->device_id, 16) }}</td>
                        <td class="text-slate-500">{{ $d->address ?: 'dynamic' }}</td>
                        <td><x-badge :color="$statusColors[$d->status] ?? 'neutral'" dot>{{ $d->statusLabel() }}</x-badge></td>
                        <td class="tabular text-slate-500">{{ number_format($d->folders_count) }}</td>
                        <td class="text-slate-500">{{ optional($d->last_seen_at)->diffForHumans() ?? 'Never' }}</td>
                        <td class="text-right">
                            <div class="inline-flex items-center gap-2">
                                <x-icon-button :href="route('devices.show', $d)" icon="eye" title="Open" />
                                <x-icon-button :href="route('devices.edit', $d)" icon="edit" title="Edit" />
                                <x-delete-button :name="'del-device-' . $d->id" :action="route('devices.destroy', $d)"
                                    title="Delete Device?" message="This removes the device and unshares it from every folder. This cannot be undone." />
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-table>
        <div class="mt-4">{{ $devices->links() }}</div>
    @endif
</x-layouts.app>
