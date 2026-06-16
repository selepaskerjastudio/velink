<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerAlert;

class ThresholdChecker
{
    /** Default thresholds — percentage (0-100). */
    private const THRESHOLDS = [
        'cpu'     => 90.0,
        'memory'  => 90.0,
        'disk'    => 90.0,
    ];

    /** Cooldown in minutes — don't re-alert for the same metric within this window. */
    private const COOLDOWN_MINUTES = 15;

    /**
     * Check server metrics against thresholds.
     * Creates new alerts for exceeded thresholds, resolves alerts when metrics return to normal.
     */
    public function check(Server $server, array $metrics): void
    {
        $checks = [
            'cpu' => [
                'value' => (float) ($metrics['cpu_percent'] ?? 0),
                'label' => 'CPU usage',
                'unit'  => '%',
            ],
            'memory' => [
                'value' => ($metrics['mem_total'] ?? 0) > 0
                    ? ((float) ($metrics['mem_used'] ?? 0) / (float) ($metrics['mem_total'] ?? 1)) * 100
                    : 0,
                'label' => 'Memory usage',
                'unit'  => '%',
            ],
            'disk' => [
                'value' => ($metrics['disk_total'] ?? 0) > 0
                    ? ((float) ($metrics['disk_used'] ?? 0) / (float) ($metrics['disk_total'] ?? 1)) * 100
                    : 0,
                'label' => 'Disk usage',
                'unit'  => '%',
            ],
        ];

        foreach ($checks as $type => $check) {
            $threshold = self::THRESHOLDS[$type];
            $activeAlert = ServerAlert::where('server_id', $server->id)
                ->where('metric_type', $type)
                ->active()
                ->latest()
                ->first();

            if ($check['value'] >= $threshold) {
                // Metric exceeds threshold
                if ($activeAlert && $activeAlert->created_at->diffInMinutes(now()) < self::COOLDOWN_MINUTES) {
                    // Cooldown active — update value but don't create new alert
                    $activeAlert->update(['value' => $check['value']]);
                    continue;
                }

                // Resolve old alert if exists, then create new one
                if ($activeAlert) {
                    $activeAlert->update(['resolved_at' => now()]);
                }

                ServerAlert::create([
                    'server_id' => $server->id,
                    'metric_type' => $type,
                    'value' => $check['value'],
                    'threshold' => $threshold,
                    'message' => "{$check['label']} at {$check['value']}{$check['unit']} (threshold: {$threshold}{$check['unit']})",
                ]);
            } elseif ($activeAlert) {
                // Metric back to normal — resolve the alert
                $activeAlert->update(['resolved_at' => now()]);
            }
        }
    }
}
