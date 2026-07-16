@php
    $eventColors = ['scan' => 'neutral', 'index' => 'info', 'conflict' => 'warn', 'completed' => 'success', 'error' => 'danger'];
@endphp
<x-layouts.app :title="'Event · ' . $event->typeLabel()">
    <x-page-header :title="$event->typeLabel() . ' Event'" icon="clock"
        :back="['href' => route('events.index'), 'label' => 'Events']" />

    <x-card title="Details">
        <dl class="space-y-3 text-sm">
            <div><dt class="text-slate-500">Type</dt><dd><x-badge :color="$eventColors[$event->type] ?? 'neutral'">{{ $event->typeLabel() }}</x-badge></dd></div>
            <div><dt class="text-slate-500">Folder</dt><dd class="text-slate-900">
                @if ($event->folder)<a href="{{ route('folders.show', $event->folder) }}" class="text-brand-700 hover:underline">{{ $event->folder->name }}</a>@else — @endif
            </dd></div>
            <div><dt class="text-slate-500">Device</dt><dd class="text-slate-900">
                @if ($event->device)<a href="{{ route('devices.show', $event->device) }}" class="text-brand-700 hover:underline">{{ $event->device->name }}</a>@else — @endif
            </dd></div>
            <div><dt class="text-slate-500">Message</dt><dd class="text-slate-900 whitespace-pre-line">{{ $event->message ?: '—' }}</dd></div>
            <div><dt class="text-slate-500">Occurred</dt><dd class="text-slate-900">{{ optional($event->occurred_at ?? $event->created_at)->format('M j, Y g:i A') ?? '—' }}</dd></div>
        </dl>
    </x-card>
</x-layouts.app>
