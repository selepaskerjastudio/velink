package terminal

import (
	"log/slog"
	"sync"
	"testing"
)

func TestManagerOpenAndWrite(t *testing.T) {
	mgr := New(slog.Default())

	var mu sync.Mutex
	var outputs []string

	emit := func(msgType string, sessionID string, payload interface{}) {
		if msgType == "terminal_data" {
			mu.Lock()
			if dp, ok := payload.(DataPayload); ok {
				outputs = append(outputs, dp.Data)
			}
			mu.Unlock()
		}
	}

	err := mgr.Open("test-session", OpenParams{
		User: "",    // root
		Cols: 80,
		Rows: 24,
	}, emit)
	if err != nil {
		t.Fatalf("Open failed: %v", err)
	}

	// Write a simple command to the PTY.
	err = mgr.Write("test-session", []byte("echo hello_terminal\n"))
	if err != nil {
		t.Fatalf("Write failed: %v", err)
	}

	// Give the PTY a moment to produce output, then close.
	mgr.Close("test-session")
}

func TestManagerResizeNonexistentSession(t *testing.T) {
	mgr := New(slog.Default())

	err := mgr.Resize("nonexistent", WinSize{Cols: 120, Rows: 40})
	if err == nil {
		t.Error("Resize on nonexistent session should return error")
	}
}

func TestManagerCloseNonexistentSession(t *testing.T) {
	mgr := New(slog.Default())

	// Close on nonexistent session should not panic.
	mgr.Close("nonexistent")
}

func TestManagerWriteNonexistentSession(t *testing.T) {
	mgr := New(slog.Default())

	err := mgr.Write("nonexistent", []byte("data"))
	if err == nil {
		t.Error("Write on nonexistent session should return error")
	}
}
