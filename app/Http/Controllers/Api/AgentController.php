<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Folder;
use App\Models\Setting;
use App\Services\OfflineLicenseVerifier;
use App\Services\RcloneEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Transport surface for the out-dialing SyncMGR agent.
 *
 * The agent is an `agent`-type Device installed on a user's own computer. It
 * dials OUT (no inbound ports) and:
 *   1. enroll   — trades its one-time enrollment token for a permanent api_key.
 *   2. heartbeat — reports liveness, receives the poll interval, the signed
 *                  license blob, and an optional self-update offer.
 *   3. poll     — receives a job spec per ENABLED pairing it participates in:
 *                 the operation, its own local path, and the REMOTE endpoint's
 *                 rclone config + path (creds delivered per-poll, never persisted).
 *   4. report   — posts the result of a run; we record a SyncEvent + roll the
 *                 pairing's status/schedule forward (via RcloneEngine).
 *   5. command-ack — confirms it picked up a panel "Sync Now".
 *
 * Credentials are handed to the agent in the poll response only; nothing here
 * writes an agent's remote creds to disk. Mirrors BackupMGR's agent transport.
 */
class AgentController extends Controller
{
    /** Trade a one-time enrollment token for a permanent agent API key. */
    public function enroll(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'os' => ['nullable', 'string', 'max:60'],
            'arch' => ['nullable', 'string', 'max:40'],
            'agent_version' => ['nullable', 'string', 'max:40'],
        ]);

        $device = Device::where('enrollment_token', hash('sha256', $data['token']))
            ->where('endpoint_type', 'agent')
            ->first();

        if (! $device) {
            return response()->json(['message' => 'Invalid or used enrollment token.'], 404);
        }

        $plainKey = 'syncagt_' . Str::random(48);
        $device->forceFill([
            'api_key' => hash('sha256', $plainKey),
            'enrollment_token' => null, // one-time: burn it on success
            'status' => 'connected',
            'os' => $data['os'] ?? $device->os,
            'arch' => $data['arch'] ?? $device->arch,
            'agent_version' => $data['agent_version'] ?? $device->agent_version,
            'host' => $device->host ?: ($data['hostname'] ?? null),
            'last_checkin_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        return response()->json([
            'device_id' => (string) $device->id,
            'api_key' => $plainKey,
        ]);
    }

    /** Liveness + config: poll interval, license blob, and any update offer. */
    public function heartbeat(Request $request)
    {
        $device = $request->attributes->get('agent_device');
        $device->forceFill([
            'last_checkin_at' => now(),
            'last_seen_at' => now(),
            'status' => 'connected',
            'agent_version' => $request->input('agent_version', $device->agent_version),
            'os' => $request->input('os', $device->os),
            'arch' => $request->input('arch', $device->arch),
        ])->save();

        $interval = (int) (Setting::get('agent_poll_interval') ?: 30);

        return response()->json([
            'poll_interval' => $interval > 0 ? $interval : 30,
            'license' => $this->licenseBlob(),
            'update' => $this->updateOffer(),
        ]);
    }

    /**
     * The job spec for every ENABLED pairing this agent participates in (as Main
     * or peer). Each job carries the resolved operation, the agent's own local
     * path, and the REMOTE (other) endpoint's rclone config + path.
     */
    public function poll(Request $request, RcloneEngine $engine)
    {
        $device = $request->attributes->get('agent_device');
        $device->forceFill(['last_checkin_at' => now(), 'last_seen_at' => now(), 'status' => 'connected'])->save();

        // Every enabled pairing where this agent is the Main or one of the peers.
        $folders = Folder::query()
            ->where('enabled', true)
            ->where(function ($q) use ($device) {
                $q->where('main_device_id', $device->id)
                    ->orWhereHas('peers', fn ($p) => $p->where('devices.id', $device->id));
            })
            ->with(['mainDevice', 'peers'])
            ->get();

        $jobs = [];
        foreach ($folders as $folder) {
            $spec = $this->jobSpec($folder, $device, $engine);
            if ($spec !== null) {
                $jobs[] = $spec;
            }
        }

        return response()->json(['jobs' => $jobs]);
    }

    /**
     * Build one job spec for the agent, or null when the pairing has no valid
     * remote counterpart. `op` is derived from the agent's role on the pairing:
     *   Send Only      -> push   (local -> remote)
     *   Receive Only   -> pull   (remote -> local)
     *   Send & Receive -> bisync (two-way)
     */
    private function jobSpec(Folder $folder, Device $agent, RcloneEngine $engine): ?array
    {
        // The agent's role (mode) on this pairing, and the REMOTE endpoint.
        if ((int) $folder->main_device_id === (int) $agent->id) {
            $role = $folder->main_mode;
            // The Main agent's counterpart is its (single) peer.
            $remote = $folder->peers->firstWhere('id', '!=', $agent->id) ?? $folder->peers->first();
        } else {
            $peer = $folder->peers->firstWhere('id', $agent->id);
            $role = $peer?->pivot?->mode ?? $folder->peer_mode;
            // A peer agent's counterpart is the Main endpoint.
            $remote = $folder->mainDevice;
        }

        if (! $remote) {
            return null;
        }

        $op = match ($role) {
            'send_only' => 'push',
            'receive_only' => 'pull',
            'send_receive' => 'bisync',
            default => null,
        };
        if ($op === null) {
            return null;
        }

        return [
            'folder_id' => $folder->id,
            'folder_slug' => $folder->folder_id,
            'name' => $folder->name,
            'op' => $op,
            'local_path' => (string) $agent->base_path,
            'remote' => [
                'config' => $engine->remoteEnv('REMOTE', $remote),
                'path' => $engine->remotePath('REMOTE', $remote, $folder->subpath),
            ],
            'schedule_mode' => $folder->schedule_mode,
            'interval_minutes' => (int) $folder->interval_minutes,
            'watch' => $folder->schedule_mode === 'onchange',
            'pending_sync_now' => (bool) $folder->pending_sync_now,
        ];
    }

    /**
     * Record a run the agent executed. Creates a SyncEvent (device = the agent)
     * and rolls the pairing's status/schedule forward via the engine.
     */
    public function report(Request $request, RcloneEngine $engine)
    {
        $device = $request->attributes->get('agent_device');

        $data = $request->validate([
            'folder_id' => ['required', 'integer'],
            'status' => ['required', 'in:success,partial,failed,running'],
            'operation' => ['nullable', 'in:push,pull,bisync'],
            'type' => ['nullable', 'in:scan,index,conflict,completed,error'],
            'files_transferred' => ['nullable', 'integer', 'min:0'],
            'bytes_transferred' => ['nullable', 'integer', 'min:0'],
            'errors' => ['nullable', 'integer', 'min:0'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'log_tail' => ['nullable', 'string'],
            'message' => ['nullable', 'string', 'max:250'],
        ]);

        $folder = $this->authorizedFolder($device, (int) $data['folder_id']);

        $event = $engine->recordReportedRun($folder, $device, $data);

        // A definitive result means this pairing's "Sync Now" (if any) is served.
        if (in_array($data['status'], ['success', 'partial', 'failed'], true) && $folder->pending_sync_now) {
            $folder->forceFill(['pending_sync_now' => false])->save();
        }

        return response()->json(['event_id' => (string) $event->id, 'status' => $event->status]);
    }

    /** Confirm the agent picked up a panel "Sync Now" for a pairing. */
    public function commandAck(Request $request)
    {
        $device = $request->attributes->get('agent_device');
        $data = $request->validate([
            'folder_id' => ['required', 'integer'],
        ]);

        $folder = $this->authorizedFolder($device, (int) $data['folder_id']);
        $folder->forceFill(['pending_sync_now' => false])->save();

        return response()->noContent();
    }

    /**
     * The pairing the agent claims to be acting on, guarded so the agent can only
     * touch pairings it actually participates in. 403/404 otherwise.
     */
    private function authorizedFolder(Device $device, int $folderId): Folder
    {
        $folder = Folder::where('id', $folderId)
            ->where(function ($q) use ($device) {
                $q->where('main_device_id', $device->id)
                    ->orWhereHas('peers', fn ($p) => $p->where('devices.id', $device->id));
            })
            ->first();

        abort_unless($folder !== null, 403, 'This agent does not participate in that pairing.');

        return $folder;
    }

    /**
     * The ScriptGain-signed license (canonical payload + signature) for the agent
     * to re-verify offline against the embedded vendor key. Built from the stored
     * .lic using the SAME canonical routine the app verifies with. Null until a
     * license file has been uploaded.
     */
    private function licenseBlob(): ?array
    {
        $raw = Setting::get('license_lic');
        if (! $raw) {
            return null;
        }
        $doc = json_decode($raw, true);
        if (! is_array($doc) || ! isset($doc['license']) || ! is_array($doc['license']) || ! isset($doc['signature'])) {
            return null;
        }

        return [
            'canonical' => (new OfflineLicenseVerifier)->canonicalize($doc['license']),
            'signature' => (string) $doc['signature'],
        ];
    }

    /**
     * Advertise a newer agent build only when auto-update is on AND all four
     * fields are set. The agent refuses any offer without a checksum + vendor
     * signature over a non-https URL, so a half-configured offer just wastes a
     * download — withhold it. Mirrors BackupMGR.
     */
    private function updateOffer(): ?array
    {
        $s = Setting::map();
        if (($s['agent_auto_update'] ?? '0') !== '1') {
            return null;
        }
        $version = trim($s['agent_latest_version'] ?? '');
        $url = trim($s['agent_download_url'] ?? '');
        $sha256 = strtolower(trim($s['agent_download_sha256'] ?? ''));
        $signature = trim($s['agent_download_signature'] ?? '');
        if ($version === '' || $url === '' || $sha256 === '' || $signature === '') {
            return null;
        }

        return [
            'version' => $version,
            'url' => $url,
            'sha256' => $sha256,
            'signature' => $signature,
        ];
    }
}
