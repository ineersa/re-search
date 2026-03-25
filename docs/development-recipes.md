# Development recipes

Console and workflow tips for local development. Prerequisites: [docs/setup.md](setup.md) (Docker, `make setup`, `make dev-bootstrap`, Messenger consumers).

## Users (authentication)

Create a user:

```bash
make console cmd='app:user:create you@example.com your-password'
```

Create an admin user:

```bash
make console cmd='app:user:create admin@example.com your-password --admin'
```

## Test the research orchestrator (CLI)

Run the orchestrator loop **synchronously** in the terminal (no Messenger workers). Useful for debugging backend behavior.

```bash
make console cmd="app:research:test 'Who was the 16th president of the united states?' -vvv"
```

## Test the UI without a real model

To exercise loaders, budget updates, and markdown rendering without calling the LLM:

1. Start a new research run in the browser.
2. Read the `runId` from the URL (e.g. `http://localhost:8080/research/runs/<uuid>`).
3. Push simulated Mercure events:

```bash
make console cmd="app:research:test-ui <runId>"
```

The page should update as if a real run were in progress. See [docs/mercure.md](mercure.md) for event shapes.
