<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Read-only public demo data for SyncMGR: devices, sync folders (pairings),
 *  device groups, and recent sync events. Idempotent. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['sync_events', 'folder_device', 'folder_peer', 'folders', 'device_group_device', 'device_groups', 'devices'] as $t) {
            if (DB::getSchemaBuilder()->hasTable($t)) DB::table($t)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $uid = DB::table('users')->where('email', 'demo@scriptgain.com')->value('id')
            ?? DB::table('users')->insertGetId(['name' => 'Demo Admin', 'email' => 'demo@scriptgain.com', 'password' => Hash::make(Str::random(40)), 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('settings')->updateOrInsert(['key' => 'setup_complete'], ['value' => '1']);

        $devDefs = [
            ['main-nas', 'local', true], ['web-prod-01', 'ssh', false], ['db-primary', 'ssh', false],
            ['offsite-b2', 's3', false], ['laptop-jordan', 'ssh', false], ['edge-proxy', 'ssh', false],
        ];
        $devs = [];
        foreach ($devDefs as $i => [$name, $etype, $isLocal]) {
            $online = $isLocal || random_int(1, 100) <= 70;
            $devs[] = DB::table('devices')->insertGetId([
                'user_id' => $uid, 'name' => $name, 'endpoint_type' => $etype,
                'host' => $etype === 's3' ? null : $name.'.internal', 'port' => $etype === 'ssh' ? 22 : null,
                'base_path' => $etype === 's3' ? null : '/srv/sync', 'bucket' => $etype === 's3' ? 'offsite-sync' : null,
                'region' => $etype === 's3' ? 'us-west-004' : null, 'device_id' => (string) Str::uuid(),
                'api_key' => 'sk_'.Str::random(28), 'agent_version' => '1.2.0', 'os' => 'linux', 'arch' => 'x86_64',
                'is_local' => $isLocal ? 1 : 0, 'status' => $online ? 'online' : 'offline',
                'last_seen_at' => $online ? now()->subSeconds(random_int(5, 120)) : now()->subHours(random_int(3, 30)),
                'last_checkin_at' => now()->subSeconds(random_int(5, 300)),
                'created_at' => now()->subDays(random_int(10, 80)), 'updated_at' => now(),
            ]);
        }

        $groups = [];
        foreach ([['Production Servers', 'All production Linux hosts'], ['Offsite Replicas', 'Backup + cold storage targets']] as [$n, $d]) {
            $groups[] = DB::table('device_groups')->insertGetId(['user_id' => $uid, 'name' => $n, 'description' => $d, 'paused' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        $folderDefs = [
            ['Website Assets', '/srv/www', 'sendreceive', 'sendonly'], ['Database Dumps', '/srv/dumps', 'sendonly', 'receiveonly'],
            ['User Uploads', '/srv/uploads', 'sendreceive', 'sendreceive'], ['Config Backups', '/etc/appconf', 'sendonly', 'receiveonly'],
            ['Media Library', '/srv/media', 'sendreceive', 'receiveonly'], ['Log Archive', '/var/log/archive', 'sendonly', 'receiveonly'],
        ];
        $folders = [];
        foreach ($folderDefs as $i => [$name, $path, $mainMode, $peerMode]) {
            $roll = random_int(1, 100);
            $status = $roll <= 78 ? 'idle' : ($roll <= 92 ? 'syncing' : 'error');
            $sched = ['on_change', 'scheduled', 'manual'][random_int(0, 2)];
            $folders[] = DB::table('folders')->insertGetId([
                'user_id' => $uid, 'main_device_id' => $devs[0], 'peer_device_id' => $devs[($i % (count($devs) - 1)) + 1],
                'main_mode' => $mainMode, 'peer_mode' => $peerMode, 'name' => $name, 'path' => $path,
                'folder_id' => Str::lower(Str::random(11)), 'type' => 'sendreceive', 'enabled' => 1,
                'status' => $status, 'schedule_mode' => $sched, 'interval_minutes' => $sched === 'scheduled' ? [15, 30, 60][random_int(0, 2)] : 60,
                'last_run_at' => now()->subMinutes(random_int(1, 600)), 'last_status' => $status === 'error' ? 'error' : 'success',
                'file_count' => random_int(120, 84000), 'size_bytes' => random_int(1e8, 8e11),
                'created_at' => now()->subDays(random_int(10, 70)), 'updated_at' => now(),
            ]);
        }

        $types = ['scan', 'transfer', 'delete', 'conflict'];
        foreach ($folders as $fi => $fid) {
            for ($i = 0; $i < random_int(6, 14); $i++) {
                $ok = random_int(1, 100) <= 88;
                DB::table('sync_events')->insert([
                    'folder_id' => $fid, 'device_id' => $devs[($fi % (count($devs) - 1)) + 1],
                    'type' => $types[random_int(0, 3)], 'status' => $ok ? 'success' : 'error',
                    'files_transferred' => $ok ? random_int(0, 4200) : 0, 'bytes_transferred' => $ok ? random_int(0, 5e9) : 0,
                    'duration_ms' => random_int(120, 240000), 'operation' => 'two-way sync',
                    'message' => $ok ? 'sync complete' : 'peer unreachable', 'occurred_at' => now()->subHours($i * random_int(1, 6)),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $this->command?->info('Sync demo seeded: '.count($devDefs).' devices, '.count($folderDefs).' folders.');
    }
}
