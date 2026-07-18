<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOwners;
use App\Models\AuditLog;
use App\Models\Device;
use App\Services\RcloneEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    use ManagesOwners;

    public function index()
    {
        $user = auth()->user();
        $devices = Device::visibleTo($user)->with(['owner:id,name', 'groups:id,name'])->withCount('folders')->latest()->paginate(25)->withQueryString();

        $stats = [
            'total' => Device::visibleTo($user)->count(),
            'connected' => Device::visibleTo($user)->where('status', 'connected')->count(),
            'local' => Device::visibleTo($user)->where('is_local', true)->count(),
        ];

        // Groups present on this page, for the client-side group filter chips.
        $groupFilters = $devices->pluck('groups')->flatten()->unique('id')->sortBy('name')->values();

        return view('devices.index', compact('devices', 'stats', 'groupFilters'));
    }

    public function create()
    {
        return view('devices.create', ['owners' => $this->assignableOwners()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);
        unset($data['owner_id']);

        // Never write an empty secret over nothing; drop blanks on create.
        foreach (['secret', 'private_key'] as $s) {
            if (($data[$s] ?? '') === '') {
                unset($data[$s]);
            }
        }

        if (empty($data['device_id'])) {
            $data['device_id'] = Device::generateDeviceId();
        }

        $device = Device::create($data);
        $this->assignFromRequest($device, $request);
        AuditLog::record('created', "Endpoint \"{$device->name}\" created", $device);

        // Agent endpoints pair with a one-time enrollment token. Mint it now and
        // surface the PLAINTEXT once (only its hash is stored) so the operator can
        // paste it into the agent's install command.
        if ($device->isAgent()) {
            $plain = $device->issueEnrollmentToken();

            return redirect()->route('devices.show', $device)
                ->with('status', "Agent \"{$device->name}\" created. Copy the enrollment code below now.")
                ->with('enrollment_token_plain', $plain);
        }

        return redirect()->route('devices.show', $device)->with('status', "Endpoint \"{$device->name}\" created.");
    }

    public function show(Device $device)
    {
        $this->guard($device);
        // Keep a ready-to-use enrollment code baked into the install command
        // for an unenrolled agent (so the operator never pastes a placeholder).
        if ($device->isAgent() && ! $device->isEnrolled() && blank($device->enrollment_plain)) {
            $device->issueEnrollmentToken();
        }
        $device->load([
            'owner:id,name',
            'groups' => fn ($q) => $q->orderBy('name'),
            'mainPairings:id,name,status,size_bytes,main_device_id',
            'peerFolders',
        ]);

        // Recent runs that touched this endpoint, for the Activity tab.
        $events = $device->syncEvents()->with('folder:id,name')->latest('occurred_at')->latest('id')->limit(20)->get();

        return view('devices.show', compact('device', 'events'));
    }

    public function edit(Device $device)
    {
        $this->guard($device);

        return view('devices.edit', ['device' => $device, 'owners' => $this->assignableOwners()]);
    }

    public function update(Request $request, Device $device)
    {
        $this->guard($device);
        $data = $this->validated($request, $device);
        if (auth()->user()->isAdmin()) {
            $data['user_id'] = $data['owner_id'] ?? null;
        }
        unset($data['owner_id']);

        // Blank secret fields on edit mean "keep the stored value".
        foreach (['secret', 'private_key'] as $s) {
            if (($data[$s] ?? '') === '') {
                unset($data[$s]);
            }
        }

        if (empty($data['device_id'])) {
            $data['device_id'] = $device->device_id ?: Device::generateDeviceId();
        }

        $device->update($data);
        $this->assignFromRequest($device, $request);
        AuditLog::record('updated', "Endpoint \"{$device->name}\" updated", $device);

        return redirect()->route('devices.show', $device)->with('status', 'Endpoint updated.');
    }

    public function destroy(Device $device)
    {
        $this->guard($device);
        $name = $device->name;
        $device->delete();
        AuditLog::record('deleted', "Endpoint \"{$name}\" deleted");

        return redirect()->route('devices.index')->with('status', "Endpoint \"{$name}\" deleted.");
    }

    /**
     * Re-arm enrollment for an agent endpoint: mint a fresh one-time token and
     * show its PLAINTEXT once. Invalidates any previous unused token. Used to
     * re-pair an agent (e.g. reinstall) — enrolling burns the token again.
     */
    public function reissueToken(Device $device)
    {
        $this->guard($device);
        abort_unless($device->isAgent(), 404);

        $plain = $device->issueEnrollmentToken();
        AuditLog::record('updated', "Enrollment code re-issued for agent \"{$device->name}\"", $device);

        return redirect()->route('devices.show', $device)
            ->with('status', 'New enrollment code generated. Copy it below now.')
            ->with('enrollment_token_plain', $plain);
    }

    /** Run a fail-soft rclone reachability probe against this endpoint. */
    public function test(Device $device, RcloneEngine $engine)
    {
        $this->guard($device);
        $result = $engine->testConnection($device);

        AuditLog::record('sync', "Tested endpoint \"{$device->name}\": " . ($result['ok'] ? 'reachable' : 'unreachable'), $device);

        if ($result['ok']) {
            return back()->with('status', "Endpoint \"{$device->name}\" is reachable.");
        }

        return back()->with('warning', "Endpoint \"{$device->name}\" is unreachable. " . Str::limit($result['output'], 400));
    }

    /**
     * Read-only JSON file browser for a live endpoint: lists the contents of the
     * endpoint's Base Path, plus an optional ?path= subfolder, via rclone lsjson.
     * Owner-guarded like show(). Agent endpoints are not reachable from the panel
     * (their files live on the remote machine), so they return a friendly note.
     */
    public function browse(Request $request, Device $device, RcloneEngine $engine)
    {
        $this->guard($device);

        if (! $device->isLive()) {
            return response()->json([
                'ok' => false,
                'cwd' => '',
                'entries' => [],
                'error' => 'This folder lives on the agent machine and cannot be browsed from the panel.',
            ]);
        }

        $result = $engine->listPath($device, (string) $request->query('path', ''));

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'cwd' => $result['cwd'] ?? '',
            'entries' => $result['entries'] ?? [],
            'error' => $result['error'] ?? null,
        ]);
    }

    /**
     * Download a live endpoint's path from the panel. With ?file=1 the ?path=
     * subpath is treated as a single file and streamed directly; otherwise the
     * folder at ?path= (default = Base Path root) is staged and zipped.
     *
     * Owner-guarded like browse(). Agent endpoints are refused (their files live
     * on the remote machine). A size pre-flight caps huge trees, and the temp
     * staging dir is always cleaned up — after send and on any failure.
     */
    public function downloadZip(Request $request, Device $device, RcloneEngine $engine)
    {
        $this->guard($device);

        if (! $device->isLive()) {
            abort(403, 'This folder lives on the agent machine and cannot be downloaded from the panel.');
        }

        $path = (string) $request->query('path', '');
        $single = $request->boolean('file');

        // --- Size guard FIRST: never stage/zip an unreasonably large path. ---
        $size = $engine->pathSize($device, $path);
        if (! ($size['ok'] ?? false)) {
            return back()->with('warning', 'Could not prepare that download. ' . ($size['error'] ?? ''));
        }
        $capBytes = (int) config('sync.download_max_bytes', 2 * 1024 * 1024 * 1024);
        $capFiles = (int) config('sync.download_max_files', 5000);
        if (($size['bytes'] ?? 0) > $capBytes || ($size['count'] ?? 0) > $capFiles) {
            AuditLog::record('sync', "Download capped for endpoint \"{$device->name}\": " . \App\Support\Bytes::human($size['bytes']) . " across {$size['count']} file(s) exceeds the panel limit of " . \App\Support\Bytes::human($capBytes) . '.', $device);

            return back()->with('warning', 'That selection is too large to download from the panel (' . \App\Support\Bytes::human($size['bytes']) . ' across ' . number_format($size['count']) . ' file(s)). The limit is ' . \App\Support\Bytes::human($capBytes) . '. Sync it to another endpoint instead.');
        }

        // Staging dir for the rclone copy. It is removed SYNCHRONOUSLY the moment
        // the downloadable artifact (zip / single file) has been produced, and on
        // every failure path. A plain-PHP shutdown backstop covers an unexpected
        // abort (it does not lean on the Laravel facades, which may be torn down
        // by shutdown time). The one artifact left in tmp/ is streamed directly
        // and removed by deleteFileAfterSend once the response is sent.
        $rand = Str::random(20);
        $tmpDir = storage_path('app/tmp');
        $stage = $tmpDir . '/stg-' . $rand;
        register_shutdown_function(fn () => self::rmrf($stage));
        \Illuminate\Support\Facades\File::ensureDirectoryExists($stage);

        $copy = $engine->copyToLocal($device, $path, $stage);
        if (! ($copy['ok'] ?? false)) {
            self::rmrf($stage);

            return back()->with('warning', 'Could not prepare that download. ' . ($copy['error'] ?? ''));
        }

        // --- Single file: stream the one file directly, no zip. --------------
        if ($single) {
            $base = basename(str_replace('\\', '/', $path));
            $src = $stage . '/' . $base;
            if ($base === '' || ! is_file($src)) {
                self::rmrf($stage);

                return back()->with('warning', 'That file could not be downloaded.');
            }
            // Move the file out of the staging dir so the dir can be removed now;
            // the file itself is cleaned up by deleteFileAfterSend after sending.
            $out = $tmpDir . '/dlf-' . $rand . '-' . $base;
            if (! @rename($src, $out)) {
                @copy($src, $out);
            }
            self::rmrf($stage);
            AuditLog::record('sync', "Downloaded file \"{$base}\" from endpoint \"{$device->name}\".", $device);

            return response()->download($out, $base)->deleteFileAfterSend(true);
        }

        // --- Folder: zip the staged copy, preserving structure. --------------
        $files = \Illuminate\Support\Facades\File::allFiles($stage);
        if (empty($files)) {
            self::rmrf($stage);

            return back()->with('warning', 'That folder is empty, so there is nothing to download.');
        }

        $slug = Str::slug($device->name) ?: 'endpoint';
        $label = $path === '' ? 'root' : (Str::slug(str_replace('/', '-', $path)) ?: 'folder');
        $zipName = "{$slug}-{$label}-" . now()->format('Ymd') . '.zip';
        // Build the zip OUTSIDE the staging dir (directly in tmp/) so the staging
        // dir can be dropped immediately; the zip is removed after send.
        $zipPath = $tmpDir . '/dl-' . $rand . '-' . $zipName;

        // Preserve the selected folder's own name at the top of the archive
        // (root download = no prefix, files sit at the archive root).
        $prefix = $path === '' ? '' : basename(str_replace('\\', '/', $path)) . '/';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            self::rmrf($stage);

            return back()->with('warning', 'Could not build the zip archive.');
        }
        foreach ($files as $f) {
            $rel = str_replace('\\', '/', $f->getRelativePathname());
            $zip->addFile($f->getPathname(), $prefix . $rel);
        }
        $zip->close();
        self::rmrf($stage);

        AuditLog::record('sync', 'Downloaded "' . ($path ?: 'Base Path') . "\" as a zip from endpoint \"{$device->name}\" (" . \App\Support\Bytes::human($size['bytes']) . ', ' . number_format($size['count']) . ' file(s)).', $device);

        return response()->download($zipPath, $zipName)->deleteFileAfterSend(true);
    }

    /**
     * Recursively delete a directory with plain filesystem calls. Safe to call
     * from a shutdown handler (does not depend on the container being alive).
     */
    private static function rmrf(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }
        foreach (@scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) && ! is_link($p) ? self::rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /**
     * Bulk-delete selected endpoints. Only the submitted ids are touched, and
     * only endpoints the current user is allowed to see.
     */
    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ids = Device::visibleTo(auth()->user())->whereIn('id', $data['ids'])->pluck('id');

        if ($ids->isEmpty()) {
            return back()->with('warning', 'No matching endpoints were selected.');
        }

        $count = Device::whereIn('id', $ids->all())->delete();

        AuditLog::record('deleted', "Bulk deleted {$count} endpoint".($count === 1 ? '' : 's').'.');

        return back()->with('status', $count.' endpoint'.($count === 1 ? '' : 's').' deleted.');
    }

    private function guard(Device $device): void
    {
        abort_unless($device->isVisibleTo(auth()->user()), 403);
    }

    private function validated(Request $request, ?Device $device = null): array
    {
        // The username field is submitted as `conn_ref` in the view to dodge
        // password-manager autofill; map it back here.
        if ($request->has('conn_ref')) {
            $request->merge(['username' => $request->input('conn_ref')]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'endpoint_type' => ['required', Rule::in(array_keys(Device::ENDPOINT_TYPES))],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:4096'],
            'private_key' => ['nullable', 'string', 'max:16384'],
            'base_path' => ['nullable', 'string', 'max:1024'],
            'os' => ['nullable', Rule::in(['windows', 'linux', 'darwin'])],
            'bucket' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:120'],
            'device_id' => ['nullable', 'string', 'max:120', Rule::unique('devices', 'device_id')->ignore($device?->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(array_keys(Device::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ]);
        $data['is_local'] = $request->boolean('is_local') || ($data['endpoint_type'] ?? null) === 'local';
        $data['ftp_tls'] = $request->boolean('ftp_tls');
        $data['s3_path_style'] = $request->boolean('s3_path_style');

        return $data;
    }
}
