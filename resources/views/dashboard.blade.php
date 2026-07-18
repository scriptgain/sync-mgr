@php
    use Illuminate\Support\Str;

    $statusColors = ['idle' => 'neutral', 'syncing' => 'info', 'scanning' => 'info', 'paused' => 'warn', 'error' => 'danger'];
    $eventColors = ['scan' => 'neutral', 'index' => 'info', 'conflict' => 'warn', 'completed' => 'success', 'error' => 'danger'];

    // KPI row.
    $kpis = [
        ['label' => 'Folders', 'value' => number_format($stats['folders']), 'icon' => 'folder',
            'sub' => number_format($fileCount) . ' files · ' . $stats['storage'], 'tone' => 'muted'],
        ['label' => 'Devices', 'value' => number_format($stats['devices']), 'icon' => 'server',
            'sub' => $stats['connected'] . ' of ' . $stats['devices'] . ' connected',
            'tone' => $stats['connected'] ? 'emerald' : 'amber'],
        ['label' => 'Events (24h)', 'value' => number_format($events24h), 'icon' => 'clock',
            'sub' => 'Sync activity today', 'tone' => 'muted'],
        ['label' => 'Failures (7d)', 'value' => number_format($failures), 'icon' => 'warning',
            'sub' => $failures ? 'Need attention' : 'No recent errors',
            'tone' => $failures ? 'rose' : 'emerald'],
    ];
    $toneClass = ['muted' => 'text-slate-400', 'amber' => 'text-amber-600', 'rose' => 'text-rose-600', 'emerald' => 'text-emerald-600'];

    // 14-day activity bar chart geometry.
    $cw = 700; $ch = 150; $padT = 12; $padB = 22;
    $plotH = $ch - $padT - $padB;
    $baseY = $padT + $plotH;
    $n = max(1, count($activity));
    $slot = ($cw - 8) / $n;
    $barW = min(26, $slot * 0.62);
    $maxVal = max(1, max(array_column($activity, 'total') ?: [0]));

    // Folder health gauge.
    $gaugeLen = 276.46;
    $healthy = $stats['folders'] - $errorFolders;
    $healthPct = $stats['folders'] ? (int) round($healthy / $stats['folders'] * 100) : null;
    if ($healthPct !== null) {
        $healthLow = $errorFolders > 0;
        $healthDash = round(min(100, $healthPct) / 100 * $gaugeLen, 1);
    }
@endphp

<x-layouts.app title="Dashboard">
    <style>
        .bk-ok-fill { fill: var(--color-brand-500); }
        .bk-ok-stroke { stroke: var(--color-brand-500); }
        .bk-ok-bg { background-color: var(--color-brand-500); }
    </style>

    <x-page-header title="Dashboard" subtitle="File sync at a glance.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" icon="server" href="{{ route('devices.index') }}">Devices</x-button>
            <x-button size="sm" icon="plus" href="{{ route('folders.create') }}">New Folder</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- KPI row --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach ($kpis as $k)
            <div class="group relative flex flex-col overflow-hidden rounded-xl bg-white ring-1 ring-slate-200 shadow-sm transition hover:shadow-md hover:ring-brand-200">
                <span class="h-1 w-full bg-gradient-to-r from-brand-400 to-brand-600"></span>
                <div class="flex flex-1 items-center gap-4 p-5">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 ring-1 ring-brand-100">
                        <x-icon :name="$k['icon']" class="h-5 w-5" />
                    </span>
                    <div class="ml-auto text-right">
                        <div class="text-2xl font-semibold tracking-tight text-slate-900 tabular">{{ $k['value'] }}</div>
                        <div class="text-sm font-medium text-slate-600">{{ $k['label'] }}</div>
                        <div class="mt-0.5 text-xs font-medium {{ $toneClass[$k['tone']] }}">{{ $k['sub'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Activity + folder health --}}
    <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
        {{-- Sync activity (signature visual) --}}
        <x-card title="Sync Activity" subtitle="Events per day, last 14 days" class="lg:col-span-2">
            <x-slot:actions>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700 ring-1 ring-inset ring-brand-200">
                    <x-icon name="sync" class="h-3.5 w-3.5" /> {{ number_format($windowTotal) }} {{ Str::plural('event', $windowTotal) }}
                </span>
            </x-slot:actions>

            @if ($windowTotal === 0)
                <div class="flex h-40 flex-col items-center justify-center text-center">
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-400"><x-icon name="clock" class="h-5 w-5" /></span>
                    <p class="mt-3 text-sm text-slate-500">No sync events in the last 14 days.</p>
                </div>
            @else
                <svg viewBox="0 0 {{ $cw }} {{ $ch }}" width="100%" class="block h-auto" role="img" aria-label="Sync events per day over the last 14 days">
                    <line x1="4" y1="{{ $baseY + 0.5 }}" x2="{{ $cw - 4 }}" y2="{{ $baseY + 0.5 }}" stroke="#e2e8f0" stroke-width="1" />
                    @foreach ($activity as $i => $d)
                        @php
                            $cx = 4 + $slot * $i + $slot / 2;
                            $x = round($cx - $barW / 2, 1);
                            $h = $d['total'] ? max(3, round($d['total'] / $maxVal * $plotH, 1)) : 0;
                            $issueH = $d['total'] ? round($d['issues'] / $d['total'] * $h, 1) : 0;
                            $doneH = $d['total'] ? round($d['done'] / $d['total'] * $h, 1) : 0;
                            $otherH = round($h - $issueH - $doneH, 1);
                        @endphp
                        @if ($h === 0.0 || $h === 0)
                            <rect x="{{ $x }}" y="{{ $baseY - 3 }}" width="{{ round($barW, 1) }}" height="3" rx="1.5" fill="#e2e8f0" />
                        @else
                            @php $cursor = $baseY; @endphp
                            @if ($issueH > 0)
                                <rect x="{{ $x }}" y="{{ round($cursor - $issueH, 1) }}" width="{{ round($barW, 1) }}" height="{{ $issueH }}" rx="2" fill="#f43f5e" />
                                @php $cursor -= $issueH; @endphp
                            @endif
                            @if ($otherH > 0)
                                <rect x="{{ $x }}" y="{{ round($cursor - $otherH, 1) }}" width="{{ round($barW, 1) }}" height="{{ $otherH }}" rx="2" fill="#cbd5e1" />
                                @php $cursor -= $otherH; @endphp
                            @endif
                            @if ($doneH > 0)
                                <rect x="{{ $x }}" y="{{ round($cursor - $doneH, 1) }}" width="{{ round($barW, 1) }}" height="{{ $doneH }}" rx="2" class="bk-ok-fill" />
                            @endif
                        @endif
                        @if ($i === 0 || $i === intdiv($n, 2) || $i === $n - 1)
                            <text x="{{ round($cx, 1) }}" y="{{ $ch - 6 }}" text-anchor="{{ $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle') }}" fill="#94a3b8" style="font-size:11px">{{ $d['label'] }}</text>
                        @endif
                    @endforeach
                </svg>

                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs font-medium text-slate-500">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bk-ok-bg"></span> Completed</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" style="background-color:#cbd5e1"></span> Scan / index</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" style="background-color:#f43f5e"></span> Error / conflict</span>
                </div>
            @endif

            <x-slot:footer>
                <div class="flex items-center gap-6">
                    <div class="flex items-center gap-2.5">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg {{ $syncingFolders ? 'bg-brand-50 text-brand-600 ring-1 ring-brand-100' : 'bg-white text-slate-400 ring-1 ring-slate-200' }}"><x-icon name="sync" class="h-4 w-4" /></span>
                        <div>
                            <p class="text-lg font-semibold leading-tight tabular text-slate-900">{{ $syncingFolders }}</p>
                            <p class="text-xs text-slate-500">Currently syncing</p>
                        </div>
                    </div>
                    <span class="h-9 w-px bg-slate-200"></span>
                    <div class="flex items-center gap-2.5">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg {{ $errorFolders ? 'bg-rose-50 text-rose-600 ring-1 ring-rose-100' : 'bg-white text-slate-400 ring-1 ring-slate-200' }}"><x-icon name="warning" class="h-4 w-4" /></span>
                        <div>
                            <p class="text-lg font-semibold leading-tight tabular {{ $errorFolders ? 'text-rose-600' : 'text-slate-900' }}">{{ $errorFolders }}</p>
                            <p class="text-xs text-slate-500">Folders in error</p>
                        </div>
                    </div>
                </div>
            </x-slot:footer>
        </x-card>

        {{-- Folder health gauge --}}
        <x-card title="Folder Health" subtitle="Folders without errors">
            @if ($healthPct !== null)
                <div>
                    <div class="mx-auto w-full max-w-[240px]">
                        <svg viewBox="0 0 200 122" width="100%" role="img" aria-label="Folder health {{ $healthPct }} percent healthy">
                            <path d="M12 110 A88 88 0 0 1 188 110" fill="none" stroke="#e2e8f0" stroke-width="14" stroke-linecap="round" />
                            <path d="M12 110 A88 88 0 0 1 188 110" fill="none" stroke-width="14" stroke-linecap="round"
                                stroke-dasharray="{{ $healthDash }} 1000"
                                @class(['bk-ok-stroke' => ! $healthLow]) @style(['stroke:#f59e0b' => $healthLow]) />
                            <text x="100" y="92" text-anchor="middle" fill="#0f172a" style="font-size:38px;font-weight:700;font-variant-numeric:tabular-nums">{{ $healthPct }}%</text>
                            <text x="100" y="110" text-anchor="middle" fill="#94a3b8" style="font-size:11px;letter-spacing:.02em">healthy</text>
                        </svg>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-lg font-semibold text-slate-900 tabular">{{ $healthy }} healthy</span>
                        <span class="text-sm text-slate-500 tabular">of {{ $stats['folders'] }} folders</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-400">
                        {{ $syncingFolders }} syncing · {{ $errorFolders }} in error
                    </p>
                </div>
            @else
                <div class="flex flex-col items-center justify-center py-6 text-center">
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-400"><x-icon name="folder" class="h-5 w-5" /></span>
                    <p class="mt-3 text-sm text-slate-500">No folders yet.</p>
                    <a href="{{ route('folders.create') }}" class="mt-1 text-sm font-medium text-brand-700 hover:underline">Create a folder</a>
                </div>
            @endif
        </x-card>
    </div>

    @if ($attention > 0)
        <div class="mt-6">
            <x-alert type="warn" title="{{ $attention }} {{ Str::plural('Folder', $attention) }} Need Attention">
                Some folders are syncing or reporting errors. <a href="{{ route('folders.index') }}" class="font-medium underline">Review folders</a>.
            </x-alert>
        </div>
    @endif

    {{-- Recent folders + events --}}
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
        <x-card title="Recent Folders" subtitle="Newest sync folders" :flush="$recentFolders->isNotEmpty()">
            <x-slot:actions>
                <x-button variant="ghost" size="sm" href="{{ route('folders.index') }}">View All</x-button>
            </x-slot:actions>

            @if ($recentFolders->isEmpty())
                <x-empty-state icon="folder" title="No Folders Yet" description="Create your first folder to start syncing.">
                    <x-slot:action><x-button icon="plus" href="{{ route('folders.create') }}">New Folder</x-button></x-slot:action>
                </x-empty-state>
            @else
                <x-table flush>
                    <thead><tr><th>Folder</th><th>Status</th><th class="text-right">Size</th></tr></thead>
                    <tbody>
                        @foreach ($recentFolders as $f)
                            <tr class="cursor-pointer" onclick="window.location='{{ route('folders.show', $f) }}'">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 ring-1 ring-brand-100"><x-icon name="folder" class="h-4 w-4" /></span>
                                        <div class="min-w-0">
                                            <div class="font-medium text-slate-900 truncate">{{ $f->name }}</div>
                                            <div class="text-xs text-slate-500 truncate">{{ $f->typeLabel() }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td><x-badge :color="$statusColors[$f->status] ?? 'neutral'" dot>{{ $f->statusLabel() }}</x-badge></td>
                                <td class="text-right tabular text-slate-600">{{ \App\Support\Bytes::human($f->size_bytes) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-table>
            @endif
        </x-card>

        <x-card title="Recent Events" subtitle="Latest sync activity" :flush="$recentEvents->isNotEmpty()">
            <x-slot:actions>
                <x-button variant="ghost" size="sm" href="{{ route('events.index') }}">View All</x-button>
            </x-slot:actions>

            @if ($recentEvents->isEmpty())
                <x-empty-state icon="clock" title="No Events Yet" description="Sync activity will appear here." />
            @else
                <x-table flush>
                    <thead><tr><th>Event</th><th>Folder</th><th class="text-right">When</th></tr></thead>
                    <tbody>
                        @foreach ($recentEvents as $e)
                            <tr class="cursor-pointer" onclick="window.location='{{ route('events.show', $e) }}'">
                                <td><x-badge :color="$eventColors[$e->type] ?? 'neutral'" dot>{{ $e->typeLabel() }}</x-badge></td>
                                <td class="text-slate-600 truncate">{{ optional($e->folder)->name ?? '—' }}</td>
                                <td class="text-right text-slate-500" data-tip="{{ optional($e->occurred_at ?? $e->created_at)->format('M j, Y g:i A') }}">{{ optional($e->occurred_at ?? $e->created_at)->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-table>
            @endif
        </x-card>
    </div>
</x-layouts.app>
