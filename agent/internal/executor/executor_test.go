package executor

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"github.com/velink/agent/internal/protocol"
)

// collector gathers emitted envelopes in a goroutine-safe way.
type collector struct {
	mu      sync.Mutex
	outputs []string
	result  *protocol.Envelope
}

func (c *collector) emit(env protocol.Envelope) {
	c.mu.Lock()
	defer c.mu.Unlock()
	switch env.Type {
	case protocol.TypeJobOutput:
		var p map[string]string
		_ = json.Unmarshal(env.Payload, &p)
		c.outputs = append(c.outputs, p["data"])
	case protocol.TypeJobResult:
		e := env
		c.result = &e
	}
}

func (c *collector) outputString() string {
	c.mu.Lock()
	defer c.mu.Unlock()
	return strings.Join(c.outputs, "")
}

func (c *collector) exitCode(t *testing.T) int {
	t.Helper()
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.result == nil {
		t.Fatal("no result envelope emitted")
	}
	var body struct {
		ExitCode int    `json:"exit_code"`
		Error    string `json:"error"`
	}
	if err := json.Unmarshal(c.result.Payload, &body); err != nil {
		t.Fatalf("decode result: %v", err)
	}
	return body.ExitCode
}

func run(t *testing.T, action string, params any) *collector {
	t.Helper()
	raw, _ := json.Marshal(params)
	c := &collector{}
	New("11111111-1111-1111-1111-111111111111").Run(context.Background(), "job-1", JobSpec{Action: action, Params: raw}, c.emit)
	return c
}

func TestShellSuccess(t *testing.T) {
	c := run(t, ActionShell, map[string]any{"command": "echo hello"})
	if got := c.exitCode(t); got != 0 {
		t.Fatalf("exit code = %d, want 0", got)
	}
	if !strings.Contains(c.outputString(), "hello") {
		t.Fatalf("output %q does not contain hello", c.outputString())
	}
}

func TestShellNonZeroExit(t *testing.T) {
	c := run(t, ActionShell, map[string]any{"command": "exit 3"})
	if got := c.exitCode(t); got != 3 {
		t.Fatalf("exit code = %d, want 3", got)
	}
}

func TestShellStderrCaptured(t *testing.T) {
	c := run(t, ActionShell, map[string]any{"command": "echo oops 1>&2"})
	if !strings.Contains(c.outputString(), "oops") {
		t.Fatalf("stderr not captured: %q", c.outputString())
	}
}

func TestShellEmptyCommand(t *testing.T) {
	c := run(t, ActionShell, map[string]any{"command": ""})
	if got := c.exitCode(t); got != 1 {
		t.Fatalf("exit code = %d, want 1", got)
	}
}

func TestWriteFile(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "sub", "out.txt")
	c := run(t, ActionWriteFile, map[string]any{"path": path, "content": "data-123", "mode": "0600"})
	if got := c.exitCode(t); got != 0 {
		t.Fatalf("exit code = %d, want 0", got)
	}
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read back: %v", err)
	}
	if string(b) != "data-123" {
		t.Fatalf("content = %q", string(b))
	}
	info, _ := os.Stat(path)
	if info.Mode().Perm() != 0o600 {
		t.Fatalf("mode = %v, want 0600", info.Mode().Perm())
	}
}

func TestRenderConfig(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "nginx.conf")
	c := run(t, ActionRenderConfig, map[string]any{
		"path":     path,
		"template": "server_name {{.domain}}; root {{.root}};",
		"vars":     map[string]any{"domain": "example.test", "root": "/var/www/app"},
	})
	if got := c.exitCode(t); got != 0 {
		t.Fatalf("exit code = %d, want 0", got)
	}
	b, _ := os.ReadFile(path)
	want := "server_name example.test; root /var/www/app;"
	if string(b) != want {
		t.Fatalf("rendered = %q, want %q", string(b), want)
	}
}

func TestRenderConfigMissingVarFails(t *testing.T) {
	dir := t.TempDir()
	c := run(t, ActionRenderConfig, map[string]any{
		"path":     filepath.Join(dir, "x.conf"),
		"template": "{{.missing}}",
		"vars":     map[string]any{},
	})
	if got := c.exitCode(t); got == 0 {
		t.Fatalf("expected non-zero exit for missing template var")
	}
}

func TestUnknownAction(t *testing.T) {
	c := run(t, "frobnicate", map[string]any{})
	if got := c.exitCode(t); got != 1 {
		t.Fatalf("exit code = %d, want 1", got)
	}
}
