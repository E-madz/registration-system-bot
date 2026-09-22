#!/usr/bin/env bash
#
# Starts a Pinggy tunnel pointing at the local Laravel server and registers
# its URL as the webhook for every messenger that has credentials configured
# in backend/.env (Telegram, Bale, Rubika). One tunnel, one port, all three
# bots can be live at the same time — only the URL path differs per
# platform. For local development only.
#
# Uses Pinggy (https://pinggy.io) rather than localtunnel: it's a plain SSH
# reverse tunnel (no package to install, just the system `ssh`), it connects
# over port 443 so it looks like ordinary HTTPS traffic to any firewall/ISP
# in between, and it doesn't suffer from localtunnel's well-known shared
# public server flakiness. The one tradeoff on the free tier is that each
# tunnel is capped at 60 minutes before Pinggy closes it - this script treats
# that exactly like any other unexpected disconnect (see below) and
# reconnects automatically, so it's a non-issue in practice.
#
# Two things are worked around here that would otherwise require a person to
# notice and restart by hand:
#   1. The tunnel can drop on its own (60-minute free-tier cap, network
#      blips) without any of our webhooks being re-registered, silently
#      taking every platform's webhook down.
#   2. Rubika's updateBotEndpoints validates the URL by actually trying to
#      reach it, and can report {"status":"InvalidUrl"} against a given
#      tunnel/exit-node for reasons outside our control.
# Both are handled the same way: get a fresh tunnel (new subdomain) and
# retry, up to a limit.
#
# Usage:
#   ./scripts/dev-tunnel.sh
#
# Optional env overrides:
#   PORT=8000                                (Laravel port)
#   PROXY_URL=http://127.0.0.1:12334          (set to "" to skip using a proxy)
#   RUBIKA_MAX_RETRIES=20                     (fresh-tunnel attempts for Rubika's InvalidUrl)
#
# PROXY_URL is only used for this script's own setWebhook calls to Telegram
# (a foreign service, typically blocked without a VPN in Iran) - Pinggy's own
# connection runs over port 443 directly and does not need it. Bale and
# Rubika are Iranian services, so their setup calls always bypass the proxy.
# The app itself also honors TELEGRAM_HTTP_PROXY (see backend/.env.example)
# so a queue worker or any other process that talks to Telegram picks up the
# same proxy without needing this script's shell env.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT_DIR/backend/.env"
PORT="${PORT:-8000}"
PROXY_URL="${PROXY_URL-http://127.0.0.1:12334}"
RUBIKA_MAX_RETRIES="${RUBIKA_MAX_RETRIES:-20}"
PINGGY_HOST="a.pinggy.io"

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

if ! command -v ssh >/dev/null 2>&1; then
    echo "Error: 'ssh' is not installed (needed for the Pinggy tunnel)." >&2
    exit 1
fi

if [[ -n "$PROXY_URL" ]]; then
    export https_proxy="$PROXY_URL"
    export http_proxy="$PROXY_URL"
    echo "Using proxy for Telegram setup calls: $PROXY_URL"
fi

TUNNEL_PID=""
TUNNEL_LOG=""

cleanup() {
    echo
    if [[ -n "$TUNNEL_PID" ]]; then
        echo "Stopping tunnel (pid $TUNNEL_PID)..."
        kill "$TUNNEL_PID" 2>/dev/null || true
    fi
    [[ -n "$TUNNEL_LOG" ]] && rm -f "$TUNNEL_LOG"
}
trap cleanup EXIT INT TERM

# Starts a fresh Pinggy tunnel and prints its URL on stdout. Kills any
# previous tunnel first. Returns non-zero if no URL shows up in time.
start_tunnel() {
    if [[ -n "$TUNNEL_PID" ]]; then
        kill "$TUNNEL_PID" 2>/dev/null || true
        wait "$TUNNEL_PID" 2>/dev/null || true
    fi
    [[ -n "$TUNNEL_LOG" ]] && rm -f "$TUNNEL_LOG"

    TUNNEL_LOG="$(mktemp)"
    # -R0:localhost:PORT asks Pinggy to allocate a random remote port that
    # forwards to our local port. Reading stdin from /dev/null keeps ssh from
    # ever trying to read from (or fight the calling shell over) a
    # controlling terminal, which matters here since this runs backgrounded.
    ssh -p 443 \
        -o StrictHostKeyChecking=no \
        -o UserKnownHostsFile=/dev/null \
        -o ServerAliveInterval=30 \
        -o ServerAliveCountMax=3 \
        -R0:localhost:"$PORT" "$PINGGY_HOST" \
        < /dev/null > "$TUNNEL_LOG" 2>&1 &
    TUNNEL_PID=$!

    local url=""
    for _ in $(seq 1 30); do
        if ! kill -0 "$TUNNEL_PID" 2>/dev/null; then
            return 1
        fi
        # Pinggy's free tier prints two URLs (one per domain); either works,
        # so the first one found is fine. Anchored to line-start so this
        # never matches the "upgrade to Pro" line, which mentions
        # https://dashboard.pinggy.io mid-sentence rather than at the start
        # of a line.
        if grep -qE '^https://' "$TUNNEL_LOG" 2>/dev/null; then
            url="$(grep -E '^https://' "$TUNNEL_LOG" | head -n1)"
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
    echo "Waiting for Pinggy tunnel URL (attempt $attempt/$RUBIKA_MAX_RETRIES)..."
    if ! TUNNEL_URL="$(start_tunnel)"; then
        echo "Pinggy tunnel didn't come up in time, retrying..." >&2
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
echo "Free-tier Pinggy tunnels expire after 60 minutes - this script reconnects"
echo "and re-registers every webhook automatically when that (or any other"
echo "disconnect) happens, so it's safe to just leave this running."
echo

# Keeps the tunnel alive: whenever the ssh process exits on its own (the
# 60-minute free-tier cap, a network blip, etc.), the webhook URL it was
# serving is dead and every platform stops receiving updates until someone
# notices - so get a fresh tunnel and re-register immediately instead of
# waiting on a person to catch it.
while true; do
    wait "$TUNNEL_PID" 2>/dev/null || true
    echo
    echo "Tunnel process ended (expired or disconnected). Restarting and re-registering webhooks..."

    for attempt in $(seq 1 "$RUBIKA_MAX_RETRIES"); do
        if ! TUNNEL_URL="$(start_tunnel)"; then
            echo "Pinggy tunnel didn't come up in time, retrying..." >&2
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
