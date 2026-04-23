# Test DevGuard end-to-end on a fresh Laravel app

A practical walkthrough: create a brand-new Laravel application, push it to GitHub, then exercise every DevGuard feature against it — local CLI, all 4 audit tools, the `fix` command, the baseline workflow, the HTML report, and the GitHub Action with SARIF inline annotations.

Time budget: **~30 minutes** end-to-end (10 minutes if you skip the GitHub Action wiring).

---

## What you'll need installed

| Tool                      | Why                                     | Check                         |
| ------------------------- | --------------------------------------- | ----------------------------- |
| PHP **≥ 8.2**             | Laravel + DevGuard runtime              | `php -v`                      |
| Composer **2.x**          | install Laravel + DevGuard              | `composer --version`          |
| `gh` CLI                  | create + push the GitHub repo           | `gh --version`                |
| Git                       | obvious                                 | `git --version`               |
| `composer global` on PATH | so `devguard` is callable from anywhere | `echo $PATH \| grep composer` |

If `~/.composer/vendor/bin` (or `~/.config/composer/vendor/bin`) is not on your `PATH`, add it to your shell rc file before continuing — otherwise installing DevGuard globally won't make `devguard` callable.

---

## Step 1 — Create a new Laravel application

```bash
cd ~/Documents
composer create-project laravel/laravel devguard-smoke
cd devguard-smoke
```

Verify it boots:
```bash
php artisan --version
# → Laravel Framework 12.x.x  (or whatever ships today)
```

> Don't bother running migrations or starting the dev server — DevGuard is purely static, it never executes your app.

---

## Step 2 — Push it to GitHub

```bash
git init
git add .
git commit -m "chore: initial laravel skeleton"

# Create the repo + push in one shot. Replace --public with --private if preferred.
gh repo create devguard-smoke --public --source=. --remote=origin --push
```

Verify:
```bash
gh repo view --web   # opens the new repo in your browser
```

---

## Step 3 — Install DevGuard globally (with the right constraint)

The caret-on-0.x trap means a naive `composer require ahmedanbar/devguard` pins to a single minor and stops upgrading. Use the OR'd constraint instead:

```bash
composer global require ahmedanbar/devguard:"^0.1 || ^0.2 || ^0.3 || ^0.4 || ^0.5 || ^0.6 || ^0.7"
devguard -V
# → DevGuard 0.7.0  (or newer)
```

If `devguard: command not found`, your `~/.composer/vendor/bin` (or `~/.config/composer/vendor/bin` on Linux) isn't on `PATH`. Fix that, re-source your shell rc, retry.

---

## Step 4 — Run all 4 audit tools locally

From inside the Laravel app:

```bash
devguard run all
```

Expected output sketch:
```
Deploy Readiness Score: ~55/100   ← fresh Laravel default isn't production-ready
  ✗ APP_DEBUG is enabled in production
  ✗ APP_KEY is missing
  ⚠ ...

Architecture Report
  ✓ All clean (fresh skeleton has no fat controllers yet)

Env Audit Report
  ✗ APP_KEY is empty
  ⚠ ...

Dependency Audit Report
  ⚠ composer.lock present but may have CVEs depending on version
```

Try each tool individually too:
```bash
devguard run deploy
devguard run architecture
devguard run env
devguard run deps
```

> **What to look for:** the score, the per-rule fail/warn icons, the suggestions block at the end. Confirm the exit code (`echo $?`) — non-zero means at least one check failed (correct for a fresh Laravel default config).

---

## Step 5 — The HTML report (auto-opens in your browser)

```bash
devguard run all --html
```

A browser tab opens with the styled report — score ring, severity-coloured groups, suggestion boxes. The file `devguard-report.html` is also written next to your `composer.json` for sharing or attaching to a CI artifact.

To skip the auto-open (useful in CI):
```bash
devguard run all --html --no-open
```

---

## Step 6 — Try the `fix` command

DevGuard can auto-resolve some issues. Two tools are fixable today: `deps` and `env`.

### Fix env keys

If `MissingEnvKeysRule` flagged anything (it usually does on a fresh project), preview the fix first:

```bash
devguard fix env --dry-run
```

Then apply with prompts:
```bash
devguard fix env
# → asks y/N per missing key
```

Or unattended (CI-style):
```bash
devguard fix env --yes
```

A backup is written to `.env.devguard.bak` exactly once per run, so you can always recover the pre-fix `.env`.

### Fix dependency CVEs

```bash
devguard fix deps --dry-run    # shows which composer update commands would run
devguard fix deps              # interactive per-CVE
```

> Architecture rules are intentionally NOT fixable — refactoring is human work. If you run `devguard fix architecture` you'll get a friendly "no fixable tools matched" message.

---

## Step 7 — The baseline workflow

The single most important feature for adopting DevGuard on real codebases. Records existing issues so future runs only show NEW ones.

```bash
# 1. Bake current issues into the baseline
devguard baseline

# Inspect what was recorded
cat devguard-baseline.json | head -30

# 2. Commit it — this is the one-time "we accept these for now" snapshot
git add devguard-baseline.json
git commit -m "chore: devguard baseline"

# 3. Re-run — now nothing should fail because everything is baselined
devguard run all
# → "All checks passed.  (N issues suppressed by baseline / @devguard-ignore)"
echo $?   # → 0

# 4. Want to see everything again? Bypass the baseline:
devguard run all --no-baseline
```

**Now make a NEW issue** — edit `app/Http/Controllers/Controller.php` and add an `env('TOTALLY_FAKE_KEY')` call **inside a method** (PHP doesn't allow free-standing function calls at the class body level — they're a syntax error and DevGuard will warn that the file couldn't be parsed):

```php
<?php

namespace App\Http\Controllers;

abstract class Controller
{
    public function devguardSmokeTest(): mixed
    {
        // Intentional: TOTALLY_FAKE_KEY is not declared in any .env file.
        return env('TOTALLY_FAKE_KEY');
    }
}
```

Re-run:

```bash
devguard run env
# → 1 new failure for TOTALLY_FAKE_KEY (everything else still suppressed)
```

This is the moment DevGuard becomes adoptable on real legacy projects.

> **If you instead get `Could not parse app/Http/Controllers/Controller.php — undeclared env() check skipped (syntax error — ...)`** — you put the `env()` call outside a method. Move it inside one, save, re-run.

### One-off ignores in code

Sometimes you need to suppress without baking into the baseline:

```php
// @devguard-ignore: direct_db_in_controller
$rows = DB::raw($trustedExpr)->get();

$x = $request->raw(); // @devguard-ignore   (suppresses every rule at this line)
```

Annotation can be on the same line as the issue OR the line directly above.

---

## Step 8 — Install a git hook so DevGuard runs before push

```bash
devguard install-hook --type=pre-push --tools=deploy,architecture
```

Then try pushing — the hook runs both tools and blocks the push if either fails. To bypass once: `git push --no-verify`.

> The hook script is tolerant of missing `devguard` (skips silently) so collaborators without DevGuard installed aren't blocked.

---

## Step 9 — Wire up the GitHub Action with SARIF

This is the biggest payoff: **DevGuard findings appear as inline red/yellow squiggles on PR diffs.**

Create `.github/workflows/devguard.yml` in your Laravel app:

```yaml
name: DevGuard

on:
  push:
    branches: [main]
  pull_request:

permissions:
  contents: read
  security-events: write   # required to upload SARIF

jobs:
  devguard:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Run DevGuard
        uses: AhmedAnbar/devguard-action@v1
        with:
          sarif-output: devguard.sarif

      - name: Upload SARIF to GitHub Code Scanning
        if: always()
        uses: github/codeql-action/upload-sarif@v4
        with:
          sarif_file: devguard.sarif
          category: devguard
```

Commit + push:
```bash
git add .github/workflows/devguard.yml
git commit -m "ci: run devguard with sarif upload"
git push
```

---

## Step 10 — Verify the inline annotations actually show up

After the workflow run completes:

1. **Actions tab** — confirm the `DevGuard` job ran (and likely failed, because fresh Laravel ≠ production-ready). The `Run DevGuard` step shows the colored console output.

2. **Open a PR** — make any small change on a branch, push, open a PR:
   ```bash
   git checkout -b test-pr
   echo "// trigger" >> app/Http/Controllers/Controller.php
   git commit -am "test: trigger devguard PR run"
   git push -u origin test-pr
   gh pr create --fill
   ```

3. **Look at the diff in the PR** — DevGuard findings on changed lines appear as 🔴 red or 🟡 yellow icons next to the line numbers. Hover for the message + suggestion.

4. **Security tab → Code scanning** — every finding is also listed here as a managed alert (resolve, dismiss, track over time).

If you don't see annotations:
- Check the workflow ran successfully (Actions tab → DevGuard job → "Upload SARIF" step succeeded)
- Confirm `permissions: security-events: write` is in your workflow
- Wait ~30 seconds — GitHub processes SARIF asynchronously after the workflow finishes

---

## Step 11 — Make the HTML report a CI artifact (optional)

Add to your workflow:

```yaml
      - name: Run DevGuard
        uses: AhmedAnbar/devguard-action@v1
        with:
          sarif-output: devguard.sarif

      - name: Generate HTML report
        if: always()
        run: |
          composer global require ahmedanbar/devguard
          ~/.config/composer/vendor/bin/devguard run all --html=devguard-report.html --no-open

      - name: Upload HTML report
        if: always()
        uses: actions/upload-artifact@v4
        with:
          name: devguard-report
          path: devguard-report.html
```

Now every workflow run produces a downloadable styled HTML report alongside the SARIF.

---

## What "good" looks like at the end

- ✅ `devguard -V` reports the latest version on your machine
- ✅ `devguard run all` shows the full audit with colored output
- ✅ `devguard run all --html` opens a styled report in your browser
- ✅ `devguard fix env --dry-run` lists actionable fixes
- ✅ `devguard baseline && devguard run all` shows "(N suppressed)" instead of failures
- ✅ A PR shows DevGuard squiggles inline on the diff
- ✅ Security → Code scanning lists DevGuard alerts

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `command not found: devguard` | `~/.composer/vendor/bin` not on PATH | Add to shell rc, re-source |
| `composer global update` says "Nothing to modify" but a new version exists | Caret-on-0.x trap | Re-pin: `composer global require ahmedanbar/devguard:"^0.1 \|\| ^0.2 \|\| ... \|\| ^0.X"` |
| Action runs but no SARIF appears in PR | Missing `security-events: write` permission | Add to workflow `permissions:` block |
| Action shows old DevGuard version | Action repo Dockerfile pin (fixed in v1.1.0) | Pin to `@v1.1.0` or wait for `@v1` rolling tag to update |
| `Baseline file is invalid` | Schema version mismatch after DevGuard upgrade | Re-run `devguard baseline` to regenerate |
| HTML browser doesn't open | `CI` env var set, or unsupported OS | Pass `--no-open` to silence the warning |

---

## Cleanup when you're done

```bash
cd ~/Documents
rm -rf devguard-smoke
gh repo delete YourGitHubUsername/devguard-smoke --yes
```

Or keep the repo around as a permanent demo/test bed — it's useful for verifying every future DevGuard release before touching production projects.
