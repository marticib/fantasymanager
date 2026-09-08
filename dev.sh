#!/usr/bin/env bash
#
# Arrenca Fantasy Assistant en local: backend (Laravel), queue worker i
# scheduler, i el frontend (Vite). Ctrl+C atura tot net.
#
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
FRONTEND_DIR="$ROOT_DIR/frontend"
LOG_DIR="$ROOT_DIR/storage-dev-logs"
BACKEND_PORT="${BACKEND_PORT:-8000}"
FRONTEND_PORT="${FRONTEND_PORT:-5173}"

mkdir -p "$LOG_DIR"

log() { echo -e "\033[1;34m[dev]\033[0m $1"; }

# --- comprovacions bàsiques ---
command -v php >/dev/null || { echo "Falta PHP."; exit 1; }
command -v composer >/dev/null || { echo "Falta Composer."; exit 1; }
command -v node >/dev/null || { echo "Falta Node.js."; exit 1; }
command -v npm >/dev/null || { echo "Falta npm."; exit 1; }

[ -f "$BACKEND_DIR/.env" ] || { echo "Falta $BACKEND_DIR/.env — copia .env.example i configura'l."; exit 1; }
[ -d "$BACKEND_DIR/vendor" ] || { log "Instal·lant dependències del backend…"; (cd "$BACKEND_DIR" && composer install); }
[ -d "$FRONTEND_DIR/node_modules" ] || { log "Instal·lant dependències del frontend…"; (cd "$FRONTEND_DIR" && npm install); }
[ -f "$FRONTEND_DIR/.env" ] || cp "$FRONTEND_DIR/.env.example" "$FRONTEND_DIR/.env"

# --- allibera els ports si ja estan ocupats per una execució anterior ---
for port in "$BACKEND_PORT" "$FRONTEND_PORT"; do
  pid=$(lsof -ti:"$port" -sTCP:LISTEN 2>/dev/null || true)
  [ -n "$pid" ] && { log "Alliberant el port $port (PID $pid)…"; kill "$pid" 2>/dev/null || true; }
done

# --- comprova connexió a la base de dades (avisa però no bloqueja) ---
if ! (cd "$BACKEND_DIR" && php artisan db:show >/dev/null 2>&1); then
  log "⚠️  No es pot connectar a la base de dades. Revisa DB_* a backend/.env (i que PostgreSQL estigui aixecat)."
fi

PIDS=()
cleanup() {
  log "Aturant tots els processos…"
  for pid in "${PIDS[@]}"; do
    kill "$pid" 2>/dev/null || true
  done
  wait 2>/dev/null || true
}
trap cleanup EXIT INT TERM

log "Backend  → http://localhost:$BACKEND_PORT  (log: storage-dev-logs/backend.log)"
(cd "$BACKEND_DIR" && php artisan serve --port="$BACKEND_PORT") >"$LOG_DIR/backend.log" 2>&1 &
PIDS+=($!)

log "Queue worker → storage-dev-logs/queue.log"
(cd "$BACKEND_DIR" && php artisan queue:work --tries=3 --sleep=3) >"$LOG_DIR/queue.log" 2>&1 &
PIDS+=($!)

log "Scheduler → storage-dev-logs/scheduler.log"
(cd "$BACKEND_DIR" && php artisan schedule:work) >"$LOG_DIR/scheduler.log" 2>&1 &
PIDS+=($!)

log "Frontend → http://localhost:$FRONTEND_PORT  (log: storage-dev-logs/frontend.log)"
(cd "$FRONTEND_DIR" && npm run dev -- --port="$FRONTEND_PORT" --strictPort) >"$LOG_DIR/frontend.log" 2>&1 &
PIDS+=($!)

# espera que el backend respongui abans de donar-ho per bo
for i in $(seq 1 30); do
  curl -sf "http://localhost:$BACKEND_PORT/up" >/dev/null 2>&1 && break
  sleep 1
done

echo
log "Tot enlaire. Ctrl+C per aturar."
log "  App:    http://localhost:$FRONTEND_PORT"
log "  API:    http://localhost:$BACKEND_PORT/api"
echo

wait
