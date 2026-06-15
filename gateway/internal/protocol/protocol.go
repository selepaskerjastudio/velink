// Package protocol defines the wire format shared between the agent, the
// gateway, and the Laravel panel. The same envelope travels over the agent
// WebSocket connection and across the Redis pub/sub channels that bridge the
// gateway to the panel.
package protocol

import (
	"encoding/json"
	"fmt"
)

// Envelope is the single message type exchanged in every direction. The actual
// command/result semantics live in Payload and are defined per Type in Fase 1.
type Envelope struct {
	// Type discriminates the message; see the Type* constants below.
	Type string `json:"type"`
	// JobID correlates a job dispatch with its output/result stream.
	JobID string `json:"job_id,omitempty"`
	// ServerID identifies the managed server a message is routed to/from.
	ServerID string `json:"server_id,omitempty"`
	// Payload is the type-specific body, decoded by the receiver.
	Payload json.RawMessage `json:"payload,omitempty"`
	// Timestamp is unix milliseconds, set by the sender.
	Timestamp int64 `json:"ts,omitempty"`
}

// Message types carried in Envelope.Type.
const (
	// TypeHello is the first message an agent may send after connecting.
	TypeHello = "hello"
	// TypeHeartbeat keeps presence warm; refreshes the Redis TTL.
	TypeHeartbeat = "heartbeat"
	// TypeJob is a unit of work dispatched panel -> agent.
	TypeJob = "job"
	// TypeJobOutput streams stdout/stderr chunks agent -> panel.
	TypeJobOutput = "job_output"
	// TypeJobResult reports terminal status (exit code) agent -> panel.
	TypeJobResult = "job_result"
	// TypeError reports a transport/protocol level error.
	TypeError = "error"
)

// Presence status values.
const (
	StatusOnline  = "online"
	StatusOffline = "offline"
)

// Redis channel names. The panel publishes dispatch envelopes and subscribes to
// inbound + presence; the gateway does the reverse.
const (
	// ChannelDispatch carries panel -> agent envelopes (routed by ServerID).
	ChannelDispatch = "coruncloud:gateway:dispatch"
	// ChannelInbound carries agent -> panel envelopes.
	ChannelInbound = "coruncloud:gateway:inbound"
	// ChannelPresence carries online/offline transitions.
	ChannelPresence = "coruncloud:gateway:presence"
)

// PresenceKey is the Redis key holding a server's live presence (with TTL).
func PresenceKey(serverID string) string {
	return fmt.Sprintf("coruncloud:presence:server:%s", serverID)
}

// PresenceEvent is published to ChannelPresence on every transition.
type PresenceEvent struct {
	ServerID     string `json:"server_id"`
	Status       string `json:"status"`
	AgentVersion string `json:"agent_version,omitempty"`
	Timestamp    int64  `json:"ts"`
}
