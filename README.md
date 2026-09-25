# Flight Gate Scheduler

Flight Gate Scheduler is a Laravel and Vue application for planning airport gate usage. It imports scheduled departures, allocates each flight to an available gate, and makes allocation capacity visible through schedules, unallocated-flight lists, and UTC analytics snapshots.

## What the application does

- Manage airports, gates, gate occupancy durations, active/inactive status, and gate exceptions.
- Import departure flights from OpenSky through queued jobs.
- Resolve and store destination airport data, including the destination ICAO code when no external-airport record is available.
- Allocate pending flights to the earliest suitable active gate while respecting existing occupancy and exceptions.
- Record a delay when an allocation finishes after the planned departure time.
- Mark flights as unallocated when no suitable gate is available, including when the first possible slot would start on the following UTC day.
- Display allocated schedules and unallocated flights with filtering and pagination.
- Capture allocation analytics every 30 minutes for the preceding UTC operational day, then display gate and flight-allocation charts. Selected days can also be rebuilt from the UI.

## Stack

- Laravel 13 on PHP 8.4 FPM
- Vue 3 and Vite
- Nginx
- PostgreSQL 16
- Redis 7
- Node 22

Compose runs seven services: `app` (PHP-FPM), `nginx`, `postgres`, `redis`, `queue`, `scheduler`, and `node` (Vite). PostgreSQL and Redis are internal-only services; neither publishes a host port.

## Prerequisites

- Docker Desktop with Docker Compose v2
- OpenSky API credentials for flight imports: `OPENSKY_CLIENT_ID` and `OPENSKY_CLIENT_SECRET`

## Start and stop

```sh
docker compose up -d --build
docker compose down
```

The application is at http://localhost:8081. Vite and HMR are at http://localhost:5174.

## First-time local setup

```sh
# Apply the database schema.
docker compose exec app php artisan migrate

# Optional: seed Frankfurt Airport (EDDF) with gates A1–A20.
docker compose exec app php artisan db:seed

# Download and import the external-airport directory used for destinations.
docker compose exec app php artisan external-airports:import
```

## Custom Artisan commands

Run the examples below through the `app` container. Import and allocation commands dispatch jobs to the queue; successful command completion means the job was queued, not necessarily that the work has completed.

### Import external airports

Downloads the OurAirports global CSV, keeps valid four-letter ICAO airports, and upserts them into `external_airports`.

```sh
docker compose exec app php artisan external-airports:import

# Use an already downloaded CSV instead.
docker compose exec app php artisan external-airports:import --file=/path/to/airports.csv
```

### Queue one OpenSky import hour

Queues an OpenSky departure import for every managed airport, or for one airport selected case-insensitively by ICAO code. The timestamp must be an exact UTC hour in `Y-m-d H:i:s` format.

```sh
docker compose exec app php artisan opensky:import-hour "2026-09-20 14:00:00"
docker compose exec app php artisan opensky:import-hour "2026-09-20 14:00:00" --airport=EDDF
```

### Allocate pending flights

Queues gate allocation for all managed airports or for one airport ID. Allocation respects active gates, occupancy duration, existing schedules, and gate exceptions.

```sh
docker compose exec app php artisan flights:allocate-pending
docker compose exec app php artisan flights:allocate-pending 1
```

### Reset existing allocations

Deletes gate schedules and returns the selected airport's flights to `pending`. With no airport ID, it applies to all airports.

```sh
docker compose exec app php artisan flights:reset-allocation
docker compose exec app php artisan flights:reset-allocation 1
```

### Capture analytics snapshots

Captures one allocation snapshot per airport. The snapshot represents the matching time on the previous UTC day.

```sh
docker compose exec app php artisan analytics:snapshot
```

## Scheduled work

The `scheduler` service runs Laravel's scheduler continuously.

- At minute `05` of every UTC hour, it queues OpenSky imports for all managed airports. The imported operational hour is controlled by `OPENSKY_OPERATIONAL_DELAY_HOURS` and defaults to 24 hours before the current hour.
- Every 30 minutes, it runs `analytics:snapshot` for all managed airports.

The `queue` service processes queued OpenSky imports and flight-allocation jobs. Check its output when troubleshooting background work:

```sh
docker compose logs -f queue
docker compose logs -f scheduler
```

## Development commands

```sh
# PHP dependencies and tests
docker compose run --rm app composer install
docker compose exec app php artisan test

# Frontend dependencies, Vite, and production build
docker compose run --rm node npm install
docker compose exec node npm run dev -- --host 0.0.0.0
docker compose exec node npm run build
```

## Local ports

| Service | Host port |
| --- | --- |
| Laravel application via Nginx | `8081` |
| Vite development server / HMR | `5174` |
