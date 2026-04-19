<?php

declare(strict_types=1);

namespace DevGuard\Tools\DeployReadiness;

use DevGuard\Contracts\CheckInterface;
use DevGuard\Contracts\ToolInterface;
use DevGuard\Core\Config\Config;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\CheckResult;
use DevGuard\Results\ToolReport;
use DevGuard\Tools\DeployReadiness\Checks\CacheConfiguredCheck;
use DevGuard\Tools\DeployReadiness\Checks\DebugModeCheck;
use DevGuard\Tools\DeployReadiness\Checks\EnvFileExistsCheck;
use DevGuard\Tools\DeployReadiness\Checks\HttpsEnforcedCheck;
use DevGuard\Tools\DeployReadiness\Checks\LoggingConfiguredCheck;
use DevGuard\Tools\DeployReadiness\Checks\QueueConfiguredCheck;
use DevGuard\Tools\DeployReadiness\Checks\RateLimitCheck;

final class DeployReadinessTool implements ToolInterface
{
    /** @var array<int, CheckInterface> */
    private array $checks;

    public function __construct(
        private readonly ?Config $config = null,
    ) {
        $this->checks = [
            new EnvFileExistsCheck($this->impactFor('env_file_exists', 20)),
            new DebugModeCheck($this->impactFor('debug_mode', 15)),
            new CacheConfiguredCheck($this->impactFor('cache_configured', 10)),
            new QueueConfiguredCheck($this->impactFor('queue_configured', 10)),
            new RateLimitCheck($this->impactFor('rate_limit', 10)),
            new HttpsEnforcedCheck($this->impactFor('https_enforced', 10)),
            new LoggingConfiguredCheck($this->impactFor('logging_configured', 5)),
        ];
    }

    public function name(): string
    {
        return 'deploy';
    }

    public function title(): string
    {
        return '🚀 Deploy Readiness Score';
    }

    public function description(): string
    {
        return 'Audits your project for production-readiness and returns a 0–100 score.';
    }

    public function run(ProjectContext $ctx): ToolReport
    {
        $results = [];
        foreach ($this->checks as $check) {
            try {
                $results[] = $check->run($ctx);
            } catch (\Throwable $e) {
                $results[] = CheckResult::fail(
                    $check->name(),
                    "Check '{$check->name()}' crashed: {$e->getMessage()}",
                    0,
                    'Investigate the error in verbose mode (-v) for the stack trace.'
                );
            }
        }

        $score = (new Scorer())->score($results);
        $report = new ToolReport(tool: $this->name(), title: 'Deploy Readiness Score', score: $score);

        foreach ($results as $r) {
            $report->add($r);
        }

        return $report;
    }

    private function impactFor(string $checkName, int $default): int
    {
        if ($this->config === null) {
            return $default;
        }
        $value = $this->config->get("tools.deploy.checks.{$checkName}.impact");
        return is_int($value) ? $value : $default;
    }
}
