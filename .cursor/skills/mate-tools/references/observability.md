# Monolog and Symfony Diagnostics via Mate

Use these tools for runtime troubleshooting without leaving MCP/Mate context.

## Monolog Tools

### `monolog-tail`

- Fetches latest log entries.
- Inputs:
  - `lines` (`integer`, default `50`): number of recent entries.
  - `level` (`string|null`): restrict by log level.
  - `environment` (`string|null`): restrict by environment log file.

### `monolog-search`

- Full-text log search.
- Inputs:
  - `term` (`string`, required): search text.
  - `regex` (`bool`, default `false`): treat `term` as a regex pattern.
  - `level` (`string|null`): level filter.
  - `channel` (`string|null`): channel filter.
  - `environment` (`string|null`): env filter.
  - `from` (`string|null`): start date/time boundary.
  - `to` (`string|null`): end date/time boundary.
  - `limit` (`integer`, default `100`): max entries.

### `monolog-context-search`

- Search by context key/value.
- Inputs:
  - `key` (`string`, required): context field key.
  - `value` (`string`, required): context field value.
  - `level` (`string|null`): level filter.
  - `environment` (`string|null`): env filter.
  - `limit` (`integer`, default `100`): max entries.

### `monolog-list-files`

- Lists available log files.
- Inputs:
  - `environment` (`string|null`): env-specific list.

### `monolog-list-channels`

- Lists discovered log channels.
- Input: `{}`.

## Symfony Profiler Tools

### `symfony-services`

- Lists Symfony services visible to container.
- Input: `{}`.

### `symfony-profiler-list`

- Lists available profiler profiles.
- Inputs:
  - `limit` (`integer`, default `20`): number of profiles.
  - `method` (`string|null`): HTTP method filter.
  - `url` (`string|null`): URL filter.
  - `ip` (`string|null`): client IP filter.
  - `statusCode` (`integer|null`): HTTP status filter.
  - `context` (`string|null`): free-form context filter.
  - `from` (`string|null`): date/time lower bound.
  - `to` (`string|null`): date/time upper bound.

### `symfony-profiler-get`

- Gets profile by token.
- Inputs:
  - `token` (`string`, required): profiler token from list/latest/search.

## Monolog patterns

### Tail recent errors

```bash
scripts/mate-tool-call.sh monolog-tail '{"lines":200,"level":"ERROR"}'
```

### Search for a known term

```bash
scripts/mate-tool-call.sh monolog-search '{"term":"SQLSTATE","environment":"dev","limit":50}'
```

### Regex for structured patterns

```bash
scripts/mate-tool-call.sh monolog-search '{"term":"TimeoutException|ConnectException","regex":true,"limit":100}'
```

### Context-driven lookup

```bash
scripts/mate-tool-call.sh monolog-context-search '{"key":"request_id","value":"abc-123"}'
```

## Symfony profiler patterns

### Latest profile

```bash
scripts/mate-tool-call.sh symfony-profiler-list '{"limit":1}'
```

### List or search profiles

```bash
scripts/mate-tool-call.sh symfony-profiler-list '{"limit":10,"method":"GET"}'
scripts/mate-tool-call.sh symfony-profiler-list '{"statusCode":500,"from":"-1 day","limit":20}'
```

### Fetch full profile by token

```bash
scripts/mate-tool-call.sh symfony-profiler-get '{"token":"<token>"}'
```

## Parameter guidance

- `environment`: use when logs are split by env (`dev`, `prod`, etc.).
- `limit`: keep small for interactive use, increase for incident sweeps.
- `from`/`to`: narrow noisy windows during an outage or deploy window.
- `resource_uri` from profiler list output indicates where to fetch full details.
