<?php

declare(strict_types=1);

namespace DevGuard\Tools\ArchitectureEnforcer\Rules;

use DevGuard\Contracts\RuleInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\RuleResult;
use DevGuard\Support\AstHelper;
use DevGuard\Tools\ArchitectureEnforcer\FileScanner;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

final class BusinessLogicInControllerRule implements RuleInterface
{
    public function __construct(
        private readonly FileScanner $scanner = new FileScanner(),
        private readonly AstHelper $ast = new AstHelper(),
        private readonly int $failThreshold = 10,
        private readonly int $warnThreshold = 6,
    ) {}

    public function name(): string
    {
        return 'business_logic_in_controller';
    }

    /** @return array<int, RuleResult> */
    public function run(ProjectContext $ctx): array
    {
        if (! $ctx->isLaravel) {
            return [RuleResult::pass($this->name(), 'Skipped (not a Laravel project)')];
        }

        $results = [];
        $offenders = 0;

        foreach ($this->scanner->controllers($ctx) as $file) {
            $absolute = $file->getRealPath() ?: $file->getPathname();
            $relative = $this->scanner->relativePath($ctx, $file);
            $tree = $this->ast->parseFile($absolute, $parseError);
            if ($tree === null) {
                // Surface the skip — silent fallbacks hide real bugs (lesson #21).
                // Without this, a controller with a syntax error is invisibly
                // unaudited; the team thinks it's clean when it's just unscanned.
                $results[] = RuleResult::warn(
                    $this->name(),
                    sprintf('Could not parse %s — complexity check skipped (%s)', $relative, $parseError ?? 'unknown error'),
                    $relative,
                    null,
                    sprintf('Run `php -l %s` to see the parse error, then re-run DevGuard.', $relative)
                );
                continue;
            }

            foreach ($this->ast->classMethods($tree) as $method) {
                if (! $method->isPublic() || $this->isFrameworkMethod($method->name->toString())) {
                    continue;
                }

                $complexity = $this->complexityOf($method);
                $line = $method->getStartLine();

                if ($complexity >= $this->failThreshold) {
                    $offenders++;
                    $results[] = RuleResult::fail(
                        $this->name(),
                        sprintf(
                            "Method %s() has cyclomatic complexity %d (threshold %d)",
                            $method->name->toString(),
                            $complexity,
                            $this->failThreshold
                        ),
                        $relative,
                        $line,
                        'Extract helper methods or move logic into a Service class.'
                    );
                } elseif ($complexity >= $this->warnThreshold) {
                    $offenders++;
                    $results[] = RuleResult::warn(
                        $this->name(),
                        sprintf(
                            "Method %s() has elevated complexity %d (warn at %d)",
                            $method->name->toString(),
                            $complexity,
                            $this->warnThreshold
                        ),
                        $relative,
                        $line,
                        'Consider splitting branches into smaller methods.'
                    );
                }
            }
        }

        if ($offenders === 0) {
            $results[] = RuleResult::pass(
                $this->name(),
                "All controller methods are below complexity {$this->warnThreshold}"
            );
        }

        return $results;
    }

    private function isFrameworkMethod(string $name): bool
    {
        return in_array($name, ['__construct', '__invoke', 'middleware'], true);
    }

    private function complexityOf(Node\Stmt\ClassMethod $method): int
    {
        $visitor = new class extends NodeVisitorAbstract {
            public int $complexity = 1;

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\If_
                    || $node instanceof Node\Stmt\ElseIf_
                    || $node instanceof Node\Stmt\For_
                    || $node instanceof Node\Stmt\Foreach_
                    || $node instanceof Node\Stmt\While_
                    || $node instanceof Node\Stmt\Do_
                    || $node instanceof Node\Stmt\Case_
                    || $node instanceof Node\Stmt\Catch_
                    || $node instanceof Node\Expr\Ternary
                    || $node instanceof Node\Expr\BinaryOp\Coalesce
                    || $node instanceof Node\Expr\BinaryOp\BooleanAnd
                    || $node instanceof Node\Expr\BinaryOp\BooleanOr
                    || $node instanceof Node\Expr\BinaryOp\LogicalAnd
                    || $node instanceof Node\Expr\BinaryOp\LogicalOr
                ) {
                    $this->complexity++;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse([$method]);

        return $visitor->complexity;
    }
}
