<?php

declare(strict_types=1);

namespace DevGuard\Tools\DependencyAudit;

use DevGuard\Contracts\FixableInterface;
use DevGuard\Contracts\FixableToolInterface;
use DevGuard\Contracts\RuleInterface;
use DevGuard\Contracts\ToolInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\RuleResult;
use DevGuard\Results\ToolReport;
use DevGuard\Tools\DependencyAudit\Rules\ComposerAuditRule;

final class DependencyAuditTool implements ToolInterface, FixableToolInterface
{
    /** @var array<int, RuleInterface> */
    private array $rules;

    public function __construct()
    {
        $this->rules = [
            new ComposerAuditRule(),
        ];
    }

    public function name(): string
    {
        return 'deps';
    }

    public function title(): string
    {
        return '📦 Dependency Audit';
    }

    public function description(): string
    {
        return 'Scans composer.lock for known security advisories and abandoned packages.';
    }

    public function run(ProjectContext $ctx): ToolReport
    {
        $report = new ToolReport(tool: $this->name(), title: 'Dependency Audit Report');

        foreach ($this->rules as $rule) {
            try {
                $results = $rule->run($ctx);
            } catch (\Throwable $e) {
                $results = [RuleResult::fail(
                    $rule->name(),
                    "Rule '{$rule->name()}' crashed: {$e->getMessage()}",
                    null,
                    null,
                    'Re-run with -v for stack trace.'
                )];
            }

            foreach ($results as $r) {
                $report->add($r);
            }
        }

        return $report;
    }

    /** @return array<int, FixableInterface> */
    public function fixableRules(): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn ($r) => $r instanceof FixableInterface,
        ));
    }
}
