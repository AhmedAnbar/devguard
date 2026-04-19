# DevGuard CLI – Implementation Plan

## Overview

Build a modular CLI tool called **DevGuard** that provides multiple developer utilities in a single interface, with a strong focus on Laravel projects (initially) but designed to be framework-agnostic.

### Initial Tools

1. **Deploy Readiness Score** – production-readiness audit
2. **Laravel Architecture Enforcer** – clean architecture linter

The CLI must be designed so new tools can be added with minimal core changes (open/closed principle).

---

## Goals

* Single CLI binary with multiple tools
* Interactive selection menu **and** non-interactive (flag-based) mode for CI/CD
* Modular architecture (tools, checks, rules are pluggable)
* Zero-config defaults, optional config file for overrides
* Clean, colorized, actionable output (human + JSON)
* Fast (< 2s on a typical Laravel app)
* Well-tested (PHPUnit/Pest)

---

## Tech Stack

| Concern        | Choice                                  | Why |
|----------------|-----------------------------------------|------|
| Language       | **PHP 8.2+**                            | Readonly props, enums, first-class callable syntax |
| Autoloading    | Composer PSR-4                          | Standard |
| CLI framework  | **symfony/console** ^7.0                | Battle-tested arg parsing, colors, tables, progress bars, IO abstraction |
| Interactive UI | **laravel/prompts** ^0.1                | Beautiful interactive menus/selects (works standalone, no Laravel required) |
| File scanning  | **symfony/finder** ^7.0                 | Robust recursive file discovery |
| AST parsing    | **nikic/php-parser** ^5.0               | For controller/service detection in architecture rules |
| Env parsing    | **vlucas/phpdotenv** ^5.6               | Reliable `.env` parsing |
| Testing        | **pestphp/pest** ^2.0                   | Expressive test DSL |
| Static checks  | **phpstan/phpstan** ^1.10 (level 8)     | Catch bugs before runtime |
| Style          | **laravel/pint**                        | Opinionated formatter |

---

## Project Structure

```
devguard/
├── bin/
│   └── devguard                          # Executable entry point
├── src/
│   ├── Core/
│   │   ├── Application.php               # Symfony Console app bootstrap
│   │   ├── ToolManager.php               # Tool registry + interactive menu
│   │   ├── Output/
│   │   │   ├── OutputFormatter.php       # Color/icon abstraction
│   │   │   ├── ConsoleRenderer.php       # Human-readable renderer
│   │   │   └── JsonRenderer.php          # CI/CD-friendly renderer
│   │   ├── ProjectContext.php            # Detects project root, framework, etc.
│   │   └── Config/
│   │       ├── ConfigLoader.php          # Loads + merges config
│   │       └── Config.php                # Typed config object
│   │
│   ├── Contracts/
│   │   ├── ToolInterface.php
│   │   ├── CheckInterface.php
│   │   ├── RuleInterface.php
│   │   └── RendererInterface.php
│   │
│   ├── Results/
│   │   ├── CheckResult.php               # Value object (status, message, impact)
│   │   ├── RuleResult.php                # Value object (status, message, file, line)
│   │   ├── ToolReport.php                # Aggregate of results from one tool
│   │   └── Status.php                    # Enum: Pass, Warning, Fail
│   │
│   ├── Tools/
│   │   ├── DeployReadiness/
│   │   │   ├── DeployReadinessTool.php
│   │   │   ├── Scorer.php                # Computes 0–100 score
│   │   │   └── Checks/
│   │   │       ├── EnvFileExistsCheck.php
│   │   │       ├── DebugModeCheck.php
│   │   │       ├── HttpsEnforcedCheck.php
│   │   │       ├── QueueConfiguredCheck.php
│   │   │       ├── RateLimitCheck.php
│   │   │       ├── CacheConfiguredCheck.php
│   │   │       └── LoggingConfiguredCheck.php
│   │   │
│   │   └── ArchitectureEnforcer/
│   │       ├── ArchitectureTool.php
│   │       ├── FileScanner.php           # Wraps symfony/finder with cache
│   │       └── Rules/
│   │           ├── FatControllerRule.php
│   │           ├── BusinessLogicInControllerRule.php
│   │           ├── ServiceLayerRule.php
│   │           ├── RepositoryRule.php
│   │           ├── FolderStructureRule.php
│   │           └── DirectDbInControllerRule.php
│   │
│   └── Support/
│       ├── PathHelper.php
│       └── AstHelper.php                 # Wraps php-parser
│
├── tests/
│   ├── Unit/
│   │   ├── Core/
│   │   ├── Tools/
│   │   └── Results/
│   ├── Feature/
│   │   └── CliTest.php                   # End-to-end CLI invocation
│   └── Fixtures/
│       └── sample-laravel-app/           # Minimal fake Laravel project
│
├── config/
│   └── devguard.php                      # Default config (overridable per project)
├── composer.json
├── phpstan.neon
├── pest.config.php
├── pint.json
└── README.md
```

---

## CLI Behavior

### Commands

```bash
devguard                       # Interactive menu
devguard list                  # List all tools
devguard run <tool>            # Run a specific tool, e.g. `devguard run deploy`
devguard run <tool> --json     # JSON output for CI/CD
devguard run <tool> --path=/x  # Override project path
devguard run all               # Run every registered tool sequentially
devguard --help
devguard --version
```

### Exit Codes

| Code | Meaning                              |
|------|--------------------------------------|
| 0    | All checks passed (or warnings only) |
| 1    | At least one failed check            |
| 2    | Tool error / unrecoverable failure   |

CI pipelines can fail builds on non-zero exit.

### Interactive Menu

```
╭─────────────────────────────────────╮
│  Welcome to DevGuard                │
╰─────────────────────────────────────╯

? Select a tool: (Use arrow keys)
❯ Deploy Readiness Score
  Laravel Architecture Enforcer
  Run all
  Exit
```

---

## Core Contracts

### ToolInterface

```php
interface ToolInterface {
    public function name(): string;            // "deploy", machine-friendly
    public function title(): string;           // "Deploy Readiness Score"
    public function description(): string;     // For menu/help
    public function run(ProjectContext $ctx): ToolReport;
}
```

### CheckInterface

```php
interface CheckInterface {
    public function name(): string;
    public function run(ProjectContext $ctx): CheckResult;
}
```

### RuleInterface

```php
interface RuleInterface {
    public function name(): string;
    public function run(ProjectContext $ctx): array; // RuleResult[]
}
```

### RendererInterface

```php
interface RendererInterface {
    public function render(ToolReport $report, OutputInterface $out): void;
}
```

---

## Result Value Objects

### Status enum

```php
enum Status: string {
    case Pass    = 'pass';
    case Warning = 'warning';
    case Fail    = 'fail';
}
```

### CheckResult

```php
final readonly class CheckResult {
    public function __construct(
        public Status $status,
        public string $message,
        public int $impact = 0,         // Score deduction weight
        public ?string $suggestion = null,
    ) {}
}
```

### RuleResult

```php
final readonly class RuleResult {
    public function __construct(
        public Status $status,
        public string $message,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $suggestion = null,
    ) {}
}
```

### ToolReport

Aggregates results, knows the tool that produced them, exposes summary helpers (`hasFailures()`, `score()`, `toArray()`).

---

## ProjectContext

Detects context **once** and passes it to every check/rule (avoids each check re-reading files).

```php
final class ProjectContext {
    public string $rootPath;
    public bool $isLaravel;
    public ?string $laravelVersion;
    public array $env;            // Parsed .env
    public array $composerJson;
}
```

Detection logic:
* `composer.json` walking up from `--path` (default: cwd)
* `isLaravel` = `composer.json` requires `laravel/framework`
* Fail loudly if no `composer.json` found

---

## Tool Manager

Responsibilities:

* Register tools at boot
* Resolve a tool by name (`run <name>`)
* Drive interactive menu (`laravel/prompts`)
* Dispatch to the correct renderer based on `--json` flag

---

## Tool #1: Deploy Readiness Score

### Output Example (Console)

```
🚀 Deploy Readiness Score: 75/100

  ✗ APP_DEBUG is enabled                          [-15]
  ✗ No rate limiting detected on API routes       [-10]
  ⚠ Queue retry not configured                    [-5]
  ✓ Cache driver configured (redis)
  ✓ HTTPS enforced via middleware

Suggestions:
  → Set APP_DEBUG=false in production .env
  → Apply throttle middleware to api routes
  → Configure queue retry_after in config/queue.php
```

### Output Example (JSON, for CI)

```json
{
  "tool": "deploy",
  "score": 75,
  "passed": false,
  "checks": [
    {"name": "debug_mode", "status": "fail", "message": "...", "impact": 15}
  ]
}
```

### Initial Checks

| Check                  | Default impact (fail) | Notes |
|------------------------|-----------------------|-------|
| `.env` file exists     | 20                    | Hard requirement |
| `APP_DEBUG=false`      | 15                    |  |
| Cache driver != array  | 10                    | Warning if `file` in prod |
| Queue driver != sync   | 10                    |  |
| Rate limiting present  | 10                    | Scans `routes/api.php` for `throttle` |
| HTTPS enforced         | 10                    | Checks middleware/AppServiceProvider |
| Logging != single/null | 5                     |  |

### Scoring Logic

* Start at 100
* Deduct each failed check's `impact`
* Deduct half the `impact` for warnings
* Floor at 0

---

## Tool #2: Laravel Architecture Enforcer

### Output Example

```
🏗  Architecture Report

  ✗ app/Http/Controllers/UserController.php:1
      Too large (1,200 lines, max 300)
  ✗ app/Http/Controllers/OrderController.php:45
      Direct DB query inside controller
  ⚠ No Service layer detected (app/Services missing)
  ✓ Folder structure follows Laravel convention

Suggestions:
  → Extract logic from UserController into UserService
  → Move DB queries into a repository or service class
```

### Initial Rules

| Rule                              | Severity | Detection method |
|-----------------------------------|----------|------------------|
| Fat controllers (>300 lines)      | fail     | Line count |
| Business logic in controllers     | fail     | AST: cyclomatic complexity threshold |
| Missing service layer             | warn     | `app/Services` exists & non-empty |
| Missing repository layer          | warn     | `app/Repositories` exists |
| Folder structure valid            | fail     | Required dirs exist |
| Direct DB queries in controllers  | fail     | AST: `DB::` or `Model::` calls in controller methods |

All thresholds configurable via `config/devguard.php`.

---

## Configuration

### Default Config (`config/devguard.php`)

```php
return [
    'tools' => [
        'deploy' => [
            'enabled' => true,
            'fail_on' => 'fail', // 'fail' | 'warning'
            'checks' => [
                'debug_mode' => ['impact' => 15],
                'rate_limit' => ['impact' => 10],
                // ...
            ],
        ],
        'architecture' => [
            'enabled' => true,
            'rules' => [
                'fat_controller' => ['max_lines' => 300],
                'service_layer'  => ['path' => 'app/Services'],
            ],
        ],
    ],
    'output' => [
        'colors' => true,
        'icons'  => true,
    ],
];
```

### Override

A project can drop a `devguard.php` at its root to override defaults. Loader merges deep with defaults.

---

## Output Formatting

Use **symfony/console** styled output:

| Status   | Color  | Icon |
|----------|--------|------|
| Pass     | green  | ✓    |
| Warning  | yellow | ⚠    |
| Fail     | red    | ✗    |

* Detect `--no-color` and `NO_COLOR` env var (https://no-color.org)
* Auto-disable colors when not a TTY (piping to file)
* JSON mode is plain (no colors, no icons)

---

## Error Handling

* All tools wrapped in try/catch at the `Application` layer
* Unhandled exceptions: print friendly error + stack trace only with `-v`
* Failed individual checks/rules do **not** crash the tool — recorded as a `Fail` result with the exception message
* No silent fallbacks: if `composer.json` is missing, exit code 2 with explicit error

---

## Testing Strategy

| Layer        | Approach |
|--------------|----------|
| Unit         | Each Check/Rule tested in isolation against fixture files |
| Feature      | Run the binary against `tests/Fixtures/sample-laravel-app/` and assert output |
| Snapshot     | Pest snapshot tests for renderer output |
| CI           | GitHub Actions matrix: PHP 8.2, 8.3 |

Target: 80%+ coverage on `src/Tools/` and `src/Core/`.

---

## CLI Entry Script (`bin/devguard`)

```php
#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use DevGuard\Core\Application;
use DevGuard\Tools\DeployReadiness\DeployReadinessTool;
use DevGuard\Tools\ArchitectureEnforcer\ArchitectureTool;

$app = new Application('DevGuard', '0.1.0');

$app->register(new DeployReadinessTool());
$app->register(new ArchitectureTool());

exit($app->run());
```

---

## Composer Configuration

```json
{
    "name": "yourname/devguard",
    "description": "Developer CLI toolkit for Laravel projects",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "symfony/console": "^7.0",
        "symfony/finder": "^7.0",
        "laravel/prompts": "^0.1",
        "vlucas/phpdotenv": "^5.6",
        "nikic/php-parser": "^5.0"
    },
    "require-dev": {
        "pestphp/pest": "^2.0",
        "phpstan/phpstan": "^1.10",
        "laravel/pint": "^1.13"
    },
    "autoload": {
        "psr-4": { "DevGuard\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "DevGuard\\Tests\\": "tests/" }
    },
    "bin": ["bin/devguard"],
    "scripts": {
        "test": "pest",
        "stan": "phpstan analyse",
        "fmt":  "pint"
    }
}
```

---

## Extensibility

To add a new tool:

1. Create `src/Tools/MyTool/MyTool.php` implementing `ToolInterface`
2. Add Checks/Rules in subdirectories
3. Register in `bin/devguard`:
   ```php
   $app->register(new MyTool());
   ```
4. (Optional) Add config under `tools.my_tool` in `config/devguard.php`

No core code changes required.

---

## MVP Phases

### Phase 1 – Foundation (Day 1–2)
* Composer setup, autoloading, dependencies
* `Application`, `ToolManager`, `ProjectContext`
* Result value objects + `Status` enum
* Console + JSON renderers
* Interactive menu wired up

### Phase 2 – Deploy Readiness (Day 3–4)
* All 7 initial checks
* `Scorer` with weighted impacts
* Tests against fixture Laravel app

### Phase 3 – Architecture Enforcer (Day 5–7)
* `FileScanner` + `AstHelper`
* All 6 initial rules
* Tests

### Phase 4 – Polish & Ship (Day 8)
* README with install + usage
* GitHub Actions CI
* Tag v0.1.0, publish to Packagist

---

## Future Enhancements

* GitHub Action wrapper (`devguard-action`)
* Auto-fix mode (`devguard fix <tool>`)
* Plugin system (composer-discoverable tools)
* HTML report renderer
* Baseline file (ignore pre-existing issues)
* Framework support beyond Laravel (Symfony, generic PHP)
* Performance profiling tool
* Security audit tool (composer audit + CVE checks)

---

## Constraints (MVP)

* Must run in **< 2s** on a typical Laravel app
* Zero configuration required for first run
* No network calls (offline-friendly)
* Output must be actionable — every failure includes a suggestion
* No external services required (everything runs locally)

---

## Expected Deliverables

* Fully working `devguard` CLI binary
* Two functional tools (Deploy Readiness, Architecture Enforcer)
* JSON output mode
* Interactive + non-interactive modes
* PHPUnit/Pest test suite (80%+ coverage on core)
* GitHub Actions CI
* README with install + usage
* Tagged v0.1.0 release on Packagist

---
