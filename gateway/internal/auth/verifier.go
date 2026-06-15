// Package auth verifies agent tokens against the Laravel panel. The token is
// stored hashed (bcrypt) in the panel database, so verification must happen in
// the panel — the gateway holds no plaintext and stays stateless.
package auth

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"time"
)

// Verifier calls the panel's internal verification endpoint.
type Verifier struct {
	panelURL string
	secret   string
	client   *http.Client
}

// ServerInfo is the subset of server data the panel returns on success.
type ServerInfo struct {
	ID     int64  `json:"id"`
	Name   string `json:"name"`
	Status string `json:"status"`
}

// New builds a Verifier targeting the given panel base URL.
func New(panelURL, secret string) *Verifier {
	return &Verifier{
		panelURL: panelURL,
		secret:   secret,
		client:   &http.Client{Timeout: 10 * time.Second},
	}
}

type verifyRequest struct {
	ServerID int64  `json:"server_id"`
	Token    string `json:"token"`
}

type verifyResponse struct {
	Valid  bool       `json:"valid"`
	Server ServerInfo `json:"server"`
}

// Verify returns the server record if the (serverID, token) pair is valid.
// A non-nil error means the verification could not be completed or was rejected.
func (v *Verifier) Verify(ctx context.Context, serverID int64, token string) (ServerInfo, error) {
	body, err := json.Marshal(verifyRequest{ServerID: serverID, Token: token})
	if err != nil {
		return ServerInfo{}, err
	}

	url := v.panelURL + "/internal/agent/verify"
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(body))
	if err != nil {
		return ServerInfo{}, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-Gateway-Secret", v.secret)

	resp, err := v.client.Do(req)
	if err != nil {
		return ServerInfo{}, fmt.Errorf("panel verify request failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return ServerInfo{}, fmt.Errorf("panel rejected agent (status %d)", resp.StatusCode)
	}

	var out verifyResponse
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return ServerInfo{}, fmt.Errorf("decode panel response: %w", err)
	}
	if !out.Valid {
		return ServerInfo{}, fmt.Errorf("panel reported invalid token")
	}

	return out.Server, nil
}
