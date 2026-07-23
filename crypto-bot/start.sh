#!/usr/bin/env bash
#
# Start de hele crypto-bot met één commando: backend (API + worker + scheduler)
# en de Vue-frontend. Idempotent — je kunt 'm zo vaak draaien als je wilt.
#
#   ./start.sh
#
# Stoppen: ./stop.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"
API_PORT="${API_PORT:-8001}"
WEB_PORT="${WEB_PORT:-4000}"

log() { printf '\033[1;36m▶ %s\033[0m\n' "$1"; }
ok()  { printf '\033[1;32m✓ %s\033[0m\n' "$1"; }

# Portable in-place sed (werkt op macOS én Linux).
sedi() { sed -i.bak "$1" "$2" && rm -f "$2.bak"; }

# --- Vereisten checken ---
for cmd in php composer node npm; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "✗ '$cmd' ontbreekt. Installeer het en probeer opnieuw."; exit 1; }
done

# --- Backend klaarzetten ---
log "Backend voorbereiden…"
cd "$BACKEND"
[ -d vendor ] || composer install --no-interaction
[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force
[ -f database/database.sqlite ] || touch database/database.sqlite
php artisan migrate --force >/dev/null
php artisan bot:seed-account >/dev/null 2>&1 || true
grep -q '^BOT_ENABLED=true' .env || sedi 's/^BOT_ENABLED=.*/BOT_ENABLED=true/' .env
ok "Backend klaar"

# --- Frontend klaarzetten ---
log "Frontend voorbereiden…"
cd "$FRONTEND"
[ -d node_modules ] || npm install --no-audit --no-fund
[ -f .env ] || cp .env.example .env
# Zorg dat de frontend naar de juiste API-poort wijst.
sedi "s#^VITE_API_BASE_URL=.*#VITE_API_BASE_URL=http://localhost:${API_PORT}/api#" .env
ok "Frontend klaar"

# --- Oude processen opruimen ---
"$ROOT/stop.sh" >/dev/null 2>&1 || true

# --- Servers starten ---
log "Servers starten…"
cd "$BACKEND"
php artisan serve --host=localhost --port="$API_PORT" > "$ROOT/.api.log" 2>&1 &  echo $! > "$ROOT/.api.pid"
php artisan queue:work --sleep=2 --tries=3      > "$ROOT/.queue.log" 2>&1 &  echo $! > "$ROOT/.queue.pid"
php artisan schedule:work                        > "$ROOT/.schedule.log" 2>&1 & echo $! > "$ROOT/.schedule.pid"
cd "$FRONTEND"
WEB_PORT="$WEB_PORT" npm run dev -- --port "$WEB_PORT" > "$ROOT/.web.log" 2>&1 & echo $! > "$ROOT/.web.pid"

# --- Wachten tot het dashboard reageert ---
log "Wachten tot het dashboard draait…"
for _ in $(seq 1 40); do
  if curl -fsS -o /dev/null "http://localhost:${WEB_PORT}" 2>/dev/null; then break; fi
  sleep 1
done

echo
ok "Dashboard:  http://localhost:${WEB_PORT}"
ok "API:        http://localhost:${API_PORT}/api/status"
echo "  Logs: $ROOT/.web.log  |  $ROOT/.api.log"
echo "  Stoppen: $ROOT/stop.sh"
command -v open >/dev/null 2>&1 && open "http://localhost:${WEB_PORT}" >/dev/null 2>&1 || true
