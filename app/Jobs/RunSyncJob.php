<?php

namespace App\Jobs;

use App\Models\Folder;
use App\Services\RcloneEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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

    public function handle(RcloneEngine $engine): void
    {
        $folder = Folder::find($this->folderId);
        if ($folder) {
            $engine->runSync($folder);
        }
    }
}
