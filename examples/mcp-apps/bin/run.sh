#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [ -f .env ]; then
    set -a
    source .env
    set +a
fi

mkdir -p var/conversations var/views var/history

if [ ! -d fixture/node_modules ]; then
    echo "Installing fixture dependencies..."
    (cd fixture && npm install --no-audit --no-fund)
fi

cleanup() {
    kill "${FIXTURE_PID:-}" "${HOST_PID:-}" "${VIEW_PID:-}" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

(cd fixture && npm start) &
FIXTURE_PID=$!

for _ in {1..50}; do
    if curl -fsS http://127.0.0.1:3001/healthz >/dev/null 2>&1; then
        break
    fi
    sleep 0.2
done

MCP_APPS_ROLE=host php -S 127.0.0.1:8080 -t public router.php >var/host.log 2>&1 &
HOST_PID=$!
MCP_APPS_ROLE=view php -S 127.0.0.1:8081 -t public router.php >var/view.log 2>&1 &
VIEW_PID=$!

echo "Chat UI:      http://127.0.0.1:8080"
echo "View origin:  http://127.0.0.1:8081 (not opened directly)"
echo "MCP fixture:  http://127.0.0.1:3001/mcp"
echo "Press Ctrl-C to stop."

wait
