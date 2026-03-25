# re-search

**re-search** is a Symfony application for **AI-assisted web research**: users submit a question, and an event-driven pipeline orchestrates LLM turns, MCP-backed web search tools, and streaming results to the browser over **Mercure**. Runs are persisted in **SQLite**, with **Symfony Messenger** separating orchestration, model calls, and tool I/O.

Stack highlights: **FrankenPHP** (PHP 8.5, worker mode), **Symfony UX** (Stimulus, Turbo, Twig/Live components), **Symfony AI** (pluggable platforms including local llama.cpp and **Z.AI**), and **Doctrine** for storage and Messenger transport.

## Documentation

| Document                                                                                     | Description                                                                                 |
| :------------------------------------------------------------------------------------------- | :------------------------------------------------------------------------------------------ |
| [ARCHITECTURE.md](ARCHITECTURE.md)                                                           | Queues, orchestrator state machine, data model, Mercure contract, rate limits, AI platforms |
| [docs/setup.md](docs/setup.md)                                                               | Local Docker setup, first-time bootstrap, Tailwind, Messenger, production-like Compose      |
| [docs/server-deployment.md](docs/server-deployment.md)                                       | VPS deployment, TLS (Caddy vs nginx/Certbot), domain, worker containers                     |
| [docs/mercure.md](docs/mercure.md)                                                           | Real-time event flow, payload types, JWT and Caddy transport notes                          |
| [docs/development-recipes.md](docs/development-recipes.md)                                   | User creation, orchestrator CLI test, UI simulation without a model                         |
| [docs/interleaved_reasoning_and_tool_calls.md](docs/interleaved_reasoning_and_tool_calls.md) | Z.AI streaming (`tool_stream`) and reasoning preservation                                   |
| [AGENTS.md](AGENTS.md)                                                                       | Contributor/agent rules (Docker, MATE tools, Symfony UX)                                    |

## Requirements

- **Docker** and **Docker Compose**
- **GNU Make** (optional but recommended; see `make help`)

## Quick start (local)

```bash
make setup          # data dir, build, up, composer install
make dev-bootstrap  # migrations, Tailwind, AssetMapper
```

In a **separate terminal** (research jobs stall without consumers):

```bash
make messenger-consume   # orchestrator, llm, tool, and scheduler transports (--all, excluding failed)
```

Optional while editing CSS: `make tailwind-watch`.

Open **http://localhost:8080** (see [docs/setup.md](docs/setup.md) for Mailpit, HTTPS port, and Compose files).

## Configuration (basics)

Copy and adjust `.env` / `.env.local` as needed:

- **`DATABASE_URL`** — SQLite at `data/research` for local dev (path via `%kernel.project_dir%`).
- **`AI_PLATFORM`** — `llama`, `zai`;
- **`RESEARCH_MODEL`** — model id for the active catalog.
- **`MCP_WEBSEARCH_URL`** — Streamable HTTP endpoint for the websearch MCP server.
- **`MESSENGER_TRANSPORT_DSN`** — Default `doctrine://default?auto_setup=0` shares the app database.
- **`RESEARCH_SUBMIT_RATE_LIMIT`** — Daily submit cap per anonymous IP (authenticated users bypass this limiter).
- **Mercure** — See [docs/mercure.md](docs/mercure.md) and [docs/setup.md](docs/setup.md).

Production: Symfony env is baked at **image build** (`composer dump-env prod`); `compose.prod.yaml` uses **`env_file: .env.prod.local`** (copy from `.env.prod.local.dist`) plus a small inline **Caddy** block on `php`. By default the app binds **`127.0.0.1:8080`** so **host nginx** can keep **80/443** for your other sites. See [docs/server-deployment.md](docs/server-deployment.md).

## License

Proprietary — see `composer.json`.
