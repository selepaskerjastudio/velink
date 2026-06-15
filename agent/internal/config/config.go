// Package config loads agent settings from the environment. The installer
// writes these into an EnvironmentFile read by the systemd unit.
package config

import (
	"fmt"
	"os"
	"strconv"
	"time"
)

// Version is the agent build version, reported to the panel on connect.
const Version = "0.1.0"

// Config holds runtime settings.
type Config struct {
	// GatewayURL is the wss:// (or ws://) base URL of the gateway.
	GatewayURL string
	// Token is the per-server enrollment token.
	Token string
	// ServerID is this server's panel UUID.
	ServerID string
	// AgentVersion is reported on connect.
	AgentVersion string
	// HeartbeatInterval is how often heartbeats are sent.
	HeartbeatInterval time.Duration
	// Insecure skips TLS verification (dev only).
	Insecure bool
}

// Load reads and validates configuration.
func Load() (Config, error) {
	cfg := Config{
		GatewayURL:        os.Getenv("AGENT_GATEWAY_URL"),
		Token:             os.Getenv("AGENT_TOKEN"),
		ServerID:          os.Getenv("AGENT_SERVER_ID"),
		AgentVersion:      Version,
		HeartbeatInterval: time.Duration(envInt("AGENT_HEARTBEAT", 30)) * time.Second,
		Insecure:          os.Getenv("AGENT_INSECURE") == "1",
	}

	switch {
	case cfg.GatewayURL == "":
		return Config{}, fmt.Errorf("AGENT_GATEWAY_URL is required")
	case cfg.Token == "":
		return Config{}, fmt.Errorf("AGENT_TOKEN is required")
	case cfg.ServerID == "":
		return Config{}, fmt.Errorf("AGENT_SERVER_ID is required")
	}

	return cfg, nil
}

func envInt(key string, fallback int) int {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			return n
		}
	}
	return fallback
}
