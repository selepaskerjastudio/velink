// Package config loads gateway settings from the environment.
package config

import (
	"fmt"
	"os"
	"strconv"
	"time"
)

// Config holds every runtime setting for the gateway.
type Config struct {
	// Listen is the host:port the WebSocket/HTTP server binds to.
	Listen string
	// PanelURL is the base URL of the Laravel panel internal API.
	PanelURL string
	// PanelSecret is the shared secret sent as X-Gateway-Secret to the panel.
	PanelSecret string
	// RedisAddr is host:port of the Redis instance shared with the panel.
	RedisAddr string
	// RedisPassword is optional.
	RedisPassword string
	// RedisDB selects the Redis logical database.
	RedisDB int
	// PresenceTTL is how long a server stays "online" without a heartbeat.
	PresenceTTL time.Duration
}

// Load reads configuration from environment variables, applying defaults.
// PanelSecret is required; everything else has a sane local default.
func Load() (Config, error) {
	cfg := Config{
		Listen:        env("GATEWAY_LISTEN", ":8080"),
		PanelURL:      env("GATEWAY_PANEL_URL", "http://127.0.0.1:8000"),
		PanelSecret:   os.Getenv("GATEWAY_PANEL_SECRET"),
		RedisAddr:     env("GATEWAY_REDIS_ADDR", "127.0.0.1:6379"),
		RedisPassword: os.Getenv("GATEWAY_REDIS_PASSWORD"),
		RedisDB:       envInt("GATEWAY_REDIS_DB", 0),
		PresenceTTL:   time.Duration(envInt("GATEWAY_PRESENCE_TTL", 90)) * time.Second,
	}

	if cfg.PanelSecret == "" {
		return Config{}, fmt.Errorf("GATEWAY_PANEL_SECRET is required")
	}

	return cfg, nil
}

func env(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func envInt(key string, fallback int) int {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			return n
		}
	}
	return fallback
}
