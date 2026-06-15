// Package bridge connects the gateway to the Laravel panel over Redis pub/sub.
// Outbound: it subscribes to the dispatch channel and routes each envelope to
// the target agent via the hub. Inbound: it publishes agent messages back to
// the panel.
package bridge

import (
	"context"
	"encoding/json"
	"log/slog"

	"github.com/coruncloud/gateway/internal/hub"
	"github.com/coruncloud/gateway/internal/protocol"
	"github.com/redis/go-redis/v9"
)

// Bridge wires Redis pub/sub to the connection hub.
type Bridge struct {
	rdb *redis.Client
	hub *hub.Hub
	log *slog.Logger
}

// New builds a Bridge.
func New(rdb *redis.Client, h *hub.Hub, log *slog.Logger) *Bridge {
	return &Bridge{rdb: rdb, hub: h, log: log}
}

// Run subscribes to the dispatch channel and routes envelopes to agents until
// the context is cancelled. Blocks; run it in its own goroutine.
func (b *Bridge) Run(ctx context.Context) error {
	sub := b.rdb.Subscribe(ctx, protocol.ChannelDispatch)
	defer sub.Close()

	// Confirm the subscription is live before consuming.
	if _, err := sub.Receive(ctx); err != nil {
		return err
	}

	ch := sub.Channel()
	for {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case msg, ok := <-ch:
			if !ok {
				return nil
			}
			b.route(msg.Payload)
		}
	}
}

func (b *Bridge) route(payload string) {
	var env protocol.Envelope
	if err := json.Unmarshal([]byte(payload), &env); err != nil {
		b.log.Warn("dropping malformed dispatch envelope", "error", err)
		return
	}
	if delivered := b.hub.Dispatch(env); !delivered {
		// The agent is offline or its queue is saturated. Fase 1's job state
		// machine in the panel is responsible for retry/timeout; here we just log.
		b.log.Warn("dispatch undeliverable", "server_id", env.ServerID, "type", env.Type, "job_id", env.JobID)
	}
}

// PublishInbound forwards an agent message to the panel.
func (b *Bridge) PublishInbound(ctx context.Context, env protocol.Envelope) error {
	payload, err := json.Marshal(env)
	if err != nil {
		return err
	}
	return b.rdb.Publish(ctx, protocol.ChannelInbound, string(payload)).Err()
}
