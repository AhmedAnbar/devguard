<?php

declare(strict_types=1);

namespace DevGuard\Contracts;

use DevGuard\Core\ProjectContext;
use DevGuard\Results\ToolReport;

interface ToolInterface
{
    public function name(): string;

    public function title(): string;

    public function description(): string;

    public function run(ProjectContext $ctx): ToolReport;
}
