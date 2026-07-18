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
        <div
            x-data="{
                selected: [],
                confirming: false,
                allIds: [{{ $devices->pluck('id')->implode(',') }}],
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
            <form method="POST" action="{{ route('devices.bulk-destroy') }}" x-ref="bulkForm" class="hidden">@csrf @method('DELETE')</form>

            {{-- Bulk actions bar: appears once at least one device is selected. --}}
            <div x-show="selected.length" x-cloak class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-50 px-4 py-2.5 ring-1 ring-inset ring-brand-200">
                <span class="text-sm font-medium text-brand-800"><span x-text="selected.length"></span> selected</span>
                <div class="flex items-center gap-2">
                    <template x-if="! confirming">
                        <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="confirming = true">Delete Selected</x-button>
                    </template>
                    <template x-if="confirming">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-brand-800">Delete <span x-text="selected.length"></span> device(s)?</span>
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
                                :disabled="allIds.length === 0" aria-label="Select all devices">
                                <span :class="(allIds.length > 0 && selected.length === allIds.length) ? 'translate-x-6' : 'translate-x-1'"
                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                            </button>
                        </th>
                        <th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Device ID</th><th>Address</th><th>Status</th><th>Folders</th><th>Last Seen</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($devices as $d)
                        <tr>
                            <td>
                                <button type="button" role="switch"
                                    :aria-checked="selected.includes({{ $d->id }}).toString()"
                                    @click="selected.includes({{ $d->id }}) ? selected.splice(selected.indexOf({{ $d->id }}), 1) : selected.push({{ $d->id }}); confirming = false"
                                    :class="selected.includes({{ $d->id }}) ? 'bg-brand-600' : 'bg-slate-300'"
                                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle"
                                    aria-label="Select device">
                                    <span :class="selected.includes({{ $d->id }}) ? 'translate-x-6' : 'translate-x-1'"
                                        class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                                </button>
                            </td>
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
        </div>
    @endif
</x-layouts.app>
