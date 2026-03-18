# re-search

Project documentation lives in `docs/`.

- Setup guide: `docs/setup.md`
- Mercure streaming: see [Mercure](#mercure) below

## Mercure

This project uses [Mercure](https://mercure.rocks/) for real-time streaming of research run events. The Mercure hub is built into FrankenPHP/Caddy.

### Flow

1. **Submit a run**: `POST /research/runs` with `query=...` (form data). Returns `runId` and `mercureTopic`.
2. **Authorize subscription**: `GET /research/runs/{id}/mercure-auth` sets the `mercureAuthorization` cookie for the run's private topic.
3. **Subscribe**: Connect to the Mercure hub and subscribe to the topic. Events stream as `activity`, `answer`, `budget`, and `complete`.

### Event types

| Type      | When                    | Payload                                      |
|-----------|-------------------------|----------------------------------------------|
| `activity`| Tool calls, steps       | `stepType`, `summary`, `meta`                |
| `answer`  | Markdown chunks         | `markdown`, `isFinal`                        |
| `budget`  | Token usage updates     | `meta.used`, `meta.remaining`, `meta.hardCap`|
| `complete`| Run finished            | `meta.status`, optional `meta.reason`        |

### Topic format

Private topics follow: `{DEFAULT_URI}/research/runs/{uuid}`. Configure `DEFAULT_URI` in `.env` (default: `http://localhost`).

### Configuration

- `MERCURE_URL`: Internal hub URL for publishing (e.g. `http://localhost/.well-known/mercure`)
- `MERCURE_PUBLIC_URL`: Public hub URL for browser connections (e.g. `http://localhost:8080/.well-known/mercure`)
- `MERCURE_JWT_SECRET`: Must match `MERCURE_PUBLISHER_JWT_KEY` for FrankenPHP

### JWT keys

`MERCURE_PUBLISHER_JWT_KEY` and `MERCURE_SUBSCRIBER_JWT_KEY` are not fetched from anywhere—you generate them yourself. They are shared secrets used to sign JWTs for publishing and subscribing.

**Generate a secret:**

```bash
openssl rand -base64 32
```

**Local development:** The default `!ChangeThisMercureHubJWTSecretKey!` in `compose.yaml` works out of the box. No change needed.

**Production:** Generate a secure secret and set it in your environment (e.g. `.env`):

```
MERCURE_PUBLISHER_JWT_KEY=your_generated_secret_here
MERCURE_SUBSCRIBER_JWT_KEY=your_generated_secret_here
```

Publisher and subscriber keys can be the same or different; using the same value is simpler.

See `docs/setup.md` for Mercure transport details.
