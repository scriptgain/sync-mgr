<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Folder;
use App\Models\SyncEvent;
use App\Support\Bytes;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();

        $folders = Folder::visibleTo($user)->count();
        $devices = Device::visibleTo($user)->count();
        $connected = Device::visibleTo($user)->where('status', 'connected')->count();
        $fileCount = (int) Folder::visibleTo($user)->sum('file_count');
        $sizeBytes = (int) Folder::visibleTo($user)->sum('size_bytes');

        $stats = [
            'folders' => $folders,
            'devices' => $devices,
            'connected' => $connected,
            'storage' => Bytes::human($sizeBytes),
        ];

        $events24h = SyncEvent::visibleTo($user)
            ->where(function ($q) {
                $q->where('occurred_at', '>=', now()->subDay())
                    ->orWhere(function ($q2) {
                        $q2->whereNull('occurred_at')->where('created_at', '>=', now()->subDay());
                    });
            })->count();

        $failures = SyncEvent::visibleTo($user)->where('type', 'error')
            ->where(function ($q) {
                $q->where('occurred_at', '>=', now()->subDays(7))
                    ->orWhere(fn ($q2) => $q2->whereNull('occurred_at')->where('created_at', '>=', now()->subDays(7)));
            })->count();

        $attention = Folder::visibleTo($user)->whereIn('status', ['error', 'syncing'])->count();
        $errorFolders = Folder::visibleTo($user)->where('status', 'error')->count();
        $syncingFolders = Folder::visibleTo($user)->where('status', 'syncing')->count();

        $recentFolders = Folder::visibleTo($user)->with('owner:id,name')->latest()->limit(6)->get();
        $recentEvents = SyncEvent::visibleTo($user)->with(['folder:id,name', 'device:id,name'])
            ->latest('occurred_at')->latest('id')->limit(8)->get();

        // 14-day sync activity. One portable query, bucketed per day in PHP.
        $since = now()->subDays(13)->startOfDay();
        $recent = SyncEvent::visibleTo($user)
            ->where(function ($q) use ($since) {
                $q->where('occurred_at', '>=', $since)
                    ->orWhere(fn ($q2) => $q2->whereNull('occurred_at')->where('created_at', '>=', $since));
            })
            ->get(['type', 'occurred_at', 'created_at']);

        $activity = collect(range(0, 13))->map(function ($i) use ($recent) {
            $day = now()->subDays(13 - $i)->startOfDay();
            $next = $day->copy()->addDay();
            $onDay = $recent->filter(function ($e) use ($day, $next) {
                $at = $e->occurred_at ?? $e->created_at;
                return $at && $at >= $day && $at < $next;
            });

            return [
                'label' => $day->format('M j'),
                'total' => $onDay->count(),
                'done' => $onDay->where('type', 'completed')->count(),
                'issues' => $onDay->whereIn('type', ['error', 'conflict'])->count(),
            ];
        })->all();

        $windowTotal = (int) array_sum(array_column($activity, 'total'));

        return view('dashboard', compact(
            'stats', 'events24h', 'attention', 'recentFolders', 'recentEvents',
            'failures', 'fileCount', 'errorFolders', 'syncingFolders',
            'activity', 'windowTotal',
        ));
    }
}
