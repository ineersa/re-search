# PHPStan via Mate

Use Mate PHPStan tools for analysis aligned with the container runtime.

## Tools

### `phpstan-analyse`

- Runs static analysis for configured paths or a specific path.
- Inputs:
  - `configuration` (`string|null`): custom PHPStan config file path.
  - `level` (`0..9|null`): override analysis strictness.
  - `path` (`string|null`): file or directory to analyze.
  - `mode` (`toon|summary|detailed|by-file|by-type`): output style.

### `phpstan-analyse-file`

- Runs static analysis for one PHP file.
- Inputs:
  - `file` (`string|null`, must end with `.php`): target file path.
  - `configuration` (`string|null`): config file override.
  - `level` (`0..9|null`): strictness override.
  - `mode` (`toon|summary|detailed`): output detail mode.

### `phpstan-clear-cache`

- Clears PHPStan cache to force fresh analysis.
- Inputs:
  - `configuration` (`string|null`): optional config path.

## Typical flow

1. Fast health check:

```bash
scripts/mate-tool-call.sh phpstan-analyse '{"mode":"summary"}'
```

2. If failing, get grouped diagnostics:

```bash
scripts/mate-tool-call.sh phpstan-analyse '{"mode":"by-file"}'
scripts/mate-tool-call.sh phpstan-analyse '{"mode":"by-type"}'
```

3. Drill into one file:

```bash
scripts/mate-tool-call.sh phpstan-analyse-file '{"file":"src/Service/Example.php","mode":"detailed"}'
```

4. If stale cache suspected:

```bash
scripts/mate-tool-call.sh phpstan-clear-cache '{}'
```

## Parameter guidance

- `path`: use for targeted scope to reduce noise while iterating.
- `mode`:
  - `summary`: binary health signal.
  - `toon`: compact output (default).
  - `detailed`: full messages.
  - `by-file`/`by-type`: triage large failures.
- `level`: temporary override when debugging level-specific behavior.
- `configuration`: useful for alt config files in monorepos.
