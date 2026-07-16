<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\SyncEvent;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaintenanceController extends Controller
{
    /** Ordered day-of-week tokens matching Carbon's lowercase `D` format. */
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** Defaults for every Maintenance setting. Keys are Setting table keys. */
    public static function defaults(): array
    {
        return [
            'auto_maintenance' => '1',
            'maintenance_window_enabled' => '0',
            'maintenance_window_start' => '02:00',
            'maintenance_window_end' => '05:00',
            'maintenance_days' => implode(',', self::DAYS),
            // Tasks.
            'prune_old_events' => '1',
            'event_days' => '30',
            'mark_stale_devices' => '1',
            'device_offline_minutes' => '30',
            'audit_log_days' => '180',
        ];
    }

    public static function values(): array
    {
        $map = Setting::map();
        $v = [];
        foreach (static::defaults() as $key => $default) {
            $v[$key] = $map[$key] ?? $default;
        }

        return $v;
    }

    /** Whether the scheduled sweep may run right now, honoring the window. */
    public static function allowedNow(?array $s = null, ?\DateTimeInterface $now = null): bool
    {
        $s ??= static::values();
        if (($s['auto_maintenance'] ?? '1') !== '1') {
            return false;
        }
        if (($s['maintenance_window_enabled'] ?? '0') !== '1') {
            return true;
        }

        $now = $now ? \Illuminate\Support\Carbon::instance($now) : now();

        $days = array_filter(explode(',', $s['maintenance_days'] ?? ''));
        if ($days && ! in_array(strtolower($now->format('D')), $days, true)) {
            return false;
        }

        $start = $s['maintenance_window_start'] ?? '00:00';
        $end = $s['maintenance_window_end'] ?? '23:59';
        $cur = $now->format('H:i');

        return $start <= $end
            ? ($cur >= $start && $cur <= $end)
            : ($cur >= $start || $cur <= $end);
    }

    /** Run the housekeeping sweep. Returns per-task counts. */
    public static function runSweep(?array $s = null): array
    {
        $s ??= static::values();
        $counts = ['events_pruned' => 0, 'devices_marked' => 0, 'audit_pruned' => 0];

        // 1. Prune old sync events past the retention window.
        if (($s['prune_old_events'] ?? '1') === '1') {
            $days = max(1, (int) ($s['event_days'] ?? 30));
            $cutoff = now()->subDays($days);
            $counts['events_pruned'] = SyncEvent::where(function ($q) use ($cutoff) {
                $q->where('occurred_at', '<', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNull('occurred_at')->where('created_at', '<', $cutoff);
                    });
            })->delete();
        }

        // 2. Mark devices offline if they have not been seen within the window.
        if (($s['mark_stale_devices'] ?? '1') === '1') {
            $minutes = max(1, (int) ($s['device_offline_minutes'] ?? 30));
            $counts['devices_marked'] = Device::where('status', '!=', 'disconnected')
                ->whereNotNull('last_seen_at')
                ->where('last_seen_at', '<', now()->subMinutes($minutes))
                ->update(['status' => 'disconnected']);
        }

        // 3. Prune old audit rows.
        $auditDays = (int) ($s['audit_log_days'] ?? 180);
        if ($auditDays > 0) {
            $counts['audit_pruned'] = AuditLog::where('created_at', '<', now()->subDays($auditDays))->delete();
        }

        return $counts;
    }

    public function edit()
    {
        $v = static::values();

        return view('settings.maintenance', [
            'v' => $v,
            'days' => self::DAYS,
            'selectedDays' => array_filter(explode(',', $v['maintenance_days'])),
            'allowedNow' => static::allowedNow($v),
            'now' => now(),
            'stats' => [
                'Devices' => Device::count(),
                'Connected Devices' => Device::where('status', 'connected')->count(),
                'Sync Events' => SyncEvent::count(),
                'Events Past Retention' => SyncEvent::where(function ($q) use ($v) {
                    $cutoff = now()->subDays((int) $v['event_days']);
                    $q->where('occurred_at', '<', $cutoff)
                        ->orWhere(function ($q2) use ($cutoff) {
                            $q2->whereNull('occurred_at')->where('created_at', '<', $cutoff);
                        });
                })->count(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'maintenance_window_start' => ['required', 'date_format:H:i'],
            'maintenance_window_end' => ['required', 'date_format:H:i'],
            'maintenance_days' => ['nullable', 'array'],
            'maintenance_days.*' => [Rule::in(self::DAYS)],
            'event_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'device_offline_minutes' => ['required', 'integer', 'min:1', 'max:100000'],
            'audit_log_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        foreach (['auto_maintenance', 'maintenance_window_enabled', 'prune_old_events', 'mark_stale_devices'] as $t) {
            Setting::put($t, $request->boolean($t) ? '1' : '0');
        }

        Setting::put('maintenance_window_start', $data['maintenance_window_start']);
        Setting::put('maintenance_window_end', $data['maintenance_window_end']);
        Setting::put('maintenance_days', implode(',', $data['maintenance_days'] ?? []));
        Setting::put('event_days', (string) $data['event_days']);
        Setting::put('device_offline_minutes', (string) $data['device_offline_minutes']);
        Setting::put('audit_log_days', (string) $data['audit_log_days']);

        AuditLog::record('updated', 'Maintenance settings updated');

        return back()->with('status', 'Maintenance settings saved.');
    }

    public function runNow()
    {
        $c = static::runSweep();
        AuditLog::record('maintenance', "Manual maintenance: {$c['events_pruned']} events pruned, {$c['devices_marked']} devices marked offline, {$c['audit_pruned']} audit rows pruned");

        return back()->with('status', "Maintenance ran: {$c['events_pruned']} event(s) pruned, {$c['devices_marked']} device(s) marked offline, {$c['audit_pruned']} audit row(s) pruned.");
    }
}
