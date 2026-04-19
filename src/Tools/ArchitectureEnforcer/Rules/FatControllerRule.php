<?php

declare(strict_types=1);

namespace DevGuard\Tools\ArchitectureEnforcer\Rules;

use DevGuard\Contracts\RuleInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\RuleResult;
use DevGuard\Tools\ArchitectureEnforcer\FileScanner;

final class FatControllerRule implements RuleInterface
{
    public function __construct(
        private readonly FileScanner $scanner = new FileScanner(),
        private readonly int $maxLines = 300,
    ) {}

    public function name(): string
    {
        return 'fat_controller';
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
            $contents = (string) file_get_contents($file->getRealPath() ?: $file->getPathname());
            $lines = substr_count($contents, "\n") + 1;

            if ($lines > $this->maxLines) {
                $offenders++;
                $results[] = RuleResult::fail(
                    $this->name(),
                    sprintf('Controller is too large (%d lines, max %d)', $lines, $this->maxLines),
                    $this->scanner->relativePath($ctx, $file),
                    1,
                    'Extract methods into Service classes or Form Requests to slim the controller.'
                );
            }
        }

        if ($offenders === 0) {
            $results[] = RuleResult::pass(
                $this->name(),
                "All controllers are within {$this->maxLines} lines"
            );
        }

        return $results;
    }
}
