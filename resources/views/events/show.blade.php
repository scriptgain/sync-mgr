<x-layouts.app :title="'Event · ' . $event->statusLabel()">
    <x-page-header :title="'Sync Run'" icon="clock"
        :subtitle="$event->message"
        :back="['href' => route('events.index'), 'label' => 'Events']" />

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <x-card title="Run Log">
                @if ($event->log_tail)
                    <pre class="overflow-x-auto rounded-lg bg-slate-900 text-slate-100 text-xs p-4 leading-relaxed whitespace-pre">{{ $event->log_tail }}</pre>
                @else
                    <p class="text-sm text-slate-500">No log output was captured for this run.</p>
                @endif
            </x-card>
        </div>
        <div class="space-y-6">
            <x-card title="Details">
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-slate-500">Status</dt><dd><x-badge :color="$event->statusColor()" dot>{{ $event->statusLabel() }}</x-badge></dd></div>
                    <div><dt class="text-slate-500">Operation</dt><dd class="text-slate-900">{{ $event->operation ? ucfirst($event->operation) : '—' }}</dd></div>
                    <div><dt class="text-slate-500">Files Transferred</dt><dd class="text-slate-900 tabular">{{ number_format($event->files_transferred) }}</dd></div>
                    <div><dt class="text-slate-500">Bytes</dt><dd class="text-slate-900 tabular">{{ \App\Support\Bytes::human($event->bytes_transferred) }}</dd></div>
                    <div><dt class="text-slate-500">Errors</dt><dd class="text-slate-900 tabular">{{ number_format($event->errors) }}</dd></div>
                    <div><dt class="text-slate-500">Duration</dt><dd class="text-slate-900 tabular">{{ $event->durationLabel() }}</dd></div>
                    <div><dt class="text-slate-500">Pairing</dt><dd class="text-slate-900">
                        @if ($event->folder)<a href="{{ route('folders.show', $event->folder) }}" class="text-brand-700 hover:underline">{{ $event->folder->name }}</a>@else — @endif
                    </dd></div>
                    <div><dt class="text-slate-500">Endpoint</dt><dd class="text-slate-900">
                        @if ($event->device)<a href="{{ route('devices.show', $event->device) }}" class="text-brand-700 hover:underline">{{ $event->device->name }}</a>@else — @endif
                    </dd></div>
                    <div><dt class="text-slate-500">Occurred</dt><dd class="text-slate-900">{{ optional($event->occurred_at ?? $event->created_at)->format('M j, Y g:i A') ?? '—' }}</dd></div>
                </dl>
            </x-card>
        </div>
    </div>
</x-layouts.app>
