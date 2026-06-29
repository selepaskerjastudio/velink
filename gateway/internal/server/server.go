// Package server is the HTTP/WebSocket front door agents dial out to.
package server

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"net"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/coder/websocket"
	"github.com/coder/websocket/wsjson"
	"github.com/google/uuid"
	"github.com/velink/gateway/internal/auth"
	"github.com/velink/gateway/internal/bridge"
	"github.com/velink/gateway/internal/hub"
	"github.com/velink/gateway/internal/presence"
	"github.com/velink/gateway/internal/protocol"
	"github.com/velink/gateway/internal/terminal"
)

// Server holds the dependencies for the agent-facing endpoints.
type Server struct {
	verifier *auth.Verifier
	hub      *hub.Hub
	presence *presence.Tracker
	bridge   *bridge.Bridge
	terminal *terminal.Manager
	log      *slog.Logger
}

// New builds a Server.
func New(v *auth.Verifier, h *hub.Hub, p *presence.Tracker, b *bridge.Bridge, log *slog.Logger) *Server {
	return &Server{verifier: v, hub: h, presence: p, bridge: b, terminal: terminal.New(), log: log}
}

// Handler returns the HTTP mux for the gateway.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/agent/connect", s.handleAgentConnect)
	mux.HandleFunc("/terminal/connect", s.handleTerminalConnect)
	mux.HandleFunc("/healthz", s.handleHealth)
	return mux
}

func (s *Server) handleHealth(w http.ResponseWriter, _ *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write([]byte(`{"status":"ok","agents":` + strconv.Itoa(s.hub.Count()) + `}`))
}

// handleAgentConnect authenticates the agent at the HTTP layer (before the WS
// upgrade), then runs the read/write pumps for the lifetime of the connection.
func (s *Server) handleAgentConnect(w http.ResponseWriter, r *http.Request) {
	token := bearerToken(r.Header.Get("Authorization"))
	serverID := r.Header.Get("X-Server-Id")
	agentVersion := r.Header.Get("X-Agent-Version")

	if token == "" || serverID == "" {
		http.Error(w, "missing credentials", http.StatusUnauthorized)
		return
	}

	info, err := s.verifier.Verify(r.Context(), serverID, token)
	if err != nil {
		s.log.Warn("agent rejected", "server_id", serverID, "error", err)
		http.Error(w, "unauthorized", http.StatusUnauthorized)
		return
	}

	ws, err := websocket.Accept(w, r, &websocket.AcceptOptions{})
	if err != nil {
		s.log.Warn("websocket accept failed", "server_id", serverID, "error", err)
		return
	}
	// Detach from the request context: the connection outlives the HTTP handler.
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	defer ws.CloseNow()

	conn := hub.NewConn(info.ID, agentVersion)
	if prev := s.hub.Add(conn); prev != nil {
		prev.Close()
	}
	defer s.hub.Remove(conn)

	if err := s.presence.Online(ctx, info.ID, agentVersion); err != nil {
		s.log.Warn("presence online failed", "server_id", info.ID, "error", err)
	}
	defer func() {
		offCtx, offCancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer offCancel()
		if err := s.presence.Offline(offCtx, info.ID); err != nil {
			s.log.Warn("presence offline failed", "server_id", info.ID, "error", err)
		}
	}()

	// Capture the agent's public IP from the TCP connection before the WS
	// upgrade hijacks the ResponseWriter. Used to enrich sysinfo messages.
	remoteHost, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		remoteHost = r.RemoteAddr
	}

	s.log.Info("agent connected", "server_id", info.ID, "name", info.Name, "version", agentVersion, "agents", s.hub.Count())

	go s.writePump(ctx, ws, conn)
	s.readPump(ctx, cancel, ws, conn, remoteHost)

	s.log.Info("agent disconnected", "server_id", info.ID, "agents", s.hub.Count()-1)
}

// writePump owns all writes to the socket, draining the connection's send queue.
func (s *Server) writePump(ctx context.Context, ws *websocket.Conn, conn *hub.Conn) {
	for {
		select {
		case <-ctx.Done():
			return
		case <-conn.Closed():
			return
		case env := <-conn.Send:
			wctx, cancel := context.WithTimeout(ctx, 10*time.Second)
			err := wsjson.Write(wctx, ws, env)
			cancel()
			if err != nil {
				s.log.Warn("write failed", "server_id", conn.ServerID, "error", err)
				conn.Close()
				return
			}
		}
	}
}

// readPump consumes inbound envelopes, refreshing presence on heartbeats and
// forwarding everything else to the panel via the bridge. remoteAddr is the
// agent's public IP address, used to enrich sysinfo messages.
func (s *Server) readPump(ctx context.Context, cancel context.CancelFunc, ws *websocket.Conn, conn *hub.Conn, remoteAddr string) {
	defer cancel()
	for {
		var env protocol.Envelope
		err := wsjson.Read(ctx, ws, &env)
		if err != nil {
			if !errors.Is(err, context.Canceled) {
				status := websocket.CloseStatus(err)
				if status != websocket.StatusNormalClosure && status != websocket.StatusGoingAway {
					s.log.Debug("read loop ended", "server_id", conn.ServerID, "error", err)
				}
			}
			return
		}

		// Stamp the authenticated server ID so the panel can trust the source.
		env.ServerID = conn.ServerID

		// Terminal messages from the agent go to the browser relay, not Redis.
		if terminal.IsTerminalType(env.Type) {
			if relay, ok := s.terminal.Get(env.JobID); ok {
				select {
				case relay.AgentOut <- env:
				default:
					s.log.Warn("terminal relay buffer full, dropping", "session", env.JobID)
				}
				continue
			}
			// No relay found — fall through to publish (panel may handle it).
		}

		switch env.Type {
		case protocol.TypeHeartbeat:
			if err := s.presence.Refresh(ctx, conn.ServerID); err != nil {
				s.log.Warn("presence refresh failed", "server_id", conn.ServerID, "error", err)
			}
		case protocol.TypeHello:
			// Connection-level greeting; presence is already set. Nothing to do
			// in the skeleton beyond acknowledging it exists.
		case protocol.TypeSysinfo:
			// Enrich the agent-supplied payload with the public IP observed by
			// the gateway (the agent cannot know its own NAT-translated address).
			env.Payload = injectPublicIP(env.Payload, remoteAddr)
			if err := s.bridge.PublishInbound(ctx, env); err != nil {
				s.log.Warn("publish inbound failed", "server_id", conn.ServerID, "error", err)
			}
		default:
			if err := s.bridge.PublishInbound(ctx, env); err != nil {
				s.log.Warn("publish inbound failed", "server_id", conn.ServerID, "error", err)
			}
		}
	}
}

// injectPublicIP merges publicIP into the JSON payload under the key
// "public_ip". Returns the original payload unchanged if publicIP is empty,
// a loopback address, or if the payload cannot be parsed.
func injectPublicIP(payload json.RawMessage, publicIP string) json.RawMessage {
	if publicIP == "" || publicIP == "127.0.0.1" || publicIP == "::1" {
		return payload
	}

	var m map[string]any
	if err := json.Unmarshal(payload, &m); err != nil {
		return payload
	}
	if m == nil {
		m = make(map[string]any)
	}
	m["public_ip"] = publicIP

	enriched, err := json.Marshal(m)
	if err != nil {
		return payload
	}
	return json.RawMessage(enriched)
}

func bearerToken(header string) string {
	const prefix = "Bearer "
	if strings.HasPrefix(header, prefix) {
		return strings.TrimSpace(header[len(prefix):])
	}
	return strings.TrimSpace(header)
}

// handleTerminalConnect accepts a browser WebSocket for an interactive terminal
// session, authenticates it, then relays bytes between the browser and the
// target agent's PTY via the existing hub dispatch path.
func (s *Server) handleTerminalConnect(w http.ResponseWriter, r *http.Request) {
	serverUUID := r.URL.Query().Get("server")
	sessionToken := r.URL.Query().Get("token")
	user := r.URL.Query().Get("user")
	colsStr := r.URL.Query().Get("cols")
	rowsStr := r.URL.Query().Get("rows")

	if serverUUID == "" || sessionToken == "" {
		http.Error(w, "missing server or token", http.StatusUnauthorized)
		return
	}

	// Authenticate the terminal session against the panel.
	serverID, err := s.verifier.VerifyTerminal(r.Context(), serverUUID, sessionToken)
	if err != nil {
		s.log.Warn("terminal auth rejected", "server_uuid", serverUUID, "error", err)
		http.Error(w, "unauthorized", http.StatusUnauthorized)
		return
	}

	// Accept the WebSocket upgrade.
	ws, err := websocket.Accept(w, r, &websocket.AcceptOptions{})
	if err != nil {
		s.log.Warn("terminal websocket accept failed", "error", err)
		return
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	defer ws.CloseNow()

	sessionID := uuid.New().String()

	// Register the relay so agent-side terminal_data messages find this browser.
	relay := s.terminal.Register(sessionID, serverID)
	defer s.terminal.Unregister(sessionID)

	s.log.Info("terminal session opened", "session", sessionID, "server", serverID, "user", user)

	// Parse initial terminal size (default 80x24).
	cols := uint16(80)
	rows := uint16(24)
	if c, err := strconv.Atoi(colsStr); err == nil && c > 0 {
		cols = uint16(c)
	}
	if r, err := strconv.Atoi(rowsStr); err == nil && r > 0 {
		rows = uint16(r)
	}

	// Send terminal_open to the agent via the hub dispatch path.
	openPayload, _ := json.Marshal(map[string]any{
		"user": user,
		"cols": cols,
		"rows": rows,
	})
	openEnv := protocol.Envelope{
		Type:      protocol.TypeTerminalOpen,
		JobID:     sessionID,
		ServerID:  serverID,
		Payload:   openPayload,
		Timestamp: protocol.Now(),
	}
	if !s.hub.Dispatch(openEnv) {
		s.log.Warn("terminal open: agent not connected", "server", serverID)
		wsjson.Write(ctx, ws, map[string]string{"type": "error", "message": "Agent not connected"})
		return
	}

	// Start the agent→browser relay goroutine.
	go func() {
		for env := range relay.AgentOut {
			if err := wsjson.Write(ctx, ws, env); err != nil {
				return
			}
		}
	}()

	// Read from browser→agent in this goroutine until disconnect.
	for {
		var msg map[string]any
		if err := wsjson.Read(ctx, ws, &msg); err != nil {
			break
		}

		msgType, _ := msg["type"].(string)

		switch msgType {
		case "input":
			// Browser sends base64-encoded keystrokes.
			data, _ := msg["data"].(string)
			payload, _ := json.Marshal(map[string]string{"data": data})
			s.hub.Dispatch(protocol.Envelope{
				Type: protocol.TypeTerminalData, JobID: sessionID,
				ServerID: serverID, Payload: payload, Timestamp: protocol.Now(),
			})
		case "resize":
			c, _ := msg["cols"].(float64)
			rw, _ := msg["rows"].(float64)
			payload, _ := json.Marshal(map[string]uint16{"cols": uint16(c), "rows": uint16(rw)})
			s.hub.Dispatch(protocol.Envelope{
				Type: protocol.TypeTerminalResize, JobID: sessionID,
				ServerID: serverID, Payload: payload, Timestamp: protocol.Now(),
			})
		}
	}

	// Browser disconnected — send terminal_close to the agent.
	s.hub.Dispatch(protocol.Envelope{
		Type: protocol.TypeTerminalClose, JobID: sessionID,
		ServerID: serverID, Timestamp: protocol.Now(),
	})

	s.log.Info("terminal session closed", "session", sessionID)
}
