#!/usr/bin/env bash
#
# Campaign API - one-shot bootstrap for Docker environments.
#
# Usage:
#   ./scripts/deploy.sh          # build + start everything, first run included
#   ./scripts/deploy.sh --reset  # additionally wipe the MySQL data volume
#
# Chris Delport <chrisd@chrisd.co.za>

set -euo pipefail

cd "$(dirname "$0")/.."

RESET=false
if [[ "${1:-}" == "--reset" ]]; then
    RESET=true
fi

say()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------- pre-flight
command -v docker >/dev/null 2>&1 || fail "Docker is not installed. See https://docs.docker.com/get-docker/"
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is required (built into recent Docker Desktop)."
docker info >/dev/null 2>&1 || fail "Docker daemon is not running. Start Docker Desktop / dockerd and retry."

say "Environment looks good: $(docker --version)"

# ------------------------------------------------------------- env + app key
if [[ ! -f .env ]]; then
    say "No .env found - creating one from .env.example"
    cp .env.example .env
fi

# APP_KEY is generated once and left alone on subsequent runs (idempotent).
if ! grep -q '^APP_KEY=base64:' .env; then
    say "Generating APP_KEY via a throwaway container (no running services needed)"
    APP_KEY="$(docker compose run --rm --no-deps app php artisan key:generate --show)"
    if command -v sed >/dev/null 2>&1; then
        sed -i.bak "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env && rm -f .env.bak
    else
        fail "Could not write APP_KEY into .env (sed unavailable). Add 'APP_KEY=${APP_KEY}' manually."
    fi
    echo "APP_KEY set."
fi

# --------------------------------------------------------------- optional --reset
if $RESET; then
    say "--reset: stopping stack and deleting the MySQL data volume"
    docker compose down -v
fi

# ----------------------------------------------------------------- build + up
say "Building images and starting services (app, nginx, mysql, redis, queue)"
docker compose up -d --build

# ------------------------------------------------------- wait for the database
# Probe the mysql container itself with mysqladmin ping - no dependency on
# app-container env vars (compose passes only APP_ENV; DB_* live in .env and
# are read by Laravel, not the shell).
say "Waiting for MySQL to accept connections"
ATTEMPTS=0
until docker compose exec -T mysql mysqladmin ping -h localhost --silent >/dev/null 2>&1; do
    ATTEMPTS=$((ATTEMPTS + 1))
    if [[ $ATTEMPTS -ge 60 ]]; then
        docker compose logs --tail 50 mysql || true
        fail "MySQL did not become ready in time. Check the logs above."
    fi
    sleep 2
done
echo "MySQL is ready."

# ---------------------------------------------------------------- migrations
# Retry a few times in case migrations race the server's first seconds.
say "Running migrations"
ATTEMPTS=0
until docker compose exec -T app php artisan migrate --force; do
    ATTEMPTS=$((ATTEMPTS + 1))
    if [[ $ATTEMPTS -ge 5 ]]; then
        fail "Migrations failed after 5 attempts. Check 'docker compose logs app'."
    fi
    sleep 3
done

# ------------------------------------------------------- wait for the API
say "Waiting for the API to respond"
ATTEMPTS=0
PORT="$(grep -E '^APP_PORT=' .env | cut -d= -f2 || true)"
PORT="${PORT:-8000}"
until curl -fsS -o /dev/null "http://localhost:${PORT}/api/clients"; do
    ATTEMPTS=$((ATTEMPTS + 1))
    if [[ $ATTEMPTS -ge 30 ]]; then
        docker compose logs --tail 50 nginx app || true
        fail "API did not become ready in time. Check the logs above."
    fi
    sleep 2
done

say "Deploy complete!"
cat <<EOF

  API:      http://localhost:${PORT}
  Clients:  GET  /api/clients
  Campaigns: POST /api/campaigns, GET /api/campaigns
  Import:   POST /api/campaigns/{id}/data   (202 - processed by the queue worker)

  Try it:
    curl -X POST http://localhost:${PORT}/api/clients -H 'Content-Type: application/json' -d '{"name":"Acme Corp"}'

  Tear down:  docker compose down          (keep data)
              docker compose down -v       (delete data)
EOF
