// Package terminal manages browser-to-agent terminal session relays in the
// gateway. When a browser opens /terminal/connect, the gateway creates a relay
// that proxies bytes between the browser WebSocket and the agent's existing
// WebSocket connection (via the hub dispatch mechanism).
package terminal

import (
	"sync"

	"github.com/velink/gateway/internal/protocol"
)

// Relay connects one browser terminal session to its target agent.
type Relay struct {
	SessionID string
	ServerID  string
	// AgentOut delivers envelopes from the agent (terminal_data, terminal_exited)
	// to the browser-WS writer goroutine.
	AgentOut chan protocol.Envelope
}

// Manager tracks all active terminal relays, keyed by session ID.
type Manager struct {
	mu      sync.RWMutex
	relays  map[string]*Relay
}

// New creates a Manager.
func New() *Manager {
	return &Manager{relays: make(map[string]*Relay)}
}

// Register creates and stores a relay for a new terminal session.
func (m *Manager) Register(sessionID, serverID string) *Relay {
	r := &Relay{
		SessionID: sessionID,
		ServerID:  serverID,
		AgentOut:  make(chan protocol.Envelope, 256),
	}

	m.mu.Lock()
	m.relays[sessionID] = r
	m.mu.Unlock()

	return r
}

// Get retrieves a relay by session ID.
func (m *Manager) Get(sessionID string) (*Relay, bool) {
	m.mu.RLock()
	defer m.mu.RUnlock()
	r, ok := m.relays[sessionID]
	return r, ok
}

// Unregister removes a relay and closes its channel.
func (m *Manager) Unregister(sessionID string) {
	m.mu.Lock()
	r, ok := m.relays[sessionID]
	if ok {
		delete(m.relays, sessionID)
	}
	m.mu.Unlock()

	if ok {
		close(r.AgentOut)
	}
}

// IsTerminalType returns true if the envelope type is a terminal message
// that should be routed to a relay rather than published to Redis.
func IsTerminalType(t string) bool {
	switch t {
	case protocol.TypeTerminalData, protocol.TypeTerminalExited:
		return true
	}
	return false
}
