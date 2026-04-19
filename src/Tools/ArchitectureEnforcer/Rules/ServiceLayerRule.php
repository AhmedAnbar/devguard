<?php

declare(strict_types=1);

namespace DevGuard\Tools\ArchitectureEnforcer\Rules;

use DevGuard\Contracts\RuleInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\RuleResult;
use DevGuard\Tools\ArchitectureEnforcer\FileScanner;

final class ServiceLayerRule implements RuleInterface
{
    public function __construct(
        private readonly FileScanner $scanner = new FileScanner(),
        private readonly string $path = 'app/Services',
    ) {}

    public function name(): string
    {
        return 'service_layer';
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
                "No Service layer detected ({$this->path} missing)",
                null,
                null,
                'Introduce a Service layer to keep controllers thin and reuse business logic.'
            )];
        }

        $count = iterator_count($this->scanner->phpFilesIn($ctx, $this->path));

        if ($count === 0) {
            return [RuleResult::warn(
                $this->name(),
                "{$this->path} exists but is empty",
                null,
                null,
                'Move logic from controllers into Service classes to reuse and test independently.'
            )];
        }

        return [RuleResult::pass(
            $this->name(),
            "Service layer detected ({$count} class" . ($count === 1 ? '' : 'es') . " in {$this->path})"
        )];
    }
}
