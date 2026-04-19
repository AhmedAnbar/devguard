<?php

declare(strict_types=1);

namespace DevGuard\Contracts;

use DevGuard\Results\ToolReport;
use Symfony\Component\Console\Output\OutputInterface;

interface RendererInterface
{
    public function render(ToolReport $report, OutputInterface $output): void;
}
