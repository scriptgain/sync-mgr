<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pausable Device Groups.
 *
 * A paused group contributes NO peers to a fan-out: pairings that fan a one-way
 * sync out to the group skip its members while it is paused, without erroring.
 * Default false so every existing group stays active. Purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_groups', function (Blueprint $table) {
            $table->boolean('paused')->default(false)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('device_groups', function (Blueprint $table) {
            $table->dropColumn('paused');
        });
    }
};
