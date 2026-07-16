@php
    $eventColors = ['scan' => 'neutral', 'index' => 'info', 'conflict' => 'warn', 'completed' => 'success', 'error' => 'danger'];
@endphp
<x-layouts.app title="Events">
    <x-page-header title="Events" icon="clock" subtitle="Scans, index updates, conflicts, and completions across your folders." />

    @if ($folders->isNotEmpty())
        <x-card class="mb-6">
            <form method="GET" action="{{ route('events.index') }}" class="flex flex-wrap items-end gap-3">
                <div class="min-w-[16rem]">
                    <x-field label="Filter By Folder" for="folder_id">
                        <x-select id="folder_id" name="folder_id" onchange="this.form.submit()">
                            <option value="">All Folders</option>
                            @foreach ($folders as $f)
                                <option value="{{ $f->id }}" @selected($folderId === $f->id)>{{ $f->name }}</option>
                            @endforeach
                        </x-select>
                    </x-field>
                </div>
                <div class="flex items-center gap-2">
                    <x-button type="submit" variant="secondary" size="sm">Apply</x-button>
                    @if ($folderId)<x-button href="{{ route('events.index') }}" variant="ghost" size="sm">Clear</x-button>@endif
                </div>
            </form>
        </x-card>
    @endif

    @if ($events->isEmpty())
        <x-card>
            <x-empty-state icon="clock" title="No Events Yet" description="Sync activity will appear here as your folders scan and sync." />
        </x-card>
    @else
        <x-table>
            <thead>
                <tr><th>Type</th><th>Folder</th><th>Device</th><th>Message</th><th>When</th></tr>
            </thead>
            <tbody>
                @foreach ($events as $e)
                    <tr>
                        <td><x-badge :color="$eventColors[$e->type] ?? 'neutral'">{{ $e->typeLabel() }}</x-badge></td>
                        <td class="font-medium text-slate-900">
                            @if ($e->folder)<a href="{{ route('folders.show', $e->folder) }}" class="hover:text-brand-700">{{ $e->folder->name }}</a>@else<span class="text-slate-400">—</span>@endif
                        </td>
                        <td class="text-slate-500">{{ $e->device?->name ?? '—' }}</td>
                        <td class="text-slate-600">{{ \Illuminate\Support\Str::limit($e->message, 80) ?: '—' }}</td>
                        <td class="text-slate-500">
                            <a href="{{ route('events.show', $e) }}" class="hover:text-brand-700">{{ optional($e->occurred_at ?? $e->created_at)->diffForHumans() ?? '—' }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-table>
        <div class="mt-4">{{ $events->links() }}</div>
    @endif
</x-layouts.app>
