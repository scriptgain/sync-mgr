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

        $stats = [
            'folders' => Folder::visibleTo($user)->count(),
            'devices' => Device::visibleTo($user)->count(),
            'connected' => Device::visibleTo($user)->where('status', 'connected')->count(),
            'storage' => Bytes::human((int) Folder::visibleTo($user)->sum('size_bytes')),
        ];

        $events24h = SyncEvent::visibleTo($user)
            ->where(function ($q) {
                $q->where('occurred_at', '>=', now()->subDay())
                    ->orWhere(function ($q2) {
                        $q2->whereNull('occurred_at')->where('created_at', '>=', now()->subDay());
                    });
            })->count();

        $attention = Folder::visibleTo($user)->whereIn('status', ['error', 'syncing'])->count();

        $recentFolders = Folder::visibleTo($user)->with('owner:id,name')->latest()->limit(6)->get();
        $recentEvents = SyncEvent::visibleTo($user)->with(['folder:id,name', 'device:id,name'])
            ->latest('occurred_at')->latest('id')->limit(8)->get();

        return view('dashboard', compact('stats', 'events24h', 'attention', 'recentFolders', 'recentEvents'));
    }
}
