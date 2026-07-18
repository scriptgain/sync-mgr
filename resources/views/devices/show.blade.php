@php
    $statusColors = ['connected' => 'success', 'disconnected' => 'neutral', 'paused' => 'warn'];
    $folderStatusColors = ['idle' => 'neutral', 'syncing' => 'info', 'scanning' => 'info', 'paused' => 'warn', 'error' => 'danger'];
    $hasSecret = filled($device->secret);
    $isAgent = $device->isAgent();
    $agentOnline = $device->agentOnline();
    $agentRepo = 'https://github.com/scriptgain/syncmgr-agent';
    $enrollPlain = session('enrollment_token_plain') ?: $device->enrollment_plain;
    $masterUrl = rtrim(config('app.url'), '/');

    // Which tabs this device shows. Install is agent-only; Connection is for the
    // network transports. Overview / Pairings / Activity apply to everything.
    $showInstall = $isAgent;
    $showConnection = ! $isAgent;
    // The Files browser only works where the master can reach the storage
    // (ftp/sftp/s3/local). Agent endpoints keep their files on the remote box.
    $showFiles = $device->isLive();
    $basePathLabel = $device->endpoint_type === 'local' ? 'Local Path' : 'Base Path';
    $defaultTab = ($isAgent && ! $device->isEnrolled()) ? 'install' : 'overview';

    $tabs = [];
    if ($showInstall) {
        $tabs['install'] = ['label' => 'Install', 'icon' => 'download'];
    }
    $tabs['overview'] = ['label' => 'Overview', 'icon' => 'info'];
    if ($showConnection) {
        $tabs['connection'] = ['label' => 'Connection', 'icon' => 'globe'];
    }
    if ($showFiles) {
        $tabs['files'] = ['label' => 'Files', 'icon' => 'folder'];
    }
    $tabs['pairings'] = ['label' => 'Pairings', 'icon' => 'folder'];
    $tabs['activity'] = ['label' => 'Activity', 'icon' => 'clock'];

    // The header status dot: live online/offline for agents, stored status otherwise.
    if ($isAgent) {
        if (! $device->isEnrolled()) {
            [$hdrColor, $hdrLabel] = ['warn', 'Not Enrolled'];
        } elseif ($agentOnline) {
            [$hdrColor, $hdrLabel] = ['success', 'Online'];
        } else {
            [$hdrColor, $hdrLabel] = ['danger', 'Offline'];
        }
    } else {
        $hdrColor = $statusColors[$device->status] ?? 'neutral';
        $hdrLabel = $device->statusLabel();
    }

    $pairings = $device->mainPairings->map(fn ($f) => tap($f)->setAttribute('_role', 'Main'))
        ->concat($device->peerFolders->map(fn ($f) => tap($f)->setAttribute('_role', 'Peer')))
        ->unique('id');
@endphp
<x-layouts.app :title="$device->name">
    <div x-data="{ tab: '{{ $defaultTab }}' }">
        {{-- Compact header: back link, name + type badge + live status dot, actions --}}
        <div class="pb-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-white text-brand-600 ring-1 ring-slate-200 shadow-sm shrink-0">
                        <x-icon name="server" class="w-5 h-5" />
                    </span>
                    <div class="min-w-0">
                        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight text-slate-900 truncate">{{ $device->name }}</h1>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <x-badge color="neutral">{{ $device->typeLabel() }}</x-badge>
                            <x-badge :color="$hdrColor" dot>{{ $hdrLabel }}</x-badge>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2 shrink-0">
                    <a href="{{ route('devices.index') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-slate-600 shadow-sm ring-1 ring-inset ring-slate-200 transition hover:bg-slate-50 hover:text-brand-700 hover:ring-slate-300">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                        Endpoints
                    </a>
                    @if ($isAgent)
                        <x-confirm-action name="reissue-enroll" :action="route('devices.enrollment-token', $device)"
                            title="Generate New Enrollment Code?"
                            message="This invalidates any previous unused code and shows a fresh one once. Use it to pair or re-pair this agent."
                            confirm="Generate Code" confirmIcon="key">
                            <x-button type="button" variant="secondary" icon="key">{{ $device->isEnrolled() ? 'Re-Pair Agent' : 'New Enrollment Code' }}</x-button>
                        </x-confirm-action>
                    @endif
                    @if ($device->isLive())
                        <form method="POST" action="{{ route('devices.test', $device) }}" x-data="{ testing: false }" x-on:submit="testing = true">
                            @csrf
                            <x-button type="submit" variant="secondary" x-bind:disabled="testing">
                                <template x-if="! testing">
                                    <span class="inline-flex items-center gap-2"><x-icon name="bolt" class="w-4 h-4 -ml-0.5 shrink-0" />Test Connection</span>
                                </template>
                                <template x-if="testing">
                                    <span class="inline-flex items-center gap-2">
                                        <svg class="w-4 h-4 -ml-0.5 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                        </svg>Testing...
                                    </span>
                                </template>
                            </x-button>
                        </form>
                    @endif
                    <x-button variant="secondary" icon="edit" href="{{ route('devices.edit', $device) }}">Edit</x-button>
                    <x-delete-button :name="'del-device'" :action="route('devices.destroy', $device)"
                        title="Delete Endpoint?" message="This removes the endpoint. Pairings that use it will stop running. This cannot be undone." />
                </div>
            </div>
        </div>

        {{-- Tab bar (underline; scrolls sideways on small screens) --}}
        <div class="border-b border-slate-200 flex gap-1 overflow-x-auto overflow-y-hidden" role="tablist" aria-label="Device Sections">
            @foreach ($tabs as $key => $t)
                <button type="button" role="tab" @click="tab = @js($key)"
                    :aria-selected="tab === @js($key) ? 'true' : 'false'"
                    :class="tab === @js($key) ? 'text-brand-700 border-brand-600' : 'text-slate-500 border-transparent hover:text-slate-800 hover:border-slate-300'"
                    class="inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2.5 -mb-px text-sm font-medium transition-colors">
                    <x-icon :name="$t['icon']" class="w-4 h-4" />{{ $t['label'] }}
                </button>
            @endforeach
        </div>

        <div class="mt-6">
            {{-- ============================ INSTALL ============================ --}}
            @if ($showInstall)
                <div x-show="tab === 'install'" x-cloak class="space-y-6">
                    <x-card title="Set Up This Agent" subtitle="Three steps to bring this computer online.">
                        {{-- Step 1: copy the enrollment code --}}
                        <div class="flex gap-4">
                            <div class="hidden sm:flex flex-col items-center shrink-0">
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-brand-600 text-white text-sm font-semibold">1</span>
                                <span class="mt-1 flex-1 w-px bg-slate-200"></span>
                            </div>
                            <div class="min-w-0 flex-1 pb-6" x-data="{ copied: false }">
                                <h4 class="text-sm font-semibold text-slate-900">Copy The Enrollment Code</h4>
                                <p class="mt-1 text-sm text-slate-600">Shown <span class="font-medium text-slate-700">once</span>. Only its hash is stored, so grab it now. The install command below already carries it.</p>
                                <div class="mt-3 flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-3">
                                    <code class="flex-1 font-mono text-xs text-emerald-300 break-all">{{ $enrollPlain ?: 'Generate a code with "New Enrollment Code" above.' }}</code>
                                    @if ($enrollPlain)
                                        <button type="button" class="shrink-0 text-slate-400 hover:text-white" data-tip="Copy Code"
                                            @click="navigator.clipboard.writeText(@js($enrollPlain)); copied = true; setTimeout(() => copied = false, 1500)">
                                            <span x-show="! copied"><x-icon name="copy" class="w-4 h-4" /></span>
                                            <span x-show="copied" x-cloak class="text-emerald-400 text-xs font-medium">Copied!</span>
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Step 2: run the install one-liner for your OS --}}
                        @php
                            $token = $enrollPlain ?: 'YOUR-ENROLLMENT-CODE';
                            $downloadBase = "{$masterUrl}/downloads/agent";
                            $cmds = [
                                'Linux' => "curl -fsSL {$downloadBase}/install.sh | sudo bash -s -- \\\n  -master {$masterUrl} -token {$token}",
                                'macOS' => "curl -fsSL {$downloadBase}/install.sh | sudo bash -s -- \\\n  -master {$masterUrl} -token {$token}",
                                'Windows' => "\$env:SYNCMGR_MASTER='{$masterUrl}'; \$env:SYNCMGR_TOKEN='{$token}'; `\n  irm {$downloadBase}/install.ps1 | iex",
                            ];
                        @endphp
                        <div class="flex gap-4">
                            <div class="hidden sm:flex flex-col items-center shrink-0">
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-brand-600 text-white text-sm font-semibold">2</span>
                                <span class="mt-1 flex-1 w-px bg-slate-200"></span>
                            </div>
                            <div class="min-w-0 flex-1 pb-6" x-data="{ os: 'Linux', copied: null }">
                                <h4 class="text-sm font-semibold text-slate-900">Run The Installer For Your OS</h4>
                                <p class="mt-1 text-sm text-slate-600">On the computer you want to sync. It downloads the agent plus bundled rclone, pairs with this Manager, and installs a background service.</p>
                                <div class="mt-3 inline-flex rounded-lg bg-slate-100 p-1">
                                    @foreach (array_keys($cmds) as $os)
                                        <button type="button" @click="os = @js($os)"
                                            :class="os === @js($os) ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                                            class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors">{{ $os }}</button>
                                    @endforeach
                                </div>
                                @foreach ($cmds as $os => $cmd)
                                    <div x-show="os === @js($os)" x-cloak class="mt-3 flex items-start gap-2 rounded-lg bg-slate-900 px-4 py-3">
                                        <pre class="flex-1 overflow-x-auto font-mono text-xs text-slate-100 whitespace-pre-wrap break-all">{{ $cmd }}</pre>
                                        <span x-show="copied === @js($os)" x-cloak class="shrink-0 self-center text-emerald-400 text-xs font-medium">Copied!</span>
                                        <button type="button" class="shrink-0 text-slate-400 hover:text-white" data-tip="Copy Command"
                                            @click="navigator.clipboard.writeText(@js($cmd)); copied = @js($os); setTimeout(() => { if (copied === @js($os)) copied = null }, 1500)"><x-icon name="copy" class="w-4 h-4" /></button>
                                    </div>
                                @endforeach
                                @unless ($enrollPlain)
                                    <p class="mt-3 text-xs text-slate-500">Use <span class="font-medium text-slate-600">{{ $device->isEnrolled() ? 'Re-Pair Agent' : 'New Enrollment Code' }}</span> above to reveal a code to drop into <span class="font-mono">-token</span>.</p>
                                @endunless
                            </div>
                        </div>

                        {{-- Step 3: it comes online --}}
                        <div class="flex gap-4">
                            <div class="hidden sm:flex flex-col items-center shrink-0">
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-emerald-500 text-white"><x-icon name="check" class="w-4 h-4" /></span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h4 class="text-sm font-semibold text-slate-900">It Comes Online</h4>
                                <p class="mt-1 text-sm text-slate-600">After the agent checks in, the status dot above turns <span class="font-medium text-emerald-600">Online</span> and its version, OS and last check-in show on the Overview tab.</p>
                            </div>
                        </div>
                    </x-card>

                    <div class="rounded-lg bg-slate-50 px-4 py-3 ring-1 ring-inset ring-slate-200">
                        <p class="text-xs text-slate-600">Binaries are served from this Manager, so the commands work today. The public source will later move to <span class="font-mono">{{ $agentRepo }}</span> (GitHub Releases) as the canonical download.</p>
                    </div>
                </div>
            @endif

            {{-- ============================ OVERVIEW ============================ --}}
            <div x-show="tab === 'overview'" x-cloak class="space-y-6">
                <x-card title="Overview">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm">
                        <div><dt class="text-slate-500">Status</dt><dd class="mt-0.5"><x-badge :color="$hdrColor" dot>{{ $hdrLabel }}</x-badge></dd></div>
                        <div><dt class="text-slate-500">Type</dt><dd class="mt-0.5 text-slate-900">{{ $device->typeLabel() }}</dd></div>
                        @if ($isAgent)
                            <div><dt class="text-slate-500">Last Check-In</dt><dd class="mt-0.5 text-slate-900">{{ optional($device->last_checkin_at)->diffForHumans() ?? 'Never' }}</dd></div>
                            <div><dt class="text-slate-500">Agent Version</dt><dd class="mt-0.5 text-slate-900">{{ $device->agent_version ?: 'Not Reported' }}</dd></div>
                            <div><dt class="text-slate-500">OS / Arch</dt><dd class="mt-0.5 text-slate-900">{{ trim(($device->os ?: 'Not Reported') . ($device->arch ? ' / ' . $device->arch : '')) }}</dd></div>
                        @endif
                        <div class="sm:col-span-2"><dt class="text-slate-500">{{ $device->endpoint_type === 'local' ? 'Local Path' : ($isAgent ? 'Local Folder Path' : 'Base Path') }}</dt><dd class="mt-0.5 text-slate-900 font-mono text-xs break-all">{{ $device->base_path ?: ($isAgent ? 'Set a Base Path on this endpoint (the folder to sync).' : 'Not Set') }}</dd></div>
                        <div><dt class="text-slate-500">Device ID</dt><dd class="mt-0.5 text-slate-900 font-mono text-xs break-all">{{ $device->device_id }}</dd></div>
                        @if (auth()->user()->isAdmin())
                            <div><dt class="text-slate-500">Owner</dt><dd class="mt-0.5 text-slate-900">{{ $device->owner?->name ?? 'Unassigned' }}</dd></div>
                        @endif
                        <div><dt class="text-slate-500">Created</dt><dd class="mt-0.5 text-slate-900">{{ $device->created_at->format('M j, Y') }}</dd></div>
                        <div class="sm:col-span-2">
                            <dt class="text-slate-500">Device Groups</dt>
                            <dd class="mt-1.5">
                                @if ($device->groups->isEmpty())
                                    <span class="text-slate-400">Not In Any Group</span>
                                @else
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($device->groups as $g)
                                            <a href="{{ route('device-groups.show', $g) }}"><x-badge color="info">{{ $g->name }}</x-badge></a>
                                        @endforeach
                                    </div>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </x-card>

                @if ($device->notes)
                    <x-card title="Notes">
                        <p class="text-sm text-slate-600 whitespace-pre-line">{{ $device->notes }}</p>
                    </x-card>
                @endif
            </div>

            {{-- ============================ CONNECTION ============================ --}}
            @if ($showConnection)
                <div x-show="tab === 'connection'" x-cloak class="space-y-6">
                    <x-card title="Connection">
                        @if (! $device->isLive())
                            <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-200">
                                <p class="text-sm text-amber-800">This endpoint type cannot run live sync yet. Use FTP, SFTP or S3 for live sync today.</p>
                            </div>
                        @endif
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm">
                            <div><dt class="text-slate-500">Type</dt><dd class="mt-0.5 text-slate-900">{{ $device->typeLabel() }}</dd></div>
                            @if ($device->endpoint_type !== 'local')
                                <div><dt class="text-slate-500">Host</dt><dd class="mt-0.5 text-slate-900 break-all">{{ $device->host ?: 'Not Set' }}</dd></div>
                                <div><dt class="text-slate-500">Port</dt><dd class="mt-0.5 text-slate-900 tabular">{{ $device->port ?: 'Not Set' }}</dd></div>
                                <div><dt class="text-slate-500">{{ $device->endpoint_type === 's3' ? 'Access Key' : 'Username' }}</dt><dd class="mt-0.5 text-slate-900 break-all">{{ $device->username ?: 'Not Set' }}</dd></div>
                                <div x-data="{ reveal: false, secret: @js($device->secret), copied: false }">
                                    <dt class="text-slate-500">{{ $device->endpoint_type === 's3' ? 'Secret Key' : 'Password' }}</dt>
                                    <dd class="mt-0.5 text-slate-900">
                                        @if ($hasSecret)
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono text-xs break-all" x-text="reveal ? secret : '••••••••••••'"></span>
                                                <button type="button" @click="reveal = ! reveal" class="text-slate-400 hover:text-slate-600" data-tip="Reveal / Hide"><x-icon name="eye" class="w-4 h-4" /></button>
                                                <button type="button" @click="navigator.clipboard.writeText(secret); copied = true; setTimeout(() => copied = false, 1500)" class="text-slate-400 hover:text-slate-600" data-tip="Copy">
                                                    <span x-show="! copied"><x-icon name="copy" class="w-4 h-4" /></span>
                                                    <span x-show="copied" x-cloak class="text-emerald-600 text-xs font-medium">Copied!</span>
                                                </button>
                                            </div>
                                        @else
                                            <span class="text-slate-400">Not Set</span>
                                        @endif
                                    </dd>
                                </div>
                            @endif
                            @if ($device->endpoint_type === 's3')
                                <div><dt class="text-slate-500">Bucket</dt><dd class="mt-0.5 text-slate-900 break-all">{{ $device->bucket ?: 'Not Set' }}</dd></div>
                                <div><dt class="text-slate-500">Region</dt><dd class="mt-0.5 text-slate-900">{{ $device->region ?: 'Not Set' }}</dd></div>
                                <div><dt class="text-slate-500">Path Style</dt><dd class="mt-0.5">@if ($device->s3_path_style)<x-badge color="info">On</x-badge>@else<x-badge color="neutral">Off</x-badge>@endif</dd></div>
                            @endif
                            @if ($device->endpoint_type === 'ftp')
                                <div><dt class="text-slate-500">Explicit TLS</dt><dd class="mt-0.5">@if ($device->ftp_tls)<x-badge color="success">On</x-badge>@else<x-badge color="neutral">Off</x-badge>@endif</dd></div>
                            @endif
                            <div class="sm:col-span-2"><dt class="text-slate-500">{{ $device->endpoint_type === 'local' ? 'Local Path' : 'Base Path' }}</dt><dd class="mt-0.5 text-slate-900 font-mono text-xs break-all">{{ $device->base_path ?: 'Not Set' }}</dd></div>
                        </dl>
                    </x-card>
                </div>
            @endif

            {{-- ============================ FILES ============================ --}}
            @if ($showFiles)
                <div x-show="tab === 'files'" x-cloak class="space-y-6"
                    x-data="fileBrowser({
                        url: @js(route('devices.browse', $device)),
                        downloadUrl: @js(route('devices.download', $device)),
                        rootLabel: @js($basePathLabel),
                        basePath: @js($device->base_path ?: ''),
                    })"
                    x-init="$watch('tab', v => { if (v === 'files' && ! loaded) { loaded = true; load('') } })">
                    {{-- Base Path banner: this is where the browser is rooted. --}}
                    <div class="rounded-lg bg-slate-50 px-4 py-3 ring-1 ring-inset ring-slate-200">
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-slate-500 shrink-0">{{ $basePathLabel }}</span>
                            <span class="font-mono text-xs text-slate-800 break-all">{{ $device->base_path ?: 'Not Set' }}</span>
                        </div>
                    </div>

                    <x-card flush>
                        <x-slot:actions>
                            <x-button variant="secondary" size="sm" icon="download" :href="route('devices.download', $device)"
                                x-bind:href="downloadUrl + '?path=' + encodeURIComponent(cwd)"
                                x-bind:data-tip="'Download This Folder And Its Subfolders As A Zip'"
                                x-bind:class="(loading || error || entries.length === 0) ? 'pointer-events-none opacity-50' : ''">Download .zip</x-button>
                            <x-button type="button" variant="secondary" size="sm" icon="refresh" x-on:click="load(cwd)" x-bind:disabled="loading">Refresh</x-button>
                        </x-slot:actions>
                        <x-slot:title>Files</x-slot:title>

                        {{-- Breadcrumb: Base Path -> subdir -> ... , each clickable to jump up. --}}
                        <div class="flex items-center flex-wrap gap-1 px-5 sm:px-6 py-3 border-b border-slate-100 text-sm">
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 font-medium text-slate-600 hover:bg-slate-100 hover:text-brand-700 transition"
                                x-on:click="load('')">
                                <x-icon name="home" class="w-4 h-4" />{{ $basePathLabel }}
                            </button>
                            <template x-for="(crumb, i) in crumbs" :key="i">
                                <span class="inline-flex items-center gap-1">
                                    <svg class="w-4 h-4 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                    <button type="button" class="rounded-md px-2 py-1 font-medium text-slate-600 hover:bg-slate-100 hover:text-brand-700 transition max-w-[16rem] truncate"
                                        x-bind:data-tip="crumb.name" x-text="crumb.name" x-on:click="load(crumb.path)"></button>
                                </span>
                            </template>
                        </div>

                        {{-- Loading state --}}
                        <div x-show="loading" x-cloak class="flex items-center justify-center gap-3 py-14 text-sm text-slate-500">
                            <svg class="w-5 h-5 animate-spin text-brand-500" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Loading Files...
                        </div>

                        {{-- Error state (fail-soft: shows the rclone message) --}}
                        <div x-show="! loading && error" x-cloak class="px-5 sm:px-6 py-10">
                            <div class="mx-auto max-w-lg text-center">
                                <span class="mx-auto inline-flex items-center justify-center w-12 h-12 rounded-xl bg-rose-50 text-rose-500 ring-1 ring-rose-200">
                                    <x-icon name="warning" class="w-6 h-6" />
                                </span>
                                <h3 class="mt-4 text-sm font-semibold text-slate-900">Could Not List This Folder</h3>
                                <p class="mt-1 text-sm text-slate-500 break-words" x-text="error"></p>
                                <div class="mt-5"><x-button type="button" variant="secondary" size="sm" icon="refresh" x-on:click="load(cwd)">Try Again</x-button></div>
                            </div>
                        </div>

                        {{-- Empty state --}}
                        <div x-show="! loading && ! error && entries.length === 0" x-cloak class="px-5 sm:px-6">
                            <x-empty-state icon="folder" title="This Folder Is Empty" description="No files or subfolders were found here." />
                        </div>

                        {{-- Listing (flush status-table style) --}}
                        <div x-show="! loading && ! error && entries.length > 0" x-cloak>
                            <x-table flush>
                                <thead><tr><th>Name</th><th class="w-32">Size</th><th class="w-56">Modified</th><th class="w-16 text-right"><span class="sr-only">Download</span></th></tr></thead>
                                <tbody>
                                    <template x-for="entry in entries" :key="entry.path">
                                        <tr x-bind:class="entry.is_dir ? 'cursor-pointer' : ''" x-on:click="entry.is_dir && load(entry.path)">
                                            <td class="font-medium text-slate-900">
                                                <span class="inline-flex items-center gap-2 min-w-0">
                                                    <template x-if="entry.is_dir">
                                                        <svg class="w-4 h-4 shrink-0 text-brand-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
                                                    </template>
                                                    <template x-if="! entry.is_dir">
                                                        <svg class="w-4 h-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                                                    </template>
                                                    <template x-if="entry.is_dir">
                                                        <button type="button" class="truncate text-left hover:text-brand-700" x-bind:data-tip="entry.name" x-text="entry.name" x-on:click.stop="load(entry.path)"></button>
                                                    </template>
                                                    <template x-if="! entry.is_dir">
                                                        <span class="truncate" x-bind:data-tip="entry.name" x-text="entry.name"></span>
                                                    </template>
                                                </span>
                                            </td>
                                            <td class="tabular text-slate-500" x-text="entry.is_dir ? '—' : humanSize(entry.size)"></td>
                                            <td class="text-slate-500" x-text="humanDate(entry.mod_time)"></td>
                                            <td class="text-right">
                                                <template x-if="! entry.is_dir">
                                                    <a x-bind:href="downloadUrl + '?path=' + encodeURIComponent(entry.path) + '&file=1'"
                                                        x-on:click.stop
                                                        class="inline-flex items-center justify-center w-8 h-8 rounded-md text-slate-400 hover:bg-slate-100 hover:text-brand-700 transition"
                                                        data-tip="Download File"><x-icon name="download" class="w-4 h-4" /></a>
                                                </template>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </x-table>
                        </div>
                    </x-card>
                </div>

                <script>
                    // File browser: fetches devices.browse for the current subpath and
                    // renders a folder/file listing. Fail-soft, read-only.
                    function fileBrowser(cfg) {
                        return {
                            url: cfg.url,
                            downloadUrl: cfg.downloadUrl,
                            rootLabel: cfg.rootLabel,
                            loading: false,
                            loaded: false,
                            error: null,
                            cwd: '',
                            entries: [],
                            get crumbs() {
                                if (! this.cwd) return [];
                                const parts = this.cwd.split('/').filter(Boolean);
                                let acc = [];
                                return parts.map((name) => {
                                    acc.push(name);
                                    return { name, path: acc.join('/') };
                                });
                            },
                            async load(path) {
                                this.loading = true;
                                this.error = null;
                                try {
                                    const res = await fetch(this.url + '?path=' + encodeURIComponent(path || ''), {
                                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                    });
                                    const data = await res.json();
                                    if (data.ok) {
                                        this.cwd = data.cwd || '';
                                        this.entries = data.entries || [];
                                    } else {
                                        this.cwd = data.cwd || this.cwd;
                                        this.entries = [];
                                        this.error = data.error || 'Could not read that folder.';
                                    }
                                } catch (e) {
                                    this.entries = [];
                                    this.error = 'Could not reach the server. Please try again.';
                                } finally {
                                    this.loading = false;
                                }
                            },
                            humanSize(bytes) {
                                bytes = Number(bytes) || 0;
                                if (bytes < 1024) return bytes + ' B';
                                const units = ['KB', 'MB', 'GB', 'TB', 'PB'];
                                let i = -1;
                                do { bytes /= 1024; i++; } while (bytes >= 1024 && i < units.length - 1);
                                return (bytes >= 10 || Number.isInteger(bytes) ? Math.round(bytes) : bytes.toFixed(1)) + ' ' + units[i];
                            },
                            humanDate(iso) {
                                if (! iso) return '—';
                                const d = new Date(iso);
                                if (isNaN(d)) return '—';
                                return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                            },
                        };
                    }
                </script>
            @endif

            {{-- ============================ PAIRINGS ============================ --}}
            <div x-show="tab === 'pairings'" x-cloak class="space-y-6">
                <x-card title="Pairings Using This Endpoint" flush>
                    @if ($pairings->isEmpty())
                        <div class="p-6"><x-empty-state icon="folder" title="No Pairings" description="Create a sync pairing that uses this endpoint as its Main or a Peer." /></div>
                    @else
                        <x-table flush>
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
            </div>

            {{-- ============================ ACTIVITY ============================ --}}
            <div x-show="tab === 'activity'" x-cloak class="space-y-6">
                <x-card title="Recent Activity" flush>
                    @if ($events->isEmpty())
                        <div class="p-6"><x-empty-state icon="clock" title="No Activity Yet" description="Runs that touch this endpoint appear here once its pairings start syncing." /></div>
                    @else
                        <x-table flush>
                            <thead><tr><th>Status</th><th>Pairing</th><th>Files</th><th>Size</th><th>Duration</th><th>Message</th><th>When</th></tr></thead>
                            <tbody>
                                @foreach ($events as $e)
                                    <tr>
                                        <td><x-badge :color="$e->statusColor()" dot>{{ $e->statusLabel() }}</x-badge></td>
                                        <td class="text-slate-600">@if ($e->folder)<a href="{{ route('folders.show', $e->folder) }}" class="hover:text-brand-700">{{ $e->folder->name }}</a>@else—@endif</td>
                                        <td class="tabular text-slate-600">{{ number_format($e->files_transferred) }}</td>
                                        <td class="tabular text-slate-600">{{ \App\Support\Bytes::human($e->bytes_transferred) }}</td>
                                        <td class="tabular text-slate-600">{{ $e->durationLabel() }}</td>
                                        <td class="text-slate-600"><a href="{{ route('events.show', $e) }}" class="hover:text-brand-700">{{ \Illuminate\Support\Str::limit($e->message, 60) ?: '—' }}</a></td>
                                        <td class="text-slate-500">{{ optional($e->occurred_at ?? $e->created_at)->diffForHumans() ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-table>
                    @endif
                </x-card>
            </div>
        </div>
    </div>
</x-layouts.app>
