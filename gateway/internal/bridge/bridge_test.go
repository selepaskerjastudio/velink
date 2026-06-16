package bridge

import (
	"context"
	"io"
	"log/slog"
	"testing"
	"time"

	"github.com/redis/go-redis/v9"
	"github.com/velink/gateway/internal/hub"
)

func quietLogger() *slog.Logger {
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}

// TestRouteDeliversToConnectedAgent verifies a well-formed dispatch envelope is
// routed to the matching agent connection.
func TestRouteDeliversToConnectedAgent(t *testing.T) {
	h := hub.New()
	conn := hub.NewConn("11111111-1111-1111-1111-111111111111", "v1")
	h.Add(conn)

	b := New(nil, h, quietLogger())
	b.route(`{"type":"job","server_id":"11111111-1111-1111-1111-111111111111","job_id":"j1"}`)

	select {
	case env := <-conn.Send:
		if env.JobID != "j1" {
			t.Fatalf("wrong envelope routed: %+v", env)
		}
	default:
		t.Fatalf("expected envelope queued on send channel")
	}
}

// TestRouteDropsMalformedPayload ensures a bad payload does not panic and does
// not enqueue anything.
func TestRouteDropsMalformedPayload(t *testing.T) {
	h := hub.New()
	b := New(nil, h, quietLogger())
	// Must not panic.
	b.route("{not json")
}

// TestRunSelfHealsAgainstDeadRedis is the core regression test: pointed at a
// Redis that is not listening, Run must NOT return (it should keep
// re-subscribing with backoff) until the context is cancelled. Before the fix,
// a failed subscription handshake caused Run to return immediately, which in
// turn shut the whole gateway down and permanently killed dispatch delivery.
func TestRunSelfHealsAgainstDeadRedis(t *testing.T) {
	// Port 1 is reserved and never has a listener, so every Subscribe fails fast.
	rdb := redis.NewClient(&redis.Options{
		Addr:        "127.0.0.1:1",
		DialTimeout: 100 * time.Millisecond,
		MaxRetries:  -1, // fail fast so we cycle the backoff loop quickly
	})
	defer rdb.Close()

	b := New(rdb, hub.New(), quietLogger())

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- b.Run(ctx) }()

	// Run must still be looping (re-subscribing) after the connection failures.
	select {
	case err := <-done:
		t.Fatalf("Run returned before ctx cancel (did not self-heal): %v", err)
	case <-time.After(600 * time.Millisecond):
		// Good: still retrying.
	}

	cancel()

	select {
	case err := <-done:
		if err != context.Canceled {
			t.Fatalf("expected context.Canceled after cancel, got %v", err)
		}
	case <-time.After(2 * time.Second):
		t.Fatalf("Run did not return after ctx cancel")
	}
}

// TestRunReturnsImmediatelyOnCancelledContext guards the fast-path: a
// pre-cancelled context must make Run return without blocking.
func TestRunReturnsImmediatelyOnCancelledContext(t *testing.T) {
	rdb := redis.NewClient(&redis.Options{Addr: "127.0.0.1:1", DialTimeout: 100 * time.Millisecond})
	defer rdb.Close()

	b := New(rdb, hub.New(), quietLogger())

	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	done := make(chan error, 1)
	go func() { done <- b.Run(ctx) }()

	select {
	case err := <-done:
		if err != context.Canceled {
			t.Fatalf("expected context.Canceled, got %v", err)
		}
	case <-time.After(time.Second):
		t.Fatalf("Run did not return promptly on a cancelled context")
	}
}
