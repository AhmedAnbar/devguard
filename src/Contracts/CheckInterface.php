<?php

declare(strict_types=1);

namespace DevGuard\Contracts;

use DevGuard\Core\ProjectContext;
use DevGuard\Results\CheckResult;

interface CheckInterface
{
    public function name(): string;

    public function run(ProjectContext $ctx): CheckResult;
}
