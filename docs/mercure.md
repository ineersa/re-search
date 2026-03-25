# Mercure (real-time research events)

This project uses [Mercure](https://mercure.rocks/) for real-time streaming of research run events. The Mercure hub is **embedded in FrankenPHP/Caddy** (not the optional standalone `mercure` service in Compose unless you enable that profile).

For how Mercure fits into orchestration, see [ARCHITECTURE.md](../ARCHITECTURE.md) (event contract and streaming).

## Flow

1. **Submit a run**: `POST /research/runs` with `query=...` (form data). Returns `runId` and `mercureTopic`.
2. **Authorize subscription**: `GET /research/runs/{id}/mercure-auth` sets the `mercureAuthorization` cookie for the run's private topic.
3. **Subscribe**: Connect to the Mercure hub and subscribe to the topic. Events stream as JSON payloads with a top-level `type` field.

## Event types

| Type       | When                         | Payload (high level) |
|------------|------------------------------|----------------------|
| `activity` | Tool calls, reasoning, stream chunks, warnings | `stepType`, `summary`, `meta` |
| `answer`   | Final answer markdown        | `markdown`, `isFinal` |
| `budget`   | Token usage                  | `meta` (used, remaining, hardCap, …) |
| `phase`    | Orchestration progress UI    | `phase`, `status`, `message`, `meta` |
| `complete` | Run finished                 | `meta.status`, optional `meta.reason` |

## Topic format

Private topics follow: `{DEFAULT_URI}/research/runs/{uuid}`. Configure `DEFAULT_URI` in `.env` (default: `http://localhost`).

## Environment variables

- `MERCURE_URL`: Internal hub URL for publishing (dev example: `http://localhost/.well-known/mercure` inside the app container; see [server-deployment.md](server-deployment.md) for Docker prod defaults).
- `MERCURE_PUBLIC_URL`: Public hub URL for browser connections (e.g. `http://localhost:8080/.well-known/mercure` in local Compose).
- `MERCURE_JWT_SECRET`: Must align with Mercure / FrankenPHP JWT configuration (see Symfony Mercure bundle docs).

## JWT keys

`MERCURE_PUBLISHER_JWT_KEY` and `MERCURE_SUBSCRIBER_JWT_KEY` are shared secrets used to sign JWTs for publishing and subscribing. You generate them yourself.

**Generate a secret:**

```bash
openssl rand -base64 32
```

**Local development:** The default `!ChangeThisMercureHubJWTSecretKey!` in `compose.yaml` works out of the box for typical dev setups.

**Production:** Generate a secure secret and set it in your environment (e.g. server `.env` or secret store):

```
MERCURE_PUBLISHER_JWT_KEY=your_generated_secret_here
MERCURE_SUBSCRIBER_JWT_KEY=your_generated_secret_here
```

Publisher and subscriber keys can be the same or different; using the same value is simpler.

## Caddy transport (Bolt)

This setup uses the current Mercure transport configuration style:

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

More context: [docs/setup.md](setup.md) (stack and Compose).
