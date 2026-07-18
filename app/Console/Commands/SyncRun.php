<?php

namespace App\Console\Commands;

use App\Models\Folder;
use App\Services\RcloneEngine;
use Illuminate\Console\Command;

/**
 * Run a single sync pairing inline (synchronously) and report the result.
 * Useful for manual runs, cron one-offs, and proving the engine end-to-end.
 */
class SyncRun extends Command
{
    protected $signature = 'sync:run {folder : Folder (pairing) id or folder_id slug}';

    protected $description = 'Run one sync pairing now and record a SyncEvent.';

    public function handle(RcloneEngine $engine): int
    {
        $key = (string) $this->argument('folder');
        $folder = Folder::where('id', $key)->orWhere('folder_id', $key)->first();

        if (! $folder) {
            $this->error("No pairing found for \"{$key}\".");

            return self::FAILURE;
        }

        $this->info("Running pairing \"{$folder->name}\" ({$folder->flowLabel()})...");
        $event = $engine->runSync($folder);

        $this->line("Status:   {$event->statusLabel()}");
        $this->line("Files:    {$event->files_transferred}");
        $this->line('Bytes:    ' . \App\Support\Bytes::human($event->bytes_transferred));
        $this->line("Errors:   {$event->errors}");
        $this->line("Duration: {$event->durationLabel()}");
        $this->line("Message:  {$event->message}");

        return $event->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
