# Composer Tools via Mate

Composer dependency management tools from `matesofmate/composer-extension`.

## Available Tools

### `composer-install`

Install dependencies from `composer.json` and `composer.lock`.

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `preferDist` | `bool` | `true` | Download dist packages |
| `noDev` | `bool` | `false` | Skip dev dependencies |
| `optimizeAutoloader` | `bool` | `false` | Optimize autoloader |
| `mode` | `string` | `'default'` | Output format mode |

**Example:**

```bash
scripts/mate-tool-call.sh composer-install '{}'
scripts/mate-tool-call.sh composer-install '{"noDev":true,"mode":"summary"}'
```

---

### `composer-require`

Add a new package requirement to `composer.json`.

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `package` | `string` | required | Package name (e.g., "symfony/console") |
| `version` | `string\|null` | `null` | Version constraint (e.g., "^6.4") |
| `dev` | `bool` | `false` | Require as dev dependency |
| `mode` | `string` | `'default'` | Output format mode |

**Examples:**

```bash
scripts/mate-tool-call.sh composer-require '{"package":"symfony/console","version":"^6.4"}'
scripts/mate-tool-call.sh composer-require '{"package":"phpunit/phpunit","dev":true}'
```

---

### `composer-update`

Update dependencies to latest versions within constraints.

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `packages` | `string\|null` | `null` | Specific packages to update (comma/space-separated, empty = all) |
| `preferDist` | `bool` | `true` | Download dist packages |
| `withDependencies` | `bool` | `true` | Update dependencies too |
| `mode` | `string` | `'default'` | Output format mode |

**Examples:**

```bash
# Update all packages
scripts/mate-tool-call.sh composer-update '{"mode":"summary"}'

# Update specific packages
scripts/mate-tool-call.sh composer-update '{"packages":"symfony/console, symfony/process"}'
```

---

### `composer-why`

Show which packages depend on a specific package.

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `package` | `string` | required | Package name to investigate |
| `mode` | `string` | `'default'` | Output format mode |

**Example:**

```bash
scripts/mate-tool-call.sh composer-why '{"package":"psr/log"}'
```

---

### `composer-why-not`

Show why a specific package version cannot be installed.

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `package` | `string` | required | Package name to investigate |
| `version` | `string\|null` | `null` | Specific version to check |
| `mode` | `string` | `'default'` | Output format mode |

**Example:**

```bash
scripts/mate-tool-call.sh composer-why-not '{"package":"php","version":"7.4"}'
```

---

### `composer-remove`

Remove a package from `composer.json`.

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `package` | `string` | required | Package name to remove |
| `dev` | `bool` | `false` | Remove from dev dependencies |
| `mode` | `string` | `'default'` | Output format mode |

**Example:**

```bash
scripts/mate-tool-call.sh composer-remove '{"package":"symfony/debug-bundle","dev":true}'
```

## Available Resources

### `composer://config`

Provides the content of `composer.json` file including dependencies, autoloading, and scripts configuration in token-optimized TOON format.

**MIME Type:** `text/plain`

## Output Modes

All tools support multiple output modes via the `mode` parameter:

- **`default`**: Standard output with key information (status, packages/dependencies, errors, warnings)
- **`summary`**: Ultra-compact output (just counts and status)
- **`detailed`**: Full information including metadata without truncation

## Response Format

All tools return **TOON-formatted strings** for maximum token efficiency:

```
command: install
status: SUCCESS
packages[2]{name,version}:
  symfony/console|6.4.0
  symfony/process|6.4.0
package_count: 2
```

## Typical Workflow

1. **Check current dependencies:**

```bash
# List configured dependencies from composer.json resource
# (fetch via MCP resource: composer://config)
```

2. **Add a new package:**

```bash
scripts/mate-tool-call.sh composer-require '{"package":"symfony/serializer","version":"^6.4"}'
```

3. **Update after changes:**

```bash
scripts/mate-tool-call.sh composer-install '{"optimizeAutoloader":true}'
```

4. **Investigate dependency issues:**

```bash
scripts/mate-tool-call.sh composer-why '{"package":"symfony/console"}'
scripts/mate-tool-call.sh composer-why-not '{"package":"symfony/console","version":"^7.0"}'
```
