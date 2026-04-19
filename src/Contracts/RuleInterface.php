<?php

declare(strict_types=1);

namespace DevGuard\Contracts;

use DevGuard\Core\ProjectContext;

interface RuleInterface
{
    public function name(): string;

    /** @return array<int, \DevGuard\Results\RuleResult> */
    public function run(ProjectContext $ctx): array;
}
