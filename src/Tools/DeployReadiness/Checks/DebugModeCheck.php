<?php

declare(strict_types=1);

namespace DevGuard\Tools\DeployReadiness\Checks;

use DevGuard\Contracts\CheckInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\CheckResult;

final class DebugModeCheck implements CheckInterface
{
    public function __construct(private readonly int $impact = 15) {}

    public function name(): string
    {
        return 'debug_mode';
    }

    public function run(ProjectContext $ctx): CheckResult
    {
        $debug = $ctx->envValue('APP_DEBUG');
        $env = $ctx->envValue('APP_ENV', 'production');

        if ($debug === null) {
            return CheckResult::warn(
                $this->name(),
                'APP_DEBUG is not set in .env',
                $this->impact,
                'Set APP_DEBUG=false in your production .env file.'
            );
        }

        $isDebug = in_array(strtolower((string) $debug), ['true', '1', 'on', 'yes'], true);

        if (! $isDebug) {
            return CheckResult::pass($this->name(), 'APP_DEBUG is disabled');
        }

        return $env === 'production'
            ? CheckResult::fail(
                $this->name(),
                'APP_DEBUG is enabled in production',
                $this->impact,
                'Set APP_DEBUG=false to avoid leaking stack traces and config to users.'
            )
            : CheckResult::warn(
                $this->name(),
                "APP_DEBUG is enabled (APP_ENV={$env})",
                $this->impact,
                'Make sure APP_DEBUG=false before deploying to production.'
            );
    }
}
