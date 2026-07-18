@php
    $statusColors = ['connected' => 'success', 'disconnected' => 'neutral', 'paused' => 'warn'];
    $folderStatusColors = ['idle' => 'neutral', 'syncing' => 'info', 'scanning' => 'info', 'paused' => 'warn', 'error' => 'danger'];
    $hasSecret = filled($device->secret);
@endphp
<x-layouts.app :title="$device->name">
    <x-page-header :title="$device->name" icon="server"
        :subtitle="$device->typeLabel() . ' · ' . $device->statusLabel()"
        :back="['href' => route('devices.index'), 'label' => 'Endpoints']">
        <x-slot:actions>
            @if ($device->isLive())
                <form method="POST" action="{{ route('devices.test', $device) }}">
                    @csrf
                    <x-button type="submit" variant="secondary" icon="bolt">Test Connection</x-button>
                </form>
            @endif
            <x-button variant="secondary" icon="edit" href="{{ route('devices.edit', $device) }}">Edit</x-button>
            <x-delete-button :name="'del-device'" :action="route('devices.destroy', $device)"
                title="Delete Endpoint?" message="This removes the endpoint. Pairings that use it will stop running. This cannot be undone." />
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <x-card title="Connection">
                @if (! $device->isLive())
                    <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-200">
                        <p class="text-sm text-amber-800">Agent transport is coming soon. This endpoint is saved, but cannot run live sync yet. Use FTP, SFTP or S3 for live sync today.</p>
                    </div>
                @endif
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="text-slate-500">Type</dt><dd class="text-slate-900">{{ $device->typeLabel() }}</dd></div>
                    @if ($device->endpoint_type !== 'local')
                        <div><dt class="text-slate-500">Host</dt><dd class="text-slate-900 break-all">{{ $device->host ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">Port</dt><dd class="text-slate-900 tabular">{{ $device->port ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">{{ $device->endpoint_type === 's3' ? 'Access Key' : 'Username' }}</dt><dd class="text-slate-900 break-all">{{ $device->username ?: '—' }}</dd></div>
                        <div x-data="{ reveal: false, secret: @js($device->secret) }">
                            <dt class="text-slate-500">{{ $device->endpoint_type === 's3' ? 'Secret Key' : 'Password' }}</dt>
                            <dd class="text-slate-900">
                                @if ($hasSecret)
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono text-xs break-all" x-text="reveal ? secret : '••••••••••••'"></span>
                                        <button type="button" @click="reveal = ! reveal" class="text-slate-400 hover:text-slate-600" data-tip="Reveal / hide"><x-icon name="eye" class="w-4 h-4" /></button>
                                        <button type="button" @click="navigator.clipboard.writeText(secret)" class="text-slate-400 hover:text-slate-600" data-tip="Copy"><x-icon name="key" class="w-4 h-4" /></button>
                                    </div>
                                @else
                                    <span class="text-slate-400">Not set</span>
                                @endif
                            </dd>
                        </div>
                    @endif
                    @if ($device->endpoint_type === 's3')
                        <div><dt class="text-slate-500">Bucket</dt><dd class="text-slate-900 break-all">{{ $device->bucket ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">Region</dt><dd class="text-slate-900">{{ $device->region ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">Path Style</dt><dd>@if ($device->s3_path_style)<x-badge color="info">On</x-badge>@else<x-badge color="neutral">Off</x-badge>@endif</dd></div>
                    @endif
                    @if ($device->endpoint_type === 'ftp')
                        <div><dt class="text-slate-500">Explicit TLS</dt><dd>@if ($device->ftp_tls)<x-badge color="success">On</x-badge>@else<x-badge color="neutral">Off</x-badge>@endif</dd></div>
                    @endif
                    <div class="sm:col-span-2"><dt class="text-slate-500">{{ $device->endpoint_type === 'local' ? 'Local Path' : 'Base Path' }}</dt><dd class="text-slate-900 font-mono text-xs break-all">{{ $device->base_path ?: '—' }}</dd></div>
                </dl>
            </x-card>

            <x-card title="Pairings Using This Endpoint">
                @php
                    $asMain = $device->mainPairings->map(fn ($f) => tap($f)->setAttribute('_role', 'Main'));
                    $asPeer = $device->peerFolders->map(fn ($f) => tap($f)->setAttribute('_role', 'Peer'));
                    $pairings = $asMain->concat($asPeer)->unique('id');
                @endphp
                @if ($pairings->isEmpty())
                    <x-empty-state icon="folder" title="No Pairings" description="Create a sync pairing that uses this endpoint as its Main or a Peer." />
                @else
                    <x-table>
                        <thead><tr><th>Pairing</th><th>Role</th><th>Status</th><th>Size</th></tr></thead>
                        <tbody>
                            @foreach ($pairings as $f)
                                <tr>
                                    <td class="font-medium text-slate-900"><a href="{{ route('folders.show', $f) }}" class="hover:text-brand-700">{{ $f->name }}</a></td>
                                    <td><x-badge :color="$f->_role === 'Main' ? 'info' : 'neutral'">{{ $f->_role }}</x-badge></td>
                                    <td><x-badge :color="$folderStatusColors[$f->status] ?? 'neutral'" dot>{{ $f->statusLabel() }}</x-badge></td>
                                    <td class="tabular text-slate-500">{{ \App\Support\Bytes::human($f->size_bytes) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-table>
                @endif
            </x-card>

            <x-card title="Device Groups">
                @if ($device->groups->isEmpty())
                    <x-empty-state icon="users" title="No Groups" description="This endpoint is not in any device group yet." />
                @else
                    <div class="flex flex-wrap gap-2">
                        @foreach ($device->groups as $g)
                            <a href="{{ route('device-groups.show', $g) }}"><x-badge color="info">{{ $g->name }}</x-badge></a>
                        @endforeach
                    </div>
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
                    <div><dt class="text-slate-500">Status</dt><dd><x-badge :color="$statusColors[$device->status] ?? 'neutral'" dot>{{ $device->statusLabel() }}</x-badge></dd></div>
                    <div><dt class="text-slate-500">Device ID</dt><dd class="text-slate-900 font-mono text-xs break-all">{{ $device->device_id }}</dd></div>
                    @if (auth()->user()->isAdmin())<div><dt class="text-slate-500">Owner</dt><dd class="text-slate-900">{{ $device->owner?->name ?? 'Unassigned' }}</dd></div>@endif
                    <div><dt class="text-slate-500">Created</dt><dd class="text-slate-900">{{ $device->created_at->format('M j, Y') }}</dd></div>
                </dl>
            </x-card>
        </div>
    </div>
</x-layouts.app>
