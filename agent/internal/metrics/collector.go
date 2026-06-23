// Package metrics gathers point-in-time system resource snapshots.
package metrics

import (
	"context"
	"math"

	"github.com/shirou/gopsutil/v4/cpu"
	"github.com/shirou/gopsutil/v4/disk"
	"github.com/shirou/gopsutil/v4/host"
	"github.com/shirou/gopsutil/v4/load"
	"github.com/shirou/gopsutil/v4/mem"
)

// Snapshot holds a single point-in-time reading of system resources.
type Snapshot struct {
	CpuPercent    float64       `json:"cpu_percent"`
	MemTotal      uint64        `json:"mem_total"`
	MemUsed       uint64        `json:"mem_used"`
	DiskTotal     uint64        `json:"disk_total"`
	DiskUsed      uint64        `json:"disk_used"`
	Load1         float64       `json:"load1"`
	UptimeSeconds uint64        `json:"uptime_seconds"`
	Services      []ServiceUsage `json:"services,omitempty"`
}

// Collect gathers a point-in-time snapshot. ctx is used for cancellation.
// interval=0 means an instantaneous (non-blocking) CPU sample.
func Collect(ctx context.Context) (Snapshot, error) {
	// CPU: interval=0 returns an instantaneous reading (since-boot average on
	// Linux, or per-call delta on macOS). false = average across all CPUs.
	percents, err := cpu.PercentWithContext(ctx, 0, false)
	if err != nil {
		return Snapshot{}, err
	}
	cpuPct := 0.0
	if len(percents) > 0 {
		cpuPct = math.Round(percents[0]*100) / 100
	}

	vmStat, err := mem.VirtualMemoryWithContext(ctx)
	if err != nil {
		return Snapshot{}, err
	}

	diskStat, err := disk.UsageWithContext(ctx, "/")
	if err != nil {
		return Snapshot{}, err
	}

	avgStat, err := load.AvgWithContext(ctx)
	if err != nil {
		return Snapshot{}, err
	}

	uptimeSec, err := host.UptimeWithContext(ctx)
	if err != nil {
		uptimeSec = 0
	}

	// Per-service CPU/memory is best-effort: a nil result (non-Linux hosts,
	// no systemd) just omits the field from the snapshot.
	services := CollectServices(ctx)

	return Snapshot{
		CpuPercent:    cpuPct,
		MemTotal:      vmStat.Total,
		MemUsed:       vmStat.Used,
		DiskTotal:     diskStat.Total,
		DiskUsed:      diskStat.Used,
		Load1:         math.Round(avgStat.Load1*100) / 100,
		UptimeSeconds: uptimeSec,
		Services:      services,
	}, nil
}
