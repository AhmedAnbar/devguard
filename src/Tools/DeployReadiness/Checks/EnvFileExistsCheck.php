<?php

declare(strict_types=1);

namespace DevGuard\Tools\DeployReadiness\Checks;

use DevGuard\Contracts\CheckInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\CheckResult;

final class EnvFileExistsCheck implements CheckInterface
{
    public function __construct(private readonly int $impact = 20) {}

    public function name(): string
    {
        return 'env_file_exists';
    }

    public function run(ProjectContext $ctx): CheckResult
    {
        if ($ctx->fileExists('.env')) {
            return CheckResult::pass($this->name(), '.env file exists');
        }

        return CheckResult::fail(
            $this->name(),
            '.env file is missing at project root',
            $this->impact,
            'Copy .env.example to .env and configure your environment values.'
        );
    }
}
