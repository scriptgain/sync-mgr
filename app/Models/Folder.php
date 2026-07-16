<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Folder extends Model
{
    use OwnedByUser;

    public const TYPES = [
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
    ];

    protected function casts(): array
    {
        return [
            'versioning' => 'boolean',
            'rescan_interval' => 'integer',
            'file_count' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    /** Devices this folder is shared with. */
    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'folder_device')
            ->withPivot('introducer')
            ->withTimestamps();
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

    /** A stable, shareable folder id (11-char Syncthing-style slug). */
    public static function generateFolderId(): string
    {
        do {
            $id = Str::lower(Str::random(6)) . '-' . Str::lower(Str::random(4));
        } while (static::where('folder_id', $id)->exists());

        return $id;
    }
}
