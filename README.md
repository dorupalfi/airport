# Flight Gate Scheduler

Local development environment for a Laravel and Vue application. This repository currently contains only the framework bootstrap; no flight or gate business logic has been added.

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

## Start and stop

```sh
docker compose up -d --build
docker compose down
```

The application is at http://localhost:8081. Vite and HMR are at http://localhost:5174.

## Common commands

```sh
# PHP dependencies and Artisan
docker compose run --rm app composer install
docker compose exec app php artisan migrate
docker compose exec app php artisan test

# Queue worker and scheduler (also start automatically with Compose)
docker compose exec queue php artisan queue:work --tries=3 --timeout=90
docker compose exec scheduler php artisan schedule:work

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
