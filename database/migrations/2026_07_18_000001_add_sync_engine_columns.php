<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the (previously metadata-only) SyncMGR schema into a real sync engine:
 *
 *  - devices become "endpoints": a remote account rclone can talk to
 *    (local / ftp / sftp / s3 / agent) with host, port, credentials and a base path.
 *  - folders become "sync pairings": a Main endpoint + a Peer endpoint, each with
 *    a Syncthing-style role (send_receive / send_only / receive_only). The roles
 *    resolve to the rclone operation (one-way mirror out/in, or two-way bisync).
 *  - sync_events gain real run metrics (status, files, bytes, duration, errors, log).
 *
 * Purely additive: no existing column is dropped, so live endpoint/pairing rows
 * created through the panel are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('endpoint_type')->nullable()->after('name');   // local|ftp|sftp|s3|agent
            $table->string('host')->nullable()->after('endpoint_type');
            $table->unsignedInteger('port')->nullable()->after('host');
            $table->string('username')->nullable()->after('port');
            $table->text('secret')->nullable()->after('username');        // encrypted: password / secret key
            $table->text('private_key')->nullable()->after('secret');     // encrypted: sftp PEM (optional)
            $table->string('base_path')->nullable()->after('private_key');
            $table->boolean('ftp_tls')->default(false)->after('base_path'); // ftp explicit TLS
            $table->string('bucket')->nullable()->after('ftp_tls');       // s3
            $table->string('region')->nullable()->after('bucket');        // s3
            $table->boolean('s3_path_style')->default(false)->after('region');
        });

        Schema::table('folders', function (Blueprint $table) {
            $table->foreignId('main_device_id')->nullable()->after('user_id')->constrained('devices')->nullOnDelete();
            $table->foreignId('peer_device_id')->nullable()->after('main_device_id')->constrained('devices')->nullOnDelete();
            $table->string('main_mode')->default('send_only')->after('peer_device_id');   // send_receive|send_only|receive_only
            $table->string('peer_mode')->default('receive_only')->after('main_mode');
            $table->string('subpath')->nullable()->after('peer_mode');
            $table->boolean('enabled')->default(false)->after('subpath');
            $table->unsignedInteger('interval_minutes')->default(0)->after('enabled'); // 0 = manual only
            $table->timestamp('last_run_at')->nullable()->after('interval_minutes');
            $table->timestamp('next_run_at')->nullable()->after('last_run_at');
            $table->string('last_status')->nullable()->after('next_run_at');
        });

        Schema::table('sync_events', function (Blueprint $table) {
            $table->string('status')->nullable()->after('type');          // success|failed|partial|running
            $table->unsignedBigInteger('files_transferred')->default(0)->after('status');
            $table->unsignedBigInteger('bytes_transferred')->default(0)->after('files_transferred');
            $table->unsignedInteger('errors')->default(0)->after('bytes_transferred');
            $table->unsignedInteger('duration_ms')->nullable()->after('errors');
            $table->string('operation')->nullable()->after('duration_ms'); // push|pull|bisync
            $table->text('log_tail')->nullable()->after('operation');
        });
    }

    public function down(): void
    {
        Schema::table('sync_events', function (Blueprint $table) {
            $table->dropColumn(['status', 'files_transferred', 'bytes_transferred', 'errors', 'duration_ms', 'operation', 'log_tail']);
        });

        Schema::table('folders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('main_device_id');
            $table->dropConstrainedForeignId('peer_device_id');
            $table->dropColumn(['main_mode', 'peer_mode', 'subpath', 'enabled', 'interval_minutes', 'last_run_at', 'next_run_at', 'last_status']);
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['endpoint_type', 'host', 'port', 'username', 'secret', 'private_key', 'base_path', 'ftp_tls', 'bucket', 'region', 's3_path_style']);
        });
    }
};
