<?php

namespace App\Console\Commands;

use App\Jobs\RunSyncJob;
use App\Models\Folder;
use Illuminate\Console\Command;

/**
 * Scheduler entry point: find enabled pairings that are due and queue a
 * RunSyncJob for each. The queue is drained by the scheduled
 * `queue:work --stop-when-empty` tick (see routes/console.php).
 *
 * Two automatic modes are dispatched here:
 *   - scheduled : due once every interval_minutes.
 *   - onchange  : eligible on (nearly) every tick, throttled by interval_minutes
 *                 as a minimum poll gap. This is the agentless, continuous
 *                 best-effort path: rclone sync is a cheap no-op when nothing
 *                 changed. TRUE event-driven instant sync (inotify) is the
 *                 future Agent transport's job, not this poller. RunSyncJob's
 *                 WithoutOverlapping guard stops a slow run from stacking.
 */
class SyncDispatchDue extends Command
{
    protected $signature = 'sync:dispatch-due';

    protected $description = 'Dispatch sync jobs for every enabled pairing that is due.';

    public function handle(): int
    {
        $due = Folder::query()
            ->where('enabled', true)
            ->whereIn('schedule_mode', ['scheduled', 'onchange'])
            // Scheduled needs a positive interval; onchange may poll every tick (gap 0).
            ->where(fn ($q) => $q->where('schedule_mode', 'onchange')
                ->orWhere(fn ($s) => $s->where('schedule_mode', 'scheduled')->where('interval_minutes', '>', 0)))
            ->whereNotNull('main_device_id')
            ->whereHas('peers')
            // Agent-managed pairings self-schedule (the installed agent watches +
            // polls); the server must not run rclone against them. Skip any pairing
            // whose Main or a peer is an agent-type endpoint.
            ->whereDoesntHave('mainDevice', fn ($d) => $d->where('endpoint_type', 'agent'))
            ->whereDoesntHave('peers', fn ($p) => $p->where('endpoint_type', 'agent'))
            ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->get();

        foreach ($due as $folder) {
            // Push next_run_at forward immediately so overlapping ticks don't
            // double-dispatch while the job sits in the queue. onchange with a
            // zero gap stays eligible on the very next tick.
            $folder->forceFill([
                'next_run_at' => $folder->interval_minutes > 0
                    ? now()->addMinutes($folder->interval_minutes)
                    : now(),
            ])->save();
            RunSyncJob::dispatch($folder->id);
        }

        $this->info("Dispatched {$due->count()} due pairing(s).");

        return self::SUCCESS;
    }
}
