#!/usr/bin/env bash
#
# Starts a localtunnel pointing at the local Laravel server and registers its
# URL as the webhook for every messenger that has credentials configured in
# backend/.env (Telegram, Bale, Rubika). One tunnel, one port, all three bots
# can be live at the same time — only the URL path differs per platform. For
# local development only.
#
# localtunnel is flaky in two ways this script now works around:
#   1. The `lt` process can die on its own (idle disconnects, network blips)
#      without any of our webhooks being re-registered, silently taking every
#      platform's webhook down until someone notices.
#   2. Rubika's updateBotEndpoints validates the URL by actually trying to
#      reach it, and fairly often reports {"status":"InvalidUrl"} against a
#      freshly-started tunnel/exit-node for reasons outside our control.
# Both are handled the same way here: get a fresh tunnel (new subdomain) and
# retry, up to a limit, instead of requiring a person to notice and restart
# by hand.
#
# Usage:
#   ./scripts/dev-tunnel.sh
#
# Optional env overrides:
#   PORT=8000                                (Laravel port)
#   PROXY_URL=http://127.0.0.1:12334          (set to "" to skip using a proxy)
#   RUBIKA_MAX_RETRIES=20                     (fresh-tunnel attempts for Rubika's InvalidUrl)
#
# PROXY_URL is only used for the tunnel process itself and this script's own
# setWebhook calls (both foreign services, typically blocked without a VPN in
# Iran). Bale and Rubika are Iranian services, so their setup calls always
# bypass the proxy. The app itself now also honors TELEGRAM_HTTP_PROXY (see
# backend/.env.example) so a queue worker or any other process that talks to
# Telegram picks up the same proxy without needing this script's shell env.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT_DIR/backend/.env"
PORT="${PORT:-8000}"
PROXY_URL="${PROXY_URL-http://127.0.0.1:12334}"
RUBIKA_MAX_RETRIES="${RUBIKA_MAX_RETRIES:-20}"

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
    echo "Using proxy for the tunnel + Telegram setup calls: $PROXY_URL"
fi

LT_PID=""
LT_LOG=""

cleanup() {
    echo
    if [[ -n "$LT_PID" ]]; then
        echo "Stopping localtunnel (pid $LT_PID)..."
        kill "$LT_PID" 2>/dev/null || true
    fi
    [[ -n "$LT_LOG" ]] && rm -f "$LT_LOG"
}
trap cleanup EXIT INT TERM

# Starts a fresh localtunnel process and prints its URL on stdout. Kills any
# previous tunnel first. Returns non-zero if no URL shows up in time.
start_tunnel() {
    if [[ -n "$LT_PID" ]]; then
        kill "$LT_PID" 2>/dev/null || true
        wait "$LT_PID" 2>/dev/null || true
    fi
    [[ -n "$LT_LOG" ]] && rm -f "$LT_LOG"

    LT_LOG="$(mktemp)"
    lt --port "$PORT" > "$LT_LOG" 2>&1 &
    LT_PID=$!

    local url=""
    for _ in $(seq 1 30); do
        if ! kill -0 "$LT_PID" 2>/dev/null; then
            return 1
        fi
        if grep -q "your url is:" "$LT_LOG" 2>/dev/null; then
            url="$(grep "your url is:" "$LT_LOG" | tail -n1 | sed 's/your url is: //')"
            break
        fi
        sleep 1
    done

    if [[ -z "$url" ]]; then
        return 1
    fi

    echo "$url"
}

register_telegram() {
    local tunnel_url="$1"
    [[ -z "$TELEGRAM_BOT_TOKEN" || -z "$TELEGRAM_WEBHOOK_SECRET" ]] && return 0

    local webhook_url="$tunnel_url/api/webhook/telegram/$TELEGRAM_WEBHOOK_SECRET"
    echo "Setting Telegram webhook to: $webhook_url"
    local response
    response="$(curl -s "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/setWebhook?url=${webhook_url}")"
    echo "Telegram response: $response"
    echo
}

register_bale() {
    local tunnel_url="$1"
    [[ -z "$BALE_BOT_TOKEN" || -z "$BALE_WEBHOOK_SECRET" ]] && return 0

    local webhook_url="$tunnel_url/api/webhook/bale/$BALE_WEBHOOK_SECRET"
    echo "Setting Bale webhook to: $webhook_url"
    local response
    response="$(curl -s --noproxy '*' "https://tapi.bale.ai/bot${BALE_BOT_TOKEN}/setWebhook?url=${webhook_url}")"
    echo "Bale response: $response"
    echo
}

# Registers both Rubika endpoint types against the given tunnel URL. Prints
# every response and returns non-zero if either came back InvalidUrl, so the
# caller can decide to retry with a fresh tunnel.
register_rubika() {
    local tunnel_url="$1"
    [[ -z "$RUBIKA_BOT_TOKEN" || -z "$RUBIKA_WEBHOOK_SECRET" ]] && return 0

    local webhook_url="$tunnel_url/api/webhook/rubika/$RUBIKA_WEBHOOK_SECRET"
    echo "Setting Rubika webhook to: $webhook_url"

    local saw_invalid_url=0
    # chat_keypad taps arrive via ReceiveUpdate. inline_keypad taps need a
    # SEPARATE endpoint, ReceiveInlineMessage (confirmed against two
    # community libraries).
    for type in ReceiveUpdate ReceiveInlineMessage; do
        local response
        response="$(curl -s --noproxy '*' -X POST "https://botapi.rubika.ir/v3/${RUBIKA_BOT_TOKEN}/updateBotEndpoints" \
            -H "Content-Type: application/json" \
            -d "{\"url\":\"${webhook_url}\",\"type\":\"${type}\"}")"
        echo "Rubika response (${type}): $response"
        if [[ "$response" == *InvalidUrl* ]]; then
            saw_invalid_url=1
        fi
    done
    echo

    return $saw_invalid_url
}

# Starts a tunnel and registers every configured platform against it,
# retrying with a fresh tunnel (new subdomain) while Rubika keeps rejecting
# the URL as invalid. Telegram/Bale are re-registered on every retry too,
# since a new tunnel means a new URL for all three.
TUNNEL_URL=""
for attempt in $(seq 1 "$RUBIKA_MAX_RETRIES"); do
    echo "Waiting for localtunnel URL (attempt $attempt/$RUBIKA_MAX_RETRIES)..."
    if ! TUNNEL_URL="$(start_tunnel)"; then
        echo "localtunnel didn't come up in time, retrying..." >&2
        continue
    fi
    echo "Tunnel URL: $TUNNEL_URL"
    echo

    register_telegram "$TUNNEL_URL"
    register_bale "$TUNNEL_URL"

    if register_rubika "$TUNNEL_URL"; then
        break
    fi

    if [[ -z "$RUBIKA_BOT_TOKEN" ]]; then
        break
    fi

    echo "Rubika rejected this tunnel URL, getting a new one and retrying..."
    echo
    TUNNEL_URL=""
done

if [[ -z "$TUNNEL_URL" ]]; then
    echo "Error: could not get a tunnel URL Rubika accepted after $RUBIKA_MAX_RETRIES attempts." >&2
    exit 1
fi

echo "Tunnel is running. Press Ctrl+C to stop."
echo "Watching it and re-registering all webhooks automatically if it dies."
echo

# Keeps the tunnel alive: if `lt` exits on its own (idle disconnect, network
# blip, etc.), the webhook URL it was serving is dead and every platform
# stops receiving updates until someone notices - so get a fresh tunnel and
# re-register immediately instead of waiting on a person to catch it.
while true; do
    wait "$LT_PID" 2>/dev/null || true
    echo
    echo "Tunnel process ended unexpectedly. Restarting and re-registering webhooks..."

    for attempt in $(seq 1 "$RUBIKA_MAX_RETRIES"); do
        if ! TUNNEL_URL="$(start_tunnel)"; then
            echo "localtunnel didn't come up in time, retrying..." >&2
            continue
        fi
        echo "New tunnel URL: $TUNNEL_URL"
        echo

        register_telegram "$TUNNEL_URL"
        register_bale "$TUNNEL_URL"

        if register_rubika "$TUNNEL_URL"; then
            break
        fi
        [[ -z "$RUBIKA_BOT_TOKEN" ]] && break

        echo "Rubika rejected this tunnel URL, getting a new one and retrying..."
        echo
    done
done
