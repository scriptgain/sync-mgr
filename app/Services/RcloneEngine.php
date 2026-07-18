<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Folder;
use App\Models\SyncEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The SyncMGR sync engine. Wraps the bundled rclone binary.
 *
 * Design notes:
 *  - Credentials never touch disk: each remote is built on the fly from
 *    RCLONE_CONFIG_<NAME>_* environment variables, and passwords are obscured
 *    per-run via `rclone obscure`.
 *  - Every public operation is fail-soft: a bad endpoint or a non-zero rclone
 *    exit becomes a recorded (failed) SyncEvent, never an uncaught exception.
 *  - The rclone operation is derived from the pairing's roles (see
 *    Folder::resolveOperation): send_only+receive_only -> `rclone sync` one way;
 *    send_receive on both -> `rclone bisync` (phase-2 seam, currently guarded).
 */
class RcloneEngine
{
    /** Resolve the rclone binary: bundled bin/rclone, else a PATH fallback. */
    public function binary(): string
    {
        $bundled = (string) config('sync.rclone_binary');
        if ($bundled !== '' && is_executable($bundled)) {
            return $bundled;
        }

        return 'rclone';
    }

    public function version(): ?string
    {
        try {
            $r = Process::timeout(15)->run([$this->binary(), 'version']);

            return $r->successful() ? trim(Str::before($r->output(), "\n")) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Obscure a plaintext secret the way rclone expects it in config. */
    protected function obscure(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $r = Process::input($plain)->timeout(15)->run([$this->binary(), 'obscure', '-']);

        return trim($r->output());
    }

    /**
     * Build the RCLONE_CONFIG_<NAME>_* env vars for one endpoint.
     * Returns an env map; unsupported/agent types return an empty map.
     */
    public function remoteEnv(string $name, Device $ep): array
    {
        $p = 'RCLONE_CONFIG_' . strtoupper($name) . '_';
        $env = [];

        switch ($ep->endpoint_type) {
            case 'local':
                $env[$p . 'TYPE'] = 'local';
                break;

            case 'ftp':
                $env[$p . 'TYPE'] = 'ftp';
                $env[$p . 'HOST'] = (string) $ep->host;
                $env[$p . 'USER'] = (string) $ep->username;
                if ($ep->port) {
                    $env[$p . 'PORT'] = (string) $ep->port;
                }
                if (($s = (string) $ep->secret) !== '') {
                    $env[$p . 'PASS'] = $this->obscure($s);
                }
                if ($ep->ftp_tls) {
                    $env[$p . 'EXPLICIT_TLS'] = 'true';
                }
                break;

            case 'sftp':
                $env[$p . 'TYPE'] = 'sftp';
                $env[$p . 'HOST'] = (string) $ep->host;
                $env[$p . 'USER'] = (string) $ep->username;
                if ($ep->port) {
                    $env[$p . 'PORT'] = (string) $ep->port;
                }
                if (($s = (string) $ep->secret) !== '') {
                    $env[$p . 'PASS'] = $this->obscure($s);
                }
                if (($k = (string) $ep->private_key) !== '') {
                    $env[$p . 'KEY_PEM'] = str_replace(["\r\n", "\n"], '\n', $k); // rclone wants literal \n escapes, not real newlines
                }
                break;

            case 's3':
                $env[$p . 'TYPE'] = 's3';
                $env[$p . 'PROVIDER'] = 'Other';
                $env[$p . 'ACCESS_KEY_ID'] = (string) $ep->username;
                $env[$p . 'SECRET_ACCESS_KEY'] = (string) $ep->secret;
                if ($ep->host) {
                    $env[$p . 'ENDPOINT'] = $ep->port ? $ep->host . ':' . $ep->port : (string) $ep->host;
                }
                if ($ep->region) {
                    $env[$p . 'REGION'] = (string) $ep->region;
                }
                if ($ep->s3_path_style) {
                    $env[$p . 'FORCE_PATH_STYLE'] = 'true';
                }
                break;
        }

        return $env;
    }

    /** Build the `name:path` remote reference for an endpoint + optional subpath. */
    public function remotePath(string $name, Device $ep, ?string $sub = null): string
    {
        $segments = [];
        if ($ep->endpoint_type === 's3' && $ep->bucket) {
            $segments[] = trim((string) $ep->bucket, '/');
        }
        if (($base = rtrim((string) $ep->base_path, '/')) !== '') {
            $segments[] = $base;
        }
        if (($sub = trim((string) $sub, '/')) !== '') {
            $segments[] = $sub;
        }

        return strtolower($name) . ':' . implode('/', array_filter($segments, fn ($s) => $s !== ''));
    }

    /**
     * Lightweight reachability probe: `rclone lsd <remote>:`. Fail-soft.
     * Returns ['ok' => bool, 'output' => string].
     */
    public function testConnection(Device $ep): array
    {
        if (! $ep->isLive()) {
            return ['ok' => false, 'output' => 'Agent transport is not available yet. Use FTP, SFTP, S3 or Local endpoints for live sync.'];
        }

        try {
            $env = $this->remoteEnv('probe', $ep);
            if ($env === []) {
                return ['ok' => false, 'output' => 'This endpoint type cannot be tested.'];
            }
            $remote = $this->remotePath('probe', $ep);
            $r = Process::path(base_path())->env($env)->timeout((int) config('sync.probe_timeout', 30))
                ->run([$this->binary(), 'lsd', $remote, '--low-level-retries', '1', '--retries', '1']);

            $out = trim($r->output() . "\n" . $r->errorOutput());

            return ['ok' => $r->successful(), 'output' => $out !== '' ? Str::limit($out, 2000) : 'Reachable.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => $e->getMessage()];
        }
    }

    /**
     * Read-only file browser for a live endpoint. Runs `rclone lsjson` against
     * remotePath(base_path/subpath) and returns a normalized, sorted listing
     * (directories first, then files, each name-sorted). Fail-soft: never throws.
     *
     * $subpath is normalized and any parent (`..`) traversal above base_path is
     * rejected, so a browser can never climb out of the endpoint's Base Path.
     *
     * @return array{ok:bool, error?:string, cwd?:string, entries?:array<int,array{name:string,path:string,is_dir:bool,size:int,mod_time:?string}>}
     */
    public function listPath(Device $ep, string $subpath = ''): array
    {
        if (! $ep->isLive()) {
            return ['ok' => false, 'error' => 'This folder lives on the agent machine and cannot be browsed from the panel.'];
        }

        $sub = $this->normalizeSubpath($subpath);
        if ($sub === null) {
            return ['ok' => false, 'error' => 'That path is not allowed.'];
        }

        try {
            $env = $this->remoteEnv('browse', $ep);
            if ($env === []) {
                return ['ok' => false, 'cwd' => $sub, 'error' => 'This endpoint type cannot be browsed.'];
            }
            $remote = $this->remotePath('browse', $ep, $sub);
            $r = Process::path(base_path())->env($env)->timeout((int) config('sync.probe_timeout', 30))
                ->run([$this->binary(), 'lsjson', $remote, '--no-modtime=false', '--low-level-retries', '1', '--retries', '1']);

            if (! $r->successful()) {
                $err = trim($r->errorOutput() ?: $r->output());

                return ['ok' => false, 'cwd' => $sub, 'error' => $err !== '' ? Str::limit($err, 500) : 'Could not read that folder.'];
            }

            $rows = json_decode($r->output(), true);
            if (! is_array($rows)) {
                return ['ok' => false, 'cwd' => $sub, 'error' => 'Could not read that folder.'];
            }

            $entries = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $name = (string) ($row['Name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $isDir = (bool) ($row['IsDir'] ?? false);
                $entries[] = [
                    'name' => $name,
                    'path' => trim(($sub === '' ? '' : $sub . '/') . $name, '/'),
                    'is_dir' => $isDir,
                    'size' => $isDir ? 0 : (int) ($row['Size'] ?? 0),
                    'mod_time' => $row['ModTime'] ?? null,
                ];
            }

            // Directories first, then files; each group sorted by name (case-insensitive).
            usort($entries, function ($a, $b) {
                if ($a['is_dir'] !== $b['is_dir']) {
                    return $a['is_dir'] ? -1 : 1;
                }

                return strcasecmp($a['name'], $b['name']);
            });

            return ['ok' => true, 'cwd' => $sub, 'entries' => $entries];
        } catch (\Throwable $e) {
            return ['ok' => false, 'cwd' => $sub, 'error' => Str::limit($e->getMessage(), 300)];
        }
    }

    /**
     * Normalize a browse subpath and reject traversal above the endpoint's Base
     * Path. Collapses `.`/empty segments; returns the cleaned relative path, or
     * null if any `..` segment tries to climb above base_path.
     */
    protected function normalizeSubpath(string $subpath): ?string
    {
        $subpath = str_replace('\\', '/', trim($subpath));
        $out = [];
        foreach (explode('/', $subpath) as $seg) {
            $seg = trim($seg);
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return null; // never climb above the endpoint's Base Path
            }
            $out[] = $seg;
        }

        return implode('/', $out);
    }

    /**
     * Measure a live endpoint's path (folder or single file) via `rclone size`.
     * Used as the pre-flight guard for panel downloads. Traversal-guarded; the
     * same `..` rejection as listPath(). Fail-soft.
     *
     * @return array{ok:bool, error?:string, bytes?:int, count?:int}
     */
    public function pathSize(Device $ep, string $subpath = ''): array
    {
        if (! $ep->isLive()) {
            return ['ok' => false, 'error' => 'This folder lives on the agent machine and cannot be downloaded from the panel.'];
        }
        $sub = $this->normalizeSubpath($subpath);
        if ($sub === null) {
            return ['ok' => false, 'error' => 'That path is not allowed.'];
        }

        try {
            $env = $this->remoteEnv('dl', $ep);
            if ($env === []) {
                return ['ok' => false, 'error' => 'This endpoint type cannot be downloaded.'];
            }
            $remote = $this->remotePath('dl', $ep, $sub);
            $r = Process::path(base_path())->env($env)->timeout((int) config('sync.probe_timeout', 30))
                ->run([$this->binary(), 'size', $remote, '--json', '--low-level-retries', '1', '--retries', '1']);

            if (! $r->successful()) {
                $err = trim($r->errorOutput() ?: $r->output());

                return ['ok' => false, 'error' => $err !== '' ? Str::limit($err, 500) : 'Could not read that path.'];
            }

            $data = json_decode($r->output(), true);
            if (! is_array($data)) {
                return ['ok' => false, 'error' => 'Could not read that path.'];
            }

            return ['ok' => true, 'bytes' => (int) ($data['bytes'] ?? 0), 'count' => (int) ($data['count'] ?? 0)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => Str::limit($e->getMessage(), 300)];
        }
    }

    /**
     * Copy a live endpoint's path (folder contents or a single file) into a
     * local temp directory with `rclone copy`, so the panel can zip / stream it.
     * Traversal-guarded; fail-soft. The caller owns $destDir and its cleanup.
     *
     * @return array{ok:bool, error?:string}
     */
    public function copyToLocal(Device $ep, string $subpath, string $destDir): array
    {
        if (! $ep->isLive()) {
            return ['ok' => false, 'error' => 'This folder lives on the agent machine and cannot be downloaded from the panel.'];
        }
        $sub = $this->normalizeSubpath($subpath);
        if ($sub === null) {
            return ['ok' => false, 'error' => 'That path is not allowed.'];
        }

        try {
            $env = $this->remoteEnv('dl', $ep);
            if ($env === []) {
                return ['ok' => false, 'error' => 'This endpoint type cannot be downloaded.'];
            }
            if (! is_dir($destDir) && ! @mkdir($destDir, 0775, true) && ! is_dir($destDir)) {
                return ['ok' => false, 'error' => 'Could not create a temporary download folder.'];
            }
            $remote = $this->remotePath('dl', $ep, $sub);
            $r = Process::path(base_path())->env($env)->timeout((int) config('sync.run_timeout', 3600))->run([
                $this->binary(), 'copy', $remote, $destDir,
                '--transfers', (string) config('sync.transfers', 4),
                '--checkers', (string) config('sync.checkers', 8),
                '--low-level-retries', '1',
            ]);

            if (! $r->successful()) {
                $err = trim($r->errorOutput() ?: $r->output());

                return ['ok' => false, 'error' => $err !== '' ? Str::limit($err, 500) : 'Download failed.'];
            }

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => Str::limit($e->getMessage(), 300)];
        }
    }

    /**
     * Run a pairing and record a SyncEvent. Always returns an event; never throws.
     */
    public function runSync(Folder $folder): SyncEvent
    {
        $folder->loadMissing('mainDevice', 'peers', 'peers.groups');
        $main = $folder->mainDevice;
        $peers = $folder->peers;

        // ---- Guards (each records a failed event and returns) ---------------
        if (! $main) {
            return $this->record($folder, null, 'failed', 'error', $folder->main_mode,
                'Pairing is missing its Main endpoint.', 0, 0, 1, 0, '');
        }
        if ($peers->isEmpty()) {
            return $this->record($folder, null, 'failed', 'error', $folder->main_mode,
                'Pairing has no peers. Add at least one peer endpoint or a device group.', 0, 0, 1, 0, '');
        }

        // Drop peers that belong to a PAUSED device group: a paused group
        // contributes no peers. If every peer is paused out, this is a benign
        // skip (not an error) so the pairing keeps its schedule.
        $active = $folder->effectivePeers();
        if ($active->isEmpty()) {
            return $this->record($folder, null, 'success', 'scan', $folder->main_mode,
                'Skipped: every peer is in a paused device group. Resume a group to sync.', 0, 0, 0, 0, '');
        }
        $peers = $active;

        // Main = Send Only -> fan a one-way push out to EVERY peer.
        if ($folder->main_mode === 'send_only') {
            return $this->runFanOut($folder, $main, $peers);
        }

        // Main = Receive Only -> pull from a single peer. Multi-peer pull is
        // ambiguous (many sources into one), so require exactly one.
        if ($folder->main_mode === 'receive_only') {
            if ($peers->count() !== 1) {
                return $this->record($folder, null, 'failed', 'error', 'pull',
                    'Pull (Main Receive Only) works with exactly one peer. Use Send Only on the Main to fan out to many peers.', 0, 0, 1, 0, '');
            }
            $peer = $peers->first();
            if (! $main->isLive() || ! $peer->isLive()) {
                return $this->record($folder, $peer, 'failed', 'error', 'pull',
                    'One or both endpoints use the Agent transport, which is not available yet. Use FTP, SFTP, S3 or Local endpoints for live sync.', 0, 0, 1, 0, '');
            }

            return $this->runOneWay($folder, $peer, $main, 'pull');
        }

        // Main = Send & Receive -> two-way bisync with a single peer (phase-2 seam).
        return $this->record($folder, $peers->first(), 'failed', 'error', 'bisync',
            'Two-Way (Send & Receive) sync is coming soon. Use Send Only + Receive Only for one-way sync today.', 0, 0, 1, 0, '');
    }

    /**
     * Fan a one-way push out from the Main (Send Only) endpoint to every peer
     * (each treated as Receive Only). Each leg runs through the exact same
     * per-pair rclone path as a single-device push and records its OWN SyncEvent
     * so per-destination success/failure is visible. Returns the last peer's
     * event; the pairing headline is a rolled-up status. A single-peer pairing
     * (the proven FTP->FTP path) is just the one-leg case of this loop.
     */
    protected function runFanOut(Folder $folder, Device $main, $peers): SyncEvent
    {
        if (! $main->isLive()) {
            return $this->record($folder, null, 'failed', 'error', 'push',
                'The Main endpoint uses the Agent transport, which is not available yet. Use FTP, SFTP, S3 or Local endpoints for live sync.', 0, 0, 1, 0, '');
        }

        // Only label per-leg when there is genuinely more than one destination,
        // so single-peer messages stay clean.
        $multi = $peers->count() > 1;

        $events = [];
        foreach ($peers as $peer) {
            if ($peer->id === $main->id) {
                continue; // never sync an endpoint onto itself
            }
            $label = $multi ? $peer->name : null;
            if (! $peer->isLive()) {
                $events[] = $this->record($folder, $peer, 'failed', 'error', 'push',
                    ($multi ? "[{$peer->name}] " : '')."Skipped: Agent transport is not available yet.", 0, 0, 1, 0, '');
                continue;
            }
            $events[] = $this->runOneWay($folder, $main, $peer, 'push', $label);
        }

        if ($events === []) {
            return $this->record($folder, null, 'failed', 'error', 'push',
                'No eligible peer endpoints to sync.', 0, 0, 1, 0, '');
        }

        // Roll the per-member outcomes up into the pairing's headline status.
        $statuses = array_map(fn ($e) => $e->status, $events);
        $ok = count(array_filter($statuses, fn ($s) => $s === 'success'));
        $summary = in_array('failed', $statuses, true)
            ? ($ok > 0 || in_array('partial', $statuses, true) ? 'partial' : 'failed')
            : (in_array('partial', $statuses, true) ? 'partial' : 'success');

        $folder->forceFill([
            'last_run_at' => Carbon::now(),
            'last_status' => $summary,
            'status' => $summary === 'failed' ? 'error' : 'idle',
            'next_run_at' => $this->nextRunAt($folder),
        ])->save();

        if (count($events) > 1) {
            AuditLog::record('sync', "Fan-out \"{$folder->name}\" {$summary}: {$ok}/".count($events).' peer(s) synced', $folder);
        }

        return end($events);
    }

    /**
     * When should this pairing next run automatically? Honors schedule_mode:
     * manual never; scheduled/onchange after interval_minutes; onchange with a
     * zero gap is eligible again on the very next dispatcher tick (null = due).
     */
    protected function nextRunAt(Folder $folder): ?Carbon
    {
        if (! $folder->enabled || $folder->schedule_mode === 'manual') {
            return null;
        }
        if ($folder->interval_minutes > 0) {
            return Carbon::now()->addMinutes($folder->interval_minutes);
        }

        return null;
    }

    /**
     * Execute one one-way rclone mirror (from -> to) and record its SyncEvent.
     * Shared by single-device pairings and by each leg of a group fan-out. An
     * optional $peerLabel prefixes the recorded message (used for fan-out legs).
     */
    protected function runOneWay(Folder $folder, Device $from, Device $to, string $op, ?string $peerLabel = null): SyncEvent
    {
        $prefix = $peerLabel ? "[{$peerLabel}] " : '';

        try {
            $env = array_merge($this->remoteEnv('src', $from), $this->remoteEnv('dst', $to));
            $srcPath = $this->remotePath('src', $from, $folder->subpath);
            $dstPath = $this->remotePath('dst', $to, $folder->subpath);

            $started = microtime(true);
            $r = Process::path(base_path())->env($env)->timeout((int) config('sync.run_timeout', 3600))->run([
                $this->binary(), 'sync', $srcPath, $dstPath,
                '--use-json-log', '--stats', '1s', '--stats-log-level', 'NOTICE',
                '--transfers', (string) config('sync.transfers', 4),
                '--checkers', (string) config('sync.checkers', 8),
                '-v',
            ]);
            $wallMs = (int) round((microtime(true) - $started) * 1000);

            $log = $r->output() . "\n" . $r->errorOutput();
            $stats = $this->parseStats($log);
            $exit = $r->exitCode();

            if ($exit === 0) {
                $status = 'success';
                $type = 'completed';
            } elseif (($stats['transfers'] ?? 0) > 0) {
                $status = 'partial';
                $type = 'conflict';
            } else {
                $status = 'failed';
                $type = 'error';
            }

            $durationMs = $stats['elapsed'] > 0 ? (int) round($stats['elapsed'] * 1000) : $wallMs;
            $files = (int) ($stats['transfers'] ?? 0);
            $bytes = (int) ($stats['bytes'] ?? 0);
            $errors = (int) ($stats['errors'] ?? 0);

            $message = $status === 'success'
                ? $prefix . "Synced {$files} file(s), " . \App\Support\Bytes::human($bytes) . '.'
                : ($status === 'partial'
                    ? $prefix . "Completed with {$errors} error(s); {$files} file(s) transferred."
                    : $prefix . 'Sync failed. ' . Str::limit(trim(Str::afterLast(trim($log), "\n")), 200));

            return $this->record($folder, $to, $status, $type, $op, $message, $files, $bytes, $errors, $durationMs, $log);
        } catch (\Throwable $e) {
            return $this->record($folder, $to, 'failed', 'error', $op,
                $prefix . 'Sync error: ' . Str::limit($e->getMessage(), 300), 0, 0, 1, 0, (string) $e);
        }
    }

    /** Extract the last rclone JSON stats object from a run log. */
    protected function parseStats(string $log): array
    {
        $out = ['transfers' => 0, 'bytes' => 0, 'errors' => 0, 'elapsed' => 0.0];

        foreach (preg_split('/\r?\n/', $log) as $line) {
            if (! str_contains($line, '"stats"')) {
                continue;
            }
            $data = json_decode($line, true);
            if (! is_array($data) || ! isset($data['stats']) || ! is_array($data['stats'])) {
                continue;
            }
            $s = $data['stats'];
            $out = [
                'transfers' => (int) ($s['transfers'] ?? $out['transfers']),
                'bytes' => (int) ($s['bytes'] ?? $out['bytes']),
                'errors' => (int) ($s['errors'] ?? $out['errors']),
                'elapsed' => (float) ($s['elapsedTime'] ?? $out['elapsed']),
            ];
        }

        return $out;
    }

    /**
     * Record a run an AGENT executed and reported back, then roll the pairing's
     * schedule/status forward exactly as record() does for a server-side run. The
     * master never ran rclone here — the agent did, locally against the remote —
     * so we trust its metrics and just persist the SyncEvent + folder headline.
     *
     * @param  array<string,mixed>  $data  the validated report payload.
     */
    public function recordReportedRun(Folder $folder, Device $agent, array $data): SyncEvent
    {
        $status = $data['status'];
        $op = $data['operation'] ?? null;
        $files = (int) ($data['files_transferred'] ?? 0);
        $bytes = (int) ($data['bytes_transferred'] ?? 0);
        $errors = (int) ($data['errors'] ?? 0);
        $type = $data['type'] ?? match ($status) {
            'success' => 'completed',
            'partial' => 'conflict',
            'running' => 'scan',
            default => 'error',
        };
        $message = $data['message'] ?? match ($status) {
            'success' => "Agent synced {$files} file(s), " . \App\Support\Bytes::human($bytes) . '.',
            'partial' => "Agent completed with {$errors} error(s); {$files} file(s) transferred.",
            'running' => 'Agent sync in progress.',
            default => 'Agent sync failed.',
        };

        return $this->record(
            $folder,
            $agent,
            $status,
            $type,
            $op ?: 'push',
            $message,
            $files,
            $bytes,
            $errors,
            (int) ($data['duration_ms'] ?? 0),
            (string) ($data['log_tail'] ?? ''),
        );
    }

    /** Persist a SyncEvent, advance the pairing's schedule, and audit it. */
    protected function record(
        Folder $folder,
        ?Device $device,
        string $status,
        string $type,
        string $op,
        string $message,
        int $files,
        int $bytes,
        int $errors,
        int $durationMs,
        string $log,
    ): SyncEvent {
        // A no-op run (success, nothing transferred, no error/conflict) just
        // advances the "last checked" time; we do NOT persist an event row for
        // it, to keep the activity feed and Recent Runs free of empty entries.
        $noop = $status === 'success' && $files === 0 && $bytes === 0 && $errors === 0
            && in_array($type, ['completed', 'scan'], true);

        $event = new SyncEvent([
            'folder_id' => $folder->id,
            'device_id' => $device?->id,
            'type' => $type,
            'status' => $status,
            'operation' => $op === 'invalid' ? null : $op,
            'message' => Str::limit($message, 250),
            'files_transferred' => $files,
            'bytes_transferred' => $bytes,
            'errors' => $errors,
            'duration_ms' => $durationMs,
            'log_tail' => $log === '' ? null : Str::limit(implode("\n", array_slice(preg_split('/\r?\n/', trim($log)), -60)), 8000),
            'occurred_at' => Carbon::now(),
        ]);
        if (! $noop) {
            $event->save();
        }

        $folder->forceFill([
            'last_run_at' => Carbon::now(),
            'last_status' => $status,
            'status' => $status === 'success' ? 'idle' : ($status === 'partial' ? 'idle' : 'error'),
            'next_run_at' => $this->nextRunAt($folder),
        ])->save();

        // Refresh the folder's tracked size from a server-reachable endpoint on a
        // real successful run (agent folders size their remote peer/main).
        if (! $noop && $status === 'success') {
            $folder->loadMissing('mainDevice', 'peers');
            $sizeEp = ($folder->mainDevice && $folder->mainDevice->isLive())
                ? $folder->mainDevice
                : $folder->peers->first(fn ($p) => $p->isLive());
            if ($sizeEp) {
                $sz = $this->pathSize($sizeEp, (string) ($folder->subpath ?? ''));
                if ($sz['ok'] ?? false) {
                    $folder->forceFill(['size_bytes' => (int) $sz['bytes'], 'file_count' => (int) ($sz['count'] ?? 0)])->save();
                }
            }
        }

        if (! $noop) {
            AuditLog::record('sync', "Sync \"{$folder->name}\" {$status}: {$message}", $folder);
        }

        return $event;
    }
}
