<?php

declare(strict_types=1);

namespace DevGuard\Tools\ArchitectureEnforcer\Rules;

use DevGuard\Contracts\RuleInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\RuleResult;

final class FolderStructureRule implements RuleInterface
{
    private const REQUIRED_DIRS = [
        'app',
        'app/Http',
        'app/Http/Controllers',
        'app/Models',
        'app/Providers',
        'config',
        'routes',
    ];

    public function name(): string
    {
        return 'folder_structure';
    }

    /** @return array<int, RuleResult> */
    public function run(ProjectContext $ctx): array
    {
        if (! $ctx->isLaravel) {
            return [RuleResult::pass($this->name(), 'Skipped (not a Laravel project)')];
        }

        $missing = [];
        foreach (self::REQUIRED_DIRS as $dir) {
            if (! is_dir($ctx->path($dir))) {
                $missing[] = $dir;
            }
        }

        if ($missing === []) {
            return [RuleResult::pass($this->name(), 'Folder structure follows Laravel convention')];
        }

        return [RuleResult::fail(
            $this->name(),
            'Missing expected directories: ' . implode(', ', $missing),
            null,
            null,
            'Create the missing directories or document why your project deviates from convention.'
        )];
    }
}
