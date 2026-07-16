<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SyncMGR domain: a Syncthing-style continuous file-sync console. This panel
 * manages the metadata (devices, folders, who shares what, and an event feed);
 * a real sync engine/agent is a later layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Peer devices in the sync cluster.
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('device_id')->unique();          // Syncthing-style device key
            $table->string('address')->nullable();          // dynamic or tcp://host:port
            $table->boolean('is_local')->default(false);    // this panel's own node
            $table->string('status')->default('disconnected'); // connected|disconnected|paused
            $table->timestamp('last_seen_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Synced folders.
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('folder_id')->unique();          // stable label shared across devices
            $table->string('path');
            $table->string('type')->default('send_receive'); // send_receive|send_only|receive_only
            $table->string('status')->default('idle');       // idle|syncing|scanning|paused|error
            $table->unsignedInteger('rescan_interval')->default(3600); // seconds
            $table->boolean('versioning')->default(false);
            $table->unsignedBigInteger('file_count')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Which devices a folder is shared with (many-to-many).
        Schema::create('folder_device', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->constrained('folders')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->boolean('introducer')->default(false);
            $table->timestamps();
            $table->unique(['folder_id', 'device_id']);
        });

        // Event feed (scans, index updates, conflicts, completions, errors).
        Schema::create('sync_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->nullable()->constrained('folders')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('type');                          // scan|index|conflict|completed|error
            $table->string('message')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_events');
        Schema::dropIfExists('folder_device');
        Schema::dropIfExists('folders');
        Schema::dropIfExists('devices');
    }
};
