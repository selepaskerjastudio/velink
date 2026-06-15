// Package hub keeps the registry of live agent connections and routes dispatch
// envelopes to the correct one.
package hub

import (
	"sync"

	"github.com/coruncloud/gateway/internal/protocol"
)

// Conn is one live agent connection. Writes go through the buffered Send channel
// so a single writer goroutine owns the underlying socket (concurrent writes to
// a WebSocket are not allowed).
type Conn struct {
	ServerID     string
	AgentVersion string
	Send         chan protocol.Envelope

	closeOnce sync.Once
	closed    chan struct{}
}

// NewConn builds a connection with a buffered send queue.
func NewConn(serverID string, agentVersion string) *Conn {
	return &Conn{
		ServerID:     serverID,
		AgentVersion: agentVersion,
		Send:         make(chan protocol.Envelope, 64),
		closed:       make(chan struct{}),
	}
}

// Close signals the writer/reader pumps to stop. Safe to call multiple times.
func (c *Conn) Close() {
	c.closeOnce.Do(func() { close(c.closed) })
}

// Closed returns a channel that is closed when the connection is shutting down.
func (c *Conn) Closed() <-chan struct{} { return c.closed }

// Hub is a concurrency-safe registry keyed by server ID.
type Hub struct {
	mu    sync.RWMutex
	conns map[string]*Conn
}

// New builds an empty Hub.
func New() *Hub {
	return &Hub{conns: make(map[string]*Conn)}
}

// Add registers a connection, returning any previous connection for the same
// server (a reconnect) so the caller can close it.
func (h *Hub) Add(c *Conn) *Conn {
	h.mu.Lock()
	defer h.mu.Unlock()
	prev := h.conns[c.ServerID]
	h.conns[c.ServerID] = c
	return prev
}

// Remove deregisters a connection only if it is still the active one (guards
// against a stale connection evicting its successor after a reconnect).
func (h *Hub) Remove(c *Conn) {
	h.mu.Lock()
	defer h.mu.Unlock()
	if cur, ok := h.conns[c.ServerID]; ok && cur == c {
		delete(h.conns, c.ServerID)
	}
}

// Get returns the live connection for a server, if any.
func (h *Hub) Get(serverID string) (*Conn, bool) {
	h.mu.RLock()
	defer h.mu.RUnlock()
	c, ok := h.conns[serverID]
	return c, ok
}

// Dispatch enqueues an envelope for delivery to the target server. It returns
// false if the server is not connected or its send queue is full.
func (h *Hub) Dispatch(env protocol.Envelope) bool {
	c, ok := h.Get(env.ServerID)
	if !ok {
		return false
	}
	select {
	case c.Send <- env:
		return true
	default:
		return false
	}
}

// Count returns the number of live connections.
func (h *Hub) Count() int {
	h.mu.RLock()
	defer h.mu.RUnlock()
	return len(h.conns)
}
