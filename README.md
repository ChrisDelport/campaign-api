# Campaign API

**Personalized Video Campaign Manager** — Laravel API + Next.js dashboard.
Author: **Chris Delport** — chrisd@chrisd.co.za

A Laravel 12+ (runs on Laravel 13) JSON API for managing client campaigns and asynchronously importing personalised video data, with duplicate detection and analytics — plus a Next.js dashboard (`../campaign-dashboard`).

## Suggested walkthrough order

If you're reading the code, this is the dependency chain:

1. `routes/api.php` — the surface
2. `app/Http/Requests/*` — payload contracts
3. `app/Http/Controllers/Api/*` — orchestration only
4. `app/Jobs/StoreCampaignDataRecords.php` — where the real work lives
5. `database/migrations/2026_01_01_000000_create_campaign_tables.php` — why the schema looks the way it does
6. `app/Console/Commands/CampaignAnalytics.php` — reporting on all of the above
7. `tests/Feature/CampaignApiTest.php` — the behaviours, end to end

## Stack

- PHP 8.3/8.4, Laravel 12+, MySQL 8, Redis 7 (queue + cache)
- Pest (tests), PHPStan + Larastan (static analysis, level 5), Laravel Pint (formatting)
- Docker + Docker Compose (app, nginx, mysql, redis, queue worker)

## Getting Started

### Prerequisites

- Docker with Compose v2 (Docker Desktop on macOS/Windows, `docker-ce` + compose plugin on Linux) — the only dependency; PHP/Composer/Node are **not** required on the host.
- `bash` (macOS/Linux out of the box; on Windows use WSL or Git Bash).

### Quick start

Clone, then run one command:

```sh
./scripts/deploy.sh
```

The script is idempotent — safe to re-run any time. It:

1. verifies Docker is installed and the daemon is running,
2. creates `.env` from `.env.example` if missing, and generates `APP_KEY` once,
3. builds the images and starts the whole stack (`app`, `nginx`, `mysql`, `redis`, `queue` worker),
4. waits for MySQL to accept connections (healthchecked),
5. runs migrations,
6. waits until the API answers, then prints the endpoints and a sample `curl`.

When it finishes, the API is live at **http://localhost:8000** and the Redis queue worker is already consuming import jobs.

```sh
curl -X POST http://localhost:8000/api/clients -H 'Content-Type: application/json' -d '{"name":"Acme Corp"}'
```

Options:

```sh
./scripts/deploy.sh --reset   # also wipe the MySQL data volume (fresh database)
```

### Tear down

```sh
docker compose down      # stop everything, keep database data
docker compose down -v   # stop and delete database data
```

### What's running

| Service | Image | Purpose |
| --- | --- | --- |
| `nginx` | `nginx:1.27-alpine` | HTTP entry point, port 8000 -> php-fpm |
| `app` | built from `Dockerfile` | PHP-FPM application container |
| `mysql` | `mysql:8.4` | Database, data persisted in the `mysql-data` volume |
| `redis` | `redis:7-alpine` | Queue + cache backend |
| `queue` | built from `Dockerfile` | Redis queue worker (`php artisan queue:work redis --tries=3 --max-jobs=1000`) |

### Manual quick start (equivalent, without the script)

```sh
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

The API is served at `http://localhost:8000` via nginx. The `queue` service runs a Redis queue worker (`php artisan queue:work redis --tries=3 --max-jobs=1000`).

## Environment variables

| Variable | Purpose | Default |
| --- | --- | --- |
| `APP_ENV` | Environment (`local`, `staging`, `production`) | `local` |
| `APP_KEY` | Encryption key (`php artisan key:generate`) | — |
| `APP_URL` | Base URL | `http://localhost:8000` |
| `DB_CONNECTION` | Database driver | `mysql` |
| `DB_HOST` | MySQL host (service name in Docker) | `mysql` |
| `DB_PORT` | MySQL port | `3306` |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | MySQL credentials | `campaign` / `secret` |
| `QUEUE_CONNECTION` | Queue backend (`redis` in Docker; tests use `sync`) | `redis` |
| `CACHE_STORE` | Cache backend | `redis` |
| `REDIS_HOST` / `REDIS_PORT` | Redis connection | `redis` / `6379` |
| `FRONTEND_URL` | Allowed CORS origin for the dashboard | `http://localhost:3000` |
| `LOG_CHANNEL` | Application log channel | `stack` |

## API

### Create client

`POST /api/clients`

```json
{ "name": "Acme Corp" }
```

`201 Created` → `{ "data": { "id": 1, "name": "Acme Corp", ... } }`. `GET /api/clients` lists all clients.

### Create campaign

`POST /api/campaigns`

```json
{
  "client_id": 1,
  "name": "Campaign Name",
  "start_date": "2026-01-01",
  "end_date": "2026-01-31"
}
```

`201 Created`

```json
{
  "data": {
    "id": 1,
    "client_id": 1,
    "name": "Campaign Name",
    "start_date": "2026-01-01",
    "end_date": "2026-01-31",
    "created_at": "2026-10-14T10:00:00+00:00",
    "updated_at": "2026-10-14T10:00:00+00:00"
  }
}
```

Validation errors return `422` with Laravel's standard error envelope. `GET /api/campaigns` lists all campaigns (newest first).

`end_date` is optional per the spec; omit it to create an open-ended campaign.

### Import campaign data

`POST /api/campaigns/{campaign_id}/data`

Accepts a bare JSON array (a single object is also accepted and wrapped):

```json
[
  {
    "user_id": "user-123",
    "video_url": "https://example.com/video.mp4",
    "custom_fields": {
      "first_name": "John",
      "surname": "Smith"
    }
  }
]
```

`202 Accepted`

```json
{
  "message": "Accepted for processing.",
  "campaign_id": 1,
  "queued": 1
}
```

Payload is validated synchronously (max 5000 records per request); storage is dispatched to the queue so the API acknowledges immediately. Nothing is persisted by the web process.

## Duplicate handling

- **Detection:** unique constraint `campaign_id + user_id` on `campaign_data` (enforced at the DB level), plus one indexed pre-read per batch to identify `user_id`s that already existed, and an in-memory scan that also catches the same `user_id` submitted twice **within one batch**.
- **Strategy:** update-on-duplicate via `CampaignData::upsert()` — re-imports overwrite `video_url` and `custom_fields` for existing rows (latest payload wins) and insert new rows. Storage is idempotent, so job retries are safe.
- **Logging:** every duplicated `user_id` gets a row in `duplicate_logs` (campaign, user, snapshot of `custom_fields`, idempotency `meta.log_key`) and an entry in the dedicated `duplicates` log channel (`storage/logs/duplicates.log`). The `log_key` makes duplicate logging idempotent — job retries or at-least-once queue delivery never log the same duplicate twice.
- **Reporting:** `duplicate_logs` is queryable (indexed on `campaign_id + user_id` and `created_at`) and feeds the analytics command.

Why not insert-only with rejection? The assessment's preferred strategy is update-on-duplicate, and it makes re-imports idempotent. Why not rely solely on `updated_at > created_at` heuristics? The explicit pre-read is deterministic and keeps the duplicate log row tied to the exact overwritten record.

## Database schema

| Table | Key columns / indexes |
| --- | --- |
| `clients` | `id`, `name`, timestamps |
| `campaigns` | `id`, `client_id` (FK, cascade), `name`, `start_date`, `end_date`, timestamps |
| `campaign_data` | `id`, `campaign_id` (FK, cascade), `user_id`, `video_url`, `custom_fields` (JSON, nullable), timestamps; **unique** `(campaign_id, user_id)` |
| `duplicate_logs` | `id`, `campaign_id` (FK, cascade), `user_id`, `custom_fields` (JSON, nullable), timestamps; index `(campaign_id, user_id)`, index `created_at` |

Migration strategy: single atomic migration for the domain tables (`2026_01_01_000000_create_campaign_tables.php`), Laravel's default framework migrations retained for cache/jobs.

## custom_fields

Stored as a MySQL `JSON` column with an `array` cast. Arbitrary keys, no schema changes, fully queryable (MySQL JSON functions, e.g. `WHERE JSON_EXTRACT(custom_fields, '$.first_name') = 'John'`) and reportable. A generated-column index can be added later for hot-path keys without any application change.

## Next.js dashboard

A companion front-end lives in `../campaign-dashboard` (Next.js 16, React 19, TypeScript, Tailwind). It provides:

- create/list clients (`POST`/`GET /api/clients`)
- create campaigns with optional `end_date` (`POST /api/campaigns`)
- paste-JSON import of campaign data showing the `202 queued` response
- campaign table

Run it:

```sh
cd ../campaign-dashboard
npm install
npm run dev   # http://localhost:3000
```

Point it at the API with `NEXT_PUBLIC_API_URL` (defaults to `http://localhost:8000`). CORS on the API allows the frontend origin via the `FRONTEND_URL` env var (default `http://localhost:3000`).

## Analytics

```sh
php artisan campaigns:analytics {campaign_id} [--field=first_name] [--field=plan]
```

Prints stored record count, duplicate event count, a custom-fields breakdown (per key: populated records, distinct values, distinct/populated ratio — with `--field` to force specific keys, auto-detected keys otherwise), and the 10 most recent duplicate events. Exits non-zero if the campaign does not exist.

## Queue architecture

- Driver: **Redis** (`QUEUE_CONNECTION=redis`) in all non-test environments.
- Job: `App\Jobs\StoreCampaignDataRecords` — `tries = 3`; `failed()` logs permanent failures.
- Worker: the `queue` compose service (`php artisan queue:work redis`). Restart workers after deploys: `docker compose exec queue php artisan queue:restart`.
- The HTTP path only validates and dispatches — storage never blocks the request.

## Testing & quality

```sh
vendor/bin/pest        # 18 tests, 44 assertions (feature tests incl. duplicate/upsert behaviour)
vendor/bin/pint --test # formatting check
vendor/bin/phpstan analyse --no-progress  # static analysis (level 5)
```

Test coverage: campaign creation + validation, 202 async import (queue asserted, nothing persisted synchronously), sync validation rejection, upsert + duplicate logging, arbitrary `custom_fields`, analytics command success/failure.

## Local development without Docker

```sh
composer install
cp .env.example .env   # adjust DB_* / REDIS_* / QUEUE_CONNECTION
php artisan key:generate && php artisan migrate
php artisan serve
php artisan queue:work redis
```

## Design decisions & trade-offs

1. **Thin controller + FormRequest validation + API Resource** — framework conventions; no service layer beyond the queued job, keeping the codebase proportional to scope.
2. **Batch-per-job** — one queue job per HTTP request payload; scales to thousands of records with a single upsert round-trip. For multi-million imports, chunking at dispatch time would be the next step.
3. **Pre-read for duplicate detection** — one extra indexed query per batch in exchange for deterministic duplicate reporting.
4. **`duplicate_logs` as a separate table** — append-only history (not a flag on `campaign_data`) so re-imports don't erase duplicate evidence.
5. **Bare-array request bodies** — the spec shows a top-level JSON array; the FormRequest normalises single objects into a list so both forms work.
6. **No auth** — the assessment defines no authentication requirement; clients/campaigns are keyed by integer IDs.

## Risks / assumptions

- **Assumption:** a `Client` exists before campaign creation (`client_id` must reference an existing client; there is no client-creation endpoint — none was specified).
- **Assumption:** `end_date` is optional, so campaigns can be open-ended (matches the spec).
- **Assumption:** "latest wins" is acceptable duplicate semantics.
- **Risk:** very large payloads are bounded at 5000 records/request; higher throughput needs payload chunking or streaming ingestion.
- **Open question:** should duplicate re-imports be rejected instead of overwritten in some compliance contexts? The strategy is isolated in one job and easy to swap.
- **Not verified in this environment:** `docker compose build/up` (Docker daemon was not running); compose file validated with `docker compose config`.
