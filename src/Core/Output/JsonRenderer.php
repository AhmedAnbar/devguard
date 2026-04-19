<?php

declare(strict_types=1);

namespace DevGuard\Core\Output;

use DevGuard\Contracts\RendererInterface;
use DevGuard\Results\ToolReport;
use Symfony\Component\Console\Output\OutputInterface;

final class JsonRenderer implements RendererInterface
{
    public function render(ToolReport $report, OutputInterface $output): void
    {
        $output->writeln(json_encode(
            $report->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }
}
