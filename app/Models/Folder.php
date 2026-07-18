<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A sync pairing: a Main endpoint + a Peer endpoint, each carrying a
 * Syncthing-style role. The pair of roles resolves to the rclone operation:
 *
 *   Main send_only  + Peer receive_only  -> push   (rclone sync main -> peer)
 *   Main receive_only + Peer send_only   -> pull   (rclone sync peer -> main)
 *   Main send_receive + Peer send_receive -> bisync (two-way, phase 2)
 *
 * Any other combination is invalid (nothing would move) and is rejected at
 * validation time.
 */
class Folder extends Model
{
    use OwnedByUser;

    // Legacy Syncthing folder types (kept for back-compat with old rows).
    public const TYPES = [
        'send_receive' => 'Send & Receive',
        'send_only' => 'Send Only',
        'receive_only' => 'Receive Only',
    ];

    // Per-device role on a pairing (identical vocabulary to TYPES).
    public const MODES = [
        'send_receive' => 'Send & Receive',
        'send_only' => 'Send Only',
        'receive_only' => 'Receive Only',
    ];

    public const STATUSES = [
        'idle' => 'Idle',
        'syncing' => 'Syncing',
        'scanning' => 'Scanning',
        'paused' => 'Paused',
        'error' => 'Error',
    ];

    protected $fillable = [
        'user_id', 'name', 'folder_id', 'path', 'type', 'status',
        'rescan_interval', 'versioning', 'file_count', 'size_bytes', 'notes',
        'main_device_id', 'peer_device_id', 'main_mode', 'peer_mode',
        'subpath', 'enabled', 'interval_minutes', 'last_run_at', 'next_run_at', 'last_status',
    ];

    protected function casts(): array
    {
        return [
            'versioning' => 'boolean',
            'rescan_interval' => 'integer',
            'file_count' => 'integer',
            'size_bytes' => 'integer',
            'enabled' => 'boolean',
            'interval_minutes' => 'integer',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /** Devices this folder is shared with (legacy many-to-many). */
    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'folder_device')
            ->withPivot('introducer')
            ->withTimestamps();
    }

    public function mainDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'main_device_id');
    }

    public function peerDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'peer_device_id');
    }

    public function syncEvents(): HasMany
    {
        return $this->hasMany(SyncEvent::class, 'folder_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function mainModeLabel(): string
    {
        return self::MODES[$this->main_mode] ?? ucfirst((string) $this->main_mode);
    }

    public function peerModeLabel(): string
    {
        return self::MODES[$this->peer_mode] ?? ucfirst((string) $this->peer_mode);
    }

    /**
     * Resolve the pairing's roles to a concrete data-flow operation.
     * Returns ['op' => 'push'|'pull'|'bisync'|'invalid', 'from' => ?Device, 'to' => ?Device].
     */
    public function resolveOperation(): array
    {
        $main = $this->main_mode;
        $peer = $this->peer_mode;

        if ($main === 'send_only' && $peer === 'receive_only') {
            return ['op' => 'push', 'from' => $this->mainDevice, 'to' => $this->peerDevice];
        }
        if ($main === 'receive_only' && $peer === 'send_only') {
            return ['op' => 'pull', 'from' => $this->peerDevice, 'to' => $this->mainDevice];
        }
        if ($main === 'send_receive' && $peer === 'send_receive') {
            return ['op' => 'bisync', 'from' => $this->mainDevice, 'to' => $this->peerDevice];
        }

        return ['op' => 'invalid', 'from' => null, 'to' => null];
    }

    /** Human sentence describing the resolved data flow, for the UI. */
    public function flowLabel(): string
    {
        return match ($this->resolveOperation()['op']) {
            'push' => 'Main → Peer (one-way mirror out)',
            'pull' => 'Peer → Main (one-way mirror in)',
            'bisync' => 'Main ⇄ Peer (two-way)',
            default => 'Invalid role combination',
        };
    }

    /** Is this pairing eligible for a scheduled run right now? */
    public function isDue(): bool
    {
        if (! $this->enabled || $this->interval_minutes <= 0) {
            return false;
        }

        return $this->next_run_at === null || $this->next_run_at->isPast();
    }

    /** A stable, shareable folder id (11-char Syncthing-style slug). */
    public static function generateFolderId(): string
    {
        do {
            $id = Str::lower(Str::random(6)) . '-' . Str::lower(Str::random(4));
        } while (static::where('folder_id', $id)->exists());

        return $id;
    }
}
