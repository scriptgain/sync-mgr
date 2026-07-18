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

    /** Run outcome (drives the status dot in the events table). */
    public const STATUSES = [
        'success' => 'Success',
        'partial' => 'Partial',
        'failed' => 'Failed',
        'running' => 'Running',
    ];

    public const STATUS_COLORS = [
        'success' => 'success',
        'partial' => 'warn',
        'failed' => 'danger',
        'running' => 'info',
    ];

    protected $fillable = [
        'folder_id', 'device_id', 'type', 'status', 'message', 'occurred_at',
        'files_transferred', 'bytes_transferred', 'errors', 'duration_ms', 'operation', 'log_tail',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'files_transferred' => 'integer',
            'bytes_transferred' => 'integer',
            'errors' => 'integer',
            'duration_ms' => 'integer',
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

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ($this->status ? ucfirst((string) $this->status) : '—');
    }

    public function statusColor(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'neutral';
    }

    /** Human duration, e.g. "0.8s" or "1m 12s". */
    public function durationLabel(): string
    {
        if ($this->duration_ms === null) {
            return '—';
        }
        $s = $this->duration_ms / 1000;
        if ($s < 60) {
            return rtrim(rtrim(number_format($s, 1), '0'), '.') . 's';
        }

        return floor($s / 60) . 'm ' . str_pad((string) ((int) round($s % 60)), 2, '0', STR_PAD_LEFT) . 's';
    }
}
