// Package executor runs jobs received from the panel and emits output/result
// envelopes back. It is transport-agnostic: callers pass an Emit callback, so
// the executor can be unit-tested without a WebSocket.
package executor

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"sync"
	"text/template"
	"time"

	"github.com/coruncloud/agent/internal/protocol"
)

// Supported job actions (the inner payload.action; the transport type is "job").
const (
	ActionShell        = "shell"
	ActionWriteFile    = "write_file"
	ActionRenderConfig = "render_config"
)

// JobSpec is the decoded job payload: an action plus action-specific params.
type JobSpec struct {
	Action string          `json:"action"`
	Params json.RawMessage `json:"params"`
}

// Emit delivers an envelope back toward the panel.
type Emit func(protocol.Envelope)

// Executor runs jobs for a given server.
type Executor struct {
	serverID string
}

// New builds an Executor stamped with the server ID for outgoing envelopes.
func New(serverID string) *Executor {
	return &Executor{serverID: serverID}
}

// Run dispatches a job by action and streams output + a terminal result.
func (e *Executor) Run(ctx context.Context, jobID string, spec JobSpec, emit Emit) {
	switch spec.Action {
	case ActionShell:
		e.runShell(ctx, jobID, spec.Params, emit)
	case ActionWriteFile:
		e.runWriteFile(jobID, spec.Params, emit)
	case ActionRenderConfig:
		e.runRenderConfig(jobID, spec.Params, emit)
	default:
		e.result(jobID, emit, 1, fmt.Sprintf("unknown action %q", spec.Action))
	}
}

type shellParams struct {
	Command string `json:"command"`
	Dir     string `json:"dir"`
	Timeout int    `json:"timeout"` // seconds; 0 = no timeout
}

func (e *Executor) runShell(ctx context.Context, jobID string, raw json.RawMessage, emit Emit) {
	var p shellParams
	if err := json.Unmarshal(raw, &p); err != nil {
		e.result(jobID, emit, 1, "bad params: "+err.Error())
		return
	}
	if p.Command == "" {
		e.result(jobID, emit, 1, "empty command")
		return
	}

	if p.Timeout > 0 {
		var cancel context.CancelFunc
		ctx, cancel = context.WithTimeout(ctx, time.Duration(p.Timeout)*time.Second)
		defer cancel()
	}

	cmd := exec.CommandContext(ctx, "/bin/sh", "-c", p.Command)
	if p.Dir != "" {
		cmd.Dir = p.Dir
	}

	stdout, err := cmd.StdoutPipe()
	if err != nil {
		e.result(jobID, emit, 1, err.Error())
		return
	}
	stderr, err := cmd.StderrPipe()
	if err != nil {
		e.result(jobID, emit, 1, err.Error())
		return
	}

	if err := cmd.Start(); err != nil {
		e.result(jobID, emit, 1, err.Error())
		return
	}

	var wg sync.WaitGroup
	wg.Add(2)
	go e.streamPipe(stdout, "stdout", jobID, emit, &wg)
	go e.streamPipe(stderr, "stderr", jobID, emit, &wg)
	wg.Wait()

	exitCode, errMsg := 0, ""
	if err := cmd.Wait(); err != nil {
		var ee *exec.ExitError
		if ok := asExitError(err, &ee); ok {
			exitCode = ee.ExitCode()
		} else {
			exitCode, errMsg = 1, err.Error()
		}
	}
	e.result(jobID, emit, exitCode, errMsg)
}

func (e *Executor) streamPipe(r io.Reader, stream, jobID string, emit Emit, wg *sync.WaitGroup) {
	defer wg.Done()
	buf := make([]byte, 4096)
	for {
		n, err := r.Read(buf)
		if n > 0 {
			e.output(jobID, emit, stream, string(buf[:n]))
		}
		if err != nil {
			return
		}
	}
}

type writeFileParams struct {
	Path    string `json:"path"`
	Content string `json:"content"`
	Mode    string `json:"mode"` // octal string e.g. "0644"
}

func (e *Executor) runWriteFile(jobID string, raw json.RawMessage, emit Emit) {
	var p writeFileParams
	if err := json.Unmarshal(raw, &p); err != nil {
		e.result(jobID, emit, 1, "bad params: "+err.Error())
		return
	}
	if p.Path == "" {
		e.result(jobID, emit, 1, "empty path")
		return
	}

	if err := writeFile(p.Path, []byte(p.Content), p.Mode); err != nil {
		e.result(jobID, emit, 1, err.Error())
		return
	}
	e.output(jobID, emit, "stdout", "wrote "+p.Path+"\n")
	e.result(jobID, emit, 0, "")
}

type renderParams struct {
	Path     string         `json:"path"`
	Template string         `json:"template"`
	Vars     map[string]any `json:"vars"`
	Mode     string         `json:"mode"`
}

func (e *Executor) runRenderConfig(jobID string, raw json.RawMessage, emit Emit) {
	var p renderParams
	if err := json.Unmarshal(raw, &p); err != nil {
		e.result(jobID, emit, 1, "bad params: "+err.Error())
		return
	}
	if p.Path == "" || p.Template == "" {
		e.result(jobID, emit, 1, "path and template are required")
		return
	}

	tmpl, err := template.New("config").Option("missingkey=error").Parse(p.Template)
	if err != nil {
		e.result(jobID, emit, 1, "template parse: "+err.Error())
		return
	}
	var buf bytes.Buffer
	if err := tmpl.Execute(&buf, p.Vars); err != nil {
		e.result(jobID, emit, 1, "template exec: "+err.Error())
		return
	}

	if err := writeFile(p.Path, buf.Bytes(), p.Mode); err != nil {
		e.result(jobID, emit, 1, err.Error())
		return
	}
	e.output(jobID, emit, "stdout", "rendered "+p.Path+"\n")
	e.result(jobID, emit, 0, "")
}

// --- helpers ---

func writeFile(path string, data []byte, modeStr string) error {
	mode := os.FileMode(0o644)
	if modeStr != "" {
		m, err := strconv.ParseUint(modeStr, 8, 32)
		if err != nil {
			return fmt.Errorf("invalid mode %q: %w", modeStr, err)
		}
		mode = os.FileMode(m)
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	return os.WriteFile(path, data, mode)
}

func (e *Executor) output(jobID string, emit Emit, stream, data string) {
	payload, _ := json.Marshal(map[string]string{"stream": stream, "data": data})
	emit(protocol.Envelope{
		Type:      protocol.TypeJobOutput,
		JobID:     jobID,
		ServerID:  e.serverID,
		Payload:   payload,
		Timestamp: protocol.Now(),
	})
}

func (e *Executor) result(jobID string, emit Emit, exitCode int, errMsg string) {
	body := map[string]any{"exit_code": exitCode}
	if errMsg != "" {
		body["error"] = errMsg
	}
	payload, _ := json.Marshal(body)
	emit(protocol.Envelope{
		Type:      protocol.TypeJobResult,
		JobID:     jobID,
		ServerID:  e.serverID,
		Payload:   payload,
		Timestamp: protocol.Now(),
	})
}

func asExitError(err error, target **exec.ExitError) bool {
	if ee, ok := err.(*exec.ExitError); ok {
		*target = ee
		return true
	}
	return false
}
