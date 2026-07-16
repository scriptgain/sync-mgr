<?php

namespace App\Models;

use App\Models\Concerns\OwnedViaFolder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncEvent extends Model
{
    use OwnedViaFolder;

    public const TYPES = [
        'scan' => 'Scan',
        'index' => 'Index',
        'conflict' => 'Conflict',
        'completed' => 'Completed',
        'error' => 'Error',
    ];

    protected $fillable = [
        'folder_id', 'device_id', 'type', 'message', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'folder_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }
}
