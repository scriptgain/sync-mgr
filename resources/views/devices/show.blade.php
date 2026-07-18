@php
    $statusColors = ['connected' => 'success', 'disconnected' => 'neutral', 'paused' => 'warn'];
    $folderStatusColors = ['idle' => 'neutral', 'syncing' => 'info', 'scanning' => 'info', 'paused' => 'warn', 'error' => 'danger'];
    $hasSecret = filled($device->secret);
    $isAgent = $device->isAgent();
    $agentOnline = $device->agentOnline();
    $agentRepo = 'https://github.com/TheLonelyFrogTech/syncmgr-agent';
    $enrollPlain = session('enrollment_token_plain');
    $masterUrl = rtrim(config('app.url'), '/');
@endphp
<x-layouts.app :title="$device->name">
    <x-page-header :title="$device->name" icon="server"
        :subtitle="$device->typeLabel() . ' · ' . $device->statusLabel()"
        :back="['href' => route('devices.index'), 'label' => 'Endpoints']">
        <x-slot:actions>
            @if ($isAgent)
                <x-confirm-action name="reissue-enroll" :action="route('devices.enrollment-token', $device)"
                    title="Generate New Enrollment Code?"
                    message="This invalidates any previous unused code and shows a fresh one once. Use it to pair or re-pair this agent."
                    confirm="Generate Code" confirmIcon="key">
                    <x-button type="button" variant="secondary" icon="key">{{ $device->isEnrolled() ? 'Re-Pair Agent' : 'New Enrollment Code' }}</x-button>
                </x-confirm-action>
            @endif
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
            @if ($isAgent)
                {{-- Enrollment code (shown once, right after create / re-issue) --}}
                @if ($enrollPlain)
                    <x-card title="Enrollment Code" x-data="{ copied: false }">
                        <p class="text-sm text-slate-600 mb-3">Copy this one-time code now. It is shown <span class="font-medium text-slate-700">once</span> and only its hash is stored. Paste it into the install command below to pair this computer.</p>
                        <div class="flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-3">
                            <code class="flex-1 font-mono text-xs text-emerald-300 break-all">{{ $enrollPlain }}</code>
                            <button type="button" class="shrink-0 text-slate-400 hover:text-white" data-tip="Copy Code"
                                @click="navigator.clipboard.writeText(@js($enrollPlain)); copied = true; setTimeout(() => copied = false, 1500)">
                                <span x-show="! copied"><x-icon name="key" class="w-4 h-4" /></span>
                                <span x-show="copied" x-cloak class="text-emerald-400 text-xs font-medium">Copied</span>
                            </button>
                        </div>
                    </x-card>
                @endif

                {{-- Agent status --}}
                <x-card title="Agent Status">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                        <div>
                            <dt class="text-slate-500">Connection</dt>
                            <dd>
                                @if (! $device->isEnrolled())
                                    <x-badge color="warn" dot>Not Enrolled</x-badge>
                                @elseif ($agentOnline)
                                    <x-badge color="success" dot>Online</x-badge>
                                @else
                                    <x-badge color="danger" dot>Offline</x-badge>
                                @endif
                            </dd>
                        </div>
                        <div><dt class="text-slate-500">Last Check-In</dt><dd class="text-slate-900">{{ optional($device->last_checkin_at)->diffForHumans() ?? 'Never' }}</dd></div>
                        <div><dt class="text-slate-500">Agent Version</dt><dd class="text-slate-900">{{ $device->agent_version ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">OS / Arch</dt><dd class="text-slate-900">{{ trim(($device->os ?: '—') . ($device->arch ? ' / ' . $device->arch : '')) }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-slate-500">Local Folder Path</dt><dd class="text-slate-900 font-mono text-xs break-all">{{ $device->base_path ?: 'Set a Base Path on this endpoint (the folder to sync).' }}</dd></div>
                    </dl>
                </x-card>

                {{-- Install commands --}}
                <x-card title="Install The Agent">
                    <p class="text-sm text-slate-600 mb-4">Run the one-liner for your operating system on the computer you want to sync. It downloads the agent, then pairs it with this Manager using the enrollment code above.</p>
                    <div class="rounded-lg bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-200 mb-4">
                        <p class="text-xs text-amber-800">The public agent build is published in a later phase. The commands below use a placeholder repository (<span class="font-mono">{{ $agentRepo }}</span>) and will not resolve until the first release ships.</p>
                    </div>
                    @php
                        $token = $enrollPlain ?: 'YOUR-ENROLLMENT-CODE';
                        $cmds = [
                            'Linux / macOS' => "curl -fsSL {$agentRepo}/releases/latest/download/install.sh | sh -s -- \\\n  --master {$masterUrl} --token {$token}",
                            'Windows (PowerShell)' => "irm {$agentRepo}/releases/latest/download/install.ps1 | iex; \\\n  syncmgr-agent enroll -master {$masterUrl} -token {$token}",
                        ];
                    @endphp
                    <div class="space-y-4" x-data="{ tab: 'Linux / macOS' }">
                        <div class="flex gap-2">
                            @foreach (array_keys($cmds) as $os)
                                <button type="button" @click="tab = @js($os)"
                                    :class="tab === @js($os) ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                    class="rounded-lg px-3 py-1.5 text-xs font-medium transition-colors">{{ $os }}</button>
                            @endforeach
                        </div>
                        @foreach ($cmds as $os => $cmd)
                            <div x-show="tab === @js($os)" x-cloak class="flex items-start gap-2 rounded-lg bg-slate-900 px-4 py-3">
                                <pre class="flex-1 overflow-x-auto font-mono text-xs text-slate-100 whitespace-pre-wrap break-all">{{ $cmd }}</pre>
                                <button type="button" class="shrink-0 text-slate-400 hover:text-white" data-tip="Copy Command"
                                    @click="navigator.clipboard.writeText(@js($cmd))"><x-icon name="key" class="w-4 h-4" /></button>
                            </div>
                        @endforeach
                    </div>
                    @unless ($enrollPlain)
                        <p class="text-xs text-slate-500 mt-3">Use <span class="font-medium text-slate-600">{{ $device->isEnrolled() ? 'Re-Pair Agent' : 'New Enrollment Code' }}</span> above to reveal a code to drop into <span class="font-mono">--token</span>.</p>
                    @endunless
                </x-card>
            @endif

            <x-card title="Connection">
                @if (! $device->isLive() && ! $isAgent)
                    <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-200">
                        <p class="text-sm text-amber-800">This endpoint type cannot run live sync yet. Use FTP, SFTP or S3 for live sync today.</p>
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
