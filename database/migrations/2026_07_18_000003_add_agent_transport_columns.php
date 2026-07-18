<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent transport (Phase 1 master surface).
 *
 * An `agent`-type Device is a cross-platform program a user installs on their own
 * computer. It dials OUT to this master, enrolls once with a one-time token, then
 * runs a bundled rclone locally against the OTHER (remote) endpoint of any pairing
 * it participates in. These columns give the master the pairing + check-in fields
 * it needs to enroll an agent and track it:
 *
 *   - enrollment_token : one-time pairing token (stored hashed; nulled on enroll).
 *   - api_key          : the agent's permanent bearer key (stored hashed, hidden).
 *   - agent_version/os/arch : reported at enroll + every heartbeat.
 *   - last_checkin_at  : drives the online/offline dot (vs offline_after_minutes).
 *
 * On folders: pending_sync_now is the panel "Sync Now" relay for agent-managed
 * pairings — the master cannot run rclone against an agent, so it raises this flag
 * and the agent claims it on its next poll.
 *
 * Purely additive: no column is dropped and no row is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('enrollment_token')->nullable()->unique()->after('device_id'); // hashed, one-time
            $table->string('api_key')->nullable()->after('enrollment_token');              // hashed bearer
            $table->string('agent_version')->nullable()->after('api_key');
            $table->string('os')->nullable()->after('agent_version');
            $table->string('arch')->nullable()->after('os');
            $table->timestamp('last_checkin_at')->nullable()->after('arch');
        });

        Schema::table('folders', function (Blueprint $table) {
            // Panel "Sync Now" relay: an agent-managed pairing sets this instead of
            // dispatching a server-side RunSyncJob; the agent clears it on pickup.
            $table->boolean('pending_sync_now')->default(false)->after('last_status');
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn('pending_sync_now');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique(['enrollment_token']);
            $table->dropColumn(['enrollment_token', 'api_key', 'agent_version', 'os', 'arch', 'last_checkin_at']);
        });
    }
};
