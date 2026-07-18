<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An endpoint: a remote account SyncMGR can read from / write to. The rclone
 * engine turns this row into an on-the-fly remote (env-var config, no creds on
 * disk). Secrets are stored with Laravel's `encrypted` cast.
 */
class Device extends Model
{
    use OwnedByUser;

    public const STATUSES = [
        'connected' => 'Connected',
        'disconnected' => 'Disconnected',
        'paused' => 'Paused',
    ];

    /** Endpoint transports. `agent` is a captured-but-not-yet-live stub. */
    public const ENDPOINT_TYPES = [
        'ftp' => 'FTP',
        'sftp' => 'SFTP',
        's3' => 'S3 / Object Storage',
        'agent' => 'Agent / Device Login',
        'local' => 'Local Path (Same Host)',
    ];

    /** Default port per transport (editable in the form). */
    public const DEFAULT_PORTS = [
        'ftp' => 21,
        'sftp' => 22,
        's3' => null,
        'agent' => 5410,
        'local' => null,
    ];

    /** Transports that can actually run a sync today. */
    public const LIVE_TYPES = ['ftp', 'sftp', 's3', 'local'];

    protected $fillable = [
        'user_id', 'name', 'device_id', 'address', 'is_local', 'status', 'last_seen_at', 'notes',
        'endpoint_type', 'host', 'port', 'username', 'secret', 'private_key',
        'base_path', 'ftp_tls', 'bucket', 'region', 's3_path_style',
        'agent_version', 'os', 'arch', 'last_checkin_at',
    ];

    // enrollment_token + api_key are pairing secrets: never mass-assignable and
    // hidden from serialization. They are written via forceFill() only.
    protected $hidden = ['secret', 'private_key', 'enrollment_token', 'api_key'];

    protected function casts(): array
    {
        return [
            'is_local' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_checkin_at' => 'datetime',
            'port' => 'integer',
            'secret' => 'encrypted',
            'private_key' => 'encrypted',
            'ftp_tls' => 'boolean',
            's3_path_style' => 'boolean',
        ];
    }

    /** Folders shared with this device (legacy Syncthing-style sharing). */
    public function folders(): BelongsToMany
    {
        return $this->belongsToMany(Folder::class, 'folder_device')
            ->withPivot('introducer')
            ->withTimestamps();
    }

    /** Device Groups this endpoint is a member of. */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(DeviceGroup::class, 'device_group_device')
            ->withTimestamps();
    }

    public function syncEvents(): HasMany
    {
        return $this->hasMany(SyncEvent::class);
    }

    /** Pairings where this endpoint is the Main (source of truth). */
    public function mainPairings(): HasMany
    {
        return $this->hasMany(Folder::class, 'main_device_id');
    }

    /** Pairings where this endpoint is the (legacy single) Peer. */
    public function peerPairings(): HasMany
    {
        return $this->hasMany(Folder::class, 'peer_device_id');
    }

    /** Pairings that include this endpoint in their peer set (the current model). */
    public function peerFolders(): BelongsToMany
    {
        return $this->belongsToMany(Folder::class, 'folder_peer')
            ->withPivot('mode')
            ->withTimestamps();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function typeLabel(): string
    {
        return self::ENDPOINT_TYPES[$this->endpoint_type] ?? ucfirst((string) ($this->endpoint_type ?: 'unknown'));
    }

    /** Is this transport wired into the live rclone engine (master runs rclone against it)? */
    public function isLive(): bool
    {
        return in_array($this->endpoint_type, self::LIVE_TYPES, true);
    }

    /** True for an agent-type endpoint (an out-dialing installed program). */
    public function isAgent(): bool
    {
        return $this->endpoint_type === 'agent';
    }

    /** Has this agent enrolled yet (traded its one-time token for a key)? */
    public function isEnrolled(): bool
    {
        return $this->isAgent() && filled($this->api_key);
    }

    /**
     * Live online/offline state for an agent, derived from its last check-in vs
     * the configured offline window. Non-agent endpoints have no agent to poll,
     * so this returns null (use the stored status instead).
     */
    public function agentOnline(): ?bool
    {
        if (! $this->isAgent()) {
            return null;
        }
        if (! $this->last_checkin_at) {
            return false;
        }
        $window = max(1, (int) config('sync.offline_after_minutes', config('backup.offline_after_minutes', 5)));

        return $this->last_checkin_at->gt(now()->subMinutes($window));
    }

    /** True when this endpoint belongs to at least one paused device group. */
    public function isInPausedGroup(): bool
    {
        $groups = $this->relationLoaded('groups') ? $this->groups : $this->groups()->get();

        return $groups->contains(fn ($g) => (bool) $g->paused);
    }

    /** A Syncthing-style device key: uppercase, no ambiguous characters. */
    public static function generateDeviceId(): string
    {
        do {
            $id = Str::upper(Str::random(52));
        } while (static::where('device_id', $id)->exists());

        return $id;
    }

    /**
     * Issue a fresh one-time enrollment token for this agent device. Returns the
     * PLAINTEXT (shown to the operator once); only its sha256 is stored. Calling
     * this again invalidates the previous token and re-arms enrollment.
     */
    public function issueEnrollmentToken(): string
    {
        $plain = 'syncenr_' . Str::random(40);
        $this->forceFill(['enrollment_token' => hash('sha256', $plain)])->save();

        return $plain;
    }
}
