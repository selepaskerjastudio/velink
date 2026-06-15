// Package protocol mirrors the gateway wire format. Keep in sync with
// gateway/internal/protocol/protocol.go (Go internal packages can't be shared
// across modules, so this is intentionally duplicated).
package protocol

import (
	"encoding/json"
	"time"
)

// Envelope is the single message exchanged over the agent WebSocket.
type Envelope struct {
	Type      string          `json:"type"`
	JobID     string          `json:"job_id,omitempty"`
	ServerID  string          `json:"server_id,omitempty"`
	Payload   json.RawMessage `json:"payload,omitempty"`
	Timestamp int64           `json:"ts,omitempty"`
}

// Message types.
const (
	TypeHello     = "hello"
	TypeHeartbeat = "heartbeat"
	TypeJob       = "job"
	TypeJobOutput = "job_output"
	TypeJobResult = "job_result"
	TypeError     = "error"
)

// Now returns the current time in unix milliseconds.
func Now() int64 {
	return time.Now().UnixMilli()
}
