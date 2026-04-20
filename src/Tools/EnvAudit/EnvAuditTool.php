<?php

declare(strict_types=1);

namespace DevGuard\Tools\EnvAudit;

use DevGuard\Contracts\FixableInterface;
use DevGuard\Contracts\FixableToolInterface;
use DevGuard\Contracts\RuleInterface;
use DevGuard\Contracts\ToolInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\RuleResult;
use DevGuard\Results\ToolReport;
use DevGuard\Tools\EnvAudit\Rules\DriftedEnvKeysRule;
use DevGuard\Tools\EnvAudit\Rules\EnvExampleExistsRule;
use DevGuard\Tools\EnvAudit\Rules\MissingEnvKeysRule;
use DevGuard\Tools\EnvAudit\Rules\OtherEnvFilesDriftRule;
use DevGuard\Tools\EnvAudit\Rules\UndeclaredEnvCallsRule;
use DevGuard\Tools\EnvAudit\Rules\WeakAppKeyRule;

final class EnvAuditTool implements ToolInterface, FixableToolInterface
{
    /** @var array<int, RuleInterface> */
    private array $rules;

    public function __construct()
    {
        $this->rules = [
            new EnvExampleExistsRule(),
            new MissingEnvKeysRule(),
            new DriftedEnvKeysRule(),
            new OtherEnvFilesDriftRule(),
            new UndeclaredEnvCallsRule(),
            new WeakAppKeyRule(),
        ];
    }

    public function name(): string
    {
        return 'env';
    }

    public function title(): string
    {
        return '🔐 Env Audit';
    }

    public function description(): string
    {
        return 'Audits .env vs .env.example: missing keys, drift, and weak app key values.';
    }

    public function run(ProjectContext $ctx): ToolReport
    {
        $report = new ToolReport(tool: $this->name(), title: 'Env Audit Report');

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
