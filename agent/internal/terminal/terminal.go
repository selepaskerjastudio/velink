// Package terminal manages interactive PTY sessions for the web terminal.
// Each session opens a persistent pseudo-terminal (via creack/pty) running a
// login shell as a specified OS user, and relays input/output bidirectionally.
package terminal

import (
	"encoding/base64"
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"sync"

	"github.com/creack/pty"
)

// WinSize carries the terminal dimensions for resize operations.
type WinSize struct {
	Cols uint16 `json:"cols"`
	Rows uint16 `json:"rows"`
}

// OpenParams is the payload of a terminal_open message.
type OpenParams struct {
	User string  `json:"user"`
	Cols uint16  `json:"cols"`
	Rows uint16  `json:"rows"`
}

// DataPayload is the payload of a terminal_data message (bidirectional).
// Data is base64-encoded raw bytes.
type DataPayload struct {
	Data string `json:"data"`
}

// Emit delivers an envelope back toward the panel/browser.
type Emit func(msgType string, sessionID string, payload interface{})

// Session holds a single PTY session.
type Session struct {
	ID      string
	ptmx    *os.File
	cmd     *exec.Cmd
	emit    Emit
	done    chan struct{}
}

// Manager tracks all active terminal sessions, keyed by session ID.
type Manager struct {
	mu       sync.Mutex
	sessions map[string]*Session
	log      *slog.Logger
}

// New creates a Manager.
func New(log *slog.Logger) *Manager {
	return &Manager{
		sessions: make(map[string]*Session),
		log:      log,
	}
}

// Open starts a new PTY session. The shell runs as the specified user
// (or root if user is empty). Cols/Rows set the initial terminal size.
// The emit callback is called with terminal_data envelopes as output arrives.
func (m *Manager) Open(sessionID string, params OpenParams, emit Emit) error {
	m.mu.Lock()
	defer m.mu.Unlock()

	if _, exists := m.sessions[sessionID]; exists {
		return fmt.Errorf("terminal session %s already exists", sessionID)
	}

	user := params.User
	if user == "" {
		user = "root"
	}

	// Build the command: use runuser (no PAM password check, root-only) to
	// switch to the target user and start a login bash shell.
	// 'su -' would require a password for locked accounts (e.g. velink).
	// TERM is set so programs know they're in a 256-color terminal.
	var cmd *exec.Cmd
	if user == "root" {
		cmd = exec.Command("/bin/bash", "--login")
	} else {
		cmd = exec.Command("runuser", "-l", user, "--", "/bin/bash", "--login")
	}
	cmd.Env = append(os.Environ(),
		"TERM=xterm-256color",
		fmt.Sprintf("COLUMNS=%d", params.Cols),
		fmt.Sprintf("LINES=%d", params.Rows),
	)

	// Start with the requested window size.
	size := &pty.Winsize{Cols: params.Cols, Rows: params.Rows}
	ptmx, err := pty.StartWithSize(cmd, size)
	if err != nil {
		return fmt.Errorf("failed to start PTY: %w", err)
	}

	sess := &Session{
		ID:   sessionID,
		ptmx: ptmx,
		cmd:  cmd,
		emit: emit,
		done: make(chan struct{}),
	}

	m.sessions[sessionID] = sess

	// Start the output reader goroutine.
	go sess.readLoop()

	m.log.Info("terminal session opened", "session", sessionID, "user", user, "pid", cmd.Process.Pid)

	return nil
}

// Write sends input bytes to the PTY stdin.
func (m *Manager) Write(sessionID string, data []byte) error {
	m.mu.Lock()
	sess, ok := m.sessions[sessionID]
	m.mu.Unlock()
	if !ok {
		return fmt.Errorf("terminal session %s not found", sessionID)
	}

	_, err := sess.ptmx.Write(data)
	return err
}

// Resize changes the PTY window size.
func (m *Manager) Resize(sessionID string, size WinSize) error {
	m.mu.Lock()
	sess, ok := m.sessions[sessionID]
	m.mu.Unlock()
	if !ok {
		return fmt.Errorf("terminal session %s not found", sessionID)
	}

	return pty.Setsize(sess.ptmx, &pty.Winsize{Cols: size.Cols, Rows: size.Rows})
}

// Close kills the PTY process and removes the session.
func (m *Manager) Close(sessionID string) {
	m.mu.Lock()
	sess, ok := m.sessions[sessionID]
	if ok {
		delete(m.sessions, sessionID)
	}
	m.mu.Unlock()

	if !ok {
		return
	}

	sess.close()
	m.log.Info("terminal session closed", "session", sessionID)
}

// CloseAll kills all sessions (used on agent shutdown/reconnect).
func (m *Manager) CloseAll() {
	m.mu.Lock()
	defer m.mu.Unlock()

	for id, sess := range m.sessions {
		sess.close()
		delete(m.sessions, id)
	}
}

// readLoop continuously reads from the PTY output and emits terminal_data
// envelopes until the PTY is closed or the process exits.
func (s *Session) readLoop() {
	defer close(s.done)

	buf := make([]byte, 4096)
	for {
		n, err := s.ptmx.Read(buf)
		if n > 0 {
			// Base64-encode the output chunk for safe JSON transport.
			data := base64.StdEncoding.EncodeToString(buf[:n])
			s.emit("terminal_data", s.ID, DataPayload{Data: data})
		}
		if err != nil {
			// EOF or error — the process has exited.
			break
		}
	}

	// Emit terminal_exited so the gateway/browser know the session ended.
	s.emit("terminal_exited", s.ID, map[string]any{})

	// Wait for the process to fully exit.
	if s.cmd.Process != nil {
		s.cmd.Wait()
	}
}

func (s *Session) close() {
	if s.cmd != nil && s.cmd.Process != nil {
		s.cmd.Process.Kill()
	}
	if s.ptmx != nil {
		s.ptmx.Close()
	}
	<-s.done
}
