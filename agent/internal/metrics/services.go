package metrics

import (
	"bufio"
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
)

// ServiceUsage is the per-systemd-unit resource snapshot the agent reports to
// the panel alongside each metrics cycle.
type ServiceUsage struct {
	Name        string  `json:"name"`
	CPUPercent  float64 `json:"cpu_percent"`
	MemoryUsage uint64  `json:"memory_usage"` // resident set size, in bytes
}

// wellKnownServices is the set of systemd units the agent reports resource
// usage for. Kept in sync with the panel's ServiceManager::WELL_KNOWN_SERVICES.
var wellKnownServices = []string{
	"nginx", "supervisor", "redis-server", "mariadb", "postgresql", "mongod",
}

// cpuClock holds the previous CPU accounting sample for a service, used to
// compute a delta-based percentage across collection cycles.
type cpuClock struct {
	usageUsec uint64 // microseconds of CPU consumed (cgroup)
	timestamp uint64 // monotonic microseconds at sample time
	memUsage  uint64 // last known RSS, carried forward on miss
	pid       int    // last known MainPID
}

var (
	clockMu sync.Mutex
	clocks  = map[string]*cpuClock{}

	// Injectable seams so tests can drive sampleService without /proc or
	// systemctl. Defaults point at the real readers.
	mainPIDReader      = realMainPID
	cgroupCPUReader    = realCgroupCPUUsec
	residentReader     = realResidentBytes
	monotonicMicroNow  = func() uint64 { return 0 }
)

// CollectServices reports per-service CPU% and resident memory for the
// well-known systemd units. Failures for individual units are swallowed (the
// service is simply omitted) so a missing unit never breaks the whole snapshot.
//
// On non-Linux hosts (where /proc and cgroups are absent) it returns nil.
func CollectServices(ctx context.Context) []ServiceUsage {
	if _, err := os.Stat("/proc"); err != nil {
		return nil
	}

	out := make([]ServiceUsage, 0, len(wellKnownServices))
	for _, name := range wellKnownServices {
		select {
		case <-ctx.Done():
			return out
		default:
		}
		if usage, ok := sampleService(name); ok {
			out = append(out, usage)
		}
	}
	return out
}

// sampleService reads a single unit's MainPID and derives its CPU% and RSS.
// Returns ok=false when the unit is not running or its data is unavailable.
func sampleService(name string) (ServiceUsage, bool) {
	pid, err := mainPIDReader(name)
	if err != nil || pid <= 0 {
		return ServiceUsage{}, false
	}

	rss := residentReader(pid)

	clockMu.Lock()
	defer clockMu.Unlock()

	prev := clocks[name]
	now := monotonicMicroNow()
	usageUsec, _ := cgroupCPUReader(name)

	var cpuPct float64
	if prev != nil && now > prev.timestamp && usageUsec >= prev.usageUsec {
		elapsed := now - prev.timestamp
		delta := usageUsec - prev.usageUsec
		// delta microseconds of CPU over elapsed microseconds of wall time,
		// scaled to a percentage. (100% = one core.)
		cpuPct = round2(float64(delta) / float64(elapsed) * 100)
	}

	clocks[name] = &cpuClock{
		usageUsec: usageUsec,
		timestamp: now,
		memUsage:  rss,
		pid:       pid,
	}

	mem := rss
	if mem == 0 && prev != nil {
		mem = prev.memUsage // carry forward if /proc read raced on a dying pid
	}

	return ServiceUsage{
		Name:        name,
		CPUPercent:  cpuPct,
		MemoryUsage: mem,
	}, mem > 0 || cpuPct > 0
}

// --- real readers (production) ---

func realMainPID(unit string) (int, error) {
	out, err := exec.Command("systemctl", "show", unit, "--property=MainPID").Output()
	if err != nil {
		return 0, err
	}
	return parseMainPID(string(out)), nil
}

func realResidentBytes(pid int) uint64 {
	b, err := os.ReadFile(filepath.Join("/proc", strconv.Itoa(pid), "status"))
	if err != nil {
		return 0
	}
	return parseVmRSS(string(b))
}

func realCgroupCPUUsec(unit string) (uint64, bool) {
	slice, ok := unitSlicePath(unit)
	if !ok {
		return 0, false
	}
	f, err := os.Open(filepath.Join(slice, "cpu.stat"))
	if err != nil {
		return 0, false
	}
	defer f.Close()

	s := bufio.NewScanner(f)
	for s.Scan() {
		if strings.HasPrefix(s.Text(), "usage_usec ") {
			fields := strings.Fields(s.Text())
			if len(fields) == 2 {
				if usec, err := strconv.ParseUint(fields[1], 10, 64); err == nil {
					return usec, true
				}
			}
			return 0, false
		}
	}
	return 0, false
}

// --- pure parsers (testable) ---

// parseMainPID pulls the MainPID property out of `systemctl show` output.
func parseMainPID(systemctlShow string) int {
	for _, line := range strings.Split(systemctlShow, "\n") {
		line = strings.TrimSpace(line)
		if strings.HasPrefix(line, "MainPID=") {
			pid, _ := strconv.Atoi(strings.TrimPrefix(line, "MainPID="))
			return pid
		}
	}
	return 0
}

// parseVmRSS extracts the VmRSS value (kB) from a /proc/{pid}/status blob and
// returns it as bytes.
func parseVmRSS(procStatus string) uint64 {
	for _, line := range strings.Split(procStatus, "\n") {
		if strings.HasPrefix(line, "VmRSS:") {
			fields := strings.Fields(line)
			// Expected: ["VmRSS:", "12345", "kB"]
			if len(fields) >= 2 {
				if kb, err := strconv.ParseUint(fields[1], 10, 64); err == nil {
					return kb * 1024
				}
			}
			return 0
		}
	}
	return 0
}

// unitSlicePath maps a service name to its cgroup v2 slice path. Only the
// common velink-managed units are mapped; anything else yields ok=false.
func unitSlicePath(unit string) (string, bool) {
	switch unit {
	case "nginx":
		return "/sys/fs/cgroup/system.slice/nginx.service", true
	case "supervisor":
		return "/sys/fs/cgroup/system.slice/supervisor.service", true
	case "redis-server":
		return "/sys/fs/cgroup/system.slice/redis-server.service", true
	case "mariadb":
		return "/sys/fs/cgroup/system.slice/mariadb.service", true
	case "postgresql":
		return "/sys/fs/cgroup/system.slice/postgresql.service", true
	case "mongod":
		return "/sys/fs/cgroup/system.slice/mongod.service", true
	}
	return "", false
}

func round2(v float64) float64 {
	return float64(int(v*100+0.5)) / 100
}
