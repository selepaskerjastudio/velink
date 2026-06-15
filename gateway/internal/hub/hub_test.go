package hub

import (
	"testing"

	"github.com/coruncloud/gateway/internal/protocol"
)

func TestReconnectReturnsPrevious(t *testing.T) {
	h := New()
	a := NewConn(1, "v1")
	b := NewConn(1, "v2")

	if prev := h.Add(a); prev != nil {
		t.Fatalf("expected nil previous on first add, got %v", prev)
	}
	prev := h.Add(b)
	if prev != a {
		t.Fatalf("expected previous connection to be returned on reconnect")
	}
	if h.Count() != 1 {
		t.Fatalf("expected 1 live connection, got %d", h.Count())
	}
}

func TestRemoveOnlyEvictsActive(t *testing.T) {
	h := New()
	a := NewConn(1, "v1")
	b := NewConn(1, "v2")
	h.Add(a)
	h.Add(b) // b is now active

	h.Remove(a) // stale connection must not evict b
	if _, ok := h.Get(1); !ok {
		t.Fatalf("active connection was wrongly evicted by a stale Remove")
	}

	h.Remove(b)
	if _, ok := h.Get(1); ok {
		t.Fatalf("expected connection removed")
	}
}

func TestDispatchToOfflineServer(t *testing.T) {
	h := New()
	if h.Dispatch(protocol.Envelope{ServerID: 99, Type: protocol.TypeJob}) {
		t.Fatalf("dispatch to offline server should fail")
	}

	c := NewConn(7, "v1")
	h.Add(c)
	if !h.Dispatch(protocol.Envelope{ServerID: 7, Type: protocol.TypeJob}) {
		t.Fatalf("dispatch to online server should succeed")
	}
	select {
	case env := <-c.Send:
		if env.ServerID != 7 {
			t.Fatalf("wrong envelope routed")
		}
	default:
		t.Fatalf("expected envelope queued on send channel")
	}
}
