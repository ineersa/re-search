# Server deployment (FrankenPHP, Docker, TLS, domain)

This guide assumes you deploy the **production Compose stack** (`compose.yaml` + `compose.prod.yaml`): one FrankenPHP container for HTTP(S) and Mercure, plus Messenger workers (1× orchestrator, 2× `llm`, 2× `tool`, 1× scheduler). SQLite lives on a **named volume** shared by the app and all workers.

## After `make up-prod`: what should I do?

**Caddy and host nginx cannot both listen on the same host ports** (80 / 443). If nginx already serves your other sites with Certbot, **keep nginx on 443** and run FrankenPHP **only on loopback** — that is the **default** in `compose.prod.yaml` (`127.0.0.1:8080` → container port 80). You **do not** add a second public web server; you add **one nginx `server` block** that reverse-proxies to `http://127.0.0.1:8080`.

Caddy **inside** the container still serves PHP and Mercure; it just speaks **HTTP** on port 80 inside the container, and TLS ends at nginx.

### 1. One-time prep

1. **DNS** — A record for your hostname → server IP (same as your other vhosts).
2. **Secrets file** — `cp .env.prod.local.dist .env.prod.local`. For **nginx in front**, keep **`SERVER_NAME=:80`** (see dist comments). Set **`DEFAULT_URI`** / **`MERCURE_PUBLIC_URL`** to your real **`https://…`** URLs (uncomment and edit in that file or bake via rebuild).
3. **`make build-prod && make up-prod`** — app listens on **127.0.0.1:8080** by default.
4. **`make doctrine-migrate-prod`** once.
5. **nginx** — Add a site that `proxy_pass`es to `http://127.0.0.1:8080` (snippet below). Use **Certbot** as you do for other sites (`certbot --nginx -d re-search.ineersa.com`).
6. **Smoke test** — HTTPS via nginx, research run, `make logs-prod-workers` if needed.

### 2. nginx → FrankenPHP (typical: you already have nginx + Certbot)

Default Compose publish: **`127.0.0.1:${RE_SEARCH_HTTP_PORT:-8080}:80`**. Only the host loopback can reach the container HTTP port; nginx proxies there.

Example **server** fragment (merge with your real `ssl_certificate` paths from Certbot):

```nginx
server {
    server_name re-search.ineersa.com;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location /.well-known/mercure {
        proxy_pass http://127.0.0.1:8080;
        proxy_read_timeout 24h;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_buffering off;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    listen 443 ssl;
    # include Certbot-managed ssl_certificate / ssl_certificate_key
}
```

Run **`certbot --nginx -d re-search.ineersa.com`** (or your workflow) so this vhost gets TLS like your other six sites.

Set reverse-proxy trust vars in `.env.prod.local` so Symfony uses `X-Forwarded-For` for `Request::getClientIp()`:

```bash
SYMFONY_TRUSTED_PROXIES=REMOTE_ADDR
SYMFONY_TRUSTED_HEADERS=x-forwarded-for,x-forwarded-host,x-forwarded-proto,x-forwarded-port
```

Without these, Symfony sees Docker bridge IPs (for example `172.30.0.1`) instead of real client IPs.

### 3. Dedicated VPS: Caddy on public 80/443 (no host nginx)

Only when **nothing** else binds host **80/443**. In the project **`.env`** file that Docker Compose reads for variable substitution, set:

```bash
RE_SEARCH_HTTP_BIND=0.0.0.0
RE_SEARCH_HTTP_PORT=80
```

Recreate the stack. Add **host** `443` / `443/udp` mappings in a **local** compose override file (not committed), e.g. `compose.override.prod-ports.yaml`, with:

```yaml
services:
  php:
    ports:
      - "443:443"
      - "443:443/udp"
```

In **`.env.prod.local`**, set **`SERVER_NAME=your.domain`**. Caddy can obtain Let’s Encrypt certs itself; **no** host Certbot required for this app.

## Symfony environment (source of truth)

Application configuration is **Symfony’s** `.env` layering, compiled into **`/app/.env.local.php`** during `docker build` by `composer dump-env prod` (see the `Dockerfile`). Put production overrides for the dump in **`.env.prod.local`** on the build host when you build the image (gitignored; allowed into the Docker build context — see `.dockerignore`).

## `.env.prod.local` and Compose `env_file`

`compose.prod.yaml` attaches **`env_file: .env.prod.local`** to every prod service (`required: false` so a missing file does not fail `docker compose config`). Copy the template:

```bash
cp .env.prod.local.dist .env.prod.local
```

That file injects **process environment** into the containers (no `export` in your shell). Use it for **`SERVER_NAME`**, **Mercure JWT / ALG** keys Caddy reads via `{env.…}` in `docker/frankenphp/Caddyfile`, and optionally other runtime overrides (`MCP_WEBSEARCH_URL`, etc.). Any name that also exists in `.env.local.php` is **overridden** in PHP by these container variables — keep Mercure secrets in sync with the Mercure bundle.

The **`php`** service still sets **`FRANKENPHP_CONFIG`** and **`MERCURE_EXTRA_DIRECTIVES`** in Compose only (multiline; awkward in dotenv).

## Caddy / FrankenPHP vs Symfony

Caddy resolves **`{env.MERCURE_PUBLISHER_JWT_KEY}`** from the **OS environment** before Symfony boots. Those values should match what Symfony uses for publishing; `.env.prod.local` loaded by Compose is the intended place to set them for the prod stack.

## One command: bring everything up

On the server (from the project root, after a build that includes your prod env files):

```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
```

Or with Make:

```bash
make up-prod
```

This starts:

- `php` — FrankenPHP + app + embedded Mercure (see Caddyfile)
- `messenger-worker-orchestrator` — consumes `orchestrator` queue (single consumer)
- `messenger-worker-llm-1` / `messenger-worker-llm-2` — consume `llm`
- `messenger-worker-tool-1` / `messenger-worker-tool-2` — consume `tool`
- `messenger-worker-scheduler` — consumes `scheduler_research_maintenance` (trace pruning schedule)

The standalone `mercure` service from the base `compose.yaml` is **disabled** in prod via a Compose profile (`standalone-mercure`); this app publishes to the hub inside FrankenPHP.

**First deploy (migrations):**

```bash
make doctrine-migrate-prod
```

**Worker logs:**

```bash
make logs-prod-workers
```

## What to set for a real host

| Area | Where |
| :--- | :--- |
| `APP_SECRET`, AI keys, Symfony Mercure URLs, etc. | `.env.prod` and/or **`.env.prod.local`** on the **build** host so `composer dump-env prod` includes them; rebuild the image |
| `SERVER_NAME`, Mercure keys for **Caddy** `{env.…}`, runtime overrides (e.g. `MCP_WEBSEARCH_URL`) | **`.env.prod.local`** next to Compose — loaded via **`env_file`** on **all** prod services (see `.env.prod.local.dist`) |

`MCP_WEBSEARCH_URL` must be reachable **from worker containers** (not only `127.0.0.1` on the host unless you use `extra_hosts` / host gateway).

## DNS (A record)

Point your hostname at the server’s public IP. Traffic hits **nginx** on 443 (or Caddy directly on a dedicated host).

## TLS summary

| Setup | Who terminates TLS |
| :--- | :--- |
| **nginx already on the host (default ports)** | **nginx + Certbot** (same as your other sites). FrankenPHP is **HTTP only** on `127.0.0.1:8080`. |
| **Dedicated machine, Docker owns 80/443** | **Caddy** inside the container (Let’s Encrypt). Use `RE_SEARCH_HTTP_BIND=0.0.0.0`, publish 443, `SERVER_NAME=your.domain`. |

### Manual certificates (advanced)

Mount PEM files and extend the Caddyfile, or terminate TLS on nginx and proxy HTTP to FrankenPHP.

## Production assets (Tailwind / AssetMapper)

The **production Docker image** runs `tailwind:build` and `asset-map:compile` during `docker build`. Rebuild the image after UI changes.

## SQLite and scaling

SQLite is acceptable for a single host and moderate concurrency. Multiple workers share one DB file on `research_data`. If you outgrow SQLite, change `DATABASE_URL` and `MESSENGER_TRANSPORT_DSN` in Symfony env and adjust infrastructure; application code stays the same.

## Related docs

- Local workflow: [docs/setup.md](setup.md)
- Stack overview: [ARCHITECTURE.md](../ARCHITECTURE.md) and [AGENTS.md](../AGENTS.md)
