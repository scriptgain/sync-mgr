<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Device Groups + multi-peer pairings.
 *
 *  - device_groups        : a named, owner-scoped set of endpoints (a saved
 *                           selection you can drop onto a pairing in one click).
 *  - device_group_device  : group <-> device membership (a device may be in many
 *                           groups; a group holds many devices).
 *  - folder_peer          : a pairing's PEER SET. A Folder now has one Main
 *                           endpoint plus N peers (ad-hoc devices and/or the
 *                           members of one or more groups, expanded at save).
 *                           Each peer carries its own role (`mode`). When the
 *                           Main is Send Only the Main fans out to every peer.
 *
 * Purely additive. The existing single `folders.peer_device_id` column is kept
 * and every current single-peer pairing is backfilled into folder_peer so live
 * rows keep working; no column is dropped and no data is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('device_group_device', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_group_id')->constrained('device_groups')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['device_group_id', 'device_id']);
        });

        Schema::create('folder_peer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->constrained('folders')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('mode')->default('receive_only'); // per-peer role: receive_only|send_only|send_receive
            $table->timestamps();
            $table->unique(['folder_id', 'device_id']);
        });

        // Schedule mode: how a pairing runs automatically.
        //   manual     -> Sync Now only.
        //   scheduled  -> every interval_minutes (the existing behavior).
        //   onchange   -> continuous best-effort poll (interval_minutes is the poll gap).
        Schema::table('folders', function (Blueprint $table) {
            $table->string('schedule_mode')->default('scheduled')->after('enabled');
        });

        // Backfill: every existing single-peer pairing becomes one folder_peer row
        // so live sync keeps running unchanged after the model moves to a peer set.
        if (Schema::hasColumn('folders', 'peer_device_id')) {
            $now = now();
            DB::table('folders')->whereNotNull('peer_device_id')->orderBy('id')
                ->each(function ($folder) use ($now) {
                    DB::table('folder_peer')->insertOrIgnore([
                        'folder_id' => $folder->id,
                        'device_id' => $folder->peer_device_id,
                        'mode' => $folder->peer_mode ?? 'receive_only',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                });
        }

        // Backfill schedule_mode from the current enabled/interval state so live
        // pairings keep their behavior (auto-running ones stay scheduled).
        DB::table('folders')->where('enabled', true)->where('interval_minutes', '>', 0)
            ->update(['schedule_mode' => 'scheduled']);
        DB::table('folders')->where(function ($q) {
            $q->where('enabled', false)->orWhere('interval_minutes', '<=', 0);
        })->update(['schedule_mode' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn('schedule_mode');
        });

        Schema::dropIfExists('folder_peer');
        Schema::dropIfExists('device_group_device');
        Schema::dropIfExists('device_groups');
    }
};
