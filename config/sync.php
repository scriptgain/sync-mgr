<?php

return [

    /*
    |--------------------------------------------------------------------------
    | rclone engine binary
    |--------------------------------------------------------------------------
    |
    | SyncMGR shells out to rclone to move files between endpoints. The static
    | binary is bundled at bin/rclone (fetched by deploy/local/fetch-rclone.sh,
    | the same pattern BackupMGR uses for kopia). If the bundled binary is not
    | present/executable the engine falls back to a "rclone" on the PATH.
    |
    */
    'rclone_binary' => env('RCLONE_BINARY', base_path('bin/rclone')),

    // Hard ceiling on a single sync run (seconds) before rclone is killed.
    'run_timeout' => (int) env('SYNC_RUN_TIMEOUT', 3600),

    // Timeout for the lightweight test-connection probe (seconds).
    'probe_timeout' => (int) env('SYNC_PROBE_TIMEOUT', 30),

    // rclone concurrency knobs.
    'transfers' => (int) env('SYNC_TRANSFERS', 4),
    'checkers' => (int) env('SYNC_CHECKERS', 8),

    // Panel "download as zip": a folder/file larger than this (bytes) or with
    // more than this many files is refused rather than staged + zipped, so a
    // huge tree can't exhaust disk or wedge a web worker. ~2 GB default.
    'download_max_bytes' => (int) env('SYNC_DOWNLOAD_MAX_BYTES', 2 * 1024 * 1024 * 1024),
    'download_max_files' => (int) env('SYNC_DOWNLOAD_MAX_FILES', 5000),

];
