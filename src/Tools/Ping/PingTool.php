<?php

declare(strict_types=1);

namespace DevGuard\Tools\Ping;

use DevGuard\Contracts\ToolInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\CheckResult;
use DevGuard\Results\ToolReport;

final class PingTool implements ToolInterface
{
    public function name(): string
    {
        return 'ping';
    }

    public function title(): string
    {
        return 'Ping (smoke test)';
    }

    public function description(): string
    {
        return 'Verifies DevGuard wiring by reporting on the detected project context.';
    }

    public function run(ProjectContext $ctx): ToolReport
    {
        $report = new ToolReport(tool: 'ping', title: 'DevGuard Ping', score: 100);

        $report->add(CheckResult::pass(
            'context_detected',
            "Project root resolved to: {$ctx->rootPath}"
        ));

        $report->add($ctx->isLaravel
            ? CheckResult::pass('laravel_detected', "Laravel detected ({$ctx->laravelVersion})")
            : CheckResult::warn('laravel_detected', 'Laravel not detected — some tools will skip', 0,
                'This is fine for the ping smoke test. Architecture/Deploy tools target Laravel projects.'));

        return $report;
    }
}
