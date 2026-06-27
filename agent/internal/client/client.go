// Package client maintains the agent's dial-out WebSocket connection to the
// gateway: it authenticates, heartbeats, executes incoming jobs, and reconnects
// automatically with exponential backoff.
package client

import (
	"context"
	"crypto/tls"
	"encoding/base64"
	"encoding/json"
	"log/slog"
	"net/http"
	"time"

	"github.com/coder/websocket"
	"github.com/coder/websocket/wsjson"
	"github.com/velink/agent/internal/config"
	"github.com/velink/agent/internal/executor"
	"github.com/velink/agent/internal/metrics"
	"github.com/velink/agent/internal/protocol"
	"github.com/velink/agent/internal/sysinfo"
	"github.com/velink/agent/internal/terminal"
)

const (
	minBackoff = 1 * time.Second
	maxBackoff = 30 * time.Second
)

// Client is the agent's connection manager.
type Client struct {
	cfg       config.Config
	exec      *executor.Executor
	terminal  *terminal.Manager
	log       *slog.Logger
}

// New builds a Client.
func New(cfg config.Config, log *slog.Logger) *Client {
	return &Client{
		cfg:      cfg,
		exec:     executor.New(cfg.ServerID),
		terminal: terminal.New(log),
		log:      log,
	}
}

// Run connects and serves until ctx is cancelled, reconnecting on failure.
func (c *Client) Run(ctx context.Context) {
	backoff := minBackoff
	for {
		if ctx.Err() != nil {
			return
		}

		err := c.connectAndServe(ctx, func() { backoff = minBackoff })
		if ctx.Err() != nil {
			return
		}

		c.log.Warn("connection lost; reconnecting", "error", err, "in", backoff)
		select {
		case <-ctx.Done():
			return
		case <-time.After(backoff):
		}
		if backoff *= 2; backoff > maxBackoff {
			backoff = maxBackoff
		}
	}
}

// connectAndServe runs one connection lifecycle. onConnected is called once the
// dial succeeds (used to reset backoff).
func (c *Client) connectAndServe(ctx context.Context, onConnected func()) error {
	header := http.Header{}
	header.Set("Authorization", "Bearer "+c.cfg.Token)
	header.Set("X-Server-Id", c.cfg.ServerID)
	header.Set("X-Agent-Version", c.cfg.AgentVersion)

	opts := &websocket.DialOptions{HTTPHeader: header}
	if c.cfg.Insecure {
		opts.HTTPClient = &http.Client{
			Transport: &http.Transport{TLSClientConfig: &tls.Config{InsecureSkipVerify: true}},
		}
	}

	dialCtx, cancelDial := context.WithTimeout(ctx, 15*time.Second)
	ws, _, err := websocket.Dial(dialCtx, c.cfg.GatewayURL+"/agent/connect", opts)
	cancelDial()
	if err != nil {
		return err
	}
	defer ws.CloseNow()

	onConnected()
	c.log.Info("connected to gateway", "url", c.cfg.GatewayURL, "server_id", c.cfg.ServerID)

	connCtx, cancel := context.WithCancel(ctx)
	defer cancel()

	send := make(chan protocol.Envelope, 64)
	send <- protocol.Envelope{Type: protocol.TypeHello, ServerID: c.cfg.ServerID, Timestamp: protocol.Now()}

	// Immediately follow hello with a sysinfo message so the panel can
	// populate hostname, private IP, and OS without a separate RPC.
	info := sysinfo.Collect()
	if sysinfoPayload, err := json.Marshal(info); err == nil {
		send <- protocol.Envelope{
			Type:      protocol.TypeSysinfo,
			ServerID:  c.cfg.ServerID,
			Payload:   json.RawMessage(sysinfoPayload),
			Timestamp: protocol.Now(),
		}
	}

	go c.writePump(connCtx, ws, send)
	go c.heartbeat(connCtx, send)
	go c.metricsLoop(connCtx, send)
	return c.readPump(connCtx, cancel, ws, send)
}

func (c *Client) writePump(ctx context.Context, ws *websocket.Conn, send chan protocol.Envelope) {
	for {
		select {
		case <-ctx.Done():
			return
		case env := <-send:
			wctx, cancel := context.WithTimeout(ctx, 10*time.Second)
			err := wsjson.Write(wctx, ws, env)
			cancel()
			if err != nil {
				c.log.Warn("write failed", "error", err)
				return
			}
		}
	}
}

func (c *Client) heartbeat(ctx context.Context, send chan protocol.Envelope) {
	t := time.NewTicker(c.cfg.HeartbeatInterval)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			select {
			case send <- protocol.Envelope{Type: protocol.TypeHeartbeat, ServerID: c.cfg.ServerID, Timestamp: protocol.Now()}:
			case <-ctx.Done():
				return
			}
		}
	}
}

func (c *Client) readPump(ctx context.Context, cancel context.CancelFunc, ws *websocket.Conn, send chan protocol.Envelope) error {
	defer cancel()
	for {
		var env protocol.Envelope
		if err := wsjson.Read(ctx, ws, &env); err != nil {
			return err
		}
		switch env.Type {
		case protocol.TypeJob:
			go c.handleJob(ctx, env, send)
		case protocol.TypeTerminalOpen:
			go c.handleTerminalOpen(ctx, env, send)
		case protocol.TypeTerminalData:
			c.handleTerminalData(env)
		case protocol.TypeTerminalResize:
			c.handleTerminalResize(env)
		case protocol.TypeTerminalClose:
			c.terminal.Close(env.JobID)
		}
	}
}

func (c *Client) handleJob(ctx context.Context, env protocol.Envelope, send chan protocol.Envelope) {
	var spec executor.JobSpec
	if err := json.Unmarshal(env.Payload, &spec); err != nil {
		c.log.Warn("malformed job payload", "job_id", env.JobID, "error", err)
		c.emit(ctx, send, failureResult(c.cfg.ServerID, env.JobID, "malformed job payload: "+err.Error()))
		return
	}

	c.log.Info("running job", "job_id", env.JobID, "action", spec.Action)
	c.exec.Run(ctx, env.JobID, spec, func(out protocol.Envelope) {
		c.emit(ctx, send, out)
	})
}

func (c *Client) emit(ctx context.Context, send chan protocol.Envelope, env protocol.Envelope) {
	select {
	case send <- env:
	case <-ctx.Done():
	}
}

// handleTerminalOpen starts a PTY session for a web terminal.
func (c *Client) handleTerminalOpen(ctx context.Context, env protocol.Envelope, send chan protocol.Envelope) {
	var params terminal.OpenParams
	if err := json.Unmarshal(env.Payload, &params); err != nil {
		c.log.Warn("malformed terminal_open payload", "session", env.JobID, "error", err)
		return
	}

	emit := func(msgType string, sessionID string, payload interface{}) {
		data, _ := json.Marshal(payload)
		c.emit(ctx, send, protocol.Envelope{
			Type:      msgType,
			JobID:     sessionID,
			ServerID:  c.cfg.ServerID,
			Payload:   data,
			Timestamp: protocol.Now(),
		})
	}

	if err := c.terminal.Open(env.JobID, params, emit); err != nil {
		c.log.Error("failed to open terminal session", "session", env.JobID, "error", err)
	}
}

// handleTerminalData writes input bytes to a PTY session.
func (c *Client) handleTerminalData(env protocol.Envelope) {
	var payload terminal.DataPayload
	if err := json.Unmarshal(env.Payload, &payload); err != nil {
		return
	}

	decoded, err := base64.StdEncoding.DecodeString(payload.Data)
	if err != nil {
		return
	}

	c.terminal.Write(env.JobID, decoded)
}

// handleTerminalResize changes the PTY window size.
func (c *Client) handleTerminalResize(env protocol.Envelope) {
	var size terminal.WinSize
	if err := json.Unmarshal(env.Payload, &size); err != nil {
		return
	}

	c.terminal.Resize(env.JobID, size)
}

// metricsLoop collects a system resource snapshot every 30 seconds and sends
// it to the gateway via the shared send channel.
func (c *Client) metricsLoop(ctx context.Context, send chan protocol.Envelope) {
	t := time.NewTicker(30 * time.Second)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			snap, err := metrics.Collect(ctx)
			if err != nil {
				c.log.Warn("metrics collection failed", "error", err)
				continue
			}
			payload, err := json.Marshal(snap)
			if err != nil {
				c.log.Warn("metrics marshal failed", "error", err)
				continue
			}
			env := protocol.Envelope{
				Type:      protocol.TypeMetrics,
				ServerID:  c.cfg.ServerID,
				Payload:   json.RawMessage(payload),
				Timestamp: protocol.Now(),
			}
			select {
			case send <- env:
			case <-ctx.Done():
				return
			}
		}
	}
}

func failureResult(serverID string, jobID, msg string) protocol.Envelope {
	payload, _ := json.Marshal(map[string]any{"exit_code": 1, "error": msg})
	return protocol.Envelope{
		Type:      protocol.TypeJobResult,
		JobID:     jobID,
		ServerID:  serverID,
		Payload:   payload,
		Timestamp: protocol.Now(),
	}
}
