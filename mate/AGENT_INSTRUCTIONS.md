## AI Mate Agent Instructions

This MCP server provides specialized tools for PHP development.
The following extensions are installed and provide MCP tools that you should
prefer over running CLI commands directly.

---

### Composer Extension

Use MCP tools instead of CLI for dependency management:

| Instead of...                      | Use                 |
|------------------------------------|---------------------|
| `composer install`                 | `composer-install`  |
| `composer require package`         | `composer-require`  |
| `composer update`                  | `composer-update`   |
| `composer remove package`          | `composer-remove`   |
| `composer why package`             | `composer-why`      |
| `composer why-not package version` | `composer-why-not`  |

#### Benefits

- Token-optimized TOON output
- Structured dependency information
- Consistent error reporting

#### Output Modes

`default`, `summary`, `detailed`

---

### PHPStan Extension

Use MCP tools instead of CLI for static analysis:

| Instead of...                           | Use                     |
|-----------------------------------------|-------------------------|
| `vendor/bin/phpstan analyse`            | `phpstan-analyse`       |
| `vendor/bin/phpstan analyse src/X.php`  | `phpstan-analyse-file`  |
| `vendor/bin/phpstan clear-result-cache` | `phpstan-clear-cache`   |

#### Benefits

- Token-optimized TOON output (~67% reduction)
- Errors grouped by file or type

#### Output Modes

`toon` (default), `summary`, `detailed`, `by-file`, `by-type`

---

### PHPUnit Extension

Use MCP tools instead of CLI for testing:

| Instead of...                         | Use                   |
|---------------------------------------|-----------------------|
| `vendor/bin/phpunit`                  | `phpunit-run-suite`   |
| `vendor/bin/phpunit tests/X.php`      | `phpunit-run-file`    |
| `vendor/bin/phpunit --filter testX`   | `phpunit-run-method`  |
| `vendor/bin/phpunit --list-tests`     | `phpunit-list-tests`  |

#### Benefits

- Token-optimized TOON output (40-50% reduction)
- Structured error grouping by file or class

#### Output Modes

`default`, `summary` (quick check), `detailed` (debugging), `by-file`, `by-class`

---

### Server Info

| Instead of...       | Use           |
|---------------------|---------------|
| `php -v`            | `server-info` |
| `php -m`            | `server-info` |
| `uname -s`          | `server-info` |

- Returns PHP version, OS, OS family, and loaded extensions in a single call

---

### Monolog Bridge

Use MCP tools instead of CLI for log analysis:

| Instead of...                     | Use                                              |
|-----------------------------------|--------------------------------------------------|
| `tail -f var/log/dev.log`         | `monolog-tail`                                   |
| `grep "error" var/log/*.log`      | `monolog-search` with term "error"               |
| `grep -E "pattern" var/log/*.log` | `monolog-search` with term "pattern", regex: true |

#### Benefits

- Structured output with parsed log entries
- Multi-file search across all logs at once
- Filter by environment, level, or channel

---

### Symfony Bridge

#### Container Introspection

| Instead of...                  | Use                |
|--------------------------------|--------------------|
| `bin/console debug:container`  | `symfony-services` |

- Direct access to compiled container
- Environment-aware (auto-detects dev/test/prod)
- Supports filtering by service ID or class name via query parameter

#### Profiler Access

When `symfony/http-kernel` is installed, profiler tools become available:

| Tool                        | Description                                             |
|-----------------------------|---------------------------------------------------------|
| `symfony-profiler-list`     | List and filter profiles by method, URL, IP, status, date range |
| `symfony-profiler-get`      | Get profile by token                                    |

**Resources:**
- `symfony-profiler://profile/{token}` - Full profile with collector list
- `symfony-profiler://profile/{token}/{collector}` - Collector-specific data

**Security:** Cookies, session data, auth headers, and sensitive env vars are automatically redacted.
