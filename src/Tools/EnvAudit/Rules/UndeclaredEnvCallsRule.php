<?php

declare(strict_types=1);

namespace DevGuard\Tools\EnvAudit\Rules;

use DevGuard\Contracts\FixableInterface;
use DevGuard\Contracts\RuleInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\Fix;
use DevGuard\Results\FixResult;
use DevGuard\Results\RuleResult;
use DevGuard\Support\AstHelper;
use DevGuard\Tools\EnvAudit\Support\EnvFileDiscovery;
use DevGuard\Tools\EnvAudit\Support\EnvFileLoader;
use PhpParser\Node;
use PhpParser\NodeFinder;
use Symfony\Component\Finder\Finder;

/**
 * Statically scans the project's source for env() / Env::get() calls and
 * reports any key that isn't declared in any .env-family file.
 *
 * "Declared" means the key appears as a left-hand side in at least one of:
 * .env, .env.example, .env.* — the union, not the intersection. The point
 * of this rule is to catch typos and stale references where the runtime
 * env() helper would silently return null and trigger a downstream bug.
 *
 * Why AST and not regex: env('KEY' /* old name * /) and multi-line calls
 * trip up regex; the parser doesn't care. nikic/php-parser is already
 * a dependency for the architecture rules.
 *
 * Out of scope by design:
 *  - Dynamic calls like env($variable) — can't validate without a runtime
 *  - The inverse check (key declared but never used) — separate rule, ask
 *    if you want it
 */
final class UndeclaredEnvCallsRule implements RuleInterface, FixableInterface
{
    /** Directories Laravel actually loads at runtime. We deliberately skip
     *  vendor/, tests/, storage/, node_modules/ to keep scan time bounded. */
    private const SCAN_DIRS = ['app', 'config', 'bootstrap', 'database', 'routes'];

    public function __construct(
        private readonly EnvFileLoader $loader = new EnvFileLoader(),
        private readonly EnvFileDiscovery $discovery = new EnvFileDiscovery(),
        private readonly AstHelper $ast = new AstHelper(),
    ) {}

    public function name(): string
    {
        return 'undeclared_env_calls';
    }

    /** @return array<int, RuleResult> */
    public function run(ProjectContext $ctx): array
    {
        $declared = $this->collectDeclaredKeys($ctx);

        // No env files at all → bail with a pass. The other rules will have
        // already flagged the missing files; we shouldn't re-noise.
        if ($declared === []) {
            return [RuleResult::pass($this->name(), 'Skipped — no .env-family files found to validate against')];
        }

        $finder = new NodeFinder();
        $reported = []; // dedupe by "file:key"
        $results = [];
        $scannedAny = false;

        foreach (self::SCAN_DIRS as $relDir) {
            $absDir = $ctx->path($relDir);
            if (! is_dir($absDir)) {
                continue;
            }

            $files = (new Finder())
                ->files()
                ->in($absDir)
                ->name('*.php')
                ->ignoreDotFiles(true)
                ->ignoreVCS(true);

            foreach ($files as $file) {
                $abs = $file->getRealPath() ?: $file->getPathname();
                $relPath = $this->relativePath($ctx, $abs);
                // Honor --changed-only: skip files outside the changed set.
                if (! $ctx->shouldScan($relPath)) {
                    continue;
                }
                $scannedAny = true;
                $ast = $this->ast->parseFile($abs, $parseError);
                if ($ast === null) {
                    // Surface the skip rather than silently dropping a file.
                    // A real user got bitten by this in v0.7.0 — they added
                    // env('FAKE') outside a method, the file no longer
                    // parsed, and the rule reported "all clean" instead of
                    // flagging the new key OR warning about the parse fail.
                    // Lesson #21 in CLAUDE.md.
                    $results[] = RuleResult::warn(
                        $this->name(),
                        sprintf('Could not parse %s — undeclared env() check skipped (%s)', $relPath, $parseError ?? 'unknown error'),
                        $relPath,
                        null,
                        sprintf('Run `php -l %s` to see the parse error, then re-run DevGuard.', $relPath)
                    );
                    continue;
                }

                $this->collectFromAst($ast, $finder, $declared, $relPath, $reported, $results);
            }
        }

        if ($results === []) {
            $emptyMessage = $scannedAny
                ? 'All env() calls reference declared keys'
                : ($ctx->isChangedOnly()
                    ? 'No changed PHP files in scope (app/config/bootstrap/database/routes)'
                    : 'Skipped — no scannable source directories (app/config/bootstrap/database/routes)');
            return [RuleResult::pass($this->name(), $emptyMessage)];
        }

        return $results;
    }

    /**
     * @param array<int, Node>   $ast
     * @param array<string,bool> $declared
     * @param array<string,bool> $reported  by-ref dedupe map
     * @param array<int,RuleResult> $results by-ref output buffer
     */
    private function collectFromAst(
        array $ast,
        NodeFinder $finder,
        array $declared,
        string $relPath,
        array &$reported,
        array &$results,
    ): void {
        // env('KEY') — global helper
        /** @var array<int, Node\Expr\FuncCall> $funcCalls */
        $funcCalls = $finder->findInstanceOf($ast, Node\Expr\FuncCall::class);
        foreach ($funcCalls as $call) {
            if (! $call->name instanceof Node\Name) {
                continue; // dynamic function name, skip
            }
            $name = strtolower(ltrim($call->name->toString(), '\\'));
            if ($name !== 'env') {
                continue;
            }
            $key = $this->literalFirstArg($call->getArgs());
            if ($key === null) {
                continue; // env($var, ...) — can't validate
            }
            $this->maybeReport($key, $declared, $relPath, $call->getStartLine(), $reported, $results, "env('%s')");
        }

        // Env::get('KEY') — Laravel facade form
        /** @var array<int, Node\Expr\StaticCall> $staticCalls */
        $staticCalls = $finder->findInstanceOf($ast, Node\Expr\StaticCall::class);
        foreach ($staticCalls as $call) {
            if (! $call->class instanceof Node\Name) {
                continue;
            }
            $className = ltrim($call->class->toString(), '\\');
            // Match the short name "Env" — handles both bare `Env::get` and
            // fully-qualified `Illuminate\Support\Env::get`.
            $shortName = $this->shortClassName($className);
            if (strtolower($shortName) !== 'env') {
                continue;
            }
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }
            if ($call->name->toString() !== 'get') {
                continue;
            }
            $key = $this->literalFirstArg($call->getArgs());
            if ($key === null) {
                continue;
            }
            $this->maybeReport($key, $declared, $relPath, $call->getStartLine(), $reported, $results, "Env::get('%s')");
        }
    }

    /** @param array<string,bool> $declared
     *  @param array<string,bool> $reported
     *  @param array<int,RuleResult> $results */
    private function maybeReport(
        string $key,
        array $declared,
        string $relPath,
        int $line,
        array &$reported,
        array &$results,
        string $callShape,
    ): void {
        if (isset($declared[$key])) {
            return;
        }
        $dedupeKey = $relPath . ':' . $key;
        if (isset($reported[$dedupeKey])) {
            return;
        }
        $reported[$dedupeKey] = true;

        $results[] = RuleResult::fail(
            $this->name(),
            sprintf(
                '%s references undeclared key — will return null at runtime',
                sprintf($callShape, $key)
            ),
            $relPath,
            $line,
            sprintf(
                'Add %s= to .env.example (and the env files that need it), or remove the call if unused.',
                $key
            ),
        );
    }

    /** @param array<int, Node\Arg> $args */
    private function literalFirstArg(array $args): ?string
    {
        if ($args === []) {
            return null;
        }
        $first = $args[0]->value;
        if ($first instanceof Node\Scalar\String_) {
            return $first->value;
        }
        // Anything else (variables, concats, function calls) is dynamic —
        // skip rather than guess.
        return null;
    }

    private function shortClassName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    private function relativePath(ProjectContext $ctx, string $absolute): string
    {
        $root = rtrim($ctx->rootPath, '/') . '/';
        return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : $absolute;
    }

    /**
     * One Fix per unique undeclared key (deduped across files). The fix
     * appends `KEY=` to `.env.example` — the safe move, since the user
     * either fills the value in OR removes both the line and the env()
     * call (if the call was a typo). Auto-deleting the env() call would
     * be too aggressive without per-issue review.
     *
     * Note: this only adds to `.env.example`. Re-running `devguard fix env`
     * afterwards will then offer to add the key to `.env` via
     * MissingEnvKeysRule (two-step intentionally — values in .env.example
     * are templates; values in .env are environment-specific).
     *
     * @return array<int, Fix>
     */
    public function proposeFixes(ProjectContext $ctx): array
    {
        // Without .env.example we have nowhere to declare the key. Other
        // rules already flag the missing template file; we stay silent here.
        if (! $ctx->fileExists('.env.example')) {
            return [];
        }

        $declared = $this->collectDeclaredKeys($ctx);
        if ($declared === []) {
            return [];
        }

        // Walk the same source dirs as run() and gather unique undeclared
        // keys. Parse failures are silently skipped here (they're surfaced
        // by run() with the parse-warning RuleResult — lesson #21 — so the
        // user already knows the file isn't being scanned).
        $finder = new NodeFinder();
        /** @var array<string, bool> $undeclaredKeys */
        $undeclaredKeys = [];

        foreach (self::SCAN_DIRS as $relDir) {
            $absDir = $ctx->path($relDir);
            if (! is_dir($absDir)) {
                continue;
            }

            $files = (new Finder())
                ->files()
                ->in($absDir)
                ->name('*.php')
                ->ignoreDotFiles(true)
                ->ignoreVCS(true);

            foreach ($files as $file) {
                $abs = $file->getRealPath() ?: $file->getPathname();
                $ast = $this->ast->parseFile($abs);
                if ($ast === null) {
                    continue;
                }
                $this->collectUndeclaredKeysFromAst($ast, $finder, $declared, $undeclaredKeys);
            }
        }

        $fixes = [];
        foreach (array_keys($undeclaredKeys) as $key) {
            $fixes[] = new Fix(
                ruleName: $this->name(),
                target: $key,
                description: sprintf('Append `%s=` to .env.example (review the value before committing)', $key),
                payload: ['key' => $key],
            );
        }
        return $fixes;
    }

    public function applyFix(ProjectContext $ctx, Fix $fix): FixResult
    {
        $key = (string) ($fix->payload['key'] ?? '');
        if ($key === '') {
            return FixResult::failed($fix, 'Fix payload missing key name');
        }

        $envExamplePath = $ctx->path('.env.example');
        if (! file_exists($envExamplePath)) {
            return FixResult::failed($fix, '.env.example disappeared since fix was proposed');
        }

        // Backup once per run so the user can recover. Successive applyFix
        // calls in the same batch must not overwrite the original snapshot.
        $backupPath = $envExamplePath . '.devguard.bak';
        if (! file_exists($backupPath)) {
            if (! @copy($envExamplePath, $backupPath)) {
                return FixResult::failed($fix, "Could not write backup to {$backupPath}");
            }
        }

        $current = (string) file_get_contents($envExamplePath);

        // Idempotency: another fix in this batch (or a prior one) may have
        // already added the key. Don't double-write.
        if (preg_match('/^' . preg_quote($key, '/') . '=/m', $current) === 1) {
            return FixResult::skipped($fix, "{$key} is already declared in .env.example");
        }

        // Always append on a new line (don't concatenate onto whatever the
        // last line happens to be).
        $separator = ($current === '' || str_ends_with($current, "\n")) ? '' : "\n";
        $line = $separator . "{$key}=\n";

        if (file_put_contents($envExamplePath, $line, FILE_APPEND) === false) {
            return FixResult::failed($fix, "Could not append to {$envExamplePath}");
        }

        return FixResult::applied($fix, "Added {$key}= to .env.example");
    }

    /**
     * Walk an AST and accumulate undeclared keys into the given set. Used
     * by proposeFixes(); deliberately not shared with collectFromAst()
     * because that one also resolves file:line and emits richer messages.
     *
     * @param array<int, Node>    $ast
     * @param array<string, bool> $declared
     * @param array<string, bool> $undeclaredKeys  by-ref accumulator (set semantics)
     */
    private function collectUndeclaredKeysFromAst(
        array $ast,
        NodeFinder $finder,
        array $declared,
        array &$undeclaredKeys,
    ): void {
        /** @var array<int, Node\Expr\FuncCall> $funcCalls */
        $funcCalls = $finder->findInstanceOf($ast, Node\Expr\FuncCall::class);
        foreach ($funcCalls as $call) {
            if (! $call->name instanceof Node\Name) {
                continue;
            }
            if (strtolower(ltrim($call->name->toString(), '\\')) !== 'env') {
                continue;
            }
            $key = $this->literalFirstArg($call->getArgs());
            if ($key === null || isset($declared[$key])) {
                continue;
            }
            $undeclaredKeys[$key] = true;
        }

        /** @var array<int, Node\Expr\StaticCall> $staticCalls */
        $staticCalls = $finder->findInstanceOf($ast, Node\Expr\StaticCall::class);
        foreach ($staticCalls as $call) {
            if (! $call->class instanceof Node\Name) {
                continue;
            }
            $shortName = $this->shortClassName(ltrim($call->class->toString(), '\\'));
            if (strtolower($shortName) !== 'env') {
                continue;
            }
            if (! $call->name instanceof Node\Identifier || $call->name->toString() !== 'get') {
                continue;
            }
            $key = $this->literalFirstArg($call->getArgs());
            if ($key === null || isset($declared[$key])) {
                continue;
            }
            $undeclaredKeys[$key] = true;
        }
    }

    /**
     * Union of every key declared in any discovered env file.
     *
     * @return array<string, bool>  using a set-style map for O(1) lookups
     */
    private function collectDeclaredKeys(ProjectContext $ctx): array
    {
        $declared = [];

        foreach (['.env', '.env.example'] as $core) {
            if ($ctx->fileExists($core)) {
                foreach (array_keys($this->loader->load($ctx->rootPath, $core)) as $k) {
                    $declared[$k] = true;
                }
            }
        }

        foreach ($this->discovery->discover($ctx->rootPath) as $other) {
            foreach (array_keys($this->loader->load($ctx->rootPath, $other)) as $k) {
                $declared[$k] = true;
            }
        }

        return $declared;
    }
}
