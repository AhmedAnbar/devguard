<?php

declare(strict_types=1);

namespace DevGuard\Tools\ArchitectureEnforcer\Rules;

use DevGuard\Contracts\RuleInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\RuleResult;
use DevGuard\Tools\ArchitectureEnforcer\FileScanner;

final class RepositoryRule implements RuleInterface
{
    public function __construct(
        private readonly FileScanner $scanner = new FileScanner(),
        private readonly string $path = 'app/Repositories',
    ) {}

    public function name(): string
    {
        return 'repository_layer';
    }

    /** @return array<int, RuleResult> */
    public function run(ProjectContext $ctx): array
    {
        if (! $ctx->isLaravel) {
            return [RuleResult::pass($this->name(), 'Skipped (not a Laravel project)')];
        }

        if (! is_dir($ctx->path($this->path))) {
            return [RuleResult::warn(
                $this->name(),
                "No Repository layer detected ({$this->path} missing)",
                null,
                null,
                'A Repository layer can isolate database access and make controllers/services easier to test.'
            )];
        }

        $count = iterator_count($this->scanner->phpFilesIn($ctx, $this->path));

        if ($count === 0) {
            return [RuleResult::warn(
                $this->name(),
                "{$this->path} exists but is empty"
            )];
        }

        return [RuleResult::pass(
            $this->name(),
            "Repository layer detected ({$count} class" . ($count === 1 ? '' : 'es') . " in {$this->path})"
        )];
    }
}
