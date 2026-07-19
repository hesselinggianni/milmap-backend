#!/usr/bin/env bash
#
# Stop alle door start.sh gestarte processen (API, worker, scheduler, frontend).
#
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

for name in api queue schedule web; do
  pidfile="$ROOT/.${name}.pid"
  if [ -f "$pidfile" ]; then
    pid="$(cat "$pidfile" 2>/dev/null || true)"
    if [ -n "${pid:-}" ] && kill -0 "$pid" 2>/dev/null; then
      # Kill de procesboom (dev-servers spawnen child-processen).
      pkill -P "$pid" 2>/dev/null || true
      kill "$pid" 2>/dev/null || true
    fi
    rm -f "$pidfile"
  fi
done

echo "✓ Alles gestopt."
