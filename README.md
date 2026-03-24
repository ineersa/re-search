# re-search

Project documentation lives in `docs/`.

- Setup guide: `docs/setup.md`
- Mercure streaming: see [Mercure](#mercure) below

## Authentication (local users)

Create a user:

```bash
make console cmd='app:user:create you@example.com your-password'
```

Create an admin user:

```bash
make console cmd='app:user:create admin@example.com your-password --admin'
```

## Testing the Orchestrator

You can test the research orchestrator loop synchronously using the console command. This bypasses the background worker and runs the loop directly in your terminal, making it easier to debug.

```bash
make console cmd="app:research:test 'Who was the 16th president of the united states?' -vvv"
```

### Testing the UI (Frontend) without the AI Model

If you want to test the frontend UI transitions (loaders, budget updates, markdown rendering) without running the real AI orchestrator, you can use the UI test command. 

This command will push a simulated sequence of events (with artificial delays) to the Mercure topic for any given Run ID.

1. Start a new research run in your browser.
2. Look at the URL to get the `runId` (e.g., `http://localhost/run/1234-5678...`).
3. Run the UI test command with that ID:
```bash
make console cmd="app:research:test-ui <runId>"
```
The browser will immediately start receiving the simulated events and rendering the UI as if a real model was processing it.

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
