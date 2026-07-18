@php
    $statusColors = ['connected' => 'success', 'disconnected' => 'neutral', 'paused' => 'warn'];
@endphp
<x-layouts.app :title="$group->name">
    <x-page-header :title="$group->name" icon="users"
        :subtitle="$group->devices->count() . ' ' . \Illuminate\Support\Str::plural('member', $group->devices->count())"
        :back="['href' => route('device-groups.index'), 'label' => 'Device Groups']">
        <x-slot:actions>
            <form method="POST" action="{{ route('device-groups.toggle-pause', $group) }}">
                @csrf
                <x-button type="submit" variant="secondary" :icon="$group->paused ? 'play' : 'pause'">{{ $group->paused ? 'Resume Group' : 'Pause Group' }}</x-button>
            </form>
            <x-button variant="secondary" icon="edit" href="{{ route('device-groups.edit', $group) }}">Edit</x-button>
            <x-delete-button :name="'del-group'" :action="route('device-groups.destroy', $group)"
                title="Delete Device Group?" message="This removes the group. Endpoints stay, but pairings that fan out to this group will stop running. This cannot be undone." />
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            @if ($group->paused)
                <div class="rounded-lg bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-200">
                    <p class="text-sm text-amber-800">This group is <span class="font-medium">paused</span>. Pairings that fan out to it skip these members until you resume it.</p>
                </div>
            @endif
            <x-card title="Member Endpoints" :flush="$group->devices->isNotEmpty()">
                @if ($group->devices->isEmpty())
                    <x-empty-state icon="server" title="No Members" description="Edit this group to add endpoints. A pairing that fans out to it will push to every member.">
                        <x-slot:action><x-button icon="edit" variant="secondary" href="{{ route('device-groups.edit', $group) }}">Add Endpoints</x-button></x-slot:action>
                    </x-empty-state>
                @else
                    <x-table flush>
                        <thead><tr><th>Name</th><th>Type</th><th>Host</th><th>Status</th></tr></thead>
                        <tbody>
                            @foreach ($group->devices as $d)
                                <tr>
                                    <td class="font-medium text-slate-900"><a href="{{ route('devices.show', $d) }}" class="hover:text-brand-700">{{ $d->name }}</a></td>
                                    <td><x-badge color="neutral">{{ $d->typeLabel() }}</x-badge></td>
                                    <td class="text-slate-500">{{ $d->endpoint_type === 'local' ? 'localhost' : ($d->host ?: '—') }}</td>
                                    <td><x-badge :color="$statusColors[$d->status] ?? 'neutral'" dot>{{ $d->statusLabel() }}</x-badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-table>
                @endif
            </x-card>

            <x-card title="How Groups Are Used">
                <p class="text-sm text-slate-600">Add this group to a pairing's peer list (New Pairing, then <span class="font-medium text-slate-700">Add Group</span>). It expands to the member endpoints above as peers, so a Main endpoint set to <span class="font-medium text-slate-700">Send Only</span> fans a one-way sync out to every member and records a run per member.</p>
            </x-card>

            @if ($group->description)
                <x-card title="Description">
                    <p class="text-sm text-slate-600 whitespace-pre-line">{{ $group->description }}</p>
                </x-card>
            @endif
        </div>

        <div class="space-y-6">
            <x-card title="Details">
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-slate-500">Status</dt><dd><x-badge :color="$group->paused ? 'warn' : 'success'" dot>{{ $group->paused ? 'Paused' : 'Active' }}</x-badge></dd></div>
                    <div><dt class="text-slate-500">Members</dt><dd class="text-slate-900">{{ number_format($group->devices->count()) }}</dd></div>
                    @if (auth()->user()->isAdmin())<div><dt class="text-slate-500">Owner</dt><dd class="text-slate-900">{{ $group->owner?->name ?? 'Unassigned' }}</dd></div>@endif
                    <div><dt class="text-slate-500">Created</dt><dd class="text-slate-900">{{ $group->created_at->format('M j, Y') }}</dd></div>
                </dl>
            </x-card>
        </div>
    </div>
</x-layouts.app>
