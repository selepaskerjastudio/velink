package metrics

import (
	"context"
	"testing"
)

// resetClocks clears the package-level delta state so tests are isolated.
func resetClocks(t *testing.T) {
	t.Helper()
	clockMu.Lock()
	clocks = map[string]*cpuClock{}
	clockMu.Unlock()
}

func TestParseMainPID(t *testing.T) {
	tests := []struct {
		name  string
		input string
		want  int
	}{
		{"with MainPID", "MainPID=12345\n", 12345},
		{"MainPID zero (stopped unit)", "MainPID=0\n", 0},
		{"multiline systemctl output", "Names=nginx.service\nMainPID=8372\n", 8372},
		{"missing property", "Names=nginx.service\n", 0},
		{"empty input", "", 0},
		{"invalid number", "MainPID=abc\n", 0},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := parseMainPID(tt.input); got != tt.want {
				t.Errorf("parseMainPID(%q) = %d, want %d", tt.input, got, tt.want)
			}
		})
	}
}

func TestParseVmRSS(t *testing.T) {
	procStatus := "Name: nginx\nUmask: 0022\nState: S (sleeping)\n" +
		"VmRSS:     12345 kB\nRssAnon:   10000 kB\n"
	if got := parseVmRSS(procStatus); got != 12345*1024 {
		t.Errorf("parseVmRSS = %d, want %d", got, 12345*1024)
	}
	if got := parseVmRSS("Name: foo\nState: R\n"); got != 0 {
		t.Errorf("parseVmRSS missing VmRSS = %d, want 0", got)
	}
}

func TestUnitSlicePath(t *testing.T) {
	want := "/sys/fs/cgroup/system.slice/nginx.service"
	if got, ok := unitSlicePath("nginx"); !ok || got != want {
		t.Errorf("unitSlicePath(nginx) = %q, %v, want %q, true", got, ok, want)
	}
	if _, ok := unitSlicePath("unknown-unit"); ok {
		t.Errorf("unitSlicePath(unknown-unit) should be ok=false")
	}
}

// TestSampleServiceCPUHeat drives sampleService with injected seams to confirm
// the delta math for CPU% across two ticks, without touching /proc or systemctl.
func TestSampleServiceCPUHeat(t *testing.T) {
	resetClocks(t)

	// Capture original seams and restore them on exit.
	origPID, origCgroup, origRSS, origMono := mainPIDReader, cgroupCPUReader, residentReader, monotonicMicroNow
	t.Cleanup(func() {
		mainPIDReader, cgroupCPUReader, residentReader, monotonicMicroNow = origPID, origCgroup, origRSS, origMono
	})

	mainPIDReader = func(string) (int, error) { return 1000, nil }
	residentReader = func(int) uint64 { return 512 * 1024 }

	// Two monotonic ticks 1s (1_000_000 µs) apart, with 50_000 µs (5%) of CPU
	// consumed in the gap → 5% of one core.
	tick := uint64(0)
	monotonicMicroNow = func() uint64 { return tick }

	cpuNow := uint64(0)
	cgroupCPUReader = func(string) (uint64, bool) { return cpuNow, true }

	// First sample: no prior, so CPU% should be 0 but memory reported.
	if _, ok := sampleService("nginx"); !ok {
		t.Fatalf("first sampleService returned ok=false")
	}

	// Advance: +1s wall, +50ms CPU.
	tick = 1_000_000
	cpuNow = 50_000

	usage, ok := sampleService("nginx")
	if !ok {
		t.Fatalf("second sampleService returned ok=false")
	}
	if usage.CPUPercent != 5.0 {
		t.Errorf("CPUPercent = %v, want 5.0", usage.CPUPercent)
	}
	if usage.MemoryUsage != 512*1024 {
		t.Errorf("MemoryUsage = %v, want %v", usage.MemoryUsage, 512*1024)
	}
}

// TestSampleServiceStoppedUnit confirms a unit without a MainPID is skipped.
func TestSampleServiceStoppedUnit(t *testing.T) {
	resetClocks(t)
	origPID := mainPIDReader
	t.Cleanup(func() { mainPIDReader = origPID })

	mainPIDReader = func(string) (int, error) { return 0, nil }

	if _, ok := sampleService("nginx"); ok {
		t.Errorf("sampleService with MainPID=0 should return ok=false")
	}
}

// TestCollectServicesGuardsNonLinux confirms CollectServices never panics on
// a host without /proc (e.g. macOS dev) — it just returns nil.
func TestCollectServicesGuardsNonLinux(t *testing.T) {
	_ = CollectServices(context.Background())
}
