<?php

declare(strict_types=1);

namespace DevGuard\Tools\EnvAudit\Rules;

use DevGuard\Contracts\RuleInterface;
use DevGuard\Core\ProjectContext;
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
final class UndeclaredEnvCallsRule implements RuleInterface
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
                $scannedAny = true;
                $abs = $file->getRealPath() ?: $file->getPathname();
                $ast = $this->ast->parseFile($abs);
                if ($ast === null) {
                    continue;
                }

                $relPath = $this->relativePath($ctx, $abs);
                $this->collectFromAst($ast, $finder, $declared, $relPath, $reported, $results);
            }
        }

        if ($results === []) {
            return [RuleResult::pass(
                $this->name(),
                $scannedAny
                    ? 'All env() calls reference declared keys'
                    : 'Skipped — no scannable source directories (app/config/bootstrap/database/routes)'
            )];
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
