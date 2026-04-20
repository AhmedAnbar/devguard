# DevGuard

A modular CLI toolkit for Laravel projects. Audits production-readiness and enforces clean architecture in under 2 seconds.

```
$ devguard run deploy

Deploy Readiness Score: 75/100

  ✓ .env file exists
  ✗ APP_DEBUG is enabled in production            [-15]
  ✗ No rate limiting detected on routes           [-10]
  ⚠ LOG_CHANNEL='single' is weak for production   [-2]

Suggestions:
  → Set APP_DEBUG=false to avoid leaking stack traces and config to users.
  → Apply 'throttle:api' middleware to your API routes.
  → Use 'daily' or 'stack' to get rotation and multi-channel fan-out.

Failed. Address the errors above before deploying.
```

---

## Features

- **Deploy Readiness Score** — 7 production-readiness checks (env, debug, cache, queue, rate limit, https, logging) with a 0–100 score.
- **Architecture Enforcer** — 6 clean-architecture rules (folder structure, fat controllers, complexity, direct DB calls, service/repository layers).
- **Env Audit** — `.env` vs `.env.example` consistency: missing keys, drift, weak APP_KEY.
- **Dependency Audit** — wraps `composer audit` to surface CVEs and abandoned packages from your `composer.lock`.
- **Git hook installer** — `devguard install-hook` adds a pre-push (or pre-commit) gate so checks block bad commits before they ship.
- **Auto-fix** — `devguard fix deps` runs `composer update <pkg>` for each advisory; `devguard fix env` writes missing keys from `.env.example` (with backup).
- **Interactive menu** when run with no arguments.
- **JSON output** for CI/CD pipelines.
- **Exit codes** that fail builds when problems are found.
- **Zero config** — works out of the box; override per-project via `devguard.php`.

---

## Install

### As a project dev dependency (recommended)

```bash
composer require --dev ahmedanbar/devguard
./vendor/bin/devguard
```

### Globally

```bash
composer global require ahmedanbar/devguard
devguard
```

Make sure `~/.composer/vendor/bin` is on your `PATH`.

---

## Usage

```bash
devguard                              # Interactive menu
devguard tools                        # List registered tools
devguard run deploy                   # Production-readiness scan
devguard run architecture             # Architecture rules
devguard run env                      # .env vs .env.example audit
devguard run deps                     # composer audit wrapper
devguard run all                      # Run every tool sequentially
devguard run deploy --json            # JSON output (CI-friendly)
devguard run deploy --path=/some/dir  # Operate on a different project

devguard install-hook                 # Install pre-push gate
devguard install-hook --type=pre-commit --tools=deploy --force

devguard fix deps                     # Interactive: prompts per CVE
devguard fix env --dry-run            # Preview the plan, change nothing
devguard fix env --yes                # Apply every fix without prompting
devguard fix all --yes                # Run every fixable tool

devguard --help
devguard --version
```

### Auto-fix

| Tool          | Fixable?       | What `devguard fix <tool>` does                              |
|---------------|----------------|--------------------------------------------------------------|
| `deps`        | ✅ yes         | `composer update <pkg> --with-dependencies` per advisory     |
| `env`         | ✅ yes         | Append missing keys from `.env.example`, backup to `.env.devguard.bak` |
| `deploy`      | ❌ not yet     | Most issues need human judgement (which queue, which cache)  |
| `architecture`| ❌ no          | Refactoring is human work — no safe auto-fix                 |

Default is interactive: every mutation prompts for `y/N`. `--dry-run` shows the plan without writing. `--yes` is for CI / when you trust the plan.

### Exit codes

| Code | Meaning                                |
|------|----------------------------------------|
| 0    | All checks passed (warnings allowed)   |
| 1    | At least one failed check              |
| 2    | Tool error / unrecoverable failure     |

CI pipelines can fail the build on non-zero exit.

---

## Tools

### Deploy Readiness Score

| Check               | Default impact | What it looks for                        |
|---------------------|----------------|------------------------------------------|
| `env_file_exists`   | 20             | `.env` present at project root           |
| `debug_mode`        | 15             | `APP_DEBUG` is not `true` in production  |
| `cache_configured`  | 10             | `CACHE_STORE` is a production driver     |
| `queue_configured`  | 10             | `QUEUE_CONNECTION` isn't `sync`/`null`   |
| `rate_limit`        | 10             | `throttle` middleware present on routes  |
| `https_enforced`    | 10             | `URL::forceScheme('https')` or APP_URL   |
| `logging_configured`| 5              | `LOG_CHANNEL` isn't `single`/`null`      |

### Architecture Enforcer

| Rule                              | Severity | Detection                                |
|-----------------------------------|----------|------------------------------------------|
| `folder_structure`                | fail     | Required Laravel directories exist       |
| `fat_controller`                  | fail     | Controller > 300 lines                   |
| `business_logic_in_controller`    | fail/warn | Cyclomatic complexity > 10 / 6           |
| `direct_db_in_controller`         | fail     | `DB::table/select/insert/...` in actions |
| `service_layer`                   | warn     | `app/Services` exists & non-empty        |
| `repository_layer`                | warn     | `app/Repositories` exists & non-empty    |

---

## JSON output

```json
{
    "tool": "deploy",
    "title": "Deploy Readiness Score",
    "score": 75,
    "passed": false,
    "results": [
        {
            "name": "debug_mode",
            "status": "fail",
            "message": "APP_DEBUG is enabled in production",
            "impact": 15,
            "suggestion": "Set APP_DEBUG=false to avoid leaking stack traces and config to users."
        }
    ]
}
```

---

## Configuration

Drop a `devguard.php` at your project root to override defaults. Only the keys you specify are merged on top of the built-ins.

```php
<?php

return [
    'tools' => [
        'deploy' => [
            'checks' => [
                'debug_mode' => ['impact' => 25],
            ],
        ],
        'architecture' => [
            'rules' => [
                'fat_controller' => ['max_lines' => 200],
            ],
        ],
    ],
];
```

---

## Extending — add your own tool

1. Create a class implementing `DevGuard\Contracts\ToolInterface`:

   ```php
   final class MyTool implements ToolInterface
   {
       public function name(): string { return 'mytool'; }
       public function title(): string { return 'My Tool'; }
       public function description(): string { return 'Does a thing.'; }

       public function run(ProjectContext $ctx): ToolReport
       {
           $report = new ToolReport(tool: $this->name(), title: $this->title());
           $report->add(CheckResult::pass('hello', 'It works'));
           return $report;
       }
   }
   ```

2. Register it in `bin/devguard` (or your own entry script):

   ```php
   $app->addTool(new MyTool());
   ```

That's it — the menu, `tools` list, JSON renderer, and exit-code logic all pick it up automatically.

---

## Development

```bash
composer install
composer test     # pest
composer stan     # phpstan
composer fmt      # laravel pint
```

Tests run against fixtures under `tests/Fixtures/` — a deliberately broken Laravel app and a clean one. CI runs both PHP 8.2 and 8.3.

---

## License

MIT
