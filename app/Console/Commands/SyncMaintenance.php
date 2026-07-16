<?php

namespace App\Console\Commands;

use App\Http\Controllers\MaintenanceController;
use App\Models\AuditLog;
use Illuminate\Console\Command;

class SyncMaintenance extends Command
{
    protected $signature = 'sync:maintenance {--force : Ignore the configured maintenance window}';

    protected $description = 'Prune old sync events, mark stale devices offline, and prune old audit rows.';

    public function handle(): int
    {
        if (! $this->option('force') && ! MaintenanceController::allowedNow()) {
            $this->info('Outside the maintenance window; skipping. Use --force to override.');

            return self::SUCCESS;
        }

        $c = MaintenanceController::runSweep();

        $this->info("Maintenance: {$c['events_pruned']} event(s) pruned, {$c['devices_marked']} device(s) marked offline, {$c['audit_pruned']} audit row(s) pruned.");
        AuditLog::record('maintenance', "Scheduled maintenance: {$c['events_pruned']} events pruned, {$c['devices_marked']} devices marked offline, {$c['audit_pruned']} audit rows pruned");

        return self::SUCCESS;
    }
}
