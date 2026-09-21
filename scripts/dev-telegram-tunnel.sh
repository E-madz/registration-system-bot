#!/usr/bin/env bash
#
# Starts a localtunnel pointing at the local Laravel server and registers
# the resulting URL as the Telegram webhook. For local development only.
#
# Usage:
#   ./scripts/dev-telegram-tunnel.sh
#
# Optional env overrides:
#   PORT=8000                                (Laravel port)
#   PROXY_URL=http://127.0.0.1:12334          (set to "" to skip using a proxy)

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT_DIR/backend/.env"
PORT="${PORT:-8000}"
PROXY_URL="${PROXY_URL-http://127.0.0.1:12334}"

if [[ ! -f "$ENV_FILE" ]]; then
    echo "Error: $ENV_FILE not found. Copy backend/.env.example to backend/.env first." >&2
    exit 1
fi

TELEGRAM_BOT_TOKEN="$(grep -E '^TELEGRAM_BOT_TOKEN=' "$ENV_FILE" | cut -d '=' -f2-)"
TELEGRAM_WEBHOOK_SECRET="$(grep -E '^TELEGRAM_WEBHOOK_SECRET=' "$ENV_FILE" | cut -d '=' -f2-)"

if [[ -z "$TELEGRAM_BOT_TOKEN" || -z "$TELEGRAM_WEBHOOK_SECRET" ]]; then
    echo "Error: TELEGRAM_BOT_TOKEN or TELEGRAM_WEBHOOK_SECRET is empty in $ENV_FILE." >&2
    exit 1
fi

if [[ -n "$PROXY_URL" ]]; then
    export https_proxy="$PROXY_URL"
    export http_proxy="$PROXY_URL"
    echo "Using proxy: $PROXY_URL"
fi

if ! command -v lt >/dev/null 2>&1; then
    echo "Error: 'lt' (localtunnel) is not installed. Run: npm install -g localtunnel" >&2
    exit 1
fi

LT_LOG="$(mktemp)"
lt --port "$PORT" > "$LT_LOG" 2>&1 &
LT_PID=$!

cleanup() {
    echo
    echo "Stopping localtunnel (pid $LT_PID)..."
    kill "$LT_PID" 2>/dev/null || true
    rm -f "$LT_LOG"
}
trap cleanup EXIT INT TERM

echo "Waiting for localtunnel URL..."
TUNNEL_URL=""
for _ in $(seq 1 30); do
    if grep -q "your url is:" "$LT_LOG" 2>/dev/null; then
        TUNNEL_URL="$(grep "your url is:" "$LT_LOG" | tail -n1 | sed 's/your url is: //')"
        break
    fi
    sleep 1
done

if [[ -z "$TUNNEL_URL" ]]; then
    echo "Error: localtunnel did not produce a URL in time. Log output:" >&2
    cat "$LT_LOG" >&2
    exit 1
fi

echo "Tunnel URL: $TUNNEL_URL"

WEBHOOK_URL="$TUNNEL_URL/api/webhook/telegram/$TELEGRAM_WEBHOOK_SECRET"
echo "Setting Telegram webhook to: $WEBHOOK_URL"

RESPONSE="$(curl -s "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/setWebhook?url=${WEBHOOK_URL}")"
echo "Telegram response: $RESPONSE"

if [[ "$RESPONSE" != *'"ok":true'* ]]; then
    echo "Warning: Telegram did not confirm the webhook was set." >&2
fi

echo
echo "Tunnel is running. Press Ctrl+C to stop."
wait "$LT_PID"
