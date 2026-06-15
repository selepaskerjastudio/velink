// Command agent is the Velink agent: a single static binary installed on
// each managed server. It dials out to the gateway, heartbeats, and executes
// jobs (provisioning, deploys, service control) on behalf of the panel.
package main

import (
	"context"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	"github.com/velink/agent/internal/client"
	"github.com/velink/agent/internal/config"
)

func main() {
	log := slog.New(slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo}))

	cfg, err := config.Load()
	if err != nil {
		log.Error("config error", "error", err)
		os.Exit(1)
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	log.Info("agent starting", "version", cfg.AgentVersion, "server_id", cfg.ServerID, "gateway", cfg.GatewayURL)
	client.New(cfg, log).Run(ctx)
	log.Info("agent stopped")
}
