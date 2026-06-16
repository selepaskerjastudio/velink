#!/bin/bash
set -e

# ── Colors ────────────────────────────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
CYAN='\033[0;36m'
BOLD='\033[1m'
RESET='\033[0m'

# ── ASCII banner ───────────────────────────────────────────────────────────────
echo -e "${BOLD}${CYAN}"
cat << 'EOF'
 __   __ _____  _      ___  _   _ _  __
 \ \ / /| ____|| |    |_ _|| \ | || |/ /
  \ V / |  _|  | |     | | |  \| || ' /
   | |  | |___ | |___  | | | |\  || . \
   |_|  |_____||_____|___||_| \_||_|\_\
EOF
echo -e "${RESET}"

# ── Helpers ────────────────────────────────────────────────────────────────────
STEP=0

step() {
    STEP=$((STEP + 1))
    echo -e "\n${BOLD}[${STEP}] $1${RESET}"
}

ok() {
    echo -e "    ${GREEN}✓${RESET} $1"
}

spin() {
    local pid=$1 msg="$2"
    local frames='⠋⠙⠹⠸⠼⠴⠦⠧⠣⠏'
    local i=0
    while kill -0 "$pid" 2>/dev/null; do
        printf "\r    ${YELLOW}${frames:$i:1}${RESET} %s" "$msg"
        i=$(( (i + 1) % ${#frames} ))
        sleep 0.08
    done
    printf "\r\033[K"
}

run() {
    local msg="$1"; shift
    local tmp; tmp=$(mktemp)
    "$@" >"$tmp" 2>&1 &
    local pid=$!
    spin "$pid" "$msg"
    wait "$pid"; local code=$?
    if [ $code -ne 0 ]; then
        echo -e "    ${RED}✗ $msg${RESET}"
        cat "$tmp"
        rm -f "$tmp"
        exit $code
    fi
    rm -f "$tmp"
    ok "$msg"
}

# ── Args ───────────────────────────────────────────────────────────────────────
RESTART_GATEWAY=false
for arg in "$@"; do
    case $arg in
        --gateway) RESTART_GATEWAY=true ;;
    esac
done

REPO_DIR="$(cd "$(dirname "$0")" && pwd)"

# ── Steps ──────────────────────────────────────────────────────────────────────
step "Pulling latest code"
run "git pull" git -C "$REPO_DIR" pull

step "Installing PHP dependencies"
cd "$REPO_DIR/panel"
run "Composer install" composer install --no-dev --optimize-autoloader --quiet

step "Building frontend assets"
run "npm install" npm ci --silent
run "Vite build" npm run build

step "Running database migrations"
run "Migrate" php artisan migrate --force

step "Refreshing caches"
run "Config cache" php artisan config:cache
run "Route cache"  php artisan route:cache
run "View cache"   php artisan view:cache

step "Restarting panel services"
run "velink-queue"        systemctl restart velink-queue
run "velink-reverb"       systemctl restart velink-reverb
run "velink-agent-listen" systemctl restart velink-agent-listen

if [ "$RESTART_GATEWAY" = true ]; then
    step "Rebuilding and restarting gateway"
    cd "$REPO_DIR/gateway"
    run "Build gateway binary" go build -o /usr/local/bin/velink-gateway ./cmd/gateway
    run "velink-gateway"       systemctl restart velink-gateway
fi

echo -e "\n${GREEN}${BOLD}Deploy complete.${RESET}\n"
