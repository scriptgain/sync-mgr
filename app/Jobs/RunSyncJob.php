<?php

namespace App\Jobs;

use App\Models\Folder;
use App\Services\RcloneEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Runs one sync pairing through the rclone engine and records a SyncEvent.
 * Dispatched by the "Sync Now" button and by the scheduler (sync:dispatch-due).
 */
class RunSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3900;
    public int $tries = 1;

    public function __construct(public int $folderId)
    {
    }

    /**
     * Never let two runs of the same pairing overlap. Critical for On-Change
     * (continuous) pairings: a slow run must not stack behind the per-tick
     * dispatcher. A queued duplicate is dropped rather than released.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-folder-'.$this->folderId))->dontRelease()];
    }

    public function handle(RcloneEngine $engine): void
    {
        $folder = Folder::find($this->folderId);
        if ($folder) {
            $engine->runSync($folder);
        }
    }
}
