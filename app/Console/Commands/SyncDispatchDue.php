<?php

namespace App\Console\Commands;

use App\Jobs\RunSyncJob;
use App\Models\Folder;
use Illuminate\Console\Command;

/**
 * Scheduler entry point: find enabled pairings whose interval has elapsed and
 * queue a RunSyncJob for each. The queue is drained by the scheduled
 * `queue:work --stop-when-empty` tick (see routes/console.php).
 */
class SyncDispatchDue extends Command
{
    protected $signature = 'sync:dispatch-due';

    protected $description = 'Dispatch sync jobs for every enabled pairing that is due.';

    public function handle(): int
    {
        $due = Folder::query()
            ->where('enabled', true)
            ->where('interval_minutes', '>', 0)
            ->whereNotNull('main_device_id')
            ->whereNotNull('peer_device_id')
            ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->get();

        foreach ($due as $folder) {
            // Push next_run_at forward immediately so overlapping ticks don't
            // double-dispatch while the job sits in the queue.
            $folder->forceFill(['next_run_at' => now()->addMinutes($folder->interval_minutes)])->save();
            RunSyncJob::dispatch($folder->id);
        }

        $this->info("Dispatched {$due->count()} due pairing(s).");

        return self::SUCCESS;
    }
}
