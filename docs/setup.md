# Development setup

This project runs on FrankenPHP with Symfony 8 worker mode, built-in Mercure, and SQLite.

**Related docs:** [Mercure hub, events, JWT](mercure.md) · [CLI recipes (users, tests)](development-recipes.md) · [Server / TLS / workers](server-deployment.md) · [Architecture overview](../ARCHITECTURE.md)

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

2. Apply database migrations, build Tailwind once, and compile AssetMapper (Composer’s `post-install` already runs `importmap:install`; this step generates CSS and compiled assets):

   ```bash
   make dev-bootstrap
   ```

3. Run **Messenger** in a dedicated terminal (or IDE task). Research runs will not progress without consumers:

   ```bash
   make messenger-consume
   ```

   This runs `messenger:consume --all` (excluding the `failed` transport), which covers **orchestrator**, **llm**, **tool**, and the **scheduler** transport (`scheduler_research_maintenance`), so trace pruning and other scheduled messages run in the same process. Use `make scheduler-consume` only if you want that transport in a **separate** consumer for isolation or logging.

4. Optional: while editing CSS/templates, keep Tailwind in watch mode (do **not** use this instead of `dev-bootstrap` for a first usable CSS build):

   ```bash
   make tailwind-watch
   ```

5. Open the app:

   - HTTP: `http://localhost:8080`
   - HTTPS: `https://localhost:8443`
   - Mailpit UI: `http://localhost:8025`
   - Mercure endpoint: `http://localhost:8080/.well-known/mercure`

6. Follow logs when needed:

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

The prod file starts FrankenPHP plus **six Messenger worker** containers (orchestrator ×1, `llm` ×2, `tool` ×2, scheduler ×1). Images include a **built** Tailwind + AssetMapper output from `docker build`.

Related commands:

- Stop: `make down-prod`
- Restart: `make restart-prod`
- Status: `make ps-prod`
- Logs: `make logs-prod`
- Worker-only logs: `make logs-prod-workers`
- Migrations inside prod stack: `make doctrine-migrate-prod`
- Console: `make console-prod cmd='about'`

For a real VPS, TLS, and domain setup, see [docs/server-deployment.md](server-deployment.md).

## Dev Compose restart policy

Local development (`compose.override.yaml`) sets `restart: "no"` on `php`, `mercure`, and `mailer` so containers do not automatically come back after a Docker daemon restart. Production workers and the prod `php` service use `restart: unless-stopped` via `compose.yaml` / `compose.prod.yaml`.

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

Caddy Bolt transport, deprecated options to avoid, JWT setup, and event payload reference: **[docs/mercure.md](mercure.md)**.

If Mercure warnings persist after editing env or Caddy-related config, recreate containers (not only restart):

```bash
make down
make up
```
