# Development setup

This project runs on FrankenPHP with Symfony 8 worker mode, built-in Mercure, and SQLite.

## Stack overview

- PHP runtime: FrankenPHP (`dunglas/frankenphp`) with PHP 8.5
- App mode: Symfony worker mode via `docker/frankenphp/worker.Caddyfile`
- Realtime: Mercure hub built into FrankenPHP/Caddy
- Database: SQLite at `data/research`
- Compose files:
  - Base: `compose.yaml`
  - Dev overrides: `compose.override.yaml`
  - Production-like overrides: `compose.prod.yaml`

## First-time local setup

1. Build images, start containers, and install dependencies:

   ```bash
   make setup
   ```

2. Open the app:

   - HTTP: `http://localhost:8080`
   - HTTPS: `https://localhost:8443`
   - Mailpit UI: `http://localhost:8025`
   - Mercure endpoint: `http://localhost:8080/.well-known/mercure`

3. Follow logs when needed:

   ```bash
   make logs
   ```

## Common local workflow

- Start: `make up`
- Stop: `make down`
- Restart: `make restart`
- Status: `make ps`
- PHP logs: `make logs-php`
- Mailpit logs: `make logs-mailer`

## Production-like local run

Use the production compose variant locally to validate prod wiring:

```bash
make up-prod
```

Related commands:

- Stop: `make down-prod`
- Restart: `make restart-prod`
- Status: `make ps-prod`
- Logs: `make logs-prod`

## Symfony and quality commands

Run all Symfony and code quality commands via `make` targets.

- Symfony console: `make console cmd='about'`
- Install deps: `make composer-install`
- Tests: `make test`
- Tests with coverage: `make test-coverage` (reports in `var/coverage/`)
- CS Fixer: `make cs-fix`
- PHPStan: `make phpstan`
- Full quality pass: `make quality`

## Configuration notes

- SQLite is persisted at `data/research`.
- Keep `data/.gitignore` as:

  ```gitignore
  *
  !.gitignore
  ```

- Dev uses bind mounts from project root into `/app`.
- Worker mode is enabled by default through `FRANKENPHP_CONFIG=import /etc/caddy/worker.Caddyfile`.
- Dev serves both protocols by setting `SERVER_NAME=localhost, http://localhost`, so HTTP works on `http://localhost:8080` and HTTPS works on `https://localhost:8443`.

## Mercure transport (important)

This setup uses the current Mercure transport configuration style.

- Do not use deprecated `transport_url` in Caddy.
- Do not use deprecated `MERCURE_TRANSPORT_URL` env var.
- Configure transport through `MERCURE_EXTRA_DIRECTIVES`, for example:

  ```caddyfile
  transport bolt {
    path /data/mercure.db
  }
  ```

If old Mercure warnings appear after editing env/config values, recreate containers (not just restart):

```bash
make down
make up
```
