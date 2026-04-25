# DevGuard

[![Latest version on Packagist](https://img.shields.io/packagist/v/ahmedanbar/devguard.svg?style=flat-square)](https://packagist.org/packages/ahmedanbar/devguard)
[![CI status](https://img.shields.io/github/actions/workflow/status/AhmedAnbar/devguard/ci.yml?branch=main&style=flat-square&label=tests)](https://github.com/AhmedAnbar/devguard/actions)
[![PHP version](https://img.shields.io/packagist/php-v/ahmedanbar/devguard.svg?style=flat-square)](https://packagist.org/packages/ahmedanbar/devguard)
[![Downloads](https://img.shields.io/packagist/dt/ahmedanbar/devguard.svg?style=flat-square)](https://packagist.org/packages/ahmedanbar/devguard)
[![License](https://img.shields.io/packagist/l/ahmedanbar/devguard.svg?style=flat-square)](LICENSE)

**A modular CLI toolkit for Laravel projects. Audits production-readiness, clean architecture, env consistency, and dependency CVEs — in under 2 seconds.**

📖 **Full documentation: [github.com/AhmedAnbar/devguard/wiki](https://github.com/AhmedAnbar/devguard/wiki)** — installation, every command, every rule, CI recipes, troubleshooting.

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
- **Env Audit** — `.env` vs `.env.example` consistency, drift across `.env.testing`/`.env.staging`/etc., undeclared `env()` calls in code, weak APP_KEY.
- **Dependency Audit** — wraps `composer audit` to surface CVEs and abandoned packages from your `composer.lock`.
- **Git hook installer** — `devguard install-hook` adds a pre-push (or pre-commit) gate so checks block bad commits before they ship.
- **Auto-fix** — `devguard fix deps` runs `composer update <pkg>` for each advisory; `devguard fix env` writes missing keys from `.env.example` (with backup).
- **Baseline file + `@devguard-ignore` annotations** — `devguard baseline` records existing issues so future runs only surface NEW ones. Adopt on legacy projects without fixing 200 things on day one.
- **`--changed-only` mode** — scan only files in `git diff` instead of every PHP file. Pre-commit hooks become sub-second on big projects.
- **Interactive menu** when run with no arguments.
- **JSON output** for CI/CD pipelines.
- **HTML report** — `--html` writes a self-contained, styled page (no CDN, no JS) you can email, archive as a CI artifact, or open locally.
- **SARIF output** — `--sarif` emits a SARIF 2.1.0 file. Upload via `github/codeql-action/upload-sarif` and DevGuard findings appear as inline annotations on PR diffs in GitHub Code Scanning.
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
composer global require "ahmedanbar/devguard:^0.1 || ^0.2 || ^0.3 || ^0.4 || ^0.5 || ^0.6 || ^0.7 || ^0.8"
devguard
```

The OR-chain is required for 0.x packages — Composer's caret operator pins to the *minor*, not the major. ([Why?](https://github.com/AhmedAnbar/devguard/wiki/Troubleshooting#the-version-pin-trap))

Make sure `~/.composer/vendor/bin` is on your `PATH`.

### As a GitHub Action (CI-only — no Composer install needed)

```yaml
- uses: actions/checkout@v4

- name: Prepare .env for audit
  run: |
    cp .env.example .env
    sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env

- uses: AhmedAnbar/devguard-action@v1
  with:
    sarif-output: devguard.sarif

- uses: github/codeql-action/upload-sarif@v3
  if: always()
  with:
    sarif_file: devguard.sarif
    category: devguard
```

Full setup, options, and Code Scanning integration: [GitHub Actions in the wiki](https://github.com/AhmedAnbar/devguard/wiki/GitHub-Actions).

For other CI systems (GitLab CI, Bitbucket, Jenkins, CircleCI), see [Other CI Systems](https://github.com/AhmedAnbar/devguard/wiki/Other-CI-Systems).

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
devguard run deploy --html            # Write devguard-report.html, auto-open in browser
devguard run all --html=report.html   # Combined HTML page (all tools, one file)
devguard run deploy --html --no-open  # Skip the browser auto-open (CI / scripts)
devguard run all --sarif              # Write devguard.sarif (additive — console still shows)
devguard run all --sarif=findings.sarif # Custom SARIF path
devguard run deploy --path=/some/dir  # Operate on a different project

devguard install-hook                 # Install pre-push gate
devguard install-hook --type=pre-commit --tools=deploy --force

devguard baseline                     # Record current issues as the accepted baseline
devguard baseline --output=audit.json # Custom baseline path
devguard run all --no-baseline        # Bypass baseline filtering, see everything

devguard run all --changed-only       # Only scan files in `git diff HEAD` (uncommitted)
devguard run all --changed-only="--cached"      # Staged only — for pre-commit hooks
devguard run all --changed-only=origin/main     # PR diff — for CI

devguard fix deps                     # Interactive: prompts per CVE
devguard fix env --dry-run            # Preview the plan, change nothing
devguard fix env --yes                # Apply every fix without prompting
devguard fix all --yes                # Run every fixable tool

devguard --help
devguard --version
```

### GitHub Code Scanning (SARIF)

```yaml
- name: Run DevGuard
  run: devguard run all --sarif=devguard.sarif

- name: Upload SARIF to GitHub Code Scanning
  if: always()
  uses: github/codeql-action/upload-sarif@v4
  with:
    sarif_file: devguard.sarif
    category: devguard
```

DevGuard findings then appear as inline annotations on PR diffs and in the repository's **Security → Code scanning** tab. Severities map: `Status::Fail` → `error`, `Status::Warning` → `warning`. Pass results aren't emitted. The `partialFingerprints` reuse the same signature scheme as the baseline file, so GitHub correctly tracks "same issue across runs" without re-flagging fixed items.

### Adopting on a legacy codebase: baseline + ignores

Run once on a project that has 50 issues:

```bash
devguard baseline                # writes devguard-baseline.json
git add devguard-baseline.json && git commit -m "chore: devguard baseline"
```

Now future `devguard run` invocations skip the baselined 50 and only surface what's *new*. To see everything anyway, pass `--no-baseline`. To regenerate after fixing a chunk, run `devguard baseline` again.

For one-off exceptions in code (no need to bake them into the baseline):

```php
// @devguard-ignore: direct_db_in_controller
$rows = DB::table('users')->whereRaw($trustedExpr)->get();

$x = $request->raw(); // @devguard-ignore   (suppresses every rule at this line)
```

The annotation can sit on the same line or directly above the issue.

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
