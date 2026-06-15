// Command gateway is the Velink realtime gateway: it terminates agent
// WebSocket connections, tracks presence in Redis, and bridges jobs/output
// between agents and the Laravel panel over Redis pub/sub.
package main

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/velink/gateway/internal/auth"
	"github.com/velink/gateway/internal/bridge"
	"github.com/velink/gateway/internal/config"
	"github.com/velink/gateway/internal/hub"
	"github.com/velink/gateway/internal/presence"
	"github.com/velink/gateway/internal/server"
	"github.com/redis/go-redis/v9"
)

func main() {
	log := slog.New(slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo}))

	cfg, err := config.Load()
	if err != nil {
		log.Error("config error", "error", err)
		os.Exit(1)
	}

	rdb := redis.NewClient(&redis.Options{
		Addr:     cfg.RedisAddr,
		Password: cfg.RedisPassword,
		DB:       cfg.RedisDB,
	})
	defer rdb.Close()

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	pingCtx, pingCancel := context.WithTimeout(ctx, 5*time.Second)
	if err := rdb.Ping(pingCtx).Err(); err != nil {
		pingCancel()
		log.Error("redis unreachable", "addr", cfg.RedisAddr, "error", err)
		os.Exit(1)
	}
	pingCancel()

	h := hub.New()
	verifier := auth.New(cfg.PanelURL, cfg.PanelSecret)
	tracker := presence.New(rdb, cfg.PresenceTTL)
	br := bridge.New(rdb, h, log)
	srv := server.New(verifier, h, tracker, br, log)

	// Outbound dispatch loop (panel -> agent).
	go func() {
		if err := br.Run(ctx); err != nil && !errors.Is(err, context.Canceled) {
			log.Error("bridge stopped", "error", err)
			stop()
		}
	}()

	httpServer := &http.Server{
		Addr:              cfg.Listen,
		Handler:           srv.Handler(),
		ReadHeaderTimeout: 10 * time.Second,
	}

	go func() {
		<-ctx.Done()
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		_ = httpServer.Shutdown(shutdownCtx)
	}()

	log.Info("gateway listening", "addr", cfg.Listen, "panel", cfg.PanelURL, "redis", cfg.RedisAddr)
	if err := httpServer.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Error("http server error", "error", err)
		os.Exit(1)
	}
	log.Info("gateway stopped")
}
