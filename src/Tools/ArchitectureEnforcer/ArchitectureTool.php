<?php

declare(strict_types=1);

namespace DevGuard\Tools\ArchitectureEnforcer;

use DevGuard\Contracts\RuleInterface;
use DevGuard\Contracts\ToolInterface;
use DevGuard\Core\Config\Config;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\RuleResult;
use DevGuard\Results\ToolReport;
use DevGuard\Tools\ArchitectureEnforcer\Rules\BusinessLogicInControllerRule;
use DevGuard\Tools\ArchitectureEnforcer\Rules\DirectDbInControllerRule;
use DevGuard\Tools\ArchitectureEnforcer\Rules\FatControllerRule;
use DevGuard\Tools\ArchitectureEnforcer\Rules\FolderStructureRule;
use DevGuard\Tools\ArchitectureEnforcer\Rules\RepositoryRule;
use DevGuard\Tools\ArchitectureEnforcer\Rules\ServiceLayerRule;

final class ArchitectureTool implements ToolInterface
{
    /** @var array<int, RuleInterface> */
    private array $rules;

    public function __construct(?Config $config = null)
    {
        $maxLines = is_int($v = $config?->get('tools.architecture.rules.fat_controller.max_lines')) ? $v : 300;
        $servicePath = is_string($v = $config?->get('tools.architecture.rules.service_layer.path')) ? $v : 'app/Services';
        $repoPath = is_string($v = $config?->get('tools.architecture.rules.repository_layer.path')) ? $v : 'app/Repositories';

        $scanner = new FileScanner();

        $this->rules = [
            new FolderStructureRule(),
            new FatControllerRule($scanner, $maxLines),
            new BusinessLogicInControllerRule($scanner),
            new DirectDbInControllerRule($scanner),
            new ServiceLayerRule($scanner, $servicePath),
            new RepositoryRule($scanner, $repoPath),
        ];
    }

    public function name(): string
    {
        return 'architecture';
    }

    public function title(): string
    {
        return '🏗  Laravel Architecture Enforcer';
    }

    public function description(): string
    {
        return 'Enforces clean architecture practices: thin controllers, proper layering, no direct DB in controllers.';
    }

    public function run(ProjectContext $ctx): ToolReport
    {
        $report = new ToolReport(tool: $this->name(), title: 'Architecture Report');

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
}
