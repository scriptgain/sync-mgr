<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named, reusable set of endpoints. Drop a group onto a pairing's peer list
 * and it expands to its member devices as peers, so a Main (Send Only) endpoint
 * fans a one-way sync out to every member.
 */
class DeviceGroup extends Model
{
    use OwnedByUser;

    protected $fillable = [
        'user_id', 'name', 'description', 'paused',
    ];

    protected function casts(): array
    {
        return [
            'paused' => 'boolean',
        ];
    }

    /** Member endpoints in this group. */
    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'device_group_device')
            ->withTimestamps();
    }

    /** First-letter avatar initial for the index table. */
    public function initial(): string
    {
        return strtoupper(mb_substr(trim((string) $this->name), 0, 1)) ?: '#';
    }
}
