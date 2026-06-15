// Package presence tracks which agents are connected, in Redis, so the panel
// can read live online/offline status and react to transitions.
package presence

import (
	"context"
	"encoding/json"
	"time"

	"github.com/coruncloud/gateway/internal/protocol"
	"github.com/redis/go-redis/v9"
)

// Tracker writes presence keys (with TTL) and publishes transitions.
type Tracker struct {
	rdb *redis.Client
	ttl time.Duration
}

// New builds a Tracker.
func New(rdb *redis.Client, ttl time.Duration) *Tracker {
	return &Tracker{rdb: rdb, ttl: ttl}
}

// Online marks a server online and (re)sets its TTL. Called on connect and on
// every heartbeat. It publishes a transition event only on the first call by
// using SET NX semantics through a published flag from the caller is avoided —
// here we always refresh the key and publish; the panel treats repeats as idempotent.
func (t *Tracker) Online(ctx context.Context, serverID string, agentVersion string) error {
	ev := protocol.PresenceEvent{
		ServerID:     serverID,
		Status:       protocol.StatusOnline,
		AgentVersion: agentVersion,
		Timestamp:    time.Now().UnixMilli(),
	}
	payload, err := json.Marshal(ev)
	if err != nil {
		return err
	}
	if err := t.rdb.Set(ctx, protocol.PresenceKey(serverID), string(payload), t.ttl).Err(); err != nil {
		return err
	}
	return t.rdb.Publish(ctx, protocol.ChannelPresence, string(payload)).Err()
}

// Refresh extends the TTL without re-publishing a transition (heartbeat path).
func (t *Tracker) Refresh(ctx context.Context, serverID string) error {
	return t.rdb.Expire(ctx, protocol.PresenceKey(serverID), t.ttl).Err()
}

// Offline clears presence and publishes the transition (called on disconnect).
func (t *Tracker) Offline(ctx context.Context, serverID string) error {
	if err := t.rdb.Del(ctx, protocol.PresenceKey(serverID)).Err(); err != nil {
		return err
	}
	ev := protocol.PresenceEvent{
		ServerID:  serverID,
		Status:    protocol.StatusOffline,
		Timestamp: time.Now().UnixMilli(),
	}
	payload, err := json.Marshal(ev)
	if err != nil {
		return err
	}
	return t.rdb.Publish(ctx, protocol.ChannelPresence, string(payload)).Err()
}
