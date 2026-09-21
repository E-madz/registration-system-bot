#!/usr/bin/env bash
#
# Starts a single localtunnel pointing at the local Laravel server and
# registers its URL as the webhook for every messenger that has credentials
# configured in backend/.env (Telegram, Bale, Rubika). One tunnel, one port,
# all three bots can be live at the same time — only the URL path differs
# per platform. For local development only.
#
# Usage:
#   ./scripts/dev-tunnel.sh
#
# Optional env overrides:
#   PORT=8000                                (Laravel port)
#   PROXY_URL=http://127.0.0.1:12334          (set to "" to skip using a proxy)
#
# PROXY_URL is only used for the tunnel itself and the Telegram API call
# (both foreign services, typically blocked without a VPN in Iran). Bale and
# Rubika are Iranian services, so their setup calls always bypass the proxy.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT_DIR/backend/.env"
PORT="${PORT:-8000}"
PROXY_URL="${PROXY_URL-http://127.0.0.1:12334}"

if [[ ! -f "$ENV_FILE" ]]; then
    echo "Error: $ENV_FILE not found. Copy backend/.env.example to backend/.env first." >&2
    exit 1
fi

env_value() {
    grep -E "^$1=" "$ENV_FILE" | head -n1 | cut -d '=' -f2-
}

TELEGRAM_BOT_TOKEN="$(env_value TELEGRAM_BOT_TOKEN)"
TELEGRAM_WEBHOOK_SECRET="$(env_value TELEGRAM_WEBHOOK_SECRET)"
BALE_BOT_TOKEN="$(env_value BALE_BOT_TOKEN)"
BALE_WEBHOOK_SECRET="$(env_value BALE_WEBHOOK_SECRET)"
RUBIKA_BOT_TOKEN="$(env_value RUBIKA_BOT_TOKEN)"
RUBIKA_WEBHOOK_SECRET="$(env_value RUBIKA_WEBHOOK_SECRET)"

if [[ -z "$TELEGRAM_BOT_TOKEN$BALE_BOT_TOKEN$RUBIKA_BOT_TOKEN" ]]; then
    echo "Error: no bot tokens configured in $ENV_FILE (TELEGRAM_BOT_TOKEN / BALE_BOT_TOKEN / RUBIKA_BOT_TOKEN)." >&2
    exit 1
fi

if ! command -v lt >/dev/null 2>&1; then
    echo "Error: 'lt' (localtunnel) is not installed. Run: npm install -g localtunnel" >&2
    exit 1
fi

if [[ -n "$PROXY_URL" ]]; then
    export https_proxy="$PROXY_URL"
    export http_proxy="$PROXY_URL"
    echo "Using proxy for the tunnel + Telegram: $PROXY_URL"
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
echo

if [[ -n "$TELEGRAM_BOT_TOKEN" && -n "$TELEGRAM_WEBHOOK_SECRET" ]]; then
    WEBHOOK_URL="$TUNNEL_URL/api/webhook/telegram/$TELEGRAM_WEBHOOK_SECRET"
    echo "Setting Telegram webhook to: $WEBHOOK_URL"
    RESPONSE="$(curl -s "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/setWebhook?url=${WEBHOOK_URL}")"
    echo "Telegram response: $RESPONSE"
    echo
fi

if [[ -n "$BALE_BOT_TOKEN" && -n "$BALE_WEBHOOK_SECRET" ]]; then
    WEBHOOK_URL="$TUNNEL_URL/api/webhook/bale/$BALE_WEBHOOK_SECRET"
    echo "Setting Bale webhook to: $WEBHOOK_URL"
    RESPONSE="$(curl -s --noproxy '*' "https://tapi.bale.ai/bot${BALE_BOT_TOKEN}/setWebhook?url=${WEBHOOK_URL}")"
    echo "Bale response: $RESPONSE"
    echo
fi

if [[ -n "$RUBIKA_BOT_TOKEN" && -n "$RUBIKA_WEBHOOK_SECRET" ]]; then
    WEBHOOK_URL="$TUNNEL_URL/api/webhook/rubika/$RUBIKA_WEBHOOK_SECRET"
    echo "Setting Rubika webhook to: $WEBHOOK_URL"
    # chat_keypad taps arrive via ReceiveUpdate. inline_keypad taps need a
    # SEPARATE endpoint, ReceiveInlineMessage (confirmed against two
    # community libraries) — registering it has so far always returned
    # {"status":"OK","data":{"status":"InvalidUrl"}} in our testing, which
    # is why inline buttons don't work yet. Registering both here so the
    # full response is visible every time we try.
    for TYPE in ReceiveUpdate ReceiveInlineMessage; do
        RESPONSE="$(curl -s --noproxy '*' -X POST "https://botapi.rubika.ir/v3/${RUBIKA_BOT_TOKEN}/updateBotEndpoints" \
            -H "Content-Type: application/json" \
            -d "{\"url\":\"${WEBHOOK_URL}\",\"type\":\"${TYPE}\"}")"
        echo "Rubika response (${TYPE}): $RESPONSE"
    done
    echo
fi

echo "Tunnel is running. Press Ctrl+C to stop."
wait "$LT_PID"
