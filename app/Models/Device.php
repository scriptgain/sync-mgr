<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Device extends Model
{
    use OwnedByUser;

    public const STATUSES = [
        'connected' => 'Connected',
        'disconnected' => 'Disconnected',
        'paused' => 'Paused',
    ];

    protected $fillable = [
        'user_id', 'name', 'device_id', 'address', 'is_local', 'status', 'last_seen_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_local' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /** Folders shared with this device. */
    public function folders(): BelongsToMany
    {
        return $this->belongsToMany(Folder::class, 'folder_device')
            ->withPivot('introducer')
            ->withTimestamps();
    }

    public function syncEvents(): HasMany
    {
        return $this->hasMany(SyncEvent::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    /** A Syncthing-style device key: uppercase, no ambiguous characters. */
    public static function generateDeviceId(): string
    {
        do {
            $id = Str::upper(Str::random(52));
        } while (static::where('device_id', $id)->exists());

        return $id;
    }
}
