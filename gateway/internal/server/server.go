// Package server is the HTTP/WebSocket front door agents dial out to.
package server

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/coder/websocket"
	"github.com/coder/websocket/wsjson"
	"github.com/coruncloud/gateway/internal/auth"
	"github.com/coruncloud/gateway/internal/bridge"
	"github.com/coruncloud/gateway/internal/hub"
	"github.com/coruncloud/gateway/internal/presence"
	"github.com/coruncloud/gateway/internal/protocol"
)

// Server holds the dependencies for the agent-facing endpoints.
type Server struct {
	verifier *auth.Verifier
	hub      *hub.Hub
	presence *presence.Tracker
	bridge   *bridge.Bridge
	log      *slog.Logger
}

// New builds a Server.
func New(v *auth.Verifier, h *hub.Hub, p *presence.Tracker, b *bridge.Bridge, log *slog.Logger) *Server {
	return &Server{verifier: v, hub: h, presence: p, bridge: b, log: log}
}

// Handler returns the HTTP mux for the gateway.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/agent/connect", s.handleAgentConnect)
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
	serverID, _ := strconv.ParseInt(r.Header.Get("X-Server-Id"), 10, 64)
	agentVersion := r.Header.Get("X-Agent-Version")

	if token == "" || serverID == 0 {
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

	s.log.Info("agent connected", "server_id", info.ID, "name", info.Name, "version", agentVersion, "agents", s.hub.Count())

	go s.writePump(ctx, ws, conn)
	s.readPump(ctx, cancel, ws, conn)

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
// forwarding everything else to the panel via the bridge.
func (s *Server) readPump(ctx context.Context, cancel context.CancelFunc, ws *websocket.Conn, conn *hub.Conn) {
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

		switch env.Type {
		case protocol.TypeHeartbeat:
			if err := s.presence.Refresh(ctx, conn.ServerID); err != nil {
				s.log.Warn("presence refresh failed", "server_id", conn.ServerID, "error", err)
			}
		case protocol.TypeHello:
			// Connection-level greeting; presence is already set. Nothing to do
			// in the skeleton beyond acknowledging it exists.
		default:
			if err := s.bridge.PublishInbound(ctx, env); err != nil {
				s.log.Warn("publish inbound failed", "server_id", conn.ServerID, "error", err)
			}
		}
	}
}

func bearerToken(header string) string {
	const prefix = "Bearer "
	if strings.HasPrefix(header, prefix) {
		return strings.TrimSpace(header[len(prefix):])
	}
	return strings.TrimSpace(header)
}
