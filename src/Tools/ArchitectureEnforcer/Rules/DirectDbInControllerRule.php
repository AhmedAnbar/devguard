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

final class DirectDbInControllerRule implements RuleInterface
{
    private const DB_QUERY_METHODS = [
        'table', 'select', 'statement', 'insert', 'update', 'delete', 'unprepared',
    ];

    public function __construct(
        private readonly FileScanner $scanner = new FileScanner(),
        private readonly AstHelper $ast = new AstHelper(),
    ) {}

    public function name(): string
    {
        return 'direct_db_in_controller';
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
            $tree = $this->ast->parseFile($absolute);
            if ($tree === null) {
                continue;
            }

            $relative = $this->scanner->relativePath($ctx, $file);

            foreach ($this->ast->classMethods($tree) as $method) {
                $hits = $this->findDbCalls($method);
                foreach ($hits as $hit) {
                    $offenders++;
                    $results[] = RuleResult::fail(
                        $this->name(),
                        sprintf(
                            "Direct DB call %s in %s()",
                            $hit['call'],
                            $method->name->toString()
                        ),
                        $relative,
                        $hit['line'],
                        'Move query into a Service or Repository class to keep the controller free of persistence concerns.'
                    );
                }
            }
        }

        if ($offenders === 0) {
            $results[] = RuleResult::pass(
                $this->name(),
                'No direct DB calls in controllers'
            );
        }

        return $results;
    }

    /** @return array<int, array{call: string, line: int}> */
    private function findDbCalls(Node\Stmt\ClassMethod $method): array
    {
        $hits = [];
        $methods = self::DB_QUERY_METHODS;

        $visitor = new class($hits, $methods) extends NodeVisitorAbstract {
            /** @param array<int, array{call: string, line: int}> $hits */
            public function __construct(public array &$hits, private readonly array $methods) {}

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Node\Expr\StaticCall) {
                    return null;
                }
                if (! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier) {
                    return null;
                }

                $class = $node->class->toString();
                $method = $node->name->toString();

                if ($class === 'DB' || str_ends_with($class, '\\DB')) {
                    if (in_array($method, $this->methods, true)) {
                        $this->hits[] = [
                            'call' => "DB::{$method}()",
                            'line' => $node->getStartLine(),
                        ];
                    }
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse([$method]);

        return $visitor->hits;
    }
}
