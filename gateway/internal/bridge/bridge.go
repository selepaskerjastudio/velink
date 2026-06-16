// Package bridge connects the gateway to the Laravel panel over Redis pub/sub.
// Outbound: it subscribes to the dispatch channel and routes each envelope to
// the target agent via the hub. Inbound: it publishes agent messages back to
// the panel.
package bridge

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"time"

	"github.com/redis/go-redis/v9"
	"github.com/velink/gateway/internal/hub"
	"github.com/velink/gateway/internal/protocol"
)

// Backoff bounds for re-subscribing after the dispatch subscription drops.
// Redis pub/sub is fire-and-forget, so any window where we are not subscribed
// loses dispatch envelopes permanently — we reconnect aggressively but cap the
// delay so a hard-down Redis doesn't spin the CPU.
const (
	resubscribeMinBackoff = 250 * time.Millisecond
	resubscribeMaxBackoff = 5 * time.Second
)

// errSubscriptionClosed signals that go-redis closed the dispatch channel after
// the underlying connection dropped, so Run should re-subscribe.
var errSubscriptionClosed = errors.New("dispatch subscription channel closed")

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

// Run keeps a live subscription to the dispatch channel, routing each envelope
// to its agent, and self-heals across transient Redis failures (EOF, restart,
// refused connections). It only returns when ctx is cancelled. Blocks; run it
// in its own goroutine.
//
// A single Subscribe + range over Channel() is not enough: if the subscription
// handshake fails, or go-redis closes the channel after the connection drops,
// the loop would exit and dispatch delivery would silently die forever. Here we
// re-subscribe with capped backoff so the gateway recovers once Redis is back.
func (b *Bridge) Run(ctx context.Context) error {
	backoff := resubscribeMinBackoff
	for {
		if err := ctx.Err(); err != nil {
			return err
		}

		err := b.consume(ctx)
		if err == nil {
			// consume only returns nil when ctx was cancelled.
			return ctx.Err()
		}
		if ctx.Err() != nil {
			return ctx.Err()
		}

		b.log.Warn("dispatch subscription dropped; re-subscribing", "error", err, "backoff", backoff)

		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(backoff):
		}

		// Exponential backoff, capped, reset on the next healthy subscription.
		backoff *= 2
		if backoff > resubscribeMaxBackoff {
			backoff = resubscribeMaxBackoff
		}
	}
}

// consume opens one subscription and routes envelopes until either ctx is
// cancelled (returns nil) or the subscription fails (returns a non-nil error so
// Run re-subscribes).
func (b *Bridge) consume(ctx context.Context) error {
	sub := b.rdb.Subscribe(ctx, protocol.ChannelDispatch)
	defer sub.Close()

	// Confirm the subscription is live before consuming. A failure here means
	// Redis is unreachable; report it so Run backs off and retries.
	if _, err := sub.Receive(ctx); err != nil {
		return err
	}

	ch := sub.Channel()
	for {
		select {
		case <-ctx.Done():
			return nil
		case msg, ok := <-ch:
			if !ok {
				// go-redis closed the channel after the underlying connection
				// dropped. Treat as a transient failure so Run re-subscribes.
				return errSubscriptionClosed
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
